<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Sala;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\Sala\GestiDellaSalaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Sala\GestiDellaSala;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La sala dell'assemblea: la pagina (Inertia, «Vede le assemblee» in sola lettura), lo stato della sala in JSON per la lettura periodica e per la pagina
 * riaperta, e **i gesti** (`POST`, JSON, «Gestisce le assemblee»): il lotto di un dispositivo, applicato in ordine da {@see GestiDellaSala}. I gesti sono
 * richieste, non visite: una visita non si mette in coda né si ripete con lo stesso numero. In ogni metodo la guardia del condominio dell'assemblea.
 */
class SalaController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function __construct(private readonly LetturaDellaSala $lettura) {}

    /** La pagina della sala. */
    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return Inertia::render('gestionale/assemblee/Sala', [
            'condominio' => ['id' => (int) $condominio->id, 'nome' => $condominio->nome],
            'condomini' => $this->getCondomini(),
            // Il menu del gestionale in alto porta alle pagine dell'esercizio: senza, i collegamenti vanno a «esercizi/undefined/…».
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'sala' => fn () => $this->lettura->perLaPagina($assemblea, $request->user()),
        ]);
    }

    /** Lo stato della sala, in JSON: la stessa forma della prop `sala`. */
    public function stato(Request $request, Condominio $condominio, Assemblea $assemblea): JsonResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return response()->json($this->lettura->perLaPagina($assemblea, $request->user()));
    }

    /** I gesti di un dispositivo: `{ora_del_server, risposte}`, una risposta per gesto, nell'ordine. */
    public function gesti(GestiDellaSalaRequest $request, Condominio $condominio, Assemblea $assemblea): JsonResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $dati = $request->validated();
        $risposte = app(GestiDellaSala::class)->ricevi($assemblea, (string) $dati['dispositivo'], array_values((array) $dati['gesti']), $request->user());

        return response()->json(['ora_del_server' => now()->utc()->toIso8601ZuluString(), 'risposte' => $risposte]);
    }
}
