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
     * Toggles the approval status of a Comunicazione and notifies the user if approved.
     *
     * Authorizes the user to approve the Comunicazione, flips the approval status,
     * and if approved, dispatches a notification to the user. Handles and logs
     * any exceptions that occur during the update or notification process.
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione entity to be approved or unapproved.
     *
     * @return \Illuminate\Http\RedirectResponse A redirect response with success or error flash messages.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to approve the comunicazione.
     */
    public function __invoke(Comunicazione $comunicazione): RedirectResponse
    {

        Gate::authorize('approve', $comunicazione);

        try {

            // Toggle approval status
            $comunicazione->is_approved = !$comunicazione->is_approved;
            // Set publication status to match approval
            $comunicazione->is_published = $comunicazione->is_approved;
            // Save changes
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

             $message = $comunicazione->is_approved
                ? $this->flashSuccess(__('comunicazioni.success_approve_communication'))
                : $this->flashWarning(__('comunicazioni.success_disapprove_communication'));

            return back()->with($message);


        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione della comunicazione ID ' . $comunicazione->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('comunicazioni.error_approve_communication'))
            );
        }
            
    }
    
}
