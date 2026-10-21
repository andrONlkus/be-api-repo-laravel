<?php

namespace App\Http\Controllers\Legacy;

use App\Http\Controllers\Controller;
use App\Services\Archive\ArchiveFileStorage;
use App\Services\Legacy\LegacyDocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Endpoint lama Ijazah & Transkrip (port dari CodeIgniter controllers/Api.php).
 * Format respons sengaja dibuat identik: {status, code, Message, data}.
 * Tanpa autentikasi, sama seperti sebelumnya.
 */
class LegacyApiController extends Controller
{
    public function __construct(private LegacyDocumentService $service) {}

    // GET /api
    public function index()
    {
        return response()->json(['status' => 'S', 'code' => '200', 'message' => 'Test API Success!']);
    }

    // GET /api/document?nim=  atau  /api/document/nim/{nim}
    public function document(Request $request, ?string $nim = null)
    {
        // REST_Controller membuang akhiran format di segmen terakhir: /nim/123.json -> 123
        $nim = $nim !== null ? preg_replace('/\.json$/', '', $nim) : $request->query('nim');

        if ($nim === '') {
            return response()->json(['status' => 'E', 'code' => '400', 'Message' => 'NIM is empty'], 404);
        }

        $doc = $this->service->findByNim((string) $nim);
        if ($doc === null) {
            return response()->json(['status' => 'E', 'code' => '400', 'Message' => 'Record is empty', 'data' => ''], 404);
        }

        $base = $this->legacyBaseUrl();
        $files = '';
        foreach ($this->service->files((int) $doc->id_doc) as $f) {
            $files .= $base.'index.php/api/download_file/'.$f['filename'].'/'.$doc->id_doc.'|'.$f['realname'].';';
        }

        return response()->json([
            'status' => 'S',
            'code' => '200',
            'Message' => 'Success',
            'data' => [
                'keyword' => $doc->keyword,
                'summary' => $doc->summary,
                'ListOfFiles' => $files,
            ],
        ]);
    }

    // GET /api/download_file/{filename}/{id_doc}
    public function downloadFile(string $filename, ?string $idDoc = null)
    {
        $filename = basename($filename);
        $storage = app(ArchiveFileStorage::class);

        if (! $storage->exists($filename)) {
            return response()->json(['status' => 'E', 'code' => '400', 'Message' => 'file not found, please contact administrator.'], 404);
        }

        if ($idDoc !== null && ctype_digit($idDoc)) {
            $this->service->logDownload((int) $idDoc);
        }

        $realname = $this->service->realname($filename);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = ! empty($realname) ? $realname.'.'.$ext : $filename;

        return $storage->disk()->download($filename, $name);
    }

    // POST /api/document (multipart: nim, school, doc_date, expired_date, exp_public, userfile[])
    public function upload(Request $request)
    {
        $nim = (string) $request->input('nim');
        $idCategory = config('arsip.legacy.categories')[$request->input('school')] ?? null;

        // school hanya dipakai saat membuat dokumen baru; update NIM lama tidak membacanya (sama dengan CodeIgniter)
        $error = match (true) {
            $nim === '' => 'NIM is empty',
            $idCategory === null && $this->service->findByNim($nim) === null => 'school must be SBE or STEM',
            default => null,
        };
        if ($error !== null) {
            return response()->json(['status' => 'E', 'code' => '400', 'Message' => $error], 400);
        }

        $files = array_values(array_filter((array) $request->file('userfile', []), fn ($f) => $f instanceof UploadedFile));
        $maxBytes = $this->service->uploadLimitMb() * 1000 * 1024;
        foreach ($files as $f) {
            if (! $f->isValid() || ($maxBytes > 0 && $f->getSize() > $maxBytes)) {
                // Respons asli CodeIgniter bila upload gagal
                return response()->json('wrong_format');
            }
        }

        $result = $this->service->upload(
            $nim,
            $idCategory,
            $request->input('doc_date'),
            $request->input('expired_date'),
            $request->input('exp_public'),
            $files,
        );

        return response()->json([
            'status' => 'S',
            'code' => '200',
            'data' => [
                'NIM' => $nim,
                'id_doc' => $result['id_doc'],
                'KodeArsip' => $result['kode_arsip'],
            ],
        ]);
    }

    // Awalan URL di ListOfFiles. CodeIgniter: base_url() + "index.php/api/download_file/..."
    private function legacyBaseUrl(): string
    {
        $base = config('arsip.legacy.base_url') ?: preg_replace('#/index\.php$#', '', url('/'));

        return rtrim($base, '/').'/';
    }
}
