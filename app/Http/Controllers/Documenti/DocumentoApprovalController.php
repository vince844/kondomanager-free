<?php

namespace App\Http\Controllers\Documenti;

use App\Events\Documenti\NotifyUserOfApprovedDocumento;
use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class DocumentoApprovalController extends Controller
{
    use HandleFlashMessages;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Documento $documento): RedirectResponse
    {
        Gate::authorize('approve', $documento);

          try {

            // Toggle approval status
            $documento->is_approved = !$documento->is_approved;
            // Set publication status to match approval
            $documento->is_published = $documento->is_approved;
            // Save changes
            $documento->save();

            if ($documento->is_approved) {

                try {

                    NotifyUserOfApprovedDocumento::dispatch($documento,  Auth::user());

                } catch (\Exception $emailException) {

                    Log::error('Error sending email for approved documento ID: ' . $documento->id . ' - ' . $emailException->getMessage());

                    return back()->with(
                        $this->flashWarning(__('documenti.error_notify_approved_document'))
                    );
                }

            }

            return back()->with(
                $this->flashSuccess(__('documenti.success_approve_document'))
            );

        } catch (\Throwable $e) {
        
            Log::error('Errore durante l\'aggiornamento dello stato di approvazione del documento ID ' . $documento->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('documenti.error_approve_document'))
            );
        }

    }
}
