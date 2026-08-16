<?php

namespace App\Http\Requests\Gestionale\Immobile\Documento;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class ImmobileDocumentoIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * Le colonne ordinabili dell'elenco documenti dell'unità.
     *
     * ⚠️ **Fuori «Autore»**: la cella legge `created_by.user.name`, cioè un utente raggiunto
     * attraverso l'anagrafica che ha caricato il file. Sono due salti, e quale dei due faccia da
     * chiave è una decisione — non la si eredita da un default.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'name'         => 'name',
            'is_published' => 'is_published',
            'created_at'   => 'created_at',
        ];
    }

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
        return array_merge([
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
