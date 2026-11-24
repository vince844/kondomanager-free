<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Comunicazioni\Utenti;

use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use App\Support\Comunicazioni\LettureComunicazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ComunicazionePresaVisioneController extends Controller
{
    /**
     * Registra la presa visione di una comunicazione da parte del condòmino.
     */
    public function __invoke(Request $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('show', $comunicazione);

        Gate::denyIf(
            ! (bool) $comunicazione->chiedi_presa_visione
            || ! LettureComunicazione::isDestinatario($comunicazione, $request->user())
        );

        LettureComunicazione::confermaPresaVisione($comunicazione, $request->user());

        return back();
    }
}
