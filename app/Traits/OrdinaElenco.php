<?php

namespace App\Traits;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * L'ordinamento di un elenco paginato, deciso dal database e non dalla pagina visibile.
 *
 * ## Il difetto che questo trait esiste per chiudere
 *
 * Le tabelle del gestionale hanno la **paginazione lato server** (`manualPagination: true`) e
 * l'**ordinamento lato client**: la libreria riceve dieci righe e ordina diligentemente quelle.
 * Cliccando «Importo ↓» su un elenco di cinquanta, l'amministratore vede il più alto **dei primi
 * dieci** presentato come il più alto. È un difetto che non dà errore e non lascia traccia: la
 * schermata è coerente con sé stessa e risponde il falso.
 *
 * Segnalato sul forum il 16/08/2026 da un amministratore che l'aveva capito da solo —
 * *«se ho 50 record ma la visualizzazione è filtrata per 10 alla volta l'ordinamento si applica
 * soltanto su quei 10»* — e verificato: **26 tabelle** con paginazione server, **zero** che
 * mandassero l'ordinamento al server.
 *
 * ## Perché una lista di colonne consentite, e non il nome che arriva dal browser
 *
 * `orderBy()` interpola il nome della colonna nella query. Passarci un valore che viene dalla
 * richiesta è un'iniezione, punto. La lista non è quindi una comodità: è il confine.
 *
 * Serve anche a un secondo scopo, meno ovvio e altrettanto importante. Molte colonne
 * dell'interfaccia **non sono campi**: «Ubicazione» monta palazzina, scala e piano; «Soggetti»
 * contiene un elenco di persone. Dichiarare esplicitamente cosa si può ordinare costringe a
 * decidere, colonna per colonna, invece di lasciare che il database ci provi.
 *
 * ## Uso
 *
 * ```php
 * $q = $condominio->immobili();
 * $this->ordina($q, $validated, [
 *     'nome'    => 'nome',                       // colonna diretta
 *     'catasto' => 'codice_catasto',             // nome a video diverso dal campo
 * ], 'nome');
 * ```
 *
 * Le regole di validazione si prendono da `regoleOrdinamento()`, così la richiesta e il
 * controller non possono divergere sull'elenco delle colonne ammesse.
 */
trait OrdinaElenco
{
    /**
     * Applica l'ordinamento chiesto, se è fra quelli consentiti.
     *
     * ⚠️ **L'ordinamento secondario non è un dettaglio estetico.** Ordinando per un campo con
     * valori ripetuti — uno stato, una tipologia — MySQL non garantisce l'ordine fra le righe
     * pari merito, e può restituirle in ordine diverso a ogni pagina: la stessa riga comparirebbe
     * a pagina 1 e a pagina 2, e un'altra da nessuna parte. La chiave primaria come secondo
     * criterio rende la paginazione stabile.
     *
     * ⚠️ **`reorder()` prima di ordinare, e non è pignoleria.** Diverse query arrivano qui con un
     * `orderBy` già applicato — `created_at desc` nei servizi di comunicazioni, segnalazioni e
     * documenti, `data_registrazione desc` negli incassi. Senza azzerarlo, l'ordinamento chiesto
     * dall'utente non diventa il criterio principale ma **il secondo**: `ORDER BY created_at DESC,
     * subject ASC, id ASC`. Con date distinte — cioè sempre — il primo criterio decide da solo e la
     * colonna scelta non ha alcun effetto. A video la freccetta si accende lo stesso, perché il
     * server rimanda `sort` fra le props: la schermata dichiara un ordinamento che non ha applicato.
     *
     * È lo stesso difetto per cui questa beta esiste, in una forma che nessuno avrebbe cercato.
     * Sta qui e non nei nove punti chiamanti perché chi chiama `ordina()` sta dicendo «l'ordine lo
     * decidi tu»: se restasse un ordine precedente sarebbe una risposta a metà, e il prossimo
     * elenco che nasce con un `orderBy` di comodo ricadrebbe nella stessa trappola.
     *
     * ⚠️ **Una voce può essere una colonna oppure una sottoquery.** «Gestione» in un elenco di
     * piani rate non è un campo: è il nome della gestione collegata. Ordinare per relazione senza
     * `join()` si fa con una sottoquery correlata — `Gestione::select('nome')->whereColumn(...)` —
     * che evita le collisioni di nomi che una join porta con sé quando due tabelle hanno entrambe
     * `nome`, e non moltiplica le righe se la relazione è a molti.
     *
     * @param  array<string, string|\Closure>  $consentite  chiave a video => colonna o sottoquery
     */
    protected function ordina(
        Builder $query,
        array $validated,
        array $consentite,
        ?string $predefinita = null,
        string $versoPredefinito = 'asc',
    ): Builder {
        $chiave = $validated['sort'] ?? $predefinita;
        $verso = ($validated['direction'] ?? $versoPredefinito) === 'desc' ? 'desc' : 'asc';

        if ($chiave === null || ! array_key_exists($chiave, $consentite)) {
            return $predefinita !== null && array_key_exists($predefinita, $consentite)
                ? $query->reorder()->orderBy($this->espressione($consentite[$predefinita]), $versoPredefinito)->orderBy('id')
                : $query;
        }

        return $query->reorder()->orderBy($this->espressione($consentite[$chiave]), $verso)->orderBy('id');
    }

    /** Una colonna resta tale; una sottoquery si costruisce al momento dell'uso. */
    private function espressione(string|\Closure $voce): mixed
    {
        return $voce instanceof \Closure ? $voce() : $voce;
    }

    /**
     * Le regole di validazione per i due parametri, da unire a quelle della richiesta.
     *
     * @param  list<string>  $consentite  le chiavi ammesse, cioè quelle di `ordina()`
     * @return array<string, array<int, mixed>>
     */
    protected static function regoleOrdinamento(array $consentite): array
    {
        return [
            'sort'      => ['sometimes', 'nullable', 'string', Rule::in($consentite)],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
