<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\RatificaRequest;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\RatificaDelloSforo;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * La ratifica dello sforo con la delibera dell'assemblea (R12, decisione 561): la fattura in sforo, o già sbloccata per l'urgenza con il motivo, si lega
 * alla delibera scelta e prende il giorno della riunione, non quello del clic. Il pagamento per l'urgenza resta `fatture.approva-sforo`, com'è. La delibera
 * è del condominio (R18).
 */
class RatificaController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(RatificaRequest $request, Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        abort_unless((int) $fattura->condominio_id === (int) $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        /** @var \App\Models\Assemblee\Delibera $delibera */
        $delibera = SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $request->validated('delibera_id'));
        RatificaDelloSforo::ratifica($fattura, $delibera, $request->validated('nota'), $request->user());
        $avviso = RatificaDelloSforo::avvisoDellaData($fattura, $delibera);

        return back()->with($this->flashSuccess('Fattura n. ' . $fattura->numero_documento . ' ratificata con la ' . lcfirst(DeliberaInParole::breve($delibera)) . '.'
            . ($avviso !== null ? ' ' . $avviso : '')));
    }
}
