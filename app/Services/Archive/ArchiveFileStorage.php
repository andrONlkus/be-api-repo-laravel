<?php

namespace App\Services\Archive;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * File fisik arsip di disk "arsip" (production: /mnt/ossfs/arsip).
 * Nama file: DOC_{doc_date}{timestamp}{index}.{ext}, sama dengan aplikasi CodeIgniter.
 */
class ArchiveFileStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('arsip.disk'));
    }

    /**
     * Simpan semua file. Jika satu gagal, file yang sudah tersimpan dihapus kembali.
     *
     * @param  UploadedFile[]  $files
     * @return array<int, array{filename: string, realname: string}>
     */
    public function store(array $files, string $docDate): array
    {
        $stamp = (string) round(microtime(true));
        $safeDate = preg_replace('/[^A-Za-z0-9_-]/', '', $docDate);
        $saved = [];

        foreach (array_values($files) as $i => $file) {
            $ext = $file->getClientOriginalExtension();
            $name = $this->uniqueName('DOC_'.$safeDate.$stamp.$i, $ext);

            if ($this->disk()->putFileAs('', $file, $name) === false) {
                $this->delete(array_column($saved, 'filename'));
                throw new RuntimeException('Gagal menyimpan file '.$file->getClientOriginalName());
            }

            $saved[] = [
                'filename' => $name,
                'realname' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ];
        }

        return $saved;
    }

    // File yang sudah tidak ada diabaikan, tidak menggagalkan proses
    public function delete(array $filenames): void
    {
        foreach ($filenames as $name) {
            $name = basename($name);
            if ($this->disk()->exists($name) && ! $this->disk()->delete($name)) {
                Log::error('Arsip: gagal menghapus file '.$name);
            }
        }
    }

    public function exists(string $filename): bool
    {
        return $this->disk()->exists(basename($filename));
    }

    // Sama seperti upload CodeIgniter (overwrite = FALSE): tambahkan angka bila nama sudah ada
    private function uniqueName(string $base, string $ext): string
    {
        $suffix = $ext !== '' ? '.'.$ext : '';
        $name = $base.$suffix;
        for ($n = 1; $this->disk()->exists($name); $n++) {
            $name = $base.$n.$suffix;
        }

        return $name;
    }
}
