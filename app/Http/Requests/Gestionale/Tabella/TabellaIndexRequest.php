<?php

namespace App\Http\Requests\Gestionale\Tabella;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class TabellaIndexRequest extends FormRequest
{
    use OrdinaElenco;
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
            'nome'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }

    /**
     * Le colonne ordinabili dell'elenco tabelle millesimali.
     *
     * ⚠️ **Fuori «Palazzina / Scala»**, che monta due relazioni in una cella: ordinarla vuol dire
     * scegliere se conta la palazzina o la scala, e la scelta va fatta, non ereditata.
     *
     * ⚠️ **Dentro «Unità Associate»**, che è un conteggio e si ordina bene: `withCount('quote')`
     * espone `quote_count`, e ordinare per quello risponde alla domanda vera — quali tabelle
     * hanno più unità collegate, e quali nessuna.
     */
    /** @return array<string, string|\Closure> */
    public static function colonneOrdinabili(): array
    {
        return [
        'nome'           => 'nome',
        'tipo'           => 'tipo',
        'stato'          => 'attiva',
        'immobili_count' => 'quote_count',
        ];
    }
}
