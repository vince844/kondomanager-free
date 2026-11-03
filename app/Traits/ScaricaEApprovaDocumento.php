<?php

namespace App\Traits;

use App\Events\Documenti\NotifyUserOfApprovedDocumento;
use App\Models\Documento;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Scaricare un documento e cambiarne l'approvazione: lo stesso lavoro dall'archivio (`Documenti\DocumentoController`,
 * `DocumentoApprovalController`) e dalle pagine dei proprietari, il fornitore e l'unità. Chi usa il trait ha già deciso
 * il permesso e che il documento è della sua pagina.
 *
 * Richiede `HandleFlashMessages` nella classe che lo usa.
 */
trait ScaricaEApprovaDocumento
{
    /**
     * Il file del documento, con il nome di scaricamento di `Documento::nomeDiScaricamento()`.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
     */
    protected function scaricaIlDocumento(Documento $documento)
    {
        try {

            if (! app(ArchivioDocumenti::class)->esiste($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found'))
                );
            }

            return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());

        } catch (\Exception $e) {

            Log::error('Error downloading documento archivio', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_downloading_document'))
            );
        }
    }

    /**
     * Approvato diventa non approvato e viceversa; la pubblicazione segue l'approvazione, e chi ha caricato il documento
     * riceve l'avviso dell'approvazione.
     */
    protected function alternaLApprovazione(Documento $documento): RedirectResponse
    {
        try {

            $documento->is_approved = ! $documento->is_approved;
            $documento->is_published = $documento->is_approved;
            $documento->save();

            if ($documento->is_approved) {

                try {

                    NotifyUserOfApprovedDocumento::dispatch($documento, Auth::user());

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
