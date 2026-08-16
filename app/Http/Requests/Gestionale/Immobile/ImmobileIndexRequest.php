<?php

namespace App\Http\Requests\Gestionale\Immobile;

use Illuminate\Foundation\Http\FormRequest;

class ImmobileIndexRequest extends FormRequest
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
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'nome'       => ['sometimes', 'string', 'max:255'],
            // Il filtro sulle pertinenze. `da_collegare` è quello che serve davvero: apri
            // l'elenco, filtri, e vedi solo ciò che manca — la bonifica quando si ha voglia,
            // invece di un allarme che insegue riga per riga.
            'pertinenze' => ['sometimes', 'string', 'in:principali,collegate,da_collegare'],
            'search'     => ['nullable', 'string'],
        ];
    }
}
