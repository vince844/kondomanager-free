<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;

class ComunicazioneApprovalController extends Controller
{
    /**
     * Toggle the approval status of a Comunicazione and redirect back with a status message.
     *
     * This method inverts the `is_approved` flag on the given Comunicazione instance.
     * If the update is successful, a success message is flashed to the session.
     * If an error occurs, it is logged and an error message is flashed instead.
     *
     * @param \App\Models\Comunicazione $comunicazione The communication model whose approval status is to be toggled.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page with a success or error flash message.
     */
    public function __invoke(Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('approve', $comunicazione);

        try {

            $comunicazione->is_approved = !$comunicazione->is_approved;
            $comunicazione->save();

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
