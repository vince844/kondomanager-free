<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Models\Anagrafica;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\AssembleeDellaPersona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La linguetta «Assemblee» nella scheda della persona (decisione 651): le assemblee in cui è convocata, con i filtri per condominio e per anno e l'elenco a
 * pagine, tutto lato server. **Solo lo studio**, con «Visualizza anagrafiche» e «Vede le assemblee»; la persona non la vede nel portale. Si legge e basta.
 */
class AnagraficaAssembleeController extends Controller
{
    public function index(Request $request, Anagrafica $anagrafica): Response
    {
        Gate::authorize('view', $anagrafica);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()));

        $filtri = $request->validate([
            'condominio' => ['nullable', 'integer'],
            'anno' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $elenco = AssembleeDellaPersona::perLaLinguetta($anagrafica, $request->user(), [
            'condominio' => isset($filtri['condominio']) ? (int) $filtri['condominio'] : null,
            'anno' => isset($filtri['anno']) ? (int) $filtri['anno'] : null,
        ], (int) ($filtri['page'] ?? 1), (int) ($filtri['per_page'] ?? 20));

        return Inertia::render('anagrafiche/AnagraficheAssemblee', [
            // La prop `anagrafica` serve al layout delle schede: senza, la barra sparisce.
            'anagrafica' => new AnagraficaResource($anagrafica),
            'riepilogo' => $elenco['riepilogo'],
            'assemblee_riepilogo' => ['totale' => $elenco['riepilogo']['totale']],
            'righe' => $elenco['righe'],
            'meta' => $elenco['meta'],
            'condomini' => $elenco['condomini'],
            'anni' => $elenco['anni'],
            'filtri' => ['condominio' => isset($filtri['condominio']) ? (int) $filtri['condominio'] : null, 'anno' => isset($filtri['anno']) ? (int) $filtri['anno'] : null],
        ]);
    }
}
