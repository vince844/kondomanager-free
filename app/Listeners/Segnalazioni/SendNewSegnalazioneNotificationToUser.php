<?php

namespace App\Listeners\Segnalazioni;

use App\Enums\NotificationType;
use App\Events\Segnalazioni\NotifyUserOfCreatedSegnalazione;
use App\Models\Anagrafica;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
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
     * This method retrieves all users associated with the same "condominio" as the new
     * segnalazione, filters them by their notification preferences for new tickets,
     * ensures email uniqueness, and sends them a notification. Errors are logged.
     *
     * @param NotifyUserOfCreatedSegnalazione $event The event containing the newly created segnalazione.
     *
     * @return void
     */
    public function handle(NotifyUserOfCreatedSegnalazione $event): void
    {
        try {

            $query = Anagrafica::with('condomini')
            ->whereHas('condomini', function ($query) use ($event) {
                $query->where('condominio_id', $event->segnalazione->condominio_id);
            });

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_TICKET->value)
                ->get()
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
