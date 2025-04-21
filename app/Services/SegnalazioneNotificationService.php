<?php

namespace App\Services;

use App\Models\Segnalazione;
use App\Models\User;
use App\Models\Anagrafica;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewAdminSegnalazioneNotification;
use App\Notifications\NewSegnalazioneNotification;
use Illuminate\Support\Facades\Log;

class SegnalazioneNotificationService
{
    /**
     * Send notifications based on segnalazione approval status.
     *
     * @param  \App\Models\Segnalazione  $segnalazione
     * @return void
     */
    public function sendAdminNotifications(Segnalazione $segnalazione): void
    {
        try {
            // Eager load related roles and users to avoid N+1 query issue
            $admins = User::role(['amministratore', 'collaboratore'])
                ->with('roles')  // assuming 'roles' is the relation for user roles
                ->get();

            // If approved, notify admins + related anagrafiche
            if ($segnalazione->is_approved) {
                // Eager load related condomini for anagrafiche
                $anagrafiche = Anagrafica::with('condomini')
                    ->whereHas('condomini', function ($query) use ($segnalazione) {
                        $query->where('condominio_id', $segnalazione->condominio_id);
                    })->get();

                $recipients = $admins->merge($anagrafiche);

                // Queue notifications for better performance
                Notification::send($recipients, new NewSegnalazioneNotification($segnalazione));

                Log::info("Notifications sent to admins and related anagrafiche for segnalazione ID: {$segnalazione->id}");
            } else {
                // Not approved yet — notify only admins
                Notification::send($admins, new NewAdminSegnalazioneNotification($segnalazione));
            }
        } catch (\Exception $e) {
            Log::error("Error sending notifications for segnalazione ID: {$segnalazione->id} - " . $e->getMessage());
        }
    }

    /**
     * Send user notifications (related anagrafiche) for segnalazione.
     *
     * @param array $validated
     * @param \App\Models\Segnalazione $segnalazione
     * @return void
     */
    public function sendUserNotifications(array $validated, Segnalazione $segnalazione): void
    {
        try {
            // Fetch anagrafiche with eager loading of related condomini and their data
            $anagrafiche = !empty($validated['anagrafiche'])
                ? Anagrafica::with('condomini')  // Eager load condomini relation
                    ->whereIn('id', $validated['anagrafiche'])
                    ->get()
                : Anagrafica::with('condomini')  // Eager load condomini relation
                    ->whereHas('condomini', function ($query) use ($validated) {
                        $query->where('condominio_id', $validated['condominio_id']);
                    })->get();

            // Remove duplicates by email
            $anagrafiche = $anagrafiche->unique('email')->values();

            // Queue notifications to anagrafiche if available
            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewSegnalazioneNotification($segnalazione));
            }
        } catch (\Exception $e) {
            Log::error("Error sending user notifications for segnalazione ID: {$segnalazione->id} - " . $e->getMessage());
        }
    }
}
