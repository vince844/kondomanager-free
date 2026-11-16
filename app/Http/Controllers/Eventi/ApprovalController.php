<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ApprovalController extends Controller
{
     use HandleFlashMessages;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Evento $evento): RedirectResponse
    {
        Gate::authorize('approve', $evento);

        try {

            // Toggle approval status
            $evento->is_approved = !$evento->is_approved;
            // Save changes
            $evento->save();

            return back()->with(
                $this->flashSuccess(__('eventi.success_approve_event'))
            );

        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione evento ID ' . $evento->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('eventi.error_approve_event'))
            );
        }
    }
}
