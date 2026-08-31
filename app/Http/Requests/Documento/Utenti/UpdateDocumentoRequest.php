<?php

namespace App\Http\Requests\Documento\Utenti;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Support\LimiteCaricamento;

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
            // ⚠️ **Almeno una, dalla 1.11.0-beta.10.** Un documento d'archivio senza categoria non
            // compare in nessuna vista per categoria e si trova solo cercandolo per nome: è il modo
            // in cui i documenti si perdono. L'obbligo vive **qui**, nei moduli dell'archivio, e non
            // a livello di schema: i documenti caricati su un soggetto — fornitore, unità,
            // anagrafica — non hanno categoria di proposito, e un vincolo sul database romperebbe
            // quei caricamenti.
            'categorie'       => ['sometimes', 'required', 'array', 'min:1'],
            'categorie.*'     => ['integer', 'exists:categorie_documento,id'],
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
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
            'categorie'     => __('validation.attributes.documenti.categorie'),
        ];
    }
}
