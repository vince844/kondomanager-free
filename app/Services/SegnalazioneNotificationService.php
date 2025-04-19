<?php

namespace App\Services;

use App\Models\Segnalazione;
use App\Models\User;
use App\Models\Anagrafica;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewAdminSegnalazioneNotification;
use App\Notifications\NewSegnalazioneNotification;
 
class SegnalazioneNotificationService
{
    /**
     * Send notifications based on segnalazione approval status.
     *
     * @param  \App\Models\Segnalazione  $segnalazione
     * @return void
     */
    public function notify(Segnalazione $segnalazione): void
    {
        // Get admin and collaborator users
        $admins = User::role(['amministratore', 'collaboratore'])->get();

        // If approved, notify admins + related anagrafiche
        if ($segnalazione->is_approved) {
            $anagrafiche = Anagrafica::whereHas('condomini', function ($query) use ($segnalazione) {
                $query->where('condominio_id', $segnalazione->condominio_id);
            })->get();

            $recipients = $admins->merge($anagrafiche);

            Notification::send($recipients, new NewSegnalazioneNotification($segnalazione));

        } else {
            // Not approved yet — notify only admins
            Notification::send($admins, new NewAdminSegnalazioneNotification($segnalazione));
        }
    }
}
