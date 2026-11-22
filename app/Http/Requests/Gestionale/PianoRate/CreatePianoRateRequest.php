<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\PianoRate;

use App\Http\Requests\Gestionale\PianoConto\Conto\ImportoSenzaSegno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePianoRateRequest extends FormRequest
{
    /**
     * «Urgenza» dalla scheda «Dalle voci del preventivo» di una gestione straordinaria (Coda 688): si accetta, con il motivo e **il giorno dei lavori**
     * («Lavori ordinati il …», `giorno_dei_lavori`), che decide chi paga. Con `false` la scelta si rifiuta con {@see URGENZA_SOLO_DALLE_FATTURE}, com'era
     * prima della Coda 688. Il giorno si chiede anche sulla scheda delle fatture, dove la fattura senza periodo lo prende dal piano.
     */
    public const URGENZA_DALLE_VOCI = true;

    public const URGENZA_SOLO_DALLE_FATTURE = 'Con l\'urgenza il piano si fa dalla scheda delle fatture.';

    /** La delibera scelta con «Scegli la delibera», risolta nel condominio dell'indirizzo (R18 del progetto delle assemblee); `null` senza. */
    private ?\App\Models\Assemblee\Delibera $delibera = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * «Scegli la delibera» nello Scudo legale (§1.4 del progetto delle assemblee, decisione 502): con una delibera scelta, «Deliberata il» viene dalla
     * riunione, non da un campo scritto a mano. La delibera è del condominio dell'indirizzo, o la richiesta è un 404 (R18). Con «Urgenza» la delibera non
     * c'è: non si legge. Il server accetta ancora la data scritta senza delibera (le importazioni e le prove la usano): la pagina la manda solo con la
     * delibera.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('delibera_id') || $this->input('tipo_autorizzazione') === 'urgenza' || ! ctype_digit((string) $this->input('delibera_id'))) {
            return;
        }
        // Il permesso prima di tutto: chi non gestisce le assemblee riceve 403, non gli errori del modulo né un 404 sulla delibera.
        \Illuminate\Support\Facades\Gate::allowIf(\App\Services\Assemblee\AccessoAlleAssemblee::gestisce($this->user()), __('errors.403_message'));
        $condominio = \App\Models\Condominio::query()->findOrFail($this->idDelCondominio());
        /** @var \App\Models\Assemblee\Delibera $delibera */
        $delibera = \App\Services\Assemblee\SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $this->input('delibera_id'));
        $this->delibera = $delibera;
        $this->merge(['data_delibera_assemblea' => $delibera->deliberata_il?->format('Y-m-d')]);
    }

    /** La delibera scelta, già risolta nel condominio; `null` senza, o con «Urgenza». */
    public function delibera(): ?\App\Models\Assemblee\Delibera
    {
        return $this->delibera;
    }

    /** La natura della gestione scelta: `gestioni.tipo`, non `piani_rate.tipo` (decisione 11). */
    public function gestioneStraordinaria(): bool
    {
        $id = $this->input('gestione_id');

        // Rilievo T-J del terzo giro: solo una gestione del condominio dell'indirizzo; di un'altra non si legge nemmeno la natura.
        return $id !== null && \App\Models\Gestione::where('condominio_id', $this->idDelCondominio())->whereKey($id)->value('tipo') === 'straordinaria';
    }

    public function messages(): array
    {
        // Coda 686 (b): ogni messaggio in parole semplici, senza il nome tecnico di un campo (`ParoleDelPiano`); qui quelli che dipendono dalla richiesta.
        return array_merge(ParoleDelPiano::messaggi(), [
            // La coda su «Urgenza» solo dove quella scelta esiste a video: il carrello del piano straordinario (verifica S6, R4), e dalla Coda 688 il riquadro
            // «La delibera, o l'urgenza» delle due schede, con il giorno dei lavori.
            'data_delibera_assemblea.required' => 'Indica la data della delibera dell\'assemblea: su una gestione straordinaria è il giorno che decide chi paga (art. 63 disp. att. c.c.).'
                . ($this->input('tipo') === 'straordinario' || self::URGENZA_DALLE_VOCI ? ' Con un intervento d\'urgenza scegli «Urgenza» e scrivi il giorno dei lavori.' : ''),
            // Coda 688: il giorno dei lavori urgenti, sulle due schede, che decide chi paga.
            'giorno_dei_lavori.required' => 'Scrivi il giorno dei lavori urgenti: decide chi paga, chi era titolare dell\'unità quel giorno.',
            'giorno_dei_lavori.date_format' => 'Scegli il giorno dei lavori urgenti.',
            // Con la delibera scelta la motivazione è una nota facoltativa; senza, o con «Urgenza», resta obbligatoria come prima.
            'motivazione_autorizzazione.required' => ParoleDelPiano::messaggi()['motivazione_autorizzazione.required_if'],
            'competenze_capitoli.*.tratti.*.dal.required' => 'Ogni tratto di competenza vuole la data di inizio.',
            'competenze_capitoli.*.tratti.*.al.required' => 'Ogni tratto di competenza vuole la data di fine.',
        ]);
    }

    /**
     * N4: la frase per una fattura che «Spese fuori preventivo» non mette a rate, con le regole del suo carrello (`CarrelloDelloStraordinario::righe()`, 1a e
     * 1b); null se la mette a rate, o se la fattura non dice niente di sé (né righe su una voce né coperture: decide il motore, come prima).
     * - una fattura corrente con le righe sulle voci del preventivo e nessuna riga «Fuori preventivo» o addebitata a una sola unità: l'altra scheda;
     * - una pregressa coperta in un altro modo (il fondo di riserva, la rata 0) e senza una copertura per sopravvenienza.
     */
    private function fuoriDalloStraordinario(\App\Models\Gestionale\FatturaPassiva $f): ?string
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        if ($f->is_pregresso) {
            $coperture = $db->table('fattura_coperture')->where('fattura_passiva_id', $f->id)->pluck('tipo_copertura')->unique()->all();

            return $coperture === [] || in_array('sopravvenienza', $coperture, true) ? null
                : "La fattura n. {$f->numero_documento} è una pregressa già coperta in un altro modo (il fondo di riserva o la rata 0), senza una copertura per "
                    . 'sopravvenienza: non si mette a rate con «Spese fuori preventivo». Toglila dal carrello.';
        }
        $righe = $db->table('righe_fattura')->leftJoin('conti', 'conti.id', '=', 'righe_fattura.conto_id')->where('righe_fattura.fattura_passiva_id', $f->id)
            ->orderBy('righe_fattura.id')->get(['righe_fattura.is_sopravvenienza', 'righe_fattura.immobile_id', 'conti.nome as voce']);
        $voce = $righe->whereNotNull('voce')->first()?->voce;
        if ($voce === null || $righe->contains(fn ($r) => (bool) $r->is_sopravvenienza || $r->immobile_id !== null)) {
            return null;
        }

        return "La fattura n. {$f->numero_documento} è sulla voce «{$voce}», senza righe «Fuori preventivo»: si mette a rate con «Dalle voci del preventivo», "
            . 'non con «Spese fuori preventivo». Toglila dal carrello.';
    }

    /** Coda 686 (b): il nome a video di ogni campo, per le frasi delle regole generiche. */
    public function attributes(): array
    {
        return ParoleDelPiano::ATTRIBUTI;
    }

    /**
     * I tratti di competenza per voce (B2, S6): ognuno con `al ≥ dal`, tutti dentro l'esercizio del piano,
     * in ordine e **senza un giorno in comune** — «01/01–15/04» e «15/04–31/12» condividono il 15 aprile e
     * `InsiemePeriodi` li rifiuta; qui lo si dice con un 422 sulla voce, non con un 500 alla generazione.
     * Il motore non interseca i tratti con l'esercizio: la validazione è l'unico presidio.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $esercizio = $this->route('esercizio');
            $inizio = $esercizio?->data_inizio ? substr((string) $esercizio->data_inizio, 0, 10) : null;
            $fine = $esercizio?->data_fine ? substr((string) $esercizio->data_fine, 0, 10) : null;

            // Sullo straordinario la competenza è il giorno della delibera, o quella dichiarata sulla fattura
            // (decisioni 12 e 20): le voci non hanno un periodo proprio, e i tratti si rifiutano invece di
            // scriverli e ignorarli (verifica S6, R1).
            if ($this->input('competenze_capitoli', []) !== [] && $this->gestioneStraordinaria()) {
                $validator->errors()->add('competenze_capitoli', 'Su una gestione straordinaria la competenza è il giorno della delibera dell\'assemblea, o quella dichiarata sulla fattura: le voci di spesa non hanno un periodo proprio.');
            }

            // Una lista che non è una lista (una richiesta costruita a mano) la rifiutano le regole del campo, con il loro messaggio: qui non si legge.
            $competenze = $this->input('competenze_capitoli', []);
            foreach (is_array($competenze) ? $competenze : [] as $i => $voce) {
                if (! is_array($voce) || ! is_array($voce['tratti'] ?? [])) {
                    continue;
                }
                $tratti = collect($voce['tratti'] ?? [])
                    ->filter(fn ($t) => is_array($t))
                    ->map(fn ($t) => ['dal' => substr((string) ($t['dal'] ?? ''), 0, 10), 'al' => substr((string) ($t['al'] ?? ''), 0, 10)])
                    ->filter(fn ($t) => $t['dal'] !== '' && $t['al'] !== '')
                    ->sortBy('dal')->values();
                $precedente = null;
                foreach ($tratti as $t) {
                    if ($t['al'] < $t['dal']) {
                        $validator->errors()->add("competenze_capitoli.{$i}", 'In un tratto di competenza la fine non può precedere l\'inizio.');
                        break;
                    }
                    if ($inizio !== null && $fine !== null && ($t['dal'] < $inizio || $t['al'] > $fine)) {
                        $validator->errors()->add("competenze_capitoli.{$i}", sprintf('I tratti di competenza devono stare dentro l\'esercizio (%s – %s): il piano conta i giorni di quell\'anno, non di un altro.', $this->giorno($inizio), $this->giorno($fine)));
                        break;
                    }
                    if ($precedente !== null && $t['dal'] <= $precedente) {
                        $validator->errors()->add("competenze_capitoli.{$i}", sprintf('Due tratti di competenza non possono avere un giorno in comune: il tratto che comincia il %s si sovrappone a quello che finisce il %s. Chiudi il primo il giorno prima.', $this->giorno($t['dal']), $this->giorno($precedente)));
                        break;
                    }
                    $precedente = $t['al'];
                }
            }

            // L'importo che il piano forza su una voce si scrive senza il segno, come quello della voce: lo decide il tipo. Una configurazione che
            // non è una lista, o una riga già in errore, la rifiutano le regole del campo.
            $configurazione = $this->input('capitoli_config');
            foreach (is_array($configurazione) ? $configurazione : [] as $i => $conf) {
                if (is_array($conf) && ImportoSenzaSegno::eNegativo($conf['importo'] ?? null)) {
                    ImportoSenzaSegno::rifiuta($validator, "capitoli_config.{$i}.importo", ImportoSenzaSegno::PER_IL_PIANO);
                }
            }

            $this->guardiaSpeseNegative($validator);
            $this->guardiaFattureRettificate($validator);
        });
    }

    /**
     * Una voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51) non entra in un piano nuovo con una cifra: il motore la
     * addebita in positivo, e se un piano la tiene già la addebiterebbe una seconda volta. Il selettore della creazione la spegne; una richiesta che
     * la porta lo stesso con un importo si rifiuta sul campo della riga, nominando la voce, e i piani che la addebitano se ce ne sono (`VoceDiSpesaNegativa`).
     * Vale anche per un sottoconto, che il fermo del piano su una copia positiva non vede.
     *
     * Una riga con l'importo vuoto non si rifiuta qui: la copia è l'importo della voce, negativo, e la ferma il fermo del piano. Una riga a zero
     * neppure, e il piano nasce con la copia a zero: non addebita la voce, e `VoceDiSpesaNegativa::pianiPerVoce` non lo conta.
     * Una riga il cui id è già rifiutato (fuori dal condominio, inesistente) si salta: questa regola non legge quella voce e non ne dice il nome.
     */
    private function guardiaSpeseNegative($validator): void
    {
        $configurazione = $this->input('capitoli_config');
        if ($this->input('tipo') === 'straordinario' || ! is_array($configurazione)) {
            return;
        }

        $condominioId = $this->idDelCondominio();
        foreach ($configurazione as $i => $conf) {
            if (! is_array($conf) || empty($conf['id']) || $validator->errors()->has("capitoli_config.{$i}.id") || $validator->errors()->has("capitoli_config.{$i}.importo")) {
                continue;
            }
            $importo = $conf['importo'] ?? null;
            if (! is_string($importo) || trim($importo) === '' || \App\Helpers\MoneyHelper::toCents($importo) <= 0) {
                continue;
            }

            $voce = \App\Models\Gestionale\Conto::whereKey($conf['id'])
                ->whereIn('piano_conto_id', fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId))
                ->with('pianoConto')
                ->first();
            if ($voce === null || ! \App\Models\Gestionale\Conto::eSpesaNegativa((string) $voce->tipo, (int) $voce->importo)) {
                continue;
            }

            $gestioneId = (int) ($voce->pianoConto?->gestione_id ?? 0);
            $piani = \App\Services\Riparto\VoceDiSpesaNegativa::pianiPerVoce([(int) $voce->id], $gestioneId)[(int) $voce->id] ?? [];
            $bloccano = \App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $voce->id])[(int) $voce->id] ?? [];
            $terzoLivello = \App\Services\Riparto\VoceDiSpesaNegativa::alTerzoLivello([(int) $voce->id]) !== [];
            $validator->errors()->add("capitoli_config.{$i}.importo", \App\Services\Riparto\VoceDiSpesaNegativa::rifiuto((string) $voce->nome, (int) $voce->importo, $piani, $bloccano, $terzoLivello));
        }
    }

    /**
     * Coda 165 (1.11.0-beta.36): una fattura rettificata da una nota del fornitore collegata non si mette in un piano per
     * più di quanto ne resta — il netto (`NettoNoteCollegate`), meno quello che altri piani ne chiedono già. È il confine
     * d'ingresso della stessa regola che ferma il ricalcolo: meglio dirlo qui, sull'indice della fattura, che trovarlo
     * alla generazione. Importo vuoto o zero vuol dire «tutto»: ciò che il motore ripartisce, mai oltre il netto.
     *
     * Le fatture senza note collegate restano come prima: il tetto generale dell'importo è la Coda 166.
     */
    private function guardiaFattureRettificate($validator): void
    {
        $fatture = $this->input('fatture_config', []);
        foreach (is_array($fatture) ? $fatture : [] as $i => $conf) {
            if (! is_array($conf) || $validator->errors()->has("fatture_config.{$i}.id") || empty($conf['id'])) {
                continue;
            }
            $f = \App\Models\Gestionale\FatturaPassiva::with(['pianiRate', 'noteCollegate', 'righe', 'coperture'])->find($conf['id']);
            if ($f === null || $f->tipo_documento !== 'fattura' || ($netto = $f->nettoNoteCollegate()) === null) {
                continue;
            }

            $importo = \App\Helpers\MoneyHelper::toCents($conf['importo'] ?? '');
            // Zero vuol dire «tutto», e «tutto» è ciò che il motore ripartisce: la parte del piano al netto delle note su
            // quella parte, mai oltre il netto (R2 della Fase 1-bis — lo stesso numero di `chiestoDaiPiani`).
            $chiesto = $importo > 0 ? $importo : max(0, min($netto['parte_piano'] - $netto['rettificato_piano'], $netto['netto']));
            $gia = $f->chiestoDaiPiani();
            $resta = max(0, $netto['netto'] - $gia);
            $inPiu = $chiesto - $resta;
            if ($inPiu <= 0) {
                continue;
            }

            $validator->errors()->add("fatture_config.{$i}.importo",
                "La fattura n. {$f->numero_documento}, al netto {$f->elencoNoteCollegate('di', true)}, vale "
                . \App\Helpers\MoneyHelper::format($netto['netto'])
                . ($gia > 0 ? ', e altri piani ne chiedono già ' . \App\Helpers\MoneyHelper::format($gia) : '')
                . ': con questo importo il piano chiederebbe ' . \App\Helpers\MoneyHelper::format($inPiu)
                . ' più di quanto ne resta. Metti al massimo ' . \App\Helpers\MoneyHelper::format($resta) . '.');
        }
    }

    private function giorno(string $iso): string
    {
        return \Carbon\CarbonImmutable::parse($iso)->format('d/m/Y');
    }

    public function rules(): array
    {
        // Decisione 44 (1.11.0-beta.42, rilievo V11 di sicurezza, c'era già nella .41): ogni id che arriva dal corpo è del condominio
        // dell'indirizzo, e la gestione anche del suo esercizio. Prima bastava che esistesse: con la gestione, una fattura, una voce, un
        // saldo o una persona di un altro condominio il piano nasceva qui con i numeri di là.
        $condominioId = (int) ($this->route('condominio')?->id ?? $this->route('condominio'));
        $esercizioId = (int) ($this->route('esercizio')?->id ?? $this->route('esercizio'));
        $contoDelCondominio = fn () => Rule::exists('conti', 'id')->whereIn('piano_conto_id', fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId));

        return [

            // --- [INIZIO FIX BIVIO ORDINARIO/STRAORDINARIO] ---
            'tipo' => ['required', 'string', 'in:ordinario,straordinario'],
            
            // Regole specifiche per il Carrello Fatture (Straordinario)
            'fatture_config' => [
                'required_if:tipo,straordinario', 
                'array',
                function ($attribute, $value, $fail) {
                    // A lista vuota questa regola non gira (non è implicita): il messaggio di quel caso è quello di `required_if` (Coda 686 (b)).
                    if ($this->tipo === 'straordinario' && (empty($value) || ! is_array($value) || count($value) === 0)) {
                        $fail('Scegli almeno una fattura da mettere a rate.');
                    }
                },
            ],
            'fatture_config.*.id'      => ['required_with:fatture_config', 'integer', Rule::exists('fatture_passive', 'id')->where('condominio_id', $condominioId),
                // Il carrello esclude le stornate solo quando si carica: stornata nel frattempo (un'altra scheda) o con una
                // richiesta costruita a mano, la fattura entrava nel piano e le rate la chiedevano (verifica delle
                // correzioni della Fase 1-bis, 1.11.0-beta.35).
                function ($attribute, $value, $fail) {
                    // Rilievo T11: solo una fattura del condominio dell'indirizzo, perché il messaggio ne dice il numero; una di un
                    // altro condominio la rifiuta già `exists`, senza dire niente di lei.
                    $f = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $this->idDelCondominio())->find($value);
                    $stato = $f ? (is_object($f->stato_pagamento) ? $f->stato_pagamento->value : $f->stato_pagamento) : null;
                    if ($f && (($f->dati_extra['is_stornata'] ?? false) || $stato === 'stornata')) {
                        $fail("La fattura n. {$f->numero_documento} è stata stornata nel frattempo: toglila dal carrello.");

                        return;
                    }
                    // Coda 165 (1.11.0-beta.36): un piano finanzia fatture. Il carrello non offre le note, ma una
                    // richiesta costruita a mano le faceva entrare — con una riga ad personam positiva, come fabbisogno.
                    if ($f && $f->tipo_documento !== 'fattura') {
                        $fail("Il documento n. {$f->numero_documento} è una nota di credito: un piano rate finanzia fatture. "
                            . 'La nota riduce la fattura che rettifica: collegala a quella, dall\'elenco delle fatture.');

                        return;
                    }
                    // …e una fattura annullata per intero da una nota del fornitore collegata non ha più niente da chiedere.
                    if ($f && ($netto = $f->nettoNoteCollegate()) !== null && $netto['netto'] <= 0) {
                        $fail("La fattura n. {$f->numero_documento} è annullata per intero {$f->elencoNoteCollegate('da', true)}: "
                            . 'non resta niente da finanziare. Toglila dal carrello.');

                        return;
                    }
                    // Coda 686 (d), D198 (c): il carrello offre le fatture della gestione scelta, e il piano mette a rate solo quelle. Una fattura della
                    // straordinaria in un piano dell'ordinaria si ripartirebbe per giorni invece che alla delibera (decisione 11). Una fattura che non sa
                    // la sua gestione (né colonna, né scrittura, né voci) passa: non si indovina.
                    $gestioneFattura = $f?->gestioneDellaFattura();
                    if ($f && $gestioneFattura !== null && $this->filled('gestione_id') && $gestioneFattura !== (int) $this->input('gestione_id')) {
                        $nome = \App\Models\Gestione::whereKey($gestioneFattura)->value('nome');
                        $fail("La fattura n. {$f->numero_documento} è della gestione «{$nome}»: si mette a rate in un piano di quella gestione. "
                            . "Scegli «{$nome}» come gestione di riferimento.");

                        return;
                    }
                    // N4 della terza prova (Fase 1-bis della beta.12): «Spese fuori preventivo» mette a rate quello che il suo carrello offre
                    // (`CarrelloDelloStraordinario::righe()`): le righe «Fuori preventivo» o di una sola unità, e le pregresse con una copertura per
                    // sopravvenienza. Una fattura della gestione giusta ma sulle voci del preventivo (una pagina rimasta aperta, una richiesta fatta a mano)
                    // arrivava al motore, che si fermava con un messaggio tecnico: qui si dice l'altra scheda.
                    if ($f && $this->input('tipo') === 'straordinario' && ($rifiuto = $this->fuoriDalloStraordinario($f)) !== null) {
                        $fail($rifiuto);
                    }
                },
            ],
            'fatture_config.*.importo' => ['required_with:fatture_config'],
            
            // Scudo Legale (Obbligatorio per Art. 1135 c.c. se straordinario)
            'tipo_autorizzazione'        => ['nullable', 'required_if:tipo,straordinario', 'in:delibera,urgenza',
                // Coda 688: «Urgenza» dalla scheda delle voci di una gestione straordinaria salvava un piano che poi non si generava (chiedeva la data
                // della delibera che con l'urgenza non si scrive). Finché i periodi per voce non ci sono, la scelta si rifiuta con la frase che dice dove si fa.
                function (string $campo, mixed $valore, \Closure $fail): void {
                    if ($valore === 'urgenza' && ! self::URGENZA_DALLE_VOCI && $this->input('tipo') !== 'straordinario' && $this->gestioneStraordinaria()) {
                        $fail(self::URGENZA_SOLO_DALLE_FATTURE);
                    }
                },
            ],
            // Con «Delibera assembleare» e la delibera scelta la motivazione diventa la nota facoltativa del piano; con «Urgenza» è il motivo dell'urgenza,
            // su ogni scheda di una gestione straordinaria, e senza una delibera scelta resta come prima (il server accetta ancora gli estremi scritti a mano).
            'motivazione_autorizzazione' => ['nullable', Rule::requiredIf(fn () => ($this->input('tipo') === 'straordinario'
                && ($this->input('tipo_autorizzazione') === 'urgenza' || ! $this->filled('delibera_id')))
                || ($this->input('tipo_autorizzazione') === 'urgenza' && $this->gestioneStraordinaria())), 'string', 'min:5'],
            // La delibera scelta con «Scegli la delibera»: un id del condominio (la risolve `prepareForValidation`, R18).
            'delibera_id' => ['nullable', 'integer'],
            // Coda 688: «Lavori ordinati il …», obbligatorio con «Urgenza» su una gestione straordinaria, sulle due schede: sulle fatture la fattura senza
            // periodo prende questo giorno, quella con il suo periodo tiene il suo. Un giorno solo, non un periodo: chi era titolare quel giorno paga tutto.
            // Non deve stare dentro l'esercizio: i lavori del 28/12 si mettono a rate l'anno dopo. Sulla gestione ordinaria non si chiede e non si scrive.
            'giorno_dei_lavori' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(fn () => $this->input('tipo_autorizzazione') === 'urgenza'
                && ($this->input('tipo') === 'straordinario' || self::URGENZA_DALLE_VOCI) && $this->gestioneStraordinaria())],
            // B2 (decisione 12): la data della delibera è **obbligatoria, senza default**, quando la GESTIONE è
            // straordinaria (è la natura che decide, decisione 11 — non `tipo`) e l'autorizzazione non è
            // l'urgenza: è il giorno che fa la competenza dello straordinario (Cass. 24654/2010) e il motore
            // senza si ferma. Con `urgenza` decide il giorno dei lavori (Coda 688), o il periodo della fattura. Facoltativa altrove.
            'data_delibera_assemblea'    => ['nullable', 'date', Rule::requiredIf(fn () => $this->gestioneStraordinaria() && $this->input('tipo_autorizzazione') !== 'urgenza')],
            // --- [FINE FIX BIVIO] ---

            'gestione_id'          => ['required', 'integer', Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId)
                ->whereIn('id', fn ($q) => $q->select('gestione_id')->from('esercizio_gestione')->where('esercizio_id', $esercizioId))],
            'nome' => [
                'required', 
                'string', 
                'max:255',
                // Rilievo T-J: l'unicità del nome solo fra i piani del condominio dell'indirizzo — con la gestione di un altro,
                // «già in uso» diceva se là esisteva un piano con quel nome.
                Rule::unique('piani_rate')->where(function ($query) {
                    return $query->where('gestione_id', $this->gestione_id)->where('condominio_id', $this->idDelCondominio());
                }),
            ],
            'descrizione'          => ['nullable', 'string'],
            'metodo_distribuzione' => ['required', 'in:prima_rata,tutte_rate,rata_zero'],
            'numero_rate'          => ['required', 'integer'],
            'giorno_scadenza'      => ['nullable', 'integer'],
            // Assente o vuota significa «parte dall'inizio della gestione»: è una scelta, non
            // un dato mancante, e per questo è `nullable` e non `required`.
            'data_prima_scadenza'  => ['nullable', 'date'],
            'note'                 => ['nullable', 'string'],
            
            // Ricorrenza
            'recurrence_enabled'   => ['sometimes', 'boolean'],
            'recurrence_frequency' => ['required_if:recurrence_enabled,1', 'in:WEEKLY,MONTHLY,DAILY,YEARLY'],
            'recurrence_interval'  => ['required_if:recurrence_enabled,1', 'integer', 'min:1'],
            'recurrence_by_day'    => ['nullable', 'array'],
            'recurrence_until'     => ['nullable', 'date'],
            'genera_subito'        => ['sometimes', 'boolean'],
            
            // Assicurati che siano conti validi
            'capitoli_ids'         => 'nullable|array',
            'capitoli_ids.*'       => ['integer', $contoDelCondominio()],
            
            // Validazione della configurazione dettagliata
            'capitoli_config'      => 'nullable|array',
            'capitoli_config.*.id' => ['required', 'integer', $contoDelCondominio()],
            'capitoli_config.*.importo' => 'nullable|string', 
            'capitoli_config.*.note' => 'nullable|string|max:255',

            // B2, S6 (decisione 20): la competenza dichiarata per voce, come tratti di date. Si applica alla
            // riga della pivot del conto indicato, o alle sue foglie se la pivot è nata su quelle (il motore
            // cerca prima il conto, poi la radice). I controlli di forma — dentro l'esercizio, ordinati, senza
            // giorni in comune — stanno in `withValidator`: `InsiemePeriodi` li rifiuterebbe con un'eccezione
            // dentro la generazione, che è il posto sbagliato.
            'competenze_capitoli'                => 'nullable|array',
            'competenze_capitoli.*.conto_id'     => ['required', 'integer', $contoDelCondominio()],
            'competenze_capitoli.*.tratti'       => 'required|array|min:1|max:6',
            'competenze_capitoli.*.tratti.*.dal' => 'required|date',
            'competenze_capitoli.*.tratti.*.al'  => 'required|date',
            
            // Configurazione personalizzata dei saldi (Riparto manuale Art. 63)
            'saldi_config'                             => ['nullable', 'array', $this->ripartoManualeQuadra()],
            'saldi_config.*.saldo_id'                  => ['required', 'integer', Rule::exists('saldi', 'id')->where('condominio_id', $condominioId)],
            'saldi_config.*.ripartizioni'              => ['required', 'array'],
            // Qualunque persona del condominio, come il modulo offre oggi (decisione 44): resta possibile un accordo fra le parti.
            'saldi_config.*.ripartizioni.*.anagrafica_id' => ['required', 'integer', Rule::exists('anagrafiche', 'id')->where(fn ($q) => $q
                // La stessa regola della persona del condominio negli incassi (`StoreIncassoRateRequest::personaDelCondominio()`):
                // associata al condominio o titolare di una sua unità, anche cessata.
                ->whereIn('id', fn ($sub) => $sub->select('anagrafica_id')->from('anagrafica_condominio')->where('condominio_id', $condominioId))
                ->orWhereIn('id', fn ($sub) => $sub->select('anagrafica_immobile.anagrafica_id')->from('anagrafica_immobile')
                    ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')->where('immobili.condominio_id', $condominioId)))],
            'saldi_config.*.ripartizioni.*.importo'    => ['required', 'string'],
        ];
    }

    /** L'id del condominio dell'indirizzo (decisione 44), letto una volta per le regole e per le chiusure. */
    private function idDelCondominio(): int
    {
        return (int) ($this->route('condominio')?->id ?? $this->route('condominio'));
    }

    /**
     * Il riparto manuale di un saldo solidale deve sommare **esattamente** al saldo.
     *
     * Non è pignoleria contabile. `piano_rate_id` viene scritto sull'**intero** saldo quando il
     * piano lo assorbe: la parte non distribuita resta quindi bloccata e non addebitata a
     * nessuno, e nessun avviso la nomina più. È il quarto modo in cui un pregresso poteva
     * sparire in silenzio, dopo i tre chiusi da questa stessa beta.
     *
     * L'avviso c'era già — `PianiRateNew.vue:1308` colora di giallo la somma che non combacia —
     * ma non legava, e un avviso che si può ignorare non è una guardia. Il controllo sta qui e
     * non nell'azione di generazione perché il riparto manuale entra **solo** da questo modulo,
     * alla creazione del piano, ed è il momento in cui l'amministratore ha ancora i suoi numeri
     * davanti: rifiutarlo alla generazione, giorni dopo, sarebbe un rimprovero fuori tempo.
     *
     * Il confronto è in **valore assoluto**: la casella del form vieta il segno meno e la
     * modale mostra il totale da distribuire senza segno, quindi su un saldo a credito
     * l'amministratore digita numeri positivi. Il verso lo rimette il server, in
     * `GenerateSaldiAction`.
     */
    private function ripartoManualeQuadra(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $config) {
                if (empty($config['ripartizioni']) || ! is_array($config['ripartizioni'])) {
                    continue;
                }

                // Un `saldo_id` inesistente, o di un altro condominio, lo segnala già `exists`: qui non si raddoppia
                // l'errore, si tace — e non si legge la cifra di un saldo di un altro condominio (rilievo T11 del giro
                // sulle correzioni della .42: il messaggio della quadratura la mostrava).
                $saldo = \App\Models\Saldo::where('condominio_id', $this->idDelCondominio())->find($config['saldo_id'] ?? null);
                if (! $saldo) {
                    continue;
                }

                $distribuito = 0;
                foreach ($config['ripartizioni'] as $riga) {
                    $distribuito += abs(\App\Helpers\MoneyHelper::toCents($riga['importo'] ?? 0));
                }

                $atteso = abs((int) $saldo->saldo_iniziale);

                // Confronto esatto, in centesimi interi. Una tolleranza qui sarebbe un
                // centesimo che si perde a ogni piano, cioè la cosa che nessuno ritrova più.
                if ($distribuito === $atteso) {
                    continue;
                }

                $scarto = $atteso - $distribuito;

                $fail(sprintf(
                    'Il riparto manuale del pregresso da %s non quadra: hai distribuito %s, '
                    . '%s %s. La differenza resterebbe agganciata al saldo senza essere '
                    . 'addebitata a nessuno, quindi la registrazione si ferma qui.',
                    \App\Helpers\MoneyHelper::format($atteso),
                    \App\Helpers\MoneyHelper::format($distribuito),
                    $scarto > 0 ? 'mancano' : 'ne hai distribuiti in più',
                    \App\Helpers\MoneyHelper::format(abs($scarto))
                ));
            }
        };
    }
}