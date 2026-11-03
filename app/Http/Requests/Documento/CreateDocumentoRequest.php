<?php

namespace App\Http\Requests\Documento;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Support\LimiteCaricamento;

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
            // ⚠️ **Almeno una, dalla 1.11.0-beta.10.** Un documento d'archivio senza categoria non
            // compare in nessuna vista per categoria e si trova solo cercandolo per nome: è il modo
            // in cui i documenti si perdono. L'obbligo vive **qui**, nei moduli dell'archivio, e non
            // a livello di schema: i documenti caricati su un soggetto — fornitore, unità,
            // anagrafica — non hanno categoria di proposito, e un vincolo sul database romperebbe
            // quei caricamenti.
            'categorie'       => ['required', 'array', 'min:1'],
            'categorie.*'     => ['integer', 'exists:categorie_documento,id'],
            'is_published'    => 'required|boolean',
            'is_approved'     => 'required|boolean',
            'created_by'      => 'required|exists:users,id',
            'file'            => 'required|file|mimes:pdf|max:'.LimiteCaricamento::regolaMax(),
            'anagrafiche'     => ['nullable', 'array'],
            'anagrafiche.*'   => ['integer', Rule::exists('anagrafiche', 'id')],
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
            'categorie'     => __('validation.attributes.documenti.categorie'),
        ];
    }
}
