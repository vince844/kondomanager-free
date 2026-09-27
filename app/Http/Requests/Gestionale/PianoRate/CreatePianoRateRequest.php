<?php

namespace App\Http\Requests\Gestionale\PianoRate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePianoRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** La natura della gestione scelta: `gestioni.tipo`, non `piani_rate.tipo` (decisione 11). */
    public function gestioneStraordinaria(): bool
    {
        $id = $this->input('gestione_id');

        return $id !== null && \App\Models\Gestione::whereKey($id)->value('tipo') === 'straordinaria';
    }

    public function messages(): array
    {
        return [
            // La coda su «Urgenza» solo dove quella scelta esiste a video: il carrello del piano straordinario (verifica S6, R4).
            'data_delibera_assemblea.required' => 'Indica la data della delibera dell\'assemblea: su una gestione straordinaria è il giorno che decide chi paga (art. 63 disp. att. c.c.).'
                . ($this->input('tipo') === 'straordinario' ? ' Con un intervento d\'urgenza scegli «Urgenza» e dichiara la competenza sulla fattura.' : ''),
            'competenze_capitoli.*.tratti.*.dal.required' => 'Ogni tratto di competenza vuole la data di inizio.',
            'competenze_capitoli.*.tratti.*.al.required' => 'Ogni tratto di competenza vuole la data di fine.',
        ];
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

            foreach ($this->input('competenze_capitoli', []) as $i => $voce) {
                $tratti = collect($voce['tratti'] ?? [])
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
        });
    }

    private function giorno(string $iso): string
    {
        return \Carbon\CarbonImmutable::parse($iso)->format('d/m/Y');
    }

    public function rules(): array
    {
        return [

            // --- [INIZIO FIX BIVIO ORDINARIO/STRAORDINARIO] ---
            'tipo' => ['required', 'string', 'in:ordinario,straordinario'],
            
            // Regole specifiche per il Carrello Fatture (Straordinario)
            'fatture_config' => [
                'required_if:tipo,straordinario', 
                'array',
                function ($attribute, $value, $fail) {
                    if ($this->tipo === 'straordinario' && (empty($value) || count($value) === 0)) {
                        $fail('Devi selezionare almeno una fattura per creare un piano straordinario.');
                    }
                },
            ],
            'fatture_config.*.id'      => ['required_with:fatture_config', 'exists:fatture_passive,id',
                // Il carrello esclude le stornate solo quando si carica: stornata nel frattempo (un'altra scheda) o con una
                // richiesta costruita a mano, la fattura entrava nel piano e le rate la chiedevano (verifica delle
                // correzioni della Fase 1-bis, 1.11.0-beta.35).
                function ($attribute, $value, $fail) {
                    $f = \App\Models\Gestionale\FatturaPassiva::find($value);
                    $stato = $f ? (is_object($f->stato_pagamento) ? $f->stato_pagamento->value : $f->stato_pagamento) : null;
                    if ($f && (($f->dati_extra['is_stornata'] ?? false) || $stato === 'stornata')) {
                        $fail("La fattura n. {$f->numero_documento} è stata stornata nel frattempo: toglila dal carrello.");
                    }
                },
            ],
            'fatture_config.*.importo' => ['required_with:fatture_config'],
            
            // Scudo Legale (Obbligatorio per Art. 1135 c.c. se straordinario)
            'tipo_autorizzazione'        => ['nullable', 'required_if:tipo,straordinario', 'in:delibera,urgenza'],
            'motivazione_autorizzazione' => ['nullable', 'required_if:tipo,straordinario', 'string', 'min:5'],
            // B2 (decisione 12): la data della delibera è **obbligatoria, senza default**, quando la GESTIONE è
            // straordinaria (è la natura che decide, decisione 11 — non `tipo`) e l'autorizzazione non è
            // l'urgenza: è il giorno che fa la competenza dello straordinario (Cass. 24654/2010) e il motore
            // senza si ferma. Con `urgenza` la competenza va dichiarata sulla fattura. Facoltativa altrove.
            'data_delibera_assemblea'    => ['nullable', 'date', Rule::requiredIf(fn () => $this->gestioneStraordinaria() && $this->input('tipo_autorizzazione') !== 'urgenza')],
            // --- [FINE FIX BIVIO] ---

            'gestione_id'          => ['required', 'exists:gestioni,id'],
            'nome' => [
                'required', 
                'string', 
                'max:255',
                Rule::unique('piani_rate')->where(function ($query) {
                    return $query->where('gestione_id', $this->gestione_id);
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
            'capitoli_ids.*'       => 'exists:conti,id', 
            
            // Validazione della configurazione dettagliata
            'capitoli_config'      => 'nullable|array',
            'capitoli_config.*.id' => 'required|exists:conti,id',
            'capitoli_config.*.importo' => 'nullable|string', 
            'capitoli_config.*.note' => 'nullable|string|max:255',

            // B2, S6 (decisione 20): la competenza dichiarata per voce, come tratti di date. Si applica alla
            // riga della pivot del conto indicato, o alle sue foglie se la pivot è nata su quelle (il motore
            // cerca prima il conto, poi la radice). I controlli di forma — dentro l'esercizio, ordinati, senza
            // giorni in comune — stanno in `withValidator`: `InsiemePeriodi` li rifiuterebbe con un'eccezione
            // dentro la generazione, che è il posto sbagliato.
            'competenze_capitoli'                => 'nullable|array',
            'competenze_capitoli.*.conto_id'     => 'required|integer|exists:conti,id',
            'competenze_capitoli.*.tratti'       => 'required|array|min:1|max:6',
            'competenze_capitoli.*.tratti.*.dal' => 'required|date',
            'competenze_capitoli.*.tratti.*.al'  => 'required|date',
            
            // Configurazione personalizzata dei saldi (Riparto manuale Art. 63)
            'saldi_config'                             => ['nullable', 'array', $this->ripartoManualeQuadra()],
            'saldi_config.*.saldo_id'                  => ['required', 'exists:saldi,id'],
            'saldi_config.*.ripartizioni'              => ['required', 'array'],
            'saldi_config.*.ripartizioni.*.anagrafica_id' => ['required', 'exists:anagrafiche,id'],
            'saldi_config.*.ripartizioni.*.importo'    => ['required', 'string'],
        ];
    }

    /**
     * Il riparto manuale di un saldo solidale deve sommare **esattamente** al saldo.
     *
     * Non è pignoleria contabile. `piano_rate_id` viene scritto sull'**intero** saldo quando il
     * piano lo assorbe: la parte non distribuita resta quindi bloccata e non addebitata a
     * nessuno, e nessun avviso la nomina più. È il quarto modo in cui un pregresso poteva
     * sparire in silenzio, dopo i tre chiusi da questa stessa beta.
     *
     * L'avviso c'era già — `PianiRateNew.vue:1303` colora di giallo la somma che non combacia —
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

                // Un `saldo_id` inesistente lo segnala già `exists`: qui non si raddoppia
                // l'errore, si tace.
                $saldo = \App\Models\Saldo::find($config['saldo_id'] ?? null);
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