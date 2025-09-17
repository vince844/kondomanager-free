<?php

namespace App\Http\Controllers\Gestionale\Tabelle\Quote;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TabellaQuotaController extends Controller
{
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
            'millesimi'  => $millesimi,
            'immobili'   => $immobili,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Condominio $condominio, Tabella $tabella)
    {
        $quotes = $request->input('quote', []);

        // Validazione generica
        $request->validate([
            'quote.*.immobile.id' => 'required|exists:immobili,id',
            'quote.*.valore' => 'required|numeric',
            'quote.*.has_contatore' => 'boolean',
            'quote.*.ultima_lettura' => 'nullable|numeric',
            'quote.*.coeff_dispersione' => 'nullable|numeric',
            'quote.*.quota_fissa' => 'nullable|numeric',
            'quote.*.quota_variabile' => 'nullable|numeric',
        ]);

        DB::transaction(function () use ($quotes, $tabella) {
            foreach ($quotes as $q) {
                $user = Auth::user();
                $data = [
                    'immobile_id' => $q['immobile']['id'] ?? null,
                    'valore'      => $q['valore'] ?? 0,
                    'updated_by'  => $user->id,
                ];

                // Coefficienti in base al tipo tabella
                if ($tabella->tipo === 'acqua') {
                    $data['coefficienti'] = [
                        'has_contatore' => $q['has_contatore'] ?? false,
                        'ultima_lettura' => ($q['has_contatore'] ?? false)
                            ? ($q['ultima_lettura'] ?? 0)
                            : null,
                    ];
                }

                if ($tabella->tipo === 'riscaldamento') {
                    $data['coefficienti'] = [
                        'coeff_dispersione' => $q['coeff_dispersione'] ?? 0,
                        'quota_fissa'       => $q['quota_fissa'] ?? null,
                        'quota_variabile'   => $q['quota_variabile'] ?? null,
                    ];
                }

                // Aggiorna o crea il record
                if (!empty($q['id'])) {
                    $record = $tabella->quote()->find($q['id']);
                    if ($record) {
                        $record->update($data);
                    }
                } elseif (!empty($q['immobile']['id'])) {
                    $user = Auth::user();
                    $tabella->quote()->create($data + ['created_by' =>$user->id]);
                }
            }
        });

        return redirect()
            ->back()
            ->with('success', 'Millesimi aggiornati correttamente.');
    }



}
