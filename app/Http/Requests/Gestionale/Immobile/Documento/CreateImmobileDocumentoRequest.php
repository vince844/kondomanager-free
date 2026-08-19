<?php

namespace App\Http\Requests\Gestionale\Immobile\Documento;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 */
class CreateImmobileDocumentoRequest extends FormRequest
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
            // L'etichetta a video dice «Descrizione (Opzionale)» e la richiesta di modifica la
            // dichiara già `nullable`: era la creazione a pretenderla, e le tre cose non si
            // erano mai guardate in faccia. Segnalata sul forum il 18/08/2026.
            'description'     => 'nullable|string',
            'created_by'      => 'required|exists:users,id',
            'is_published'    => 'required|boolean',
            'is_approved'     => 'required|boolean',
            // Il tetto non è più un numero fisso: `max:20480` prometteva 20 MB su qualunque
            // installazione, anche dove il server ne accetta 2. Ora è il minimo fra i limiti di
            // PHP e il nostro, letto a ogni richiesta.
            'file'            => 'required|file|mimes:pdf|max:'.\App\Support\LimiteCaricamento::regolaMax(),
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
        ];
    }
}
