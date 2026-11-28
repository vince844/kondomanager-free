<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\Conto\SalvaLettureDiFineAnnoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Services\Gestionale\LettureDiFineAnno;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;

/**
 * Le letture di fine anno di una voce, nel riquadro della pagina del piano dei conti: si leggono quando si apre la voce (una alla volta, non con la
 * pagina), si scrivono e si tolgono. Non toccano le rate né la voce: valgono per il conguaglio, quindi si scrivono anche con il piano approvato; in un
 * esercizio chiuso no.
 */
class LettureDiFineAnnoController extends Controller
{
    public function show(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): JsonResponse
    {
        // Il binding della rotta tiene la voce nel piano dei conti e il piano nell'esercizio; il condominio dell'indirizzo si guarda qui.
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id && (int) $pianoConto->condominio_id === (int) $condominio->id, 404);
        return response()->json(LettureDiFineAnno::perLaVoce($conto, $esercizio));
    }

    public function update(SalvaLettureDiFineAnnoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        // Il binding della rotta tiene la voce nel piano dei conti e il piano nell'esercizio; il condominio dell'indirizzo si guarda qui.
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id && (int) $pianoConto->condominio_id === (int) $condominio->id, 404);
        if (($esercizio->stato ?? null) === 'chiuso') {
            return back()->withErrors(['letture' => LettureDiFineAnno::ESERCIZIO_CHIUSO]);
        }
        try {
            LettureDiFineAnno::salva($conto, $esercizio, $request->validated());
        } catch (UniqueConstraintViolationException) {
            // Il blocco della voce mette in fila due salvataggi; se un altro salvataggio scrive lo stesso le sue righe, la pagina lo dice e non si rompe.
            return back()->withErrors(['letture' => LettureDiFineAnno::SALVATE_INSIEME]);
        }

        return back()->with('message', ['message' => 'Letture di fine anno salvate: il conguaglio di «'.$conto->nome.'» le usa.', 'type' => 'success']);
    }

    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        // Il binding della rotta tiene la voce nel piano dei conti e il piano nell'esercizio; il condominio dell'indirizzo si guarda qui.
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id && (int) $pianoConto->condominio_id === (int) $condominio->id, 404);
        if (($esercizio->stato ?? null) === 'chiuso') {
            return back()->withErrors(['letture' => LettureDiFineAnno::ESERCIZIO_CHIUSO]);
        }
        LettureDiFineAnno::togli($conto, $esercizio);

        return back()->with('message', ['message' => 'Letture di fine anno tolte: il conguaglio di «'.$conto->nome.'» torna alle percentuali della voce.', 'type' => 'success']);
    }
}
