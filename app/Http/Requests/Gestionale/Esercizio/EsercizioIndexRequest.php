<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class EsercizioIndexRequest extends FormRequest
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
     * Le colonne ordinabili dell'elenco esercizi: **tutte**, perché tutte corrispondono a un
     * campo vero. È il caso semplice, e vale la pena notarlo — la maggior parte delle colonne
     * del gestionale lo è.
     */
    /** @return array<string, string|\Closure> */
    public static function colonneOrdinabili(): array
    {
        return [
        'nome'        => 'nome',
        'descrizione' => 'descrizione',
        'data_inizio' => 'data_inizio',
        'data_fine'   => 'data_fine',
        'stato'       => 'stato',
        ];
    }

}
