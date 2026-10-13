<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Services\Archive\MasterDataService;
use Illuminate\Http\Request;

/**
 * Data master untuk aplikasi pemanggil API arsip.
 */
class MasterController extends Controller
{
    public function __construct(private MasterDataService $service) {}

    public function categories(Request $request)
    {
        $filters = $request->validate([
            'level' => ['nullable', 'in:1,2,3,4'],
            'parent' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ], ['in' => 'Pilihan: :values.', 'integer' => 'Harus berupa ID angka.']);

        $data = $this->service->categories(
            isset($filters['level']) ? (int) $filters['level'] : null,
            isset($filters['parent']) ? (int) $filters['parent'] : null,
            $filters['q'] ?? null,
        );

        return $this->success($data, 'Data kategori berhasil diambil.');
    }

    public function departements()
    {
        return $this->success($this->service->departements(), 'Data departemen berhasil diambil.');
    }

    public function tipefiles()
    {
        return $this->success($this->service->tipefiles(), 'Data tipe file berhasil diambil.');
    }
}
