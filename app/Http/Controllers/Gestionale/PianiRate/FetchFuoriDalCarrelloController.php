<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Gestionale\CarrelloDelloStraordinario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Coda 686 (a), decisioni 506 e 510: quando il carrello della scheda «Spese fuori preventivo» è vuoto, la pagina dice che cosa non mostra e dove andare —
 * le fatture della gestione su una voce del piano dei conti, quelle «Fuori preventivo» di un altro esercizio, quelle già per intero in un altro piano.
 * La pagina lo chiede solo a carrello vuoto. Sola lettura.
 */
class FetchFuoriDalCarrelloController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request, CarrelloDelloStraordinario $carrello): JsonResponse
    {
        $request->validate([
            'gestione_id'  => ['required', 'integer', Rule::exists('gestioni', 'id')->where('condominio_id', $condominio->id)],
            'esercizio_id' => ['required', 'integer', Rule::exists('esercizi', 'id')->where('condominio_id', $condominio->id)],
        ]);

        return response()->json($carrello->fuoriDalCarrello((int) $condominio->id, (int) $request->input('gestione_id'), (int) $request->input('esercizio_id')));
    }
}
