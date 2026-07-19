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
        $isPregresso = filter_var($this->input('is_pregresso', false), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'fornitore_id'    => 'required|exists:fornitori,id',

            // Scopato per condominio E per stato: senza il primo vincolo si poteva
            // registrare una fattura nell'esercizio di un altro condominio, senza il
            // secondo direttamente in un esercizio chiuso — scavalcando il muro
            // contabile che destroy() e motivoBloccoModifica() presidiano a valle.
            'esercizio_id'    => [
                'required',
                Rule::exists('esercizi', 'id')
                    ->where('condominio_id', $this->route('condominio')->id)
                    ->where('stato', 'aperto'),
            ],
            'is_pregresso'    => 'nullable',
            
            // ── CAMPI COMUNI ──
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where('condominio_id', $this->route('condominio')->id),
            ],
            
            'tipo_documento'     => 'required|in:fattura,nota_credito',
            'numero_documento'   => 'required|string|max:50',
            'data_documento'     => 'required|date',
            'data_scadenza'      => 'required|date',
            'conto_corrente_id'  => 'nullable|exists:conti_contabili,id',
            'modalita_pagamento' => 'required|string',
            'stato_approvazione' => 'required|in:da_approvare,approvata,contestata,sforo_motivato',
            
            'iban_fornitore'     => 'nullable|string',
            'dati_extra'         => 'nullable|array',
            'file'               => 'nullable|file|mimes:pdf,xml,p7m,jpg,png|max:10240',

            // ── REGOLE SCUDO LEGALE E BUDGET (INTATTE E PROTETTE) ──
            'dati_extra.override_budget'                       => 'nullable|array',
            'dati_extra.override_budget.motivazione'           => 'required_with:dati_extra.override_budget|string|min:10',
            'dati_extra.override_budget.importo_sforo'         => 'required_with:dati_extra.override_budget|integer',
            'dati_extra.override_budget.strategia_rientro'     => 'required_with:dati_extra.override_budget|in:conguaglio_fine_anno,rata_integrativa,fondo_riserva',
            'dati_extra.override_budget.fondo_patrimoniale_id' => 'nullable|integer|exists:conti_contabili,id',

            'dati_extra.log_legale_sopravvenienza'                           => 'nullable|array',
            'dati_extra.log_legale_sopravvenienza.nome_voce'                 => 'required_with:dati_extra.log_legale_sopravvenienza|string|min:5',
            'dati_extra.log_legale_sopravvenienza.origine_decisionale'       => 'required_with:dati_extra.log_legale_sopravvenienza|in:gestione_corrente,delibera_assembleare',
            'dati_extra.log_legale_sopravvenienza.data_assemblea'            => 'nullable|date',
            'dati_extra.log_legale_sopravvenienza.tipo_ripartizione'         => 'required_with:dati_extra.log_legale_sopravvenienza|in:millesimale,ad_personam',
            'dati_extra.log_legale_sopravvenienza.is_ordinario'              => 'required_with:dati_extra.log_legale_sopravvenienza|boolean',
            'dati_extra.log_legale_sopravvenienza.richiede_copertura'        => 'required_with:dati_extra.log_legale_sopravvenienza|boolean',
            'dati_extra.log_legale_sopravvenienza.motivazione_sforo'         => 'nullable|string',
            'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id'    => 'nullable|integer|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_proprietario'  => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_inquilino'     => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_usufruttuario' => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
        ];

        // ── LOGICA BIVIO CONDIZIONALE ──
        if ($isPregresso) {
            // Se è PREGRESSO: Controlliamo le coperture e l'eccedenza
            $rules['imponibile_pregresso']       = 'required|numeric|min:0';
            $rules['aliquota_iva_pregressa']     = 'nullable|numeric|min:0|max:100';
            $rules['data_competenza_originaria'] = 'nullable|date';
            $rules['saldo_patrimoniale_id']      = 'nullable|integer|exists:saldi,id';
            
            $rules['coperture']                       = 'nullable|array';
            $rules['coperture.*.tipo_copertura']      = 'required_with:coperture|in:rata_0,sopravvenienza,fondo_riserva,saldo_patrimoniale';
            $rules['coperture.*.importo']             = 'required_with:coperture|numeric|min:0';
            $rules['coperture.*.fonte_id']            = 'nullable|integer';
            $rules['coperture.*.nota_amministratore'] = 'nullable|string|max:500';

        } else {
            // Se è CORRENTE: Controlliamo le righe e il preventivo
            $rules['righe']                      = 'required|array|min:1';
            $rules['righe.*.descrizione']        = 'required|string';
            $rules['righe.*.importo_imponibile'] = 'required|numeric';
            $rules['righe.*.aliquota_iva']       = 'required|numeric|min:0|max:100';
            // Scopati per condominio: FatturaPassivaService risolve il capitolo con
            // Conto::find() e ne usa il conto_contabile_id per la riga DARE. Un id di
            // un altro condominio avrebbe agganciato la scrittura al mastro altrui,
            // inquinandone i saldi senza che nulla lo segnalasse.
            $rules['righe.*.conto_id'] = [
                'nullable',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')
                        ->where('condominio_id', $this->route('condominio')->id)
                ),
            ];
            $rules['righe.*.immobile_id'] = [
                'nullable',
                Rule::exists('immobili', 'id')->where('condominio_id', $this->route('condominio')->id),
            ];
            $rules['righe.*.is_sopravvenienza']  = 'nullable|boolean';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'dati_extra.override_budget.motivazione.min' => 'La motivazione dello sforamento deve essere di almeno 10 caratteri.',
            'dati_extra.override_budget.motivazione.required_with' => 'La motivazione è obbligatoria quando si supera il budget.',
            'dati_extra.override_budget.strategia_rientro.required_with' => 'Devi selezionare una strategia di rientro per lo sforo.',
            'dati_extra.override_budget.fondo_patrimoniale_id.exists' => 'Il fondo di riserva selezionato non è valido.',
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            
            $isPregresso = filter_var($this->input('is_pregresso', false), FILTER_VALIDATE_BOOLEAN);

            // FIX: Se è un debito pregresso, ignoriamo del tutto l'analisi delle righe
            if (!$isPregresso) {
                $righe = $this->input('righe', []);
                $haLavoriPrivati = false;

                foreach ($righe as $idx => $riga) {
                    if (!empty($riga['immobile_id'])) {
                        $haLavoriPrivati = true;
                    }

                    $isSopravvenienza = filter_var($riga['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    if (!$isSopravvenienza && empty($riga['conto_id'])) {
                        $validator->errors()->add(
                            "righe.{$idx}.conto_id",
                            'Il capitolo di spesa è obbligatorio per righe non contrassegnate come impreviste.'
                        );
                    }
                }

                // Controllo Scudo Patrimoniale
                $strategia = $this->input('dati_extra.override_budget.strategia_rientro');
                $usaFondo = ($strategia === 'fondo_riserva');

                if ($usaFondo && $haLavoriPrivati) {
                    $validator->errors()->add(
                        'dati_extra.override_budget.strategia_rientro',
                        'SCUDO PATRIMONIALE: Non puoi utilizzare il Fondo Riserva condominiale per coprire spese private.'
                    );
                }
            }
        });
    }
}