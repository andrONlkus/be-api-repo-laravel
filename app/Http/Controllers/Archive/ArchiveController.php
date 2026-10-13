<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Http\Requests\Archive\SearchArchiveRequest;
use App\Http\Requests\Archive\StoreArchiveRequest;
use App\Http\Requests\Archive\UpdateArchiveRequest;
use App\Services\Archive\ArchiveService;
use Illuminate\Support\Arr;

/**
 * API arsip umum. Rancangan: be-api-repo/analisa/06-rancangan-api-arsip.md
 */
class ArchiveController extends Controller
{
    public function __construct(private ArchiveService $service) {}

    public function index(SearchArchiveRequest $request)
    {
        return $this->success($this->service->search($request->validated()), 'Data arsip berhasil diambil.');
    }

    public function show(int $idDoc)
    {
        return $this->success($this->service->detail($idDoc), 'Data arsip berhasil diambil.');
    }

    public function store(StoreArchiveRequest $request)
    {
        // Field opsional yang dikirim kosong memakai nilai default
        $data = array_filter(Arr::except($request->validated(), ['userfile', 'created_by', 'validate_access']), fn ($v) => $v !== null);

        $result = $this->service->create(
            $data,
            $request->uploadedFiles(),
            $request->validateAccess(),
            (int) ($request->validated('created_by') ?? config('arsip.default_user')),
        );

        return $this->success($result, 'Arsip berhasil dibuat.', 201);
    }

    public function update(UpdateArchiveRequest $request, int $idDoc)
    {
        $data = Arr::except($request->validated(), ['userfile', 'edited_by', 'validate_access', 'file_mode', 'delete_files']);

        $result = $this->service->update(
            $idDoc,
            $data,
            $request->uploadedFiles(),
            $request->validated('file_mode') ?? 'append',
            $request->validated('delete_files') ?? [],
            $request->validateAccess(),
            (int) ($request->validated('edited_by') ?? config('arsip.default_user')),
        );

        return $this->success($result, 'Arsip berhasil diperbarui.');
    }

    public function download(int $idDoc, string $filename)
    {
        return $this->service->download($idDoc, $filename);
    }
}
