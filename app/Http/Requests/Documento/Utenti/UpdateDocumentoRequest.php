<?php

namespace App\Http\Requests\Documento\Utenti;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

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
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }

    public function prepareForValidation(): void
    {

        $user = Auth::user();

        $this->merge([
            'created_by'   => $user->id,
            'is_approved'  => $user->hasPermissionTo(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value),
            'is_published' => $user->hasPermissionTo(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value),
        ]);
    }

    public function attributes()
    {
        return [
            'name'          => __('validation.attributes.documenti.name'),
            'description'   => __('validation.attributes.documenti.description'),
            'category_id'   => __('validation.attributes.documenti.category_id'),
        ];
    }
}
