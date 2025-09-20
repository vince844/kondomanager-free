<?php

namespace App\Http\Controllers\Gestionale\Tabelle\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Tabella\Quota\UpdateQuoteRequest;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TabellaQuotaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio, Tabella $tabella): Response
    {

        $millesimi = $tabella->quote()->with('immobile.palazzina', 'immobile.scala')->get();

        // Tutti gli immobili del condominio
        $immobili = $condominio->immobili()
            ->with(['palazzina', 'scala']) 
            ->select('id', 'nome', 'interno', 'piano', 'superficie', 'palazzina_id', 'scala_id')
            ->orderBy('nome')
            ->get();

        return Inertia::render('gestionale/tabelle/quote/QuoteList', [
            'condominio' => $condominio,
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

                // Coefficienti acqua
                if ($tabella->tipo === 'acqua') {
                    $data['coefficienti'] = [
                        'has_contatore'  => $q['has_contatore'] ?? false,
                        'ultima_lettura' => ($q['has_contatore'] ?? false) ? ($q['ultima_lettura'] ?? 0) : null,
                    ];
                }

                // Coefficienti riscaldamento
                if ($tabella->tipo === 'riscaldamento') {
                    $data['coefficienti'] = [
                        'coeff_dispersione' => $q['coeff_dispersione'] ?? 0,
                        'quota_fissa'       => $q['quota_fissa'] ?? null,
                        'quota_variabile'   => $q['quota_variabile'] ?? null,
                    ];
                }

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
