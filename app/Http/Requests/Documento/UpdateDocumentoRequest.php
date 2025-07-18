<?php

namespace App\Http\Requests\Documento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Enums\Permission;

/**
 * @method bool merge(string $key)
 */
class UpdateDocumentoRequest extends FormRequest
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
            'name'            => ['sometimes', 'required', 'string'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'created_by'      => ['sometimes', 'required', 'exists:users,id'],
            'is_approved'     => ['sometimes', 'required', 'boolean'],
            'is_published'    => ['sometimes', 'required', 'boolean'],
            'category_id'     => ['sometimes', 'required', 'integer', Rule::exists('categorie_documento', 'id')],
            'condomini_ids'   => ['sometimes', 'required', 'array', 'min:1'],
            'condomini_ids.*' => ['integer', Rule::exists('condomini', 'id')],
            'anagrafiche'     => ['sometimes', 'nullable', 'array'],
            'anagrafiche.*'   => ['integer', Rule::exists('anagrafiche', 'id')],
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    public function prepareForValidation(): void
    {

        $user = Auth::user();

        $this->merge([
            'created_by' => $user->id,
            'is_approved' => $user->hasPermissionTo(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value),
        ]);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'name'          => __('validation.attributes.documenti.name'),
            'description'   => __('validation.attributes.documenti.description'),
            'is_published'  => __('validation.attributes.documenti.is_published'),
            'condomini_ids' => __('validation.attributes.documenti.condomini_ids'),
            'category_id'   => __('validation.attributes.documenti.category_id'),
        ];
    }
}
