<?php

namespace App\Services\Legacy;

use App\Services\Archive\ArchiveFileStorage;
use App\Services\Archive\ArchiveService;
use App\Services\Archive\KodeArsipService;
use App\Services\Concerns\UsesDmsConnection;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Logika endpoint lama Ijazah & Transkrip (port dari CodeIgniter Api.php / M_api.php).
 * Perilaku dan isi respons dijaga sama supaya aplikasi pemakai tidak perlu diubah.
 */
class LegacyDocumentService
{
    use UsesDmsConnection;

    public function __construct(
        private ArchiveFileStorage $storage,
        private KodeArsipService $kode,
        private ArchiveService $archive,
    ) {}

    // M_api::getDocumentByKeyword (versi aman dari SQL Injection)
    public function findByNim(string $nim): ?object
    {
        return $this->db()->table('document')
            ->where('status', 'A')
            ->where(fn ($w) => $w->where('keyword', 'Ijazah,Transkrip,'.$nim)->orWhere('summary', 'Ijazah dan Transkrip - '.$nim))
            ->orderByDesc('created_date')
            ->first();
    }

    // M_api::getFiles: dari tabel files, atau document.filename untuk dokumen lama
    public function files(int $idDoc): array
    {
        return $this->archive->filesFor([$idDoc])[$idDoc] ?? [];
    }

    // M_api::getRealname
    public function realname(string $filename): ?string
    {
        return $this->db()->table('files')->where('filename', $filename)->value('realname');
    }

    public function logDownload(int $idDoc): void
    {
        $this->log($idDoc, 'Download via API');
    }

    public function uploadLimitMb(): int
    {
        return (int) $this->db()->table('departement')
            ->where('id_departement', config('arsip.legacy.departement'))
            ->value('upload_limit');
    }

    /**
     * Api::document_post. Bila NIM sudah punya dokumen aktif, file lama diganti
     * (file lama baru dihapus setelah file baru & database berhasil disimpan).
     * $idCategory hanya dipakai untuk dokumen baru, jadi boleh null saat update.
     *
     * @param  UploadedFile[]  $files
     * @return array{id_doc: int|string, kode_arsip: string}
     */
    public function upload(string $nim, ?int $idCategory, ?string $docDate, ?string $expiredDate, ?string $expPublic, array $files): array
    {
        $existing = $this->findByNim($nim);
        $stored = $files ? $this->storage->store($files, (string) $docDate) : [];
        $fileCsv = implode(',', array_column($stored, 'filename'));

        $common = [
            'yearDate' => $docDate,
            'filename' => $fileCsv,
            'status' => 'A',
            'isABulk' => 'N',
            'active_until' => date('Y-m-d H:i:s', strtotime((string) $expiredDate)),
            'departement_owner' => config('arsip.legacy.departement'),
            'tipefile' => config('arsip.legacy.tipefile'),
            'public' => '1',
            'expired_public' => $expPublic,
            'link' => '',
        ];
        $user = (string) config('arsip.default_user');

        try {
            if ($existing === null) {
                $codeFull = (string) $this->db()->table('categories')->where('id_category', $idCategory)->value('code_full');
                [$idDoc, $kodeArsip] = $this->kode->withLock($codeFull, fn () => $this->db()->transaction(function () use ($codeFull, $common, $idCategory, $nim, $stored, $user) {
                    $kodeArsip = $this->kode->next($codeFull);
                    $idDoc = (int) $this->db()->table('document')->insertGetId($common + [
                        'kode_arsip' => $kodeArsip,
                        'id_category' => (string) $idCategory,
                        'keyword' => 'Ijazah,Transkrip,'.$nim,
                        'summary' => 'Ijazah dan Transkrip - '.$nim,
                        'created_by' => $user,
                        'created_date' => now()->format('Y-m-d H:i:s'),
                    ], 'id_doc');
                    $this->insertFiles($idDoc, $stored);
                    $this->log($idDoc, 'Create via API');

                    return [$idDoc, $kodeArsip];
                }));

                return ['id_doc' => $idDoc, 'kode_arsip' => $kodeArsip];
            }

            $idDoc = (int) $existing->id_doc;
            $oldFiles = array_column($this->files($idDoc), 'filename');
            $this->db()->transaction(function () use ($idDoc, $common, $stored, $user) {
                $this->db()->table('document')->where('id_doc', $idDoc)->update($common + [
                    'edited_by' => $user,
                    'edited_date' => now()->format('Y-m-d H:i:s'),
                ]);
                $this->db()->table('files')->where('id_doc', $idDoc)->delete();
                $this->insertFiles($idDoc, $stored);
                $this->log($idDoc, 'Update via API');
            });
        } catch (Throwable $e) {
            $this->storage->delete(array_column($stored, 'filename'));
            throw $e;
        }

        $this->storage->delete(array_diff($oldFiles, array_column($stored, 'filename')));

        // CodeIgniter mengembalikan id_doc update sebagai string (hasil query mysqli)
        return ['id_doc' => (string) $idDoc, 'kode_arsip' => $existing->kode_arsip];
    }

    private function insertFiles(int $idDoc, array $stored): void
    {
        foreach ($stored as $f) {
            $this->db()->table('files')->insert(['id_doc' => $idDoc, 'filename' => $f['filename'], 'realname' => $f['realname']]);
        }
    }

    private function log(int $idDoc, string $activity): void
    {
        $this->db()->table('doc_log')->insert([
            'id_doc' => $idDoc,
            'idusers' => (string) config('arsip.default_user'),
            'activity' => $activity,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
