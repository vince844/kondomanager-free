<?php

namespace App\Listeners\Documenti;

use App\Enums\NotificationType;
use App\Events\Documenti\NotifyUserOfCreatedDocumento;
use App\Models\Anagrafica;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Services\Notifiche\DestinatariNotifica;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewDocumentoNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * La platea è quella di `DestinatariNotifica`, letta dall'archivio e non dai dati del modulo:
     * un documento in bozza o in moderazione non avvisa nessuno (Coda 198); l'avviso
     * parte alla prima pubblicazione, dalla modifica o dall'approvazione. La mail parte solo per
     * ciò che il destinatario può aprire.
     */
    public function handle(NotifyUserOfCreatedDocumento $event): void
    {
        try {
            $platea = app(DestinatariNotifica::class)->perModello($event->documento);

            if ($platea->isEmpty()) {
                return;
            }

            $query = Anagrafica::whereIn('id', $platea);

            // Chi lo crea non riceve l'annuncio del proprio (Coda 302): la creazione dal pannello passa di
            // qui, e chi ha anche un'anagrafica nel palazzo era nella platea. L'approvazione e la
            // creazione dal portale lo escludono già (`SendApprovedDocumentoNotificationToUser`).
            $creatore = $event->documento->created_by;

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_ARCHIVE_DOCUMENT->value)
                ->get()
                ->reject(fn (Anagrafica $a) => $creatore !== null && (int) $a->user_id === (int) $creatore)
                ->unique('email')
                ->values();

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewDocumentoNotification($event->documento));
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfNewDocumento error for documento ID {$event->documento->id}: " . $e->getMessage());
        }
    }
}
