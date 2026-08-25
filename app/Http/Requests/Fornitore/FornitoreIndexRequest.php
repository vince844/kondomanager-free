<?php

namespace App\Http\Requests\Fornitore;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class FornitoreIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /** ⚠️ Fuori «Referenti», che è un elenco di persone. */
    public static function colonneOrdinabili(): array
    {
        return [
            'ragione_sociale' => 'ragione_sociale',
            'indirizzo'       => 'indirizzo',
            'codice_fiscale'  => 'codice_fiscale',
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
            'page'             => ['sometimes', 'integer', 'min:1'],
            'per_page'         => ['sometimes', 'integer'],
            'ragione_sociale'  => ['sometimes', 'string', 'max:255'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
