<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFatturaRequest extends FormRequest
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
            'fornitore_id'    => 'required|exists:fornitori,id',
            'esercizio_id'    => 'required|exists:esercizi,id',
            'is_pregresso'    => 'nullable|boolean',

            // gestione_id deve appartenere al condominio della route.
            // Impedisce che un utente passi l'ID di una gestione di un altro condominio.
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where(
                    'condominio_id',
                    $this->route('condominio')->id
                ),
            ],

            'tipo_documento'     => 'required|in:fattura,nota_credito',
            'numero_documento'   => 'required|string|max:50',
            'data_documento'     => 'required|date',
            'data_scadenza'      => 'required|date',

            'conto_corrente_id'  => 'nullable|exists:conti_contabili,id',
            'modalita_pagamento' => 'required|string',
            'stato_approvazione' => 'required|in:da_approvare,approvata,contestata,sforo_motivato',
            'dati_extra'         => 'nullable|array',

            // Validazione override_budget: se presente deve essere completo.
            // Il Service imposterà automaticamente stato_approvazione = 'sforo_motivato'.
            'dati_extra.override_budget'                    => 'nullable|array',
            'dati_extra.override_budget.motivazione'        => 'required_with:dati_extra.override_budget|string|min:10',
            'dati_extra.override_budget.importo_sforo'      => 'required_with:dati_extra.override_budget|integer',

            'righe'                      => 'required|array|min:1',
            'righe.*.descrizione'        => 'required|string',
            'righe.*.importo_imponibile' => 'required|numeric',
            'righe.*.aliquota_iva'       => 'required|numeric',
            'righe.*.conto_id'           => 'required|exists:conti,id',
            'righe.*.immobile_id'        => 'nullable|exists:immobili,id',

            'file' => 'nullable|file|mimes:pdf,xml,p7m,jpg,png|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'dati_extra.override_budget.motivazione.min' =>'La motivazione dello sforamento deve essere di almeno 10 caratteri.',
            'dati_extra.override_budget.motivazione.required_with' =>'La motivazione è obbligatoria quando si supera il budget.',
            'numero_documento.required' => 'Il numero documento è obbligatorio.',
            'righe.*.descrizione.required' => 'La causale della riga è obbligatoria.',
            'righe.*.conto_id.required' => 'Il capitolo di spesa è obbligatorio.',
            'righe.*.importo_imponibile.required' => 'L\'importo è obbligatorio.',
        ];
    }
}
