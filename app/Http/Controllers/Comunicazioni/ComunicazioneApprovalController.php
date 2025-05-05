<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use App\Services\ComunicazioneNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;

class ComunicazioneApprovalController extends Controller
{
     /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicazioneNotificationService 
     */
    public function __construct(
        private ComunicazioneNotificationService $notificationService
    ) {}

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

                    $this->notificationService->sendUserComunicazioneApproved($comunicazione);

                } catch (\Exception $emailException) {

                    Log::error('Error sending email for approved comunicazione ID: ' . $comunicazione->id . ' - ' . $emailException->getMessage());

                    return to_route('admin.comunicazioni.index')->with([
                        'message' => [
                            'type'    => 'warning',
                            'message' => "La comunicazione è stata approvata, ma si è verificato un errore nell'invio della notifica!"
                        ]
                    ]);
                }

            }

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "Lo stato di approvazione della comunicazione è stato aggiornato con successo"
                ]
            ]);

        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione della comunicazione ID ' . $comunicazione->id . ': ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di aprovare la comunicazione"
                ]
            ]);
        }
            
    }
}
