<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Events\Segnalazioni\NotifyUserOfApprovedSegnalazione;
use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class SegnalazioneApprovalController extends Controller
{
    use HandleFlashMessages;

    /**
     * Toggles the approval status of a Segnalazione and notifies the user if approved.
     *
     * Authorizes the current user to approve the Segnalazione, then updates its approval status.
     * If approved, it dispatches a notification to the user. Exceptions during status update
     * or email notification are logged and handled with appropriate flash messages.
     *
     * @param \App\Models\Segnalazione $segnalazione The segnalazione entity to be approved or unapproved.
     *
     * @return \Illuminate\Http\RedirectResponse Redirect response with a success or error message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to approve the segnalazione.
     */
    public function __invoke(Segnalazione $segnalazione): RedirectResponse
    {

        Gate::authorize('approve', $segnalazione);
         
        try {

            // Toggle approval status
            $segnalazione->is_approved = !$segnalazione->is_approved;
            // Set publication status to match approval
            $segnalazione->is_published = $segnalazione->is_approved;
            // Save changes
            $segnalazione->save();

            if ($segnalazione->is_approved) {

                try {

                    NotifyUserOfApprovedSegnalazione::dispatch($segnalazione,  Auth::user());

                } catch (\Exception $emailException) {

                    Log::error('Error sending email for approved ticket ID: ' . $segnalazione->id . ' - ' . $emailException->getMessage());

                    return back()->with(
                        $this->flashWarning(__('segnalazioni.error_notify_approved_ticket'))
                    );
                }

            }

            return back()->with(
                $this->flashSuccess(__('segnalazioni.success_approve_ticket'))
            );

        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione della segnalazione ID ' . $segnalazione->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('segnalazioni.error_approve_ticket'))
            );
        }

    }
}
