<?php

namespace App\Listeners\Segnalazioni;

use App\Enums\NotificationType;
use App\Events\Segnalazioni\NotifyUserOfCreatedSegnalazione;
use App\Models\Anagrafica;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendNewSegnalazioneNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;
    
    /**
     * Handle the event of a new "Segnalazione" being created by notifying eligible users.
     *
     * La platea è quella di `DestinatariNotifica` (Code 302 e 198): chi trova la segnalazione
     * nell'elenco del portale. Nessuno se è una bozza; le persone collegate se è indirizzata a
     * qualcuno; il condominio solo se non lo è e non è privata. Poi il filtro delle preferenze e
     * dei permessi.
     *
     * @param NotifyUserOfCreatedSegnalazione $event The event containing the newly created segnalazione.
     *
     * @return void
     */
    public function handle(NotifyUserOfCreatedSegnalazione $event): void
    {
        try {

            $platea = app(DestinatariNotifica::class)->perModello($event->segnalazione);

            if ($platea->isEmpty()) {
                return;
            }

            $query = Anagrafica::whereIn('id', $platea);

            // Chi lo crea non riceve l'annuncio della propria (Coda 302): la creazione dal pannello passa di
            // qui, e chi ha anche un'anagrafica nel palazzo era nella platea. L'approvazione e la
            // creazione dal portale lo escludono già (`SendApprovedSegnalazioneNotificationToUser`).
            $creatore = $event->segnalazione->created_by;

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_TICKET->value)
                ->get()
                ->reject(fn (Anagrafica $a) => $creatore !== null && (int) $a->user_id === (int) $creatore)
                ->unique('email')
                ->values();

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewSegnalazioneNotification($event->segnalazione));
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfNewSegnalazione error for segnalazione ID {$event->segnalazione->id}: " . $e->getMessage());
        }

    }
}
