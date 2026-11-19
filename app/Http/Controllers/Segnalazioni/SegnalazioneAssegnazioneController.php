<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Segnalazioni\AssegnazioneSegnalazioni;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SegnalazioneAssegnazioneController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('assegna', $segnalazione);

        // P6 (Fase 1-bis della beta.12): il rifiuto con le parole della pagina, non con il nome della colonna (si legge sotto il menu «Assegnata a»).
        $validated = $request->validate([
            'assigned_to' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ], [
            'assigned_to.integer' => __('segnalazioni.assignment.not_found'),
            'assigned_to.exists' => __('segnalazioni.assignment.not_found'),
        ]);

        $nuovoId = $validated['assigned_to'] !== null ? (int) $validated['assigned_to'] : null;

        if ($nuovoId === null) {
            AssegnazioneSegnalazioni::assegna($segnalazione, null, $request->user());

            return back()->with($this->flashSuccess(__('segnalazioni.assignment.unassigned')));
        }

        $destinatario = User::findOrFail($nuovoId);

        if ($segnalazione->assigned_to !== null && (int) $segnalazione->assigned_to === $nuovoId) {
            AssegnazioneSegnalazioni::assegna($segnalazione, $destinatario, $request->user());

            return back()->with($this->flashSuccess(__('segnalazioni.assignment.assigned', ['name' => $destinatario->name])));
        }

        if (! AssegnazioneSegnalazioni::puoRicevere($destinatario)) {
            throw ValidationException::withMessages([
                'assigned_to' => __('segnalazioni.assignment.not_assignable'),
            ]);
        }

        AssegnazioneSegnalazioni::assegna($segnalazione, $destinatario, $request->user());

        return back()->with($this->flashSuccess(__('segnalazioni.assignment.assigned', ['name' => $destinatario->name])));
    }
}

