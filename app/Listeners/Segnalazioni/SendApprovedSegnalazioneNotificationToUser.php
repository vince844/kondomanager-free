<?php

namespace App\Listeners\Segnalazioni;

use App\Enums\NotificationType;
use App\Events\Segnalazioni\NotifyUserOfApprovedSegnalazione;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Segnalazioni\ApprovedSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendApprovedSegnalazioneNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * Handle the event.
     */
    public function handle(NotifyUserOfApprovedSegnalazione $event): void
    {
        $segnalazione = $event->segnalazione;
        $user = $event->user;

        try {
            $creator = $segnalazione->createdBy;

            if ($creator && $this->isUserEligibleForNotification($creator, NotificationType::APPROVED_TICKET->value)) {
                Notification::send($creator, new ApprovedSegnalazioneNotification($segnalazione, $user));
            }

            if (!$segnalazione->is_private && $segnalazione->is_approved) {

                  $query = Anagrafica::with('condomini')
                    ->whereHas('condomini', function ($query) use ($segnalazione) {
                        $query->where('condominio_id', $segnalazione->condominio_id);
                    });

                $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_TICKET->value)
                    ->get()
                    ->unique('email');

                if ($creator !== null) {
                    $anagrafiche = $anagrafiche->reject(fn ($a) => $a->user_id === $creator->id);
                }

                $anagrafiche = $anagrafiche->values();

                if ($anagrafiche->isNotEmpty()) {
                    Notification::send($anagrafiche, new NewSegnalazioneNotification($segnalazione));
                }
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfApprovedComunicazione error for segnalazione ID {$segnalazione->id}: " . $e->getMessage());
        }

    }

    private function isUserEligibleForNotification(User $user, string $notificationType): bool
    {
        return $this->filterByNotificationPreference(
            User::where('id', $user->id),
            $notificationType
        )->exists();
    }
}
