<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Services\RegistroModifiche\Ripristino\RipristinoDiUnCampo;
use App\Services\RegistroModifiche\Ripristino\RipristinoNonPossibile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Ripristina» dalla «Storia» (§8 e P3 del progetto della tappa 1): l'anteprima per la finestra della bozza («Rimettere l'email di prima?», «Adesso … · Torna
 * a …», la frase sul registro degli invii) e il ripristino. Tutte e due chiedono il permesso della storia (mai i ruoli `utente` e `fornitore`) e, dentro
 * {@see RipristinoDiUnCampo}, il permesso di modificare quella scheda. Quando non si può, un 422 con la frase; quando la scheda non passa le regole della
 * pagina, il 422 della validazione con il messaggio della pagina.
 */
class RipristinoController extends Controller
{
    public function anteprima(Request $request, RegistroModifica $riga): JsonResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeLaStoria($request->user()), __('errors.403_message'));
        $campo = $request->validate(['campo' => ['required', 'string', 'max:100']])['campo'];

        try {
            return response()->json(RipristinoDiUnCampo::anteprima($riga, $campo, $request->user()));
        } catch (RipristinoNonPossibile $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function ripristina(Request $request, RegistroModifica $riga): JsonResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeLaStoria($request->user()), __('errors.403_message'));
        $campo = $request->validate(['campo' => ['required', 'string', 'max:100']])['campo'];

        try {
            RipristinoDiUnCampo::ripristina($riga, $campo, $request->user());
        } catch (RipristinoNonPossibile $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $c = RipristinoDiUnCampo::campoDellaRiga($riga, $campo);

        return response()->json(['message' => 'Rimesso: «' . $c['etichetta'] . '» torna a ' . ($c['prima'] !== null ? '«' . $c['prima'] . '»' : 'vuoto')
            . '. Il ripristino è una riga del registro, con il tuo nome.']);
    }
}
