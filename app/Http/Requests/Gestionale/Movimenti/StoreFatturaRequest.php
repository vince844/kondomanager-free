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

            'saldo_patrimoniale_id'      => 'nullable|integer|exists:saldi,id',
            
            // VALIDAZIONE COPERTURE (Il Double Lock)
            'coperture'                       => 'nullable|array',
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
            'righe.*.conto_id'           => 'exclude_if:is_pregresso,1,true|nullable|exists:conti,id',
            'righe.*.is_sopravvenienza'  => 'exclude_if:is_pregresso,1,true|nullable|boolean',
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

            // Validazione override_budget (INTATTA)
            'dati_extra.override_budget'                    => 'nullable|array',
            'dati_extra.override_budget.motivazione'        => 'required_with:dati_extra.override_budget|string|min:10',
            'dati_extra.override_budget.importo_sforo'      => 'required_with:dati_extra.override_budget|integer',
            'dati_extra.override_budget.strategia_rientro'     => 'required_with:dati_extra.override_budget|in:conguaglio_fine_anno,rata_integrativa,fondo_riserva',
            'dati_extra.override_budget.fondo_patrimoniale_id' => 'nullable|integer|exists:conti_contabili,id',

            // --- INIZIO FIX: Validazione Scudo Legale (Nuova Modale Spesa Imprevista) ---
            'dati_extra.log_legale_sopravvenienza'                           => 'nullable|array',
            'dati_extra.log_legale_sopravvenienza.nome_voce'                 => 'required_with:dati_extra.log_legale_sopravvenienza|string|min:5',
            'dati_extra.log_legale_sopravvenienza.origine_decisionale'       => 'required_with:dati_extra.log_legale_sopravvenienza|in:gestione_corrente,delibera_assembleare',
            'dati_extra.log_legale_sopravvenienza.data_assemblea'            => 'nullable|date',
            
            // Nuovi Flag di Routing
            'dati_extra.log_legale_sopravvenienza.tipo_ripartizione'         => 'required_with:dati_extra.log_legale_sopravvenienza|in:millesimale,ad_personam',
            'dati_extra.log_legale_sopravvenienza.is_ordinario'              => 'required_with:dati_extra.log_legale_sopravvenienza|boolean',
            'dati_extra.log_legale_sopravvenienza.richiede_copertura'        => 'required_with:dati_extra.log_legale_sopravvenienza|boolean',
            'dati_extra.log_legale_sopravvenienza.motivazione_sforo'         => 'nullable|string',

            // Ripartizione Millesimale (Obbligatoria SOLO se tipo_ripartizione = 'millesimale')
            'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id'    => 'nullable|integer|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_proprietario'  => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_inquilino'     => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            'dati_extra.log_legale_sopravvenienza.percentuale_usufruttuario' => 'nullable|numeric|required_if:dati_extra.log_legale_sopravvenienza.tipo_ripartizione,millesimale',
            // --- FINE FIX ---

            'file' => 'nullable|file|mimes:pdf,xml,p7m,jpg,png|max:10240',
        ];
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
            $righe = $this->input('righe', []);
            $haLavoriPrivati = false;

            foreach ($righe as $idx => $riga) {
                // Controllo 1: Se c'è un immobile, attiviamo il flag "lavori privati"
                if (!empty($riga['immobile_id'])) {
                    $haLavoriPrivati = true;
                }

                // Controllo 2 (Pre-esistente): Obbligo capitolo di spesa
                $isSopravvenienza = filter_var($riga['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (!$isSopravvenienza && empty($riga['conto_id'])) {
                    $validator->errors()->add(
                        "righe.{$idx}.conto_id",
                        'Il capitolo di spesa è obbligatorio per righe non contrassegnate come impreviste.'
                    );
                }
            }

            // --- INIZIO FIX SCUDO PATRIMONIALE ---
            // Nota: Questo controllo funziona magicamente sia per gli sfori budget che per 
            // le nuove spese impreviste, dato che entrambe usano il namespace `override_budget` 
            // per segnalare la copertura!
            $strategia = $this->input('dati_extra.override_budget.strategia_rientro');
            $usaFondo = ($strategia === 'fondo_riserva');

            if ($usaFondo && $haLavoriPrivati) {
                $validator->errors()->add(
                    'dati_extra.override_budget.strategia_rientro',
                    'SCUDO PATRIMONIALE: Non puoi utilizzare il Fondo Riserva condominiale per coprire spese private.'
                );
            }
            // --- FINE FIX SCUDO PATRIMONIALE ---
        });
    }
}