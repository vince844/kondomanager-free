<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Events\Comunicazioni\NotifyUserOfApprovedComunicazione;
use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

class ComunicazioneApprovalController extends Controller
{
    use HandleFlashMessages;

    /**
     * Toggles the approval status of a given Comunicazione and handles related notifications.
     *
     * This method performs the following actions:
     * - Authorizes the user to approve the comunicazione using the 'approve' policy.
     * - Toggles the `is_approved` flag of the comunicazione and saves the change.
     * - If the comunicazione is now approved, it attempts to notify the creator and related users 
     *   by calling the `sendUserComunicazioneApproved` method from the ComunicazioneNotificationService.
     * - If an exception occurs during the notification process, a warning is shown and the error is logged.
     * - If an exception occurs during the update itself, an error is shown and the issue is logged.
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione instance whose approval status is to be toggled.
     * @return \Illuminate\Http\RedirectResponse Redirect response with a success, warning, or error message.
     */
    public function __invoke(Comunicazione $comunicazione): RedirectResponse
    {

        Gate::authorize('approve', $comunicazione);

        try {

            $comunicazione->is_approved = !$comunicazione->is_approved;
            $comunicazione->save();

            if ($comunicazione->is_approved) {

                try {

                    NotifyUserOfApprovedComunicazione::dispatch($comunicazione,  Auth::user());

                } catch (\Exception $emailException) {

                    Log::error('Error sending email for approved comunicazione ID: ' . $comunicazione->id . ' - ' . $emailException->getMessage());

                    return back()->with(
                        $this->flashWarning(__('comunicazioni.error_notify_approved_communication'))
                    );
                }

            }

            return back()->with(
                $this->flashSuccess(__('comunicazioni.success_approve_communication'))
            );

        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione della comunicazione ID ' . $comunicazione->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('comunicazioni.error_approve_communication'))
            );
        }
            
    }
    
}
