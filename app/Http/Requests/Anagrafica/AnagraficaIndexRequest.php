<?php

namespace App\Http\Requests\Anagrafica;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class AnagraficaIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * ⚠️ Fuori «Contatti» e «Condomini», che contengono elenchi: senza scegliere quale voce faccia
     * da chiave, l'ordinamento sarebbe per **quante** ce ne sono.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'nome'      => 'nome',
            'indirizzo' => 'indirizzo',
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
            'nome'             => ['sometimes', 'string', 'max:255'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
