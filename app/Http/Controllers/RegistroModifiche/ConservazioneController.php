<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\RegistroModifiche\AccessoAiCasiSpeciali;
use App\Services\RegistroModifiche\ConservazioneDelRegistro;
use App\Services\RegistroModifiche\SospensioneDellaCancellazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per quanto tempo si tengono le righe del registro delle modifiche (§11 del progetto, decisione 518 (1)): lo stato per il componente
 * `ConservazioneDelRegistro.vue` (la durata, «Adesso: 10 anni», «le righe del 2026 si tolgono da sole nel 2036», e con `?condominio=` la sospensione di quel
 * condominio) e il cambio della durata, solo dell'amministratore (la rotta ha il ruolo). JSON: il componente è lo stesso nella pagina del condominio e in
 * quella dello studio, e carica da sé.
 */
class ConservazioneController extends Controller
{
    public function mostra(Request $request): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::puoLeggere($request->user()), 403);

        $stato = ConservazioneDelRegistro::stato(AccessoAiCasiSpeciali::amministratore($request->user()));
        $condominio = $request->integer('condominio');
        if ($condominio > 0 && Condominio::query()->whereKey($condominio)->exists()) {
            $sospensione = SospensioneDellaCancellazione::stato($condominio);
            $stato['sospensione'] = $sospensione === null ? null : ['dal' => $sospensione['dal'], 'motivo' => $sospensione['motivo']];
        }

        return response()->json($stato);
    }

    public function cambia(Request $request): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);

        $dati = $request->validate(['anni' => ['required', 'integer', Rule::in(ConservazioneDelRegistro::DURATE)]], [
            'anni.in' => 'Si sceglie fra 10, 15 e 20 anni.',
        ], ['anni' => 'durata']);

        ConservazioneDelRegistro::cambia((int) $dati['anni']);

        return response()->json(ConservazioneDelRegistro::stato(true));
    }
}
