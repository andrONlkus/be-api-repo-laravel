<?php

namespace App\Services\Archive;

use App\Services\Concerns\UsesDmsConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * API arsip umum: pencarian, detail, create, update, download.
 */
class ArchiveService
{
    use UsesDmsConnection;

    // Nama field request => kolom tabel document
    private const FIELD_MAP = [
        'id_category' => 'id_category',
        'departement_owner' => 'departement_owner',
        'tipefile' => 'tipefile',
        'doc_date' => 'yearDate',
        'keyword' => 'keyword',
        'summary' => 'summary',
        'active_until' => 'active_until',
        'public' => 'public',
        'expired_public' => 'expired_public',
        'link' => 'link',
    ];

    private const SORTS = ['created_date' => 'd.created_date', 'kode_arsip' => 'd.kode_arsip', 'doc_date' => 'd.yearDate', 'id_doc' => 'd.id_doc'];

    public function __construct(
        private MasterDataService $master,
        private CategoryAccessService $access,
        private KodeArsipService $kode,
        private ArchiveFileStorage $storage,
    ) {}

    public function search(array $f): array
    {
        $page = (int) ($f['page'] ?? 1);
        $limit = (int) ($f['limit'] ?? 20);
        $query = $this->baseQuery();

        if (! empty($f['q'])) {
            $term = '%'.addcslashes($f['q'], '\\%_').'%';
            $query->where(fn ($w) => $w->where('d.keyword', 'like', $term)
                ->orWhere('d.summary', 'like', $term)
                ->orWhere('d.kode_arsip', 'like', $term));
        }
        if (! empty($f['id_category'])) {
            if (! empty($f['include_sub'])) {
                $codeFull = $this->master->category((int) $f['id_category'])->code_full;
                $query->where(fn ($w) => $w->where('c.code_full', $codeFull)->orWhere('c.code_full', 'like', $codeFull.'.%'));
            } else {
                $query->where('d.id_category', (string) $f['id_category']);
            }
        }
        foreach (['departement_owner', 'tipefile'] as $field) {
            if (! empty($f[$field])) {
                $query->where('d.'.$field, (int) $f[$field]);
            }
        }
        if (! empty($f['year'])) {
            $query->where('d.yearDate', 'like', $f['year'].'%');
        }
        if (! empty($f['doc_date'])) {
            $query->where('d.yearDate', $f['doc_date']);
        }
        if (! empty($f['created_from'])) {
            $query->where('d.created_date', '>=', $f['created_from'].' 00:00:00');
        }
        if (! empty($f['created_to'])) {
            $query->where('d.created_date', '<=', $f['created_to'].' 23:59:59');
        }
        if (isset($f['public'])) {
            $query->where('d.public', (string) $f['public']);
        }

        $total = (clone $query)->count();
        $order = $f['order'] ?? 'desc';
        $rows = $query->select($this->columns())
            ->orderBy(self::SORTS[$f['sort'] ?? 'created_date'], $order)
            ->orderBy('d.id_doc', $order)
            ->forPage($page, $limit)
            ->get();

        $files = $this->filesFor($rows->pluck('id_doc')->all());

        return [
            'data' => $rows->map(fn ($row) => $this->format($row, $files[(int) $row->id_doc] ?? []))->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    public function detail(int $idDoc): array
    {
        $row = $this->findOrFail($idDoc);

        return $this->format($row, $this->filesFor([$idDoc])[$idDoc] ?? []);
    }

    /**
     * @param  UploadedFile[]  $files
     */
    public function create(array $data, array $files, bool $validateAccess, int $user): array
    {
        $category = $this->master->category((int) $data['id_category']);
        $dept = $this->master->departement((int) $data['departement_owner']);
        $this->assertFileSizes($files, (int) $dept->upload_limit);

        if ($validateAccess && ! $this->access->has((int) $category->id_category, (int) $dept->id_departement)) {
            throw new AccessDeniedHttpException('Departemen tidak memiliki akses upload ke kategori ini.');
        }

        $columns = $this->toColumns($data) + [
            'public' => '0',
            'expired_public' => null,
            'link' => '',
            'active_until' => now()->addYears((int) $category->doc_life)->toDateString(),
        ];

        $stored = $this->storage->store($files, $data['doc_date']);

        try {
            [$idDoc, $kodeArsip] = $this->kode->withLock($category->code_full, fn () => $this->db()->transaction(function () use ($category, $columns, $stored, $user) {
                $kodeArsip = $this->kode->next($category->code_full);
                $idDoc = (int) $this->db()->table('document')->insertGetId(array_merge($columns, [
                    'kode_arsip' => $kodeArsip,
                    'filename' => implode(',', array_column($stored, 'filename')),
                    'status' => 'A',
                    'isABulk' => 'N',
                    'created_by' => (string) $user,
                    'created_date' => now()->format('Y-m-d H:i:s'),
                ]), 'id_doc');
                $this->insertFiles($idDoc, $stored);
                $this->log($idDoc, $user, 'Create via API');

                return [$idDoc, $kodeArsip];
            }));
        } catch (Throwable $e) {
            $this->storage->delete(array_column($stored, 'filename'));
            throw $e;
        }

        return [
            'id_doc' => $idDoc,
            'kode_arsip' => $kodeArsip,
            'files' => $this->formatFiles($idDoc, $stored),
        ];
    }

    /**
     * @param  UploadedFile[]  $files
     * @param  string  $fileMode  append | replace
     * @param  string[]  $deleteFiles
     */
    public function update(int $idDoc, array $data, array $files, string $fileMode, array $deleteFiles, bool $validateAccess, int $user): array
    {
        $doc = $this->findOrFail($idDoc);
        $existing = array_column($this->filesFor([$idDoc])[$idDoc] ?? [], 'filename');

        $unknown = array_diff($deleteFiles, $existing);
        if ($unknown) {
            throw ValidationException::withMessages(['delete_files' => 'File bukan milik arsip ini: '.implode(', ', $unknown)]);
        }
        if ($fileMode === 'replace' && empty($files)) {
            throw ValidationException::withMessages(['userfile' => 'file_mode=replace membutuhkan minimal 1 file baru.']);
        }

        $remove = $fileMode === 'replace' ? $existing : array_values(array_unique($deleteFiles));
        $remaining = array_values(array_diff($existing, $remove));
        if (empty($files) && empty($remaining)) {
            throw ValidationException::withMessages(['delete_files' => 'Arsip harus tetap memiliki minimal 1 file.']);
        }

        $changes = $this->toColumns($data);
        if (empty($changes) && empty($files) && empty($remove)) {
            throw ValidationException::withMessages(['data' => 'Tidak ada data yang diubah.']);
        }

        $deptId = (int) ($changes['departement_owner'] ?? $doc->departement_owner);
        $dept = $this->master->departement($deptId);
        $this->assertFileSizes($files, $dept ? (int) $dept->upload_limit : 20);

        if ($validateAccess && ! $this->access->has((int) ($changes['id_category'] ?? $doc->id_category), $deptId)) {
            throw new AccessDeniedHttpException('Departemen tidak memiliki akses edit ke kategori ini.');
        }

        $stored = $files ? $this->storage->store($files, $changes['yearDate'] ?? $doc->doc_date) : [];

        try {
            $this->db()->transaction(function () use ($idDoc, $changes, $remaining, $remove, $stored, $user) {
                $this->db()->table('document')->where('id_doc', $idDoc)->update(array_merge($changes, [
                    'filename' => implode(',', array_merge($remaining, array_column($stored, 'filename'))),
                    'edited_by' => $user,
                    'edited_date' => now()->format('Y-m-d H:i:s'),
                ]));
                if ($remove) {
                    $this->db()->table('files')->where('id_doc', $idDoc)->whereIn('filename', $remove)->delete();
                }
                $this->insertFiles($idDoc, $stored);
                $this->log($idDoc, $user, 'Update via API');
            });
        } catch (Throwable $e) {
            $this->storage->delete(array_column($stored, 'filename'));
            throw $e;
        }

        // File fisik lama baru dihapus setelah database berhasil diperbarui
        $this->storage->delete($remove);

        return $this->detail($idDoc);
    }

    public function download(int $idDoc, string $filename): StreamedResponse
    {
        $this->findOrFail($idDoc);
        $file = collect($this->filesFor([$idDoc])[$idDoc] ?? [])->firstWhere('filename', $filename);
        if ($file === null || ! $this->storage->exists($filename)) {
            throw new NotFoundHttpException('File tidak ditemukan.');
        }

        $this->log($idDoc, config('arsip.default_user'), 'Download via API');

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = ($file['realname'] !== '' ? $file['realname'] : pathinfo($filename, PATHINFO_FILENAME)).($ext !== '' ? '.'.$ext : '');

        return $this->storage->disk()->download(basename($filename), $name);
    }

    // ---------------------------------------------------------------

    private function baseQuery(): Builder
    {
        return $this->db()->table('document as d')
            ->leftJoin('categories as c', 'c.id_category', '=', 'd.id_category')
            ->leftJoin('departement as p', 'p.id_departement', '=', 'd.departement_owner')
            ->leftJoin('tipefile as t', 't.id_tipefile', '=', 'd.tipefile')
            ->where('d.status', 'A');
    }

    private function columns(): array
    {
        return [
            'd.id_doc', 'd.kode_arsip', 'd.yearDate as doc_date', 'd.id_category', 'c.code_full', 'c.name_category', 'c.doc_status',
            'd.departement_owner', 'p.nama_departement', 'd.tipefile', 't.tipefile as nama_tipefile',
            'd.keyword', 'd.summary', 'd.public', 'd.expired_public', 'd.active_until', 'd.link',
            'd.created_by', 'd.created_date', 'd.edited_by', 'd.edited_date',
        ];
    }

    private function findOrFail(int $idDoc): object
    {
        $row = $this->baseQuery()->select($this->columns())->where('d.id_doc', $idDoc)->first();
        if ($row === null) {
            throw new NotFoundHttpException('Arsip tidak ditemukan.');
        }

        return $row;
    }

    /**
     * File per dokumen dari tabel files. Dokumen lama tanpa baris di tabel files
     * memakai kolom document.filename (dipisah koma).
     *
     * @return array<int, array<int, array{filename: string, realname: string}>>
     */
    public function filesFor(array $idDocs): array
    {
        $map = [];
        if (empty($idDocs)) {
            return $map;
        }

        $rows = $this->db()->table('files')->select('id_doc', 'filename', 'realname')
            ->whereIn('id_doc', $idDocs)->orderBy('id')->get();
        foreach ($rows as $r) {
            $map[(int) $r->id_doc][] = ['filename' => $r->filename, 'realname' => (string) $r->realname];
        }

        $missing = array_diff(array_map('intval', $idDocs), array_keys($map));
        if ($missing) {
            $docs = $this->db()->table('document')->select('id_doc', 'filename')->whereIn('id_doc', $missing)->get();
            foreach ($docs as $d) {
                foreach (array_filter(array_map('trim', explode(',', (string) $d->filename))) as $name) {
                    $map[(int) $d->id_doc][] = ['filename' => $name, 'realname' => ''];
                }
            }
        }

        return $map;
    }

    private function toColumns(array $data): array
    {
        $columns = [];
        foreach (self::FIELD_MAP as $field => $column) {
            if (array_key_exists($field, $data)) {
                $columns[$column] = $data[$field];
            }
        }
        if (array_key_exists('id_category', $columns)) {
            $columns['id_category'] = (string) $columns['id_category'];
        }
        if (array_key_exists('link', $columns)) {
            $columns['link'] = (string) $columns['link'];
        }

        return $columns;
    }

    /**
     * @param  UploadedFile[]  $files
     */
    private function assertFileSizes(array $files, int $limitMb): void
    {
        $maxBytes = $limitMb * 1000 * 1024; // sama dengan CodeIgniter: upload_limit * 1000 KB
        foreach (array_values($files) as $i => $file) {
            if ($maxBytes > 0 && $file->getSize() > $maxBytes) {
                throw ValidationException::withMessages([
                    'userfile.'.$i => $file->getClientOriginalName().': ukuran melebihi batas departemen ('.$limitMb.' MB).',
                ]);
            }
        }
    }

    private function insertFiles(int $idDoc, array $stored): void
    {
        foreach ($stored as $f) {
            $this->db()->table('files')->insert(['id_doc' => $idDoc, 'filename' => $f['filename'], 'realname' => $f['realname']]);
        }
    }

    private function log(int $idDoc, int $user, string $activity): void
    {
        $this->db()->table('doc_log')->insert([
            'id_doc' => $idDoc,
            'idusers' => (string) $user,
            'activity' => $activity,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function formatFiles(int $idDoc, array $files): array
    {
        return array_map(fn ($f) => [
            'filename' => $f['filename'],
            'realname' => $f['realname'],
            'url' => route('archive.file', ['idDoc' => $idDoc, 'filename' => $f['filename']]),
        ], $files);
    }

    private function format(object $row, array $files): array
    {
        return [
            'id_doc' => (int) $row->id_doc,
            'kode_arsip' => $row->kode_arsip,
            'doc_date' => $row->doc_date,
            'category' => [
                'id_category' => (int) $row->id_category,
                'code_full' => $row->code_full,
                'name_category' => $row->name_category,
                'doc_status' => $row->doc_status,
            ],
            'departement' => ['id_departement' => (int) $row->departement_owner, 'nama_departement' => $row->nama_departement],
            'tipefile' => ['id_tipefile' => (int) $row->tipefile, 'tipefile' => $row->nama_tipefile],
            'keyword' => $row->keyword,
            'summary' => $row->summary,
            'public' => (string) $row->public,
            'expired_public' => $row->expired_public,
            'active_until' => $row->active_until,
            'link' => $row->link,
            'created_by' => $row->created_by !== null ? (int) $row->created_by : null,
            'created_date' => $row->created_date,
            'edited_by' => $row->edited_by !== null ? (int) $row->edited_by : null,
            'edited_date' => $row->edited_date,
            'files' => $this->formatFiles((int) $row->id_doc, $files),
        ];
    }
}
