<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use Illuminate\Validation\Rule;

/**
 * «Prepara l'assemblea» (§3 P2): il tipo, per chi (anche i condòmini di una tabella segnata «di un gruppo», Coda 734: allora la tabella è il gruppo), le
 * date, dove, come si partecipa, la tabella del valore, i punti proposti spuntati e i punti scritti dall'amministratore. I proposti arrivano come le loro chiavi: il server rilegge le proposte e prende solo quelle (R18: niente si fida del corpo della
 * richiesta). I punti scritti (`punti`) hanno le regole, i nomi dei campi e le frasi di «Aggiungi un punto» ({@see PuntoRequest}): un errore torna sul suo
 * punto (`punti.1.testo`) e l'assemblea non nasce. Le date le controlla il servizio con la frase della pagina (R11). Con `aggiorna` ogni campo è
 * facoltativo.
 */
class PreparaAssembleaRequest extends RichiestaDelleAssemblee
{
    /** L'assemblea dei condòmini di un gruppo senza una tabella segnata (Coda 734, §1.1-bis del progetto della tappa 3). */
    public const FRASE_DEL_GRUPPO = 'Scegli una tabella segnata «di un gruppo»: è lei che dice chi è convocato.';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obbligo = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'tipo' => [$obbligo, Rule::in(TipoAssemblea::valori())],
            'ambito' => [$obbligo, Rule::in(AmbitoAssemblea::valori())],
            'palazzina_id' => ['nullable', 'integer', 'required_if:ambito,palazzina'],
            'scala_id' => ['nullable', 'integer', 'required_if:ambito,scala'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'modalita' => ['sometimes', Rule::in(ModalitaAssemblea::valori())],
            'piattaforma' => ['nullable', 'string', 'max:255'],
            'consenso_videoconferenza_il' => ['nullable', 'date_format:Y-m-d'],
            'consenso_videoconferenza_modo' => ['nullable', 'string', 'max:255'],
            // Per i condòmini di un gruppo la tabella del valore è il gruppo (Coda 734): senza, l'assemblea non sa chi convocare.
            'tabella_id' => ['nullable', 'integer', 'required_if:ambito,gruppo'],
            // «Segna come tabella di proprietà generale»: solo per il condominio intero e solo se il condominio non ne ha già una.
            'segna_di_proprieta' => ['sometimes', 'boolean'],
            // L'affissione dell'avviso per la modifica della destinazione d'uso (art. 1117-ter: almeno trenta giorni consecutivi).
            'affissione_dal' => ['nullable', 'date_format:Y-m-d'],
            'affissione_al' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:affissione_dal'],
            'sedute' => [$obbligo, 'array', 'min:1'],
            'sedute.*.convocazione' => ['required', Rule::in(['prima', 'seconda'])],
            'sedute.*.numero' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sedute.*.giorno' => ['required', 'date_format:Y-m-d'],
            'sedute.*.ora' => ['required', 'date_format:H:i'],
            'sedute.*.luogo' => ['nullable', 'string', 'max:255'],
            'proposte' => ['sometimes', 'array'],
            'proposte.*' => ['string', 'max:191'],
            // L'ordine dei punti: le chiavi dei proposti e `scritto:N` per il punto scritto N; il server tiene solo quelle che conosce.
            'ordine' => ['sometimes', 'array', 'max:100'],
            'ordine.*' => ['string', 'max:191'],
            'maggioranze' => ['sometimes', 'array'],
            'maggioranze.*' => ['nullable', Rule::in(Maggioranza::valori())],
            // I punti scritti in questa pagina, come li manda la finestra del punto.
            'punti' => ['sometimes', 'array', 'max:50'],
            'punti.*' => ['array'],
            ...PuntoRequest::regoleDelPunto('required', 'punti.*.'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tipo' => 'il tipo dell\'assemblea',
            'ambito' => 'per chi è l\'assemblea',
            'palazzina_id' => 'la palazzina',
            'scala_id' => 'la scala',
            'luogo' => 'dove si tiene',
            'modalita' => 'come si partecipa',
            'piattaforma' => 'la piattaforma',
            'consenso_videoconferenza_il' => 'il giorno del consenso alla videoconferenza',
            'consenso_videoconferenza_modo' => 'come è stato dato il consenso',
            'tabella_id' => 'la tabella del valore',
            'affissione_dal' => 'il primo giorno dell\'affissione',
            'affissione_al' => 'l\'ultimo giorno dell\'affissione',
            'sedute' => 'le date dell\'avviso',
            'sedute.*.convocazione' => 'la convocazione',
            'sedute.*.numero' => 'il numero della data di prosecuzione',
            'sedute.*.giorno' => 'il giorno della convocazione',
            'sedute.*.ora' => 'l\'ora della convocazione',
            'sedute.*.luogo' => 'dove si tiene quella data',
            'proposte' => 'i punti spuntati',
            'ordine' => 'l\'ordine dei punti',
            'maggioranze.*' => 'la maggioranza',
            'punti' => 'i punti scritti da te',
            ...PuntoRequest::nomiDeiCampi('punti.*.'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'sedute.required' => 'Scrivi almeno la prima convocazione.',
            'sedute.min' => 'Scrivi almeno la prima convocazione.',
            'sedute.*.ora.date_format' => 'L\'ora della convocazione si scrive così: 18:30.',
            'sedute.*.ora.required' => 'Scrivi l\'ora della convocazione.',
            'sedute.*.giorno.required' => 'Scrivi il giorno della convocazione.',
            'palazzina_id.required_if' => 'Scegli la palazzina.',
            'tabella_id.required_if' => self::FRASE_DEL_GRUPPO,
            'scala_id.required_if' => 'Scegli la scala.',
            'affissione_al.after_or_equal' => 'L\'affissione finisce dopo il giorno in cui comincia.',
            'punti.max' => 'Al massimo 50 punti scritti per volta: gli altri li aggiungi nella pagina dell\'assemblea.',
            ...PuntoRequest::paroleDegliErrori('punti.*.'),
        ];
    }

    /** L'ordine dei punti come la pagina l'ha mandato (le chiavi dei proposti e `scritto:N`), o `null` se non c'è: allora l'ordine è quello di sempre. @return list<string>|null */
    public function ordine(): ?array
    {
        return $this->has('ordine') ? array_values(array_map('strval', (array) $this->validated('ordine', []))) : null;
    }

    /** Le sedute nella forma del servizio: `fissata_il` con il giorno e l'ora come si leggono. @return list<array<string, mixed>> */
    public function sedute(): array
    {
        return array_map(fn (array $s) => ['convocazione' => $s['convocazione'], 'numero' => (int) ($s['numero'] ?? 1) ?: 1,
            'fissata_il' => $s['giorno'] . ' ' . $s['ora'], 'luogo' => $s['luogo'] ?? null], array_values((array) $this->validated('sedute', [])));
    }

    /**
     * I punti scritti, nell'ordine della pagina, con i campi di «Aggiungi un punto» ({@see PuntoRequest::campiDelPunto()}: l'importo in centesimi, niente
     * fonte, chiave, memo né oggetti dalla richiesta). La palazzina, la scala e la tabella le risolve il controller nel condominio.
     *
     * @return list<array<string, mixed>>
     */
    public function puntiScritti(): array
    {
        return array_map(fn (array $p) => PuntoRequest::campiDelPunto($p), array_values((array) $this->validated('punti', [])));
    }
}
