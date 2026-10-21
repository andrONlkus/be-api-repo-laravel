<?php

namespace App\Http\Requests\Archive;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Aturan validasi bersama untuk create & update arsip.
 */
trait ArchiveRules
{
    protected function metadataRules(string $presence): array
    {
        // Update: field yang dikirim kosong boleh null lalu diabaikan (tidak mengubah kolom)
        $p = $presence === 'required' ? ['required'] : [$presence, 'nullable'];

        return [
            'id_category' => [...$p, 'integer', Rule::exists('dms.categories', 'id_category')
                ->where('status_category', 'active')->whereIn('level', [2, 3, 4])],
            'departement_owner' => [...$p, 'integer', Rule::exists('dms.departement', 'id_departement')->where('status_departement', 'active')],
            'tipefile' => [...$p, 'integer', Rule::exists('dms.tipefile', 'id_tipefile')->where('status_tipefile', 'active')],
            'doc_date' => [...$p, 'date_format:Y-m'],
            'keyword' => [...$p, 'string'],
            'summary' => [...$p, 'string'],
            'active_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'public' => ['sometimes', 'nullable', 'in:0,1'],
            'expired_public' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'link' => ['sometimes', 'nullable', 'string', 'max:155'],
            'validate_access' => ['sometimes', 'nullable', 'in:0,1'],
        ];
    }

    protected function fileRules(string $presence): array
    {
        $types = implode(',', config('arsip.allowed_types'));

        return [
            'userfile' => [$presence, 'array', 'min:1'],
            'userfile.*' => ['file', 'extensions:'.$types, 'mimes:'.$types],
        ];
    }

    protected function userRule(): array
    {
        return ['sometimes', 'nullable', 'integer', Rule::exists('dms.users', 'idusers')->where('active', 'Y')];
    }

    public function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'integer' => 'Harus berupa ID angka.',
            'id_category.exists' => 'Kategori tidak ditemukan, tidak aktif, atau level 1 (pilih level 2-4).',
            'departement_owner.exists' => 'Departemen tidak ditemukan atau tidak aktif.',
            'tipefile.exists' => 'Tipe file tidak ditemukan atau tidak aktif.',
            'created_by.exists' => 'User tidak ditemukan atau tidak aktif.',
            'edited_by.exists' => 'User tidak ditemukan atau tidak aktif.',
            'date_format' => 'Format :format.',
            'in' => 'Pilihan: :values.',
            'link.max' => 'Maksimal 155 karakter.',
            'userfile.required' => 'Minimal 1 file wajib diupload (field userfile[]).',
            'userfile.array' => 'Kirim file lewat field userfile[].',
            'userfile.*.extensions' => 'Ekstensi file tidak diizinkan (boleh: :values).',
            'userfile.*.mimes' => 'Isi file tidak sesuai ekstensinya (boleh: :values).',
            'userfile.*.file' => 'Upload file gagal.',
        ];
    }

    // Field userfile tunggal (tanpa []) diperlakukan sama dengan userfile[]
    protected function normalizeUserfile(): void
    {
        $file = $this->files->get('userfile');
        if ($file instanceof SymfonyUploadedFile) {
            $this->files->set('userfile', [$file]);
            $this->convertedFiles = null;
        }
    }

    public function validateAccess(): bool
    {
        return $this->input('validate_access') === '1';
    }

    /** @return UploadedFile[] */
    public function uploadedFiles(): array
    {
        return array_values($this->file('userfile', []));
    }
}
