<?php

namespace App\Http\Requests\Archive;

use Illuminate\Foundation\Http\FormRequest;

class UpdateArchiveRequest extends FormRequest
{
    use ArchiveRules;

    public function authorize(): bool
    {
        return true;
    }

    // delete_files boleh string tunggal; nilai kosong dibuang
    protected function prepareForValidation(): void
    {
        $this->normalizeUserfile();

        if ($this->has('delete_files')) {
            $names = array_map(fn ($v) => trim((string) $v), (array) $this->input('delete_files'));
            $this->merge(['delete_files' => array_values(array_filter($names, 'strlen'))]);
        }
    }

    // Semua field opsional; hanya field yang dikirim (dan tidak kosong) yang diubah
    public function rules(): array
    {
        return $this->metadataRules('sometimes') + $this->fileRules('sometimes') + [
            'edited_by' => $this->userRule(),
            'file_mode' => ['sometimes', 'nullable', 'in:append,replace'],
            'delete_files' => ['sometimes', 'array'],
            'delete_files.*' => ['string'],
        ];
    }
}
