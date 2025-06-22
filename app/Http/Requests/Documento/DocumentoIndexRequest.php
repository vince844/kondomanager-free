<?php

namespace App\Http\Requests\Documento;

use Illuminate\Foundation\Http\FormRequest;

class DocumentoIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page'          => ['sometimes', 'integer', 'min:1'],
            'per_page'      => ['sometimes', 'integer'],
            'name'          => ['sometimes', 'string', 'max:255'],
            'category_id'   => ['sometimes', 'array'],
            'category_id.*' => ['integer', 'exists:categorie_documento,id'],
            'search'        => ['nullable', 'string'],

        ];
    }
}
