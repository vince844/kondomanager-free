<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use App\Enums\Fiscale\TipoRitenuta;
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

        // ⚠️ Vedi la nota estesa in UpdateFatturaRequest: il `min:0` sull'imponibile di riga
        // vale **solo** per la nota di credito, dove il segno lo porta già il moltiplicatore
        // −1 del servizio e una riga negativa lo annullerebbe, rimettendo `netto_a_pagare` in
        // positivo e facendo cadere la guardia «Non puoi stornare una Nota di Credito».
        // Sulla fattura ordinaria la riga negativa è legittima — lo storno «Oneri di sistema»
        // di una bolletta — e il motore la registra come AVERE sul capitolo.
        $isNotaCredito = $this->input('tipo_documento') === 'nota_credito';

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
            // Coda 165 (1.11.0-beta.36): la fattura che la nota del fornitore rettifica. Facoltativa, solo sulla nota,
            // verso una fattura dello stesso fornitore e condominio di QUALUNQUE esercizio (su un file vero il legame
            // attraversa l'esercizio). La scala dei piani la applica `guardiaFatturaRettificata()` qui sotto.
            'fattura_rettificata_id' => [
                'nullable', 'integer', 'prohibited_unless:tipo_documento,nota_credito',
                Rule::exists('fatture_passive', 'id')
                    ->where('condominio_id', $this->route('condominio')->id)
                    ->where('fornitore_id', $this->input('fornitore_id'))
                    ->where('tipo_documento', 'fattura'),
            ],
            // La conferma dell'avviso quando la fattura sta in un piano che ha già incassato: la chiede il servizio, che
            // conosce le righe vere; il modulo la manda con la spunta sotto il campo (R3, R7 della Fase 1-bis).
            'conferma_avviso_nota' => 'nullable|boolean',
            'numero_documento'   => 'required|string|max:50',
            'data_documento'     => 'required|date',
            'data_scadenza'      => 'required|date',
            // La competenza (B2, S6): entrambi gli estremi o nessuno. Il motore con uno solo scende al
            // gradino successivo in silenzio (`RisolutoreCompetenza::dichiarata()` vuole tutti e due),
            // quindi la metà si rifiuta qui, non si tollera. `dal = al` è la delibera puntuale (D2).
            'competenza_dal'     => 'nullable|date|required_with:competenza_al',
            'competenza_al'      => 'nullable|date|required_with:competenza_dal|after_or_equal:competenza_dal',
            'conto_corrente_id'  => 'nullable|exists:conti_contabili,id',
            'modalita_pagamento' => 'required|string',
            'stato_approvazione' => 'required|in:da_approvare,approvata,contestata,sforo_motivato',
            
            'iban_fornitore'     => 'nullable|string',
            'dati_extra'         => 'nullable|array',

            // ⚠️ **Non è un campo della fattura: è la risposta a una domanda sul FORNITORE**
            // (Coda 116), chiesta una volta sola quando nessuno si è mai pronunciato sulla sua
            // ritenuta. Viaggia con la fattura perché è lì che la domanda viene fatta — nel
            // momento in cui serve — ma il controller la scrive sull'anagrafica, non qui.
            'posizione_ritenuta'        => 'nullable|array',
            'posizione_ritenuta.soggetto' => 'nullable|boolean',
            'posizione_ritenuta.tipo'   => ['nullable', Rule::in(array_column(TipoRitenuta::cases(), 'value'))],
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
            // ⚠️ **Obbligatoria come il suo imponibile, non `nullable`.**
            // Il servizio ripiegava su `?? 22` e l'anteprima su `Number('') || 0`: svuotare il
            // campo faceva vedere zero IVA e salvarne il 22 %, cioè € 220,00 di debito su
            // € 1.000,00 che nessuno aveva digitato. Le due strade non si allineano inventando
            // un valore — né 22 né 0 sono quello che l'amministratore ha detto: si chiede.
            // Il modulo parte da 22, quindi obbligarla non costa nulla a chi non la tocca.
            // Trovato dalla Fase 1-bis della beta.19, lente «parità PHP/TS».
            $rules['aliquota_iva_pregressa']     = 'required|numeric|min:0|max:100';
            // L'imposta che il documento dichiara di sé. Quando c'è vince sull'aliquota, che
            // sul pannello pregresso è una media arrotondata a due decimali e su un documento
            // a più aliquote non ricostruisce il numero vero.
            $rules['imposta_pregressa']          = 'nullable|numeric|min:0';
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

        // ⚠️ **I riepiloghi IVA del documento, se ce li porta un file.**
        // È l'unico dato nuovo che il form può mandare dalla beta.19, e da qui esce il numero
        // che finisce nella riga di testata della scrittura contabile: va tipizzato, non
        // accettato per fiducia. `natura` è nullable perché la maggior parte dei blocchi non
        // ne ha una, ma quando c'è fa parte della CHIAVE del gruppo insieme all'aliquota.
        $rules['riepiloghi']                 = 'nullable|array|max:50';
        $rules['riepiloghi.*.aliquota_iva']  = 'required_with:riepiloghi|numeric|min:0|max:100';
        $rules['riepiloghi.*.natura']        = 'nullable|string|max:10';
        $rules['riepiloghi.*.imponibile']    = 'required_with:riepiloghi|numeric';
        // ⚠️ Erano gli unici campi di denaro del blocco senza limite inferiore, e finiscono
        // verbatim in `importo_iva` e `netto_a_pagare`. Un'imposta negativa non esiste in una
        // fattura: il segno lo mette il tipo di documento, non l'importo.
        $rules['riepiloghi.*.imposta']       = 'required_with:riepiloghi|numeric|min:0';

        // La natura viaggia anche sulla riga: è ciò che la lega al suo gruppo.
        $rules['righe.*.natura']             = 'nullable|string|max:10';

        return $rules;
    }

    public function messages(): array
    {
        return [
            'fattura_rettificata_id.exists' => 'La fattura che la nota rettifica dev\'essere una fattura dello stesso fornitore, registrata in questo condominio.',
            'fattura_rettificata_id.prohibited_unless' => 'Solo una nota di credito rettifica una fattura.',
            'dati_extra.override_budget.motivazione.min' => 'La motivazione dello sforamento deve essere di almeno 10 caratteri.',
            'dati_extra.override_budget.motivazione.required_with' => 'La motivazione è obbligatoria quando si supera il budget.',
            'dati_extra.override_budget.strategia_rientro.required_with' => 'Devi selezionare una strategia di rientro per lo sforo.',
            'dati_extra.override_budget.fondo_patrimoniale_id.exists' => 'Il fondo di riserva selezionato non è valido.',
            'data_competenza_originaria.required_if' => 'La data di origine è obbligatoria per i debiti pregressi (verifica prescrizione).',
            'coperture.required_if' => 'Devi specificare come coprire questo debito pregresso.',
            'imponibile_pregresso.required_if' => 'L\'importo della fattura pregressa è obbligatorio.',
            'righe.required_unless' => 'Devi inserire almeno una voce di spesa.',
            'numero_documento.required' => 'Il numero documento è obbligatorio.',
            'competenza_dal.required_with' => 'La competenza vuole entrambe le date: manca l\'inizio.',
            'competenza_al.required_with' => 'La competenza vuole entrambe le date: manca la fine.',
            'competenza_al.after_or_equal' => 'La fine della competenza non può precedere l\'inizio.',
            'righe.*.descrizione.required_with' => 'La causale della riga è obbligatoria.',
            'righe.*.conto_id.required_with' => 'Il capitolo di spesa è obbligatorio.',
            'righe.*.importo_imponibile.required_with' => 'L\'importo è obbligatorio.',
            'righe.*.importo_imponibile.min'          => "Su una nota di credito l'importo di riga non può essere negativo: "
                .'il segno lo mette già il tipo di documento. Scrivi la cifra da accreditare.',
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

                // ⚠️ **Una nota di credito deve restare un credito — il vincolo è sul documento.**
                // Ha sostituito il `min:0` per riga: le singole righe di una nota possono essere
                // negative, perché la nota che storna una fattura con una riga in diminuzione
                // porta quella riga col segno opposto. Quello che non può succedere è che la
                // SOMMA si ribalti: il moltiplicatore −1 del servizio produrrebbe un documento di
                // segno positivo che continua a chiamarsi nota di credito.
                if ($this->input('tipo_documento') === 'nota_credito') {
                    $sommaRighe = 0.0;
                    foreach ($righe as $riga) {
                        $sommaRighe += (float) ($riga['importo_imponibile'] ?? 0);
                    }

                    if ($sommaRighe <= 0) {
                        $validator->errors()->add(
                            'righe',
                            'Una nota di credito deve accreditare qualcosa: la somma delle righe è '
                            .number_format($sommaRighe, 2, ',', '.').'. Le singole righe possono '
                            .'essere negative — è il caso dello storno di una riga in diminuzione — '
                            .'ma il totale no.'
                        );
                    }
                }

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
            $this->guardiaPosizioneRitenutaMaiDecisa($validator);
            $this->guardiaPeriodoPregressaScoperta($validator);
            $this->guardiaFatturaRettificata($validator);
        });
    }

    /**
     * Coda 165 (1.11.0-beta.36; decisione 26, punto 6): la nota collegata a una fattura che sta in un piano che non ha
     * incassato niente non si registra finché il piano non è tolto — la stessa scala dello storno. Il motivo è quello del
     * modello (`FatturaPassiva::motivoBloccoNotaCollegata`), che il modulo mostra prima dell'invio: una riga di codice sola
     * per decidere e per spiegare. Il servizio lo ricontrolla dentro la transazione (terza porta).
     */
    private function guardiaFatturaRettificata($validator): void
    {
        if (blank($this->input('fattura_rettificata_id')) || $this->input('tipo_documento') !== 'nota_credito') {
            return;
        }
        if ($validator->errors()->hasAny(['fattura_rettificata_id', 'righe', 'righe.*', 'imponibile_pregresso', 'aliquota_iva_pregressa', 'imposta_pregressa', 'riepiloghi', 'riepiloghi.*'])) {
            return;
        }
        $fattura = \App\Models\Gestionale\FatturaPassiva::with(['pianiRate', 'noteCollegate', 'righe', 'coperture'])
            ->find((int) $this->input('fattura_rettificata_id'));
        if ($fattura === null) {
            return;
        }
        if ($motivo = $fattura->motivoBloccoNotaCollegata($this->importoLordoNotaCents(), 'registra la nota', null, $this->input('stato_approvazione') === 'contestata')) {
            $validator->errors()->add('fattura_rettificata_id', $motivo);
        }
    }

    /**
     * La magnitudine lorda della nota in richiesta, in centesimi: quella che il servizio scriverà, con la sua formula
     * (`FatturaPassivaService::totaleLordoCents`) — l'imposta dichiarata dall'XML, distribuita, vince sul calcolo per riga.
     * Ricalcolata qui a parte, la nota che annulla una fattura importata veniva rifiutata per un centesimo (V2); la nota
     * pregressa valeva 0 (R1).
     */
    private function importoLordoNotaCents(): int
    {
        // Gli stessi dati che il servizio riceve: `importo_iva_dichiarata` per riga non ha una regola, quindi non arriva
        // al servizio dalla richiesta, e qui non deve contare (W4 del terzo giro).
        $dati = $this->all();
        if (is_array($dati['righe'] ?? null)) {
            $dati['righe'] = array_map(fn ($r) => is_array($r) ? \Illuminate\Support\Arr::except($r, ['importo_iva_dichiarata']) : $r, $dati['righe']);
        }

        return \App\Services\Gestionale\FatturaPassivaService::totaleLordoCents($dati);
    }

    /**
     * Decisione 26 (1.11.0-beta.35): una pregressa con una parte **non coperta** dai saldi iniziali — la copertura
     * «sopravvenienza», l'unica che finisce in un piano — non si registra senza il periodo in cui il costo è maturato,
     * e quel periodo si chiude **prima dell'esercizio** in cui la fattura si registra. Una pregressa non si modifica dopo
     * (si storna) e il periodo lo conosce solo chi registra: dopo, il piano lo dovrebbe indovinare, e dopo una vendita
     * lo pagherebbe chi è entrato. Tutta coperta dai saldi, non va in nessun piano e il periodo resta facoltativo.
     *
     * Sta qui e non nel servizio: lo storno e i test chiamano il servizio direttamente, con pregresse di prima.
     */
    private function guardiaPeriodoPregressaScoperta($validator): void
    {
        if (! filter_var($this->input('is_pregresso', false), FILTER_VALIDATE_BOOLEAN) || $this->input('tipo_documento') === 'nota_credito') {
            return;
        }
        // Gli `after` girano anche quando le regole sono già fallite: con un campo che la regola legge già in errore, la
        // regola tace — prima un `coperture` che non era una lista dava un 500 (R8 della Fase 1-bis).
        if ($validator->errors()->hasAny(['coperture', 'coperture.*', 'competenza_dal', 'competenza_al', 'imponibile_pregresso', 'aliquota_iva_pregressa', 'imposta_pregressa'])) {
            return;
        }
        // Le due porte della sopravvenienza: l'eccedenza con la voce legale (il modulo), o una copertura scritta a mano.
        $eccedenza = \App\Services\Gestionale\FatturaPassivaService::eccedenzaPregressaCents($this->all());
        $scoperta = ($eccedenza > 0 && $this->filled('dati_extra.log_legale_sopravvenienza'))
            || collect($this->input('coperture', []))->contains(fn ($c) => ($c['tipo_copertura'] ?? null) === 'sopravvenienza');
        if (! $scoperta) {
            return;
        }

        $dal = $this->input('competenza_dal');
        $al = $this->input('competenza_al');
        if (blank($dal) || blank($al)) {
            $validator->errors()->add('competenza_dal', 'Fattura pregressa con una parte non coperta dai saldi iniziali: dichiara il periodo in cui il costo è maturato. Quella parte finisce in un piano rate, e senza il periodo, dopo una vendita, la pagherebbe chi è entrato per un costo di quando l\'unità era di chi è uscito. Dopo non si potrà più aggiungere: una pregressa si storna, non si modifica.');

            return;
        }
        $inizioEsercizio = \Illuminate\Support\Facades\DB::table('esercizi')->where('id', $this->input('esercizio_id'))->value('data_inizio');
        if (\App\Services\Gestionale\FatturaPassivaService::periodoChiusoPrimaDellEsercizio($al, $inizioEsercizio) === false) {
            $validator->errors()->add('competenza_al', sprintf(
                'Il periodo di una fattura pregressa si chiude prima dell\'esercizio in cui la registri (inizia il %s): è un costo di un esercizio passato.',
                \Carbon\Carbon::parse($inizioEsercizio)->format('d/m/Y'),
            ));
        }
    }

    /**
     * Un fornitore su cui **nessuno si è mai pronunciato** non passa senza una risposta.
     *
     * ## Perché è un obbligo e non un avviso
     *
     * ⚠️ **L'assenza del blocco `<DatiRitenuta>` non significa «nessuna ritenuta».** L'obbligo
     * è del condominio come sostituto d'imposta, non del fornitore che lo scrive in fattura:
     * sei degli undici XML veri non hanno quel blocco, e uno dei sei è un geometra su cui il
     * 20% è dovuto. Oggi quel documento si registrava a netto pieno, in silenzio — il
     * condominio pagava tutto al fornitore e all'Erario non versava niente, restandone
     * responsabile.
     *
     * Un avviso ignorabile non basta, e la ragione è misurabile: ciò che si perde ignorandolo
     * è denaro dovuto all'Erario, e il rimedio è più caro del gesto. Ma **non è nemmeno un
     * blocco duro**, perché la risposta arriva già proposta dal documento: nel caso comune è
     * una conferma, ed è chiesta **una volta sola nella vita del fornitore** — la data in
     * `fornitori.ritenuta_decisa_il` è ciò che impedisce alla domanda di tornare.
     *
     * ⛔ **Non si applica ai fornitori già classificati**, che sono la stragrande maggioranza:
     * chi ha già una posizione dichiarata non viene interrotto, mai.
     */
    private function guardiaPosizioneRitenutaMaiDecisa($validator): void
    {
        $fornitore = Fornitore::find($this->input('fornitore_id'));

        if (! $fornitore || ! $fornitore->posizioneRitenutaMaiDecisa()) {
            return;
        }

        // La risposta è arrivata: la validazione è soddisfatta. Ad applicarla al fornitore è
        // il controller, non questa classe — una FormRequest che scrive a database sarebbe
        // una sorpresa dentro un oggetto che tutti leggono come «controlla e basta».
        if ($this->has('posizione_ritenuta.soggetto')) {
            return;
        }

        $validator->errors()->add(
            'posizione_ritenuta.soggetto',
            sprintf(
                'Su %s nessuno si è ancora pronunciato: dì se è soggetto a ritenuta d\'acconto. '
                .'La fattura non lo dichiara mai — l\'obbligo è del condominio, non del fornitore — '
                .'e la domanda ti viene fatta una volta sola.',
                $fornitore->ragione_sociale
            )
        );
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