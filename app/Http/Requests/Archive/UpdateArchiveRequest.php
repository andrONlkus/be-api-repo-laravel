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

    // Semua field opsional; hanya field yang dikirim yang diubah
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
