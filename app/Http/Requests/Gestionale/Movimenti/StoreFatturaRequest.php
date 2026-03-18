<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFatturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fornitore_id'    => 'required|exists:fornitori,id',
            'esercizio_id'    => 'required|exists:esercizi,id',
            'is_pregresso'    => 'nullable', // Accettiamo qualsiasi formato, lo castiamo nel service,

            // ── BIVIO 1: CAMPI ESCLUSIVI PER DEBITO PREGRESSO ──
            'imponibile_pregresso'       => 'nullable|numeric|required_if:is_pregresso,1,true|min:0',
            'aliquota_iva_pregressa'     => 'nullable|numeric|required_if:is_pregresso,1,true|min:0|max:100',
            'data_competenza_originaria' => 'nullable|date|required_if:is_pregresso,1,true',
            
            // VALIDAZIONE COPERTURE (Il Double Lock)
            'coperture'                       => 'nullable|array|required_if:is_pregresso,1,true',
            'coperture.*.tipo_copertura'      => 'required_with:coperture|in:rata_0,sopravvenienza,fondo_riserva',
            'coperture.*.importo'             => 'required_with:coperture|numeric|min:0.01',
            'coperture.*.fonte_id'            => 'nullable|integer',
            'coperture.*.nota_amministratore' => 'nullable|string|max:500',

            // ── BIVIO 2: CAMPI ESCLUSIVI PER FATTURA CORRENTE ──
            // Se è un debito pregresso, escludiamo completamente le righe dalla validazione
            'righe'                      => 'exclude_if:is_pregresso,1,true,"1","true"|required|array',
            'righe.*.descrizione'        => 'exclude_if:is_pregresso,1,true,"1","true"|required|string',
            'righe.*.importo_imponibile' => 'exclude_if:is_pregresso,1,true,"1","true"|required|numeric',
            'righe.*.aliquota_iva'       => 'exclude_if:is_pregresso,1,true,"1","true"|required|numeric',
            'righe.*.conto_id'           => 'exclude_if:is_pregresso,1,true,"1","true"|required|exists:conti,id',
            'righe.*.immobile_id'        => 'exclude_if:is_pregresso,1,true,"1","true"|nullable|exists:immobili,id',

            // ── CAMPI COMUNI ──
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

            // Validazione override_budget
            'dati_extra.override_budget'                    => 'nullable|array',
            'dati_extra.override_budget.motivazione'        => 'required_with:dati_extra.override_budget|string|min:10',
            'dati_extra.override_budget.importo_sforo'      => 'required_with:dati_extra.override_budget|integer',

            // Validazione Scudo Legale (Sopravvenienza)
            'dati_extra.log_legale_sopravvenienza'                           => 'nullable|array',
            'dati_extra.log_legale_sopravvenienza.nome_voce'                 => 'required_with:dati_extra.log_legale_sopravvenienza|string|min:5',
            'dati_extra.log_legale_sopravvenienza.autorizzazione'            => 'required_with:dati_extra.log_legale_sopravvenienza|in:urgenza,assemblea',
            'dati_extra.log_legale_sopravvenienza.data_assemblea'            => 'required_if:dati_extra.log_legale_sopravvenienza.autorizzazione,assemblea|nullable|date',
            'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id'    => 'required_with:dati_extra.log_legale_sopravvenienza|integer',
            'dati_extra.log_legale_sopravvenienza.percentuale_proprietario'  => 'required_with:dati_extra.log_legale_sopravvenienza|numeric',
            'dati_extra.log_legale_sopravvenienza.percentuale_inquilino'     => 'required_with:dati_extra.log_legale_sopravvenienza|numeric',
            'dati_extra.log_legale_sopravvenienza.percentuale_usufruttuario' => 'required_with:dati_extra.log_legale_sopravvenienza|numeric',
            'dati_extra.log_legale_sopravvenienza.note'                      => 'nullable|string',

            'file' => 'nullable|file|mimes:pdf,xml,p7m,jpg,png|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'dati_extra.override_budget.motivazione.min' => 'La motivazione dello sforamento deve essere di almeno 10 caratteri.',
            'dati_extra.override_budget.motivazione.required_with' => 'La motivazione è obbligatoria quando si supera il budget.',
            'data_competenza_originaria.required_if' => 'La data di origine è obbligatoria per i debiti pregressi (verifica prescrizione).',
            'coperture.required_if' => 'Devi specificare come coprire questo debito pregresso.',
            'imponibile_pregresso.required_if' => 'L\'importo della fattura pregressa è obbligatorio.',
            'righe.required_unless' => 'Devi inserire almeno una voce di spesa.',
            'numero_documento.required' => 'Il numero documento è obbligatorio.',
            'righe.*.descrizione.required_with' => 'La causale della riga è obbligatoria.',
            'righe.*.conto_id.required_with' => 'Il capitolo di spesa è obbligatorio.',
            'righe.*.importo_imponibile.required_with' => 'L\'importo è obbligatorio.',
        ];
    }

    /**
     * Catturiamo gli errori di validazione e li stampiamo nel log prima di bloccare l'utente.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Illuminate\Support\Facades\Log::error('❌ VALIDAZIONE FALLITA IN STORE_FATTURA_REQUEST:');
        \Illuminate\Support\Facades\Log::error($validator->errors()->toArray());
        
        parent::failedValidation($validator);
    }
}