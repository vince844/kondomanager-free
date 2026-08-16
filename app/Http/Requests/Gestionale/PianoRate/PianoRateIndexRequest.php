<?php

namespace App\Http\Requests\Gestionale\PianoRate;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class PianoRateIndexRequest extends FormRequest
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
     * Le colonne ordinabili dell'elenco piani rate.
     *
     * ⚠️ **Fuori «Emissione» e «Importo totale».** La prima monta numero di rate e stato di
     * emissione in una cella; la seconda è un importo **aggregato dai capitoli**, che per essere
     * ordinato richiederebbe una sottoquery di somma — fattibile, ma è una decisione sul costo
     * della query, non un default.
     *
     * ⚠️ **Dentro «Gestione»**, che è una relazione: si ordina per il nome della gestione con una
     * sottoquery correlata, senza `join()` — così non ci sono collisioni fra `piani_rate.nome` e
     * `gestioni.nome`, che si chiamano uguale.
     */
    /** @return array<string, string|\Closure> */
    public static function colonneOrdinabili(): array
    {
        return [
        'nome'      => 'nome',
        'gestione'  => fn () => \App\Models\Gestione::select('nome')
            ->whereColumn('gestioni.id', 'piani_rate.gestione_id'),
        ];
    }

}
