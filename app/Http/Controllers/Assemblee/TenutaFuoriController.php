<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\TenutaFuoriRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * «Tenuta su carta» (R16, decisione 618; nel codice `TenutaFuori…`): l'assemblea preparata nel programma e tenuta su carta diventa registrata a mano con i suoi punti; per i punti
 * trattati nasce la delibera, quelli senza esito restano «non trattati» e i loro memo tornano in attesa. Gli id dei punti sono quelli di questa assemblea:
 * il servizio legge solo i suoi.
 */
class TenutaFuoriController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(TenutaFuoriRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $suoi = $assemblea->punti()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $esiti = array_intersect_key($request->esiti(), array_flip($suoi));
        StatiDellAssemblea::tenutaFuoriDalProgramma($assemblea, $request->user(), $esiti, (string) $request->validated('giorno'),
            $request->filled('numero_verbale') ? (int) $request->validated('numero_verbale') : null);
        $delibere = count($esiti);

        return back()->with($this->flashSuccess('Assemblea tenuta su carta: ' . ($delibere === 1 ? '1 delibera registrata' : $delibere . ' delibere registrate')
            . '. Le altre le registri dopo, sul loro punto.'));
    }
}
