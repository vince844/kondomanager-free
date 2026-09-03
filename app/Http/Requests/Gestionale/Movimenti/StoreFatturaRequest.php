<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use App\Models\Fornitore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\LimiteCaricamento;

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
            // ⚠️ `extensions:`, non `mimes:` — stessa correzione di StoreFatturaDocumentoRequest,
            // dalla revisione avversariale della beta.12 (Coda 102). `mimes:` guarda il
            // contenuto: una busta .p7m è ASN.1 generico, `finfo` la vede
            // `application/octet-stream` e la rifiuta SEMPRE, qualunque file .p7m reale.
            // Correggerla solo nella rotta nuova e non qui avrebbe lasciato la
            // registrazione a rifiutare lo stesso file dall'altra porta.
            'file'               => ['nullable', 'file', 'extensions:pdf,xml,p7m,jpg,jpeg,png',
                // Il tetto di questa porta resta **10 MB, il suo**: un allegato di fattura è un
                // documento singolo, non un archivio. Quello che cambia è che adesso non promette
                // mai più di quanto il server accetti davvero.
                'max:'.LimiteCaricamento::regolaMax(10.0)],

            // ── REGOLE RITENUTA D'ACCONTO (Fase 1) ──
            // Difetto corretto (design §8 punto 1): la chiave non era validata,
            // quindi FatturaPassivaController::store() la scartava con
            // $request->validated() e vinceva sempre il default "applica".
            'applica_ritenuta' => 'nullable|boolean',
            'dati_extra.fiscal.motivo_esclusione_ritenuta' => [
                'nullable', 'string', Rule::in(array_column(MotivoEsclusioneRitenuta::cases(), 'value')),
                'required_if:applica_ritenuta,false',
            ],
            'dati_extra.fiscal.motivo_esclusione_ritenuta_note' => [
                'nullable', 'string', 'max:500',
                'required_if:dati_extra.fiscal.motivo_esclusione_ritenuta,'.MotivoEsclusioneRitenuta::OVERRIDE_MANUALE->value,
            ],
            'dati_extra.fiscal.conferma_codice_tributo_mancante' => 'nullable|boolean',

            // ── REGOLE SCUDO LEGALE E BUDGET (INTATTE E PROTETTE) ──
            'dati_extra.override_budget'                       => 'nullable|array',
            'dati_extra.override_budget.motivazione'           => 'required_with:dati_extra.override_budget|string|min:10',
            // `min:0` perché questo numero arriva dal client e viene usato verbatim per la
            // scrittura di copertura da fondo di riserva: un valore negativo girerebbe il segno
            // del giroconto. Entrambi i produttori lato form sono non negativi per costruzione,
            // quindi la regola non ha falsi positivi — è la rete sotto, non un cambio di flusso.
            'dati_extra.override_budget.importo_sforo'         => 'required_with:dati_extra.override_budget|integer|min:0',
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

            // Design §8 punto 9: la base ritenuta non coincide con l'imponibile IVA
            // (cassa professionale esclusa, rivalsa INPS GS inclusa, posa accessoria
            // esclusa). Il flag è per riga, default true se assente.
            $rules['righe.*.concorre_base_ritenuta'] = 'nullable|boolean';
            $rules['righe.*.natura_riga_ritenuta'] = [
                'nullable', 'string', Rule::in(array_column(NaturaRigaRitenuta::cases(), 'value')),
            ];
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

                    // ⚠️ **Una riga da € 0,00 non chiede nessun capitolo, e non è un caso di
                    // confine.** Cinque degli undici XML veri del collaudo portano righe
                    // puramente descrittive — «PROTOCOLLO 10000-2025», «Riga ausiliaria
                    // contenente informazioni tecniche» — con importo nullo: sono contenuto
                    // del documento, non spese, e pretendere che l'amministratore le collochi
                    // a budget è un attrito senza motivo, per giunta spiegato con un messaggio
                    // che parla di capitolo obbligatorio senza dire perché lo chieda per zero
                    // euro (Fase 1-bis, reperto 3).
                    //
                    // ⚠️ La guardia gemella di `UpdateFatturaRequest` chiede una cosa
                    // **diversa** (esenta le righe con `immobile_id`, non le sopravvenienze):
                    // le due non vanno unificate, va aggiunta a ognuna la sua esenzione.
                    $importoNullo = (float) ($riga['importo_imponibile'] ?? 0) === 0.0;

                    if (!$isSopravvenienza && !$importoNullo && empty($riga['conto_id'])) {
                        // ⚠️ «Fuori preventivo» e non «imprevista»: è la parola che l'amministratore
                        // ha davanti sul pulsante che attiva questo stato (FatturaRegisterNew.vue,
                        // riquadro della riga). Il campo si chiama `is_sopravvenienza` e il messaggio
                        // ne ricalcava il nome tecnico, mandando a cercare una spunta «imprevista»
                        // che sullo schermo non esiste — segnalato da Vincenzo il 03/09/2026.
                        $validator->errors()->add(
                            "righe.{$idx}.conto_id",
                            'Il capitolo di spesa è obbligatorio, oppure segna la riga come «fuori preventivo».'
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

            $this->guardiaNaturaPercipienteMancante($validator);
        });
    }

    /**
     * Design §2.4 M2: natura_percipiente mancante non blocca subito (i dati
     * reali hanno codici tributo misti), ma senza natura né override manuale
     * legacy il codice tributo (1019 vs 1020) è indeterminabile — warning
     * bloccante con conferma esplicita in v1.10, blocco duro rimandato a v1.11.
     */
    private function guardiaNaturaPercipienteMancante($validator): void
    {
        $fornitore = Fornitore::find($this->input('fornitore_id'));
        if (! $fornitore || ! $fornitore->soggetto_ritenuta || $fornitore->regime_forfetario || ! $fornitore->tipo_ritenuta) {
            return;
        }

        if ($fornitore->natura_percipiente || $fornitore->codice_tributo) {
            return; // il regime nuovo la risolve da sé, o c'è un override legacy esplicito
        }

        $richiestaApplicazione = $this->input('applica_ritenuta') ?? ($this->input('tipo_documento') !== 'nota_credito');
        if (! filter_var($richiestaApplicazione, FILTER_VALIDATE_BOOLEAN)) {
            return; // ritenuta non applicata su questo documento: il codice tributo non serve
        }

        if (filter_var($this->input('dati_extra.fiscal.conferma_codice_tributo_mancante', false), FILTER_VALIDATE_BOOLEAN)) {
            return; // confermato esplicitamente dall'utente
        }

        $validator->errors()->add(
            'dati_extra.fiscal.conferma_codice_tributo_mancante',
            "Impossibile determinare il codice tributo (1019 o 1020): sull'anagrafica di {$fornitore->ragione_sociale} manca la natura del percipiente. Completala nell'anagrafica fornitore oppure conferma di voler procedere comunque."
        );
    }
}