<?php

namespace App\Listeners\Comunicazioni;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Events\Comunicazioni\NotifyAdminOfCreatedComunicazione;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\Comunicazioni\ApproveComunicazioneNotification;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewComunicazioneNotificationToAdmin implements ShouldQueue
{
    use FilterByNotificationPreference;

    public function handle(NotifyAdminOfCreatedComunicazione $event): void
    {
        try {

            $comunicazione = $event->comunicazione;
            $validated = $event->validated;

            $adminQuery = $comunicazione->is_approved
                ? User::permission(Permission::APPROVE_COMUNICAZIONI->value)
                : User::permission(Permission::PUBLISH_COMUNICAZIONI->value);

            $admins = $this->filterByNotificationPreference(
                $adminQuery,
                NotificationType::NEW_COMMUNICATION->value
            )->get();

            if ($comunicazione->is_approved) {

                if ($comunicazione->is_private) {

                    Notification::send($admins, new NewComunicazioneNotification($comunicazione));

                } else {

                    $anagrafiche = $this->getAnagrafiche($comunicazione, $validated);

                    $recipients = $admins->keyBy('email')
                        ->union($anagrafiche)
                        ->values();

                    Notification::send($recipients, new NewComunicazioneNotification($comunicazione));

                }

            } else {

                Notification::send($admins, new ApproveComunicazioneNotification($comunicazione));
                
            }

        } catch (\Exception $e) {
            Log::error("NotifyAdminsOfNewComunicazione error for comunicazione ID {$event->comunicazione->id}: " . $e->getMessage());
        }
    }

    /**
     * La platea è quella di `DestinatariNotifica` (Coda 302), letta dall'archivio: la stessa
     * dell'elenco del portale, senza il creatore.
     */
    private function getAnagrafiche(Comunicazione $comunicazione, array $validated): Collection
    {
        $query = Anagrafica::whereIn('id', app(DestinatariNotifica::class)->perModello($comunicazione));

        $filtered = $this->filterByNotificationPreference($query, NotificationType::NEW_COMMUNICATION->value)->get();
        
        return $filtered
            ->reject(fn ($a) => $a->user_id === $validated['created_by'])
            ->unique('email')
            ->values();
    }
}
