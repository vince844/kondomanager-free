<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida i dati per la modifica di una fattura passiva aperta.
 *
 * Differenze rispetto a StoreFatturaRequest:
 *  - fornitore_id e tipo_documento sono immutabili: non accettati né validati
 *  - is_pregresso non accettato (fatture pregresse non modificabili)
 *  - stato_approvazione non accettato (non modificabile con update)
 *  - dati_extra.override_budget non accettato (sforo_motivato blocca a monte)
 *  - Le coperture sopravvenienza non sono accettate (bloccate a monte)
 *  - Obbligatoria la gestione_id per ricostruire la scrittura contabile
 */
class UpdateFatturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where('condominio_id', $this->route('condominio')->id),
            ],

            'numero_documento'   => 'required|string|max:50',
            'data_documento'     => 'required|date',
            'data_scadenza'      => 'required|date',
            'modalita_pagamento' => 'required|string',
            'iban_fornitore'     => 'nullable|string',
            'conto_corrente_id'  => 'nullable|exists:conti_contabili,id',
            'applica_ritenuta'   => 'nullable|boolean',

            'righe'                      => 'required|array|min:1',
            'righe.*.descrizione'        => 'required|string',
            'righe.*.importo_imponibile' => 'required|numeric',
            'righe.*.aliquota_iva'       => 'required|numeric|min:0|max:100',
            'righe.*.conto_id'           => 'nullable|exists:conti,id',
            'righe.*.immobile_id'        => 'nullable|exists:immobili,id',

            'file' => 'nullable|file|mimes:pdf,xml,p7m,jpg,png|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'righe.required'                          => 'Devi inserire almeno una voce di spesa.',
            'righe.*.descrizione.required'            => 'La causale della riga è obbligatoria.',
            'righe.*.importo_imponibile.required'     => "L'importo è obbligatorio.",
            'righe.*.importo_imponibile.numeric'      => "L'importo deve essere un numero.",
            'gestione_id.required'                    => 'La gestione contabile è obbligatoria.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $righe = $this->input('righe', []);

            foreach ($righe as $idx => $riga) {
                $isSopravvenienza = filter_var($riga['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($isSopravvenienza) {
                    $validator->errors()->add(
                        "righe.{$idx}.is_sopravvenienza",
                        'Le righe di sopravvenienza non sono modificabili: usa lo storno.'
                    );
                }

                if (empty($riga['immobile_id']) && empty($riga['conto_id'])) {
                    $validator->errors()->add(
                        "righe.{$idx}.conto_id",
                        'Il capitolo di spesa è obbligatorio per righe non assegnate a un immobile.'
                    );
                }
            }
        });
    }
}
