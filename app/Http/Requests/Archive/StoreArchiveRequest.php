<?php

namespace App\Http\Requests\Archive;

use Illuminate\Foundation\Http\FormRequest;

class StoreArchiveRequest extends FormRequest
{
    use ArchiveRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUserfile();
    }

    public function rules(): array
    {
        return $this->metadataRules('required') + $this->fileRules('required') + [
            'created_by' => $this->userRule(),
        ];
    }
}
