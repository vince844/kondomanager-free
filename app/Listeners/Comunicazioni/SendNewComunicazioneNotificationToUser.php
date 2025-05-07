<?php

namespace App\Listeners\Comunicazioni;

use App\Enums\NotificationType;
use App\Events\Comunicazioni\NotifyUserOfCreatedComunicazione;
use App\Models\Anagrafica;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewComunicazioneNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    public function handle(NotifyUserOfCreatedComunicazione $event): void
    {
        try {
            $query = !empty($event->validated['anagrafiche'])
                ? Anagrafica::whereIn('id', $event->validated['anagrafiche'])
                : Anagrafica::whereHas('condomini', fn ($q) =>
                    $q->whereIn('condominio_id', $event->validated['condomini_ids'])
                );

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_COMMUNICATION->value)
                ->get()
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
