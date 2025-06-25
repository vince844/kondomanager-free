<?php

namespace App\Http\Requests\Documento\Utenti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Enums\Permission;

/**
 * @method bool merge(string $key)
 */
class CreateDocumentoRequest extends FormRequest
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
            'name'            => 'required|string|max:255',
            'description'     => 'required|string',
            'category_id'     => 'required|string|exists:categorie_documento,id',
            'is_published'    => 'required|boolean',
            'is_approved'     => 'required|boolean',
            'is_private'      => 'sometimes|boolean',
            'created_by'      => 'required|exists:users,id',
            'file'            => 'required|file|mimes:pdf|max:20480',
            'condomini_ids'   => ['required', 'array'],
            'condomini_ids.*' => ['integer', Rule::exists('condomini', 'id')],
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
            'created_by'   => $user->id,
            'is_approved'  => $user->hasPermissionTo(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value),
            'is_published' => $user->hasPermissionTo(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value),
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
            'condomini_ids' => __('validation.attributes.documenti.condomini_ids'),
        ];
    }
}
