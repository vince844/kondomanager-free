<?php

namespace App\Listeners\Comunicazioni;

use App\Enums\NotificationType;
use App\Events\Comunicazioni\NotifyUserOfApprovedComunicazione;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Comunicazioni\ApprovedComunicazioneNotification;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendApprovedComunicazioneNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    public function handle(NotifyUserOfApprovedComunicazione $event): void
    {
        $comunicazione = $event->comunicazione;
        $user = $event->user;

        try {
            $creator = $comunicazione->createdBy;

            if ($creator && $this->isUserEligibleForNotification($creator, NotificationType::APPROVED_COMMUNICATION->value)) {
                Notification::send($creator, new ApprovedComunicazioneNotification($comunicazione, $user));
            }

            if (!$comunicazione->is_private && $comunicazione->is_approved) {
                $condominioIds = $comunicazione->condomini->pluck('id')->toArray();

                $query = Anagrafica::whereHas('condomini', fn ($q) =>
                    $q->whereIn('condominio_id', $condominioIds)
                );

                $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_COMMUNICATION->value)
                    ->get()
                    ->unique('email');

                if ($creator !== null) {
                    $anagrafiche = $anagrafiche->reject(fn ($a) => $a->user_id === $creator->id);
                }

                $anagrafiche = $anagrafiche->values();

                if ($anagrafiche->isNotEmpty()) {
                    Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));
                }
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfApprovedComunicazione error for comunicazione ID {$comunicazione->id}: " . $e->getMessage());
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
