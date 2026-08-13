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

        $millesimi = $tabella->quote()->with('immobile.palazzina', 'immobile.scala')->get();

        $immobili = $condominio->immobili()
            ->with(['palazzina', 'scala']) 
            ->select('id', 'nome', 'interno', 'piano', 'superficie', 'palazzina_id', 'scala_id')
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
                    'valore'      => $q['valore'] ?? 0,
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
