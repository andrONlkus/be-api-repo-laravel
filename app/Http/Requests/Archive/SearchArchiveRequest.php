<?php

namespace App\Http\Requests\Archive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // order tidak peka huruf besar; include_sub selain "1" dianggap 0
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('order'))) {
            $this->merge(['order' => strtolower($this->query('order'))]);
        }
        if ($this->has('include_sub')) {
            $this->merge(['include_sub' => $this->input('include_sub') === '1' ? '1' : '0']);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'id_category' => ['nullable', 'integer', Rule::exists('dms.categories', 'id_category')->where('status_category', 'active')],
            'include_sub' => ['nullable', 'in:0,1'],
            'departement_owner' => ['nullable', 'integer', 'min:1'],
            'tipefile' => ['nullable', 'integer', 'min:1'],
            'year' => ['nullable', 'digits:4'],
            'doc_date' => ['nullable', 'date_format:Y-m'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d'],
            'public' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:created_date,kode_arsip,doc_date,id_doc'],
            'order' => ['nullable', 'in:asc,desc'],
        ];
    }

    public function messages(): array
    {
        return [
            'integer' => 'Harus berupa angka.',
            'id_category.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'digits' => 'Format YYYY.',
            'date_format' => 'Format :format.',
            'in' => 'Pilihan: :values.',
            'limit.max' => 'Maksimal 100.',
            'min' => 'Minimal :min.',
        ];
    }
}
