<?php

namespace App\Services\Archive;

use App\Services\Concerns\UsesDmsConnection;
use Closure;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Nomor kode arsip: YY/MM/code_full/N.
 */
class KodeArsipService
{
    use UsesDmsConnection;

    /**
     * Jalankan $callback dengan kunci MySQL per code_full, supaya dua request bersamaan
     * tidak mendapat nomor yang sama. Nama kunci sama dengan aplikasi CodeIgniter
     * (Archive.php) sehingga aman bila keduanya berjalan berdampingan.
     */
    public function withLock(string $codeFull, Closure $callback): mixed
    {
        $db = $this->db();
        if ($db->getDriverName() !== 'mysql') {
            return $callback();
        }

        $name = 'arsip_kode_'.$codeFull;
        $row = $db->selectOne('SELECT GET_LOCK(?, 10) AS ok', [$name]);
        if (! $row || (int) $row->ok !== 1) {
            throw new ServiceUnavailableHttpException(null, 'Server sibuk, silakan coba lagi.');
        }

        try {
            return $callback();
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /**
     * Nomor berikutnya = nomor terbesar bulan ini + 1. Prefix diakhiri '/' supaya
     * kategori induk tidak tertukar dengan sub-kategorinya (PDK.001 vs PDK.001.002).
     */
    public function next(string $codeFull): string
    {
        $prefix = now()->format('y/m').'/'.$codeFull.'/';

        $max = $this->db()->table('document')
            ->where('kode_arsip', 'like', $prefix.'%')
            ->pluck('kode_arsip')
            ->filter(fn ($kode) => str_starts_with($kode, $prefix))
            ->map(fn ($kode) => (int) substr($kode, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.($max + 1);
    }
}
