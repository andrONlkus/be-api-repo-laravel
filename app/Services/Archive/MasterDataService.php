<?php

namespace App\Services\Archive;

use App\Services\Concerns\UsesDmsConnection;

/**
 * Data master DMS: kategori, departemen, tipe file, user.
 */
class MasterDataService
{
    use UsesDmsConnection;

    public function category(int $id): ?object
    {
        return $this->db()->table('categories')
            ->select('id_category', 'code_full', 'name_category', 'level', 'id_parent', 'doc_status', 'doc_life')
            ->where('id_category', $id)
            ->where('status_category', 'active')
            ->first();
    }

    public function categories(?int $level = null, ?int $parent = null, ?string $q = null): array
    {
        $query = $this->db()->table('categories')
            ->select('id_category', 'code_full', 'name_category', 'level', 'id_parent', 'doc_status', 'doc_life')
            ->where('status_category', 'active');

        if ($level !== null) {
            $query->where('level', $level);
        }
        if ($parent !== null) {
            $query->where('id_parent', $parent);
        }
        if ($q !== null && $q !== '') {
            $query->where(fn ($w) => $w->where('name_category', 'like', '%'.$q.'%')->orWhere('code_full', 'like', '%'.$q.'%'));
        }

        return $query->orderBy('code_full')->get()->map(fn ($r) => [
            'id_category' => (int) $r->id_category,
            'code_full' => $r->code_full,
            'name_category' => $r->name_category,
            'level' => (int) $r->level,
            'id_parent' => (int) $r->id_parent,
            'doc_status' => $r->doc_status,
            'doc_life' => (int) $r->doc_life,
            // Hanya level 2-4 yang bisa dipakai untuk upload arsip
            'can_upload' => (int) $r->level >= 2,
        ])->all();
    }

    public function departement(int $id): ?object
    {
        return $this->db()->table('departement')
            ->select('id_departement', 'nama_departement', 'upload_limit')
            ->where('id_departement', $id)
            ->where('status_departement', 'active')
            ->first();
    }

    public function departements(): array
    {
        return $this->db()->table('departement')
            ->select('id_departement', 'nama_departement', 'upload_limit')
            ->where('status_departement', 'active')
            ->orderBy('nama_departement')
            ->get()->map(fn ($r) => [
                'id_departement' => (int) $r->id_departement,
                'nama_departement' => $r->nama_departement,
                'upload_limit' => (int) $r->upload_limit, // MB
            ])->all();
    }

    public function tipefiles(): array
    {
        return $this->db()->table('tipefile')
            ->select('id_tipefile', 'tipefile')
            ->where('status_tipefile', 'active')
            ->orderBy('tipefile')
            ->get()->map(fn ($r) => ['id_tipefile' => (int) $r->id_tipefile, 'tipefile' => $r->tipefile])->all();
    }
}
