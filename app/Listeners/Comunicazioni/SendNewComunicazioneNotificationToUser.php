<?php

namespace App\Listeners\Comunicazioni;

use App\Enums\NotificationType;
use App\Events\Comunicazioni\NotifyUserOfCreatedComunicazione;
use App\Models\Anagrafica;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewComunicazioneNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * La platea è quella di `DestinatariNotifica`, letta dall'archivio e non dai dati del modulo:
     * una bozza o una comunicazione in moderazione non avvisa nessuno (Coda 198); l'avviso
     * parte alla prima pubblicazione, dalla modifica o dall'approvazione. La mail parte solo per
     * ciò che il destinatario può aprire.
     */
    public function handle(NotifyUserOfCreatedComunicazione $event): void
    {
        try {
            $platea = app(DestinatariNotifica::class)->perModello($event->comunicazione);

            if ($platea->isEmpty()) {
                return;
            }

            $query = Anagrafica::whereIn('id', $platea);

            // Chi lo crea non riceve l'annuncio della propria (Coda 302): la creazione dal pannello passa di
            // qui, e chi ha anche un'anagrafica nel palazzo era nella platea. L'approvazione e la
            // creazione dal portale lo escludono già (`SendApprovedComunicazioneNotificationToUser`).
            $creatore = $event->comunicazione->created_by;

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_COMMUNICATION->value)
                ->get()
                ->reject(fn (Anagrafica $a) => $creatore !== null && (int) $a->user_id === (int) $creatore)
                ->unique('email')
                ->values();

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewComunicazioneNotification($event->comunicazione));
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfNewComunicazione error for comunicazione ID {$event->comunicazione->id}: " . $e->getMessage());
        }
    }
}
