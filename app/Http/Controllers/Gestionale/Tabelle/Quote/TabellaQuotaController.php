<?php

namespace App\Http\Controllers\Gestionale\Tabelle\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Tabella\Quota\UpdateQuoteRequest;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TabellaQuotaController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    /**
     * La tabella dell'indirizzo deve appartenere al condominio dell'indirizzo.
     *
     * ⚠️ **Il binding implicito risolve i due modelli per id, ciascuno per conto suo**: niente
     * lega `{tabella}` a `{condominio}`. Senza questa guardia, aprendo la tabella di un condominio
     * sotto l'indirizzo di un altro, la pagina mostrava le quote **vere** della tabella e le unità
     * del condominio **dell'indirizzo**; salvando, il primo statement della transazione cancella
     * le quote reali (`whereNotIn(...)->delete()`) e ci scrive sopra unità estranee.
     *
     * ⚠️ `->scopeBindings()` **non** avrebbe chiuso questo caso da solo, e oggi non è applicabile:
     * Laravel deriva il nome della relazione con `Str::plural(Str::camel(...))`, cioè cercherebbe
     * `Condominio::tabellas()`. Vedi la coda ㊷ in `docs/roadmap.md`. La guardia a mano è la stessa
     * forma già usata in `CassaController`, `SaldoInizialeController` e `ControlliPostImportController`.
     */
    private function assicuraTabellaDelCondominio(Condominio $condominio, Tabella $tabella): void
    {
        abort_unless($tabella->condominio_id === $condominio->id, 404);
    }

    /**
     * Il millesimo non compilato resta **vuoto**; lo zero resta **zero**.
     *
     * Il middleware `ConvertEmptyStringsToNull` trasforma già la casella vuota in `null` prima
     * della validazione, quindi qui arriva `null`. La stringa vuota è comunque prevista: la
     * colonna è `decimal(12,5)` e MySQL in modalità stretta risponde 1366 a `''`.
     */
    private static function valoreOppureNulla(mixed $valore): ?string
    {
        if ($valore === null || (is_string($valore) && trim($valore) === '')) {
            return null;
        }

        return (string) $valore;
    }

    /**
     * Display the millesimi (quota) distribution for a specific tabella.
     *
     * This method shows the quota distribution table for a condominio, displaying
     * the millesimi (thousandths) allocation across all immobili (properties).
     * It provides both the current quota distribution from the specified tabella
     * and the complete list of immobili for reference and management purposes.
     *
     * The method is used to visualize and manage how condominio expenses are
     * distributed among properties based on their millesimi allocation.
     *
     * @param Condominio $condominio The condominio instance (from route binding)
     * @param Tabella $tabella The specific quota table instance (from route binding) to display
     * 
     * @return Response Returns an Inertia.js response rendering the quota distribution list
     * 
     * @uses Condominio To access the condominio's immobili and context
     * @uses Tabella To access the specific quota table and its millesimi distribution
     * 
     * @example
     * // Typical request: GET /condomini/1/tabelle/5/quote
     * // Returns the quota distribution for table ID 5 in condominio ID 1
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio or tabella not found
     * 
     * @data_retrieval
     * - Millesimi: Quota distribution with related immobile, palazzina, and scala data
     * - Immobili: Complete list of properties with essential details for reference
     * - Esercizio: Current financial period for navigation context
     * 
     * @relationships
     * - Millesimi are loaded with immobile->palazzina and immobile->scala relationships
     * - Immobili are loaded with palazzina and scala relationships
     * - Provides hierarchical property structure (condominio -> palazzina -> scala -> immobile)
     * 
     * @frontend_data
     * - condominio: Current condominio context
     * - esercizio: Current active financial period for navigation
     * - tabella: The specific quota table being displayed
     * - millesimi: Quota distribution data with property relationships
     * - immobili: Complete list of properties for reference and management
     */
    public function index(Condominio $condominio, Tabella $tabella): Response
    {
        $this->assicuraTabellaDelCondominio($condominio, $tabella);

        $millesimi = $tabella->quote()->with('immobile.palazzina', 'immobile.scala')->get();

        // ⚠️ La `select()` porta anche **tipologia e pertinenza**, che prima restavano fuori: sono
        // i criteri con cui la schermata raggruppa le unità per associarle in blocco, e un criterio
        // non si può offrire su un dato che non è arrivato. Le due informazioni esistevano già in
        // `millesimi[].immobile` (il modello intero) e mancavano solo qui, cioè proprio nell'elenco
        // delle unità **non ancora associate** — le uniche su cui la scelta ha senso.
        $immobili = $condominio->immobili()
            ->with(['palazzina', 'scala', 'tipologiaImmobile:id,nome,categoria'])
            ->select(
                'id',
                'nome',
                'interno',
                'piano',
                'superficie',
                'palazzina_id',
                'scala_id',
                'tipologia_id',
                'pertinenza_di_immobile_id',
            )
            ->orderBy('nome')
            ->get();

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/tabelle/quote/QuoteList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'tabella'    => $tabella,
            'millesimi'  => $millesimi->values(),
            'immobili'   => $immobili,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateQuoteRequest $request, Condominio $condominio, Tabella $tabella): RedirectResponse
    {
        $this->assicuraTabellaDelCondominio($condominio, $tabella);

        $validated = $request->validated();
        $quotes = $validated['quote'] ?? [];
        $createdBy = $validated['created_by'];
        $updatedBy = $validated['updated_by'];

        DB::transaction(function () use ($quotes, $tabella, $createdBy, $updatedBy) {

            // Cancella le righe che non sono più presenti nel form
            $idsPresenti = collect($quotes)
                ->pluck('id')
                ->filter()
                ->toArray();

            $tabella->quote()
                ->whereNotIn('id', $idsPresenti)
                ->delete();

            foreach ($quotes as $q) {
                $data = [
                    'immobile_id' => $q['immobile']['id'] ?? null,
                    // ⚠️ **NULL si conserva, non diventa 0.** Era `$q['valore'] ?? 0`, e con la
                    // regola `required` non si notava perché il valore vuoto non arrivava mai fin
                    // qui. Dalla beta.61 arriva, e collassarlo a zero distruggerebbe l'unica cosa
                    // che la beta introduce: la differenza fra «non ancora compilato» e «non
                    // partecipa». Il motore le tratta già come la stessa cosa (`$valore <= 0.0` →
                    // salta la riga); è la colonna a doverle tenere distinte, perché è da lì che
                    // l'avviso alla generazione legge.
                    'valore'      => self::valoreOppureNulla($q['valore'] ?? null),
                    'updated_by'  => $updatedBy,
                ];

                // ⚠️ **Qui si scrivevano cinque coefficienti che nessuno leggeva** (tolti nella
                // beta.50). Sulle tabelle di tipo `acqua` il modulo chiedeva `has_contatore` e
                // `ultima_lettura`; su quelle di tipo `riscaldamento` `coeff_dispersione`,
                // `quota_fissa` e `quota_variabile`. Venivano validati, salvati nella colonna
                // `coefficienti` — e **il motore di riparto non li apriva mai**: zero occorrenze
                // in `CalcoloQuoteService` e `RipartoTabelleService`, che ripartiscono su
                // `valore` come per qualunque altra tabella.
                //
                // Non era un'etichetta che mente: era un modulo che raccoglieva letture dei
                // contatori e ripartiva ignorandole. Un amministratore che le compilava credeva
                // di ripartire a consumo e ripartiva a millesimi.
                //
                // ⚠️ **I due tipi di tabella restano, e non sono il difetto.** Una tabella
                // `tipo = acqua` con unità di misura `mtcubi`, dove si scrivono i metri cubi di
                // ciascuna unità, **è già una ripartizione a consumo che funziona** — il motore
                // normalizza su `valore / somma dei valori`. Ciò che mancava era la gestione dei
                // contatori e la quota fissa/variabile della UNI 10200, cioè il modulo previsto
                // per la v1.15 (`water_metering_module.md`).

                // Aggiornamento o creazione
                if (!empty($q['id'])) {
                    if ($record = $tabella->quote()->find($q['id'])) {
                        $record->update($data);
                    }
                } elseif (!empty($q['immobile']['id'])) {
                    $tabella->quote()->create($data + ['created_by' => $createdBy]);
                }
            }
        });

        return to_route('admin.gestionale.tabelle.index', [
            'condominio' => $condominio->id,
            'tabella'    => $tabella->id,
        ])->with($this->flashSuccess(__('gestionale.success_update_quote_tabella')));

    }

}
