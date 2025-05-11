<?php

namespace App\Listeners\Segnalazioni;

use App\Enums\NotificationType;
use App\Events\Segnalazioni\NotifyAdminOfCreatedSegnalazione;
use App\Models\Anagrafica;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\ApproveSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;

class SendNewSegnalazioneNotificationToAdmin implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * Handle the NotifyAdminOfCreatedSegnalazione event.
     *
     * This method notifies administrators and potentially other users (Anagrafiche)
     * when a new Segnalazione is created, based on its approval and privacy status.
     * 
     * - If the Segnalazione is approved and private, only admins are notified.
     * - If it's approved and public, both admins and eligible Anagrafiche are notified.
     * - If not approved, admins receive a notification to approve the Segnalazione.
     * 
     * All notifications respect individual users' notification preferences.
     *
     * @param  \App\Events\NotifyAdminOfCreatedSegnalazione  $event The event containing the Segnalazione and validated data.
     * @return void
     */
    public function handle(NotifyAdminOfCreatedSegnalazione $event): void
    {
        try {

            $segnalazione = $event->segnalazione;

            $adminQuery = User::permission('Accesso pannello amministratore');

            $admins = $this->filterByNotificationPreference(
                $adminQuery,
                NotificationType::NEW_TICKET->value
            )->get();

            if ($segnalazione->is_approved) {

                if ($segnalazione->is_private) {

                    Notification::send($admins, new NewSegnalazioneNotification($segnalazione));

                } else {

                    $anagrafiche = $this->getAnagrafiche($segnalazione);

                    $recipients = $admins->keyBy('email')
                        ->union($anagrafiche)
                        ->values();

                    Notification::send($recipients, new NewSegnalazioneNotification($segnalazione));
                }

            } else {

                Notification::send($admins, new ApproveSegnalazioneNotification($segnalazione));
                
            }
        
        } catch (\Exception $e) {
            Log::error("NotifyAdminsOfNewSegnalazione error for comunicazione ID {$event->segnalazione->id}: " . $e->getMessage());
        }
    }

    /**
     * Retrieve a filtered collection of Anagrafica records related to the given Segnalazione.
     *
     * This method selects all Anagrafica entries associated with the same Condominio
     * as the Segnalazione, filters them by notification preferences for the NEW_TICKET type,
     * removes the creator of the Segnalazione, and ensures uniqueness by email.
     *
     * @param  \App\Models\Segnalazione $segnalazione The Segnalazione instance to base the filtering on.
     * @return \Illuminate\Support\Collection A collection of filtered Anagrafica models.
     */
    private function getAnagrafiche(Segnalazione $segnalazione): Collection
    {

        $anagraficheQuery = Anagrafica::with('condomini')
            ->whereHas('condomini', function ($query) use ($segnalazione) {
                $query->where('condominio_id', $segnalazione->condominio_id);
            });

        $filtered = $this->filterByNotificationPreference($anagraficheQuery, NotificationType::NEW_TICKET->value)->get();

        return $filtered
            ->reject(fn ($a) => $a->user_id === $segnalazione->created_by)
            ->unique('email')
            ->values();
   
    }
}
