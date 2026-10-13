<?php

namespace App\Services\Archive;

use App\Services\Concerns\UsesDmsConnection;

/**
 * Hak akses departemen ke kategori (tabel category_acc).
 * Nilai access bertingkat: 1 = view, 2 = download, 3 = upload/edit/create.
 * Akses yang diberikan di kategori induk berlaku untuk sub-kategorinya.
 */
class CategoryAccessService
{
    use UsesDmsConnection;

    public const VIEW = 1;

    public const DOWNLOAD = 2;

    public const EDIT = 3;

    public function has(int $idCategory, int $idDept, int $minAccess = self::EDIT): bool
    {
        $ids = $this->withAncestors($idCategory);

        return $this->db()->table('category_acc')
            ->where('id_dept', $idDept)
            ->whereIn('id_category', $ids)
            ->pluck('access')
            ->contains(fn ($access) => (int) $access >= $minAccess);
    }

    private function withAncestors(int $idCategory): array
    {
        $ids = [];
        $id = $idCategory;
        while ($id > 0 && ! in_array($id, $ids, true) && count($ids) < 10) {
            $ids[] = $id;
            $id = (int) $this->db()->table('categories')->where('id_category', $id)->value('id_parent');
        }

        return $ids;
    }
}
