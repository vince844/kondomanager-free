<?php

namespace App\Listeners\Comunicazioni;

use App\Enums\NotificationType;
use App\Events\Comunicazioni\NotifyUserOfApprovedComunicazione;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Comunicazioni\ApprovedComunicazioneNotification;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
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

            // Coda 302 (e 198): la platea è quella di `DestinatariNotifica`, la stessa dell'elenco
            // del portale: le persone collegate se è indirizzata a qualcuno, i condomìni collegati
            // se non lo è a nessuno, l'autore e le persone collegate se è privata. Il creatore
            // riceve l'avviso «approvata» qui sopra.
            $platea = app(DestinatariNotifica::class)->perModello($comunicazione);

            if ($platea->isNotEmpty()) {
                $query = Anagrafica::whereIn('id', $platea);

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
