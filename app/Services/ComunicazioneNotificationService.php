<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\NewAdminComunicazioneNotification;
use App\Notifications\NewComunicazioneNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class ComunicazioneNotificationService
{
    public function sendUserNotifications(array $validated, Comunicazione $comunicazione) {

        $anagrafiche = !empty($validated['anagrafiche'])
            ? Anagrafica::whereIn('id', $validated['anagrafiche'])->get()
            : Anagrafica::whereHas('condomini', function ($query) use ($validated) {
                $query->where('condominio_id', $validated['condomini_ids']);
            })->get();
        
        // Apply unique filter and reassign it properly
        $anagrafiche = $anagrafiche->unique('email')->values();
        
        if ($anagrafiche->isNotEmpty()) {
            Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));

        }
        
    }

    public function sendAdminNotifications(Comunicazione $comunicazione) {

        try {

            // Eager load related roles and users to avoid N+1 query issue
            $admins = User::role(['amministratore', 'collaboratore'])
                ->with('roles')  
                ->get();
            
            if ($comunicazione->is_approved && $comunicazione->is_private) {
                Log::info("Sending notification to admins for approved/private comunicazione ID: {$comunicazione->id}");
                Notification::send($admins, new NewComunicazioneNotification($comunicazione));
            }

            if (!$comunicazione->is_approved && $comunicazione->is_private) {
                Notification::send($admins, new NewAdminComunicazioneNotification($comunicazione));
            }

            // If approved, notify admins + related anagrafiche
            if ($comunicazione->is_approved && !$comunicazione->is_private) {
                // Eager load related condomini for anagrafiche
                $anagrafiche = Anagrafica::with('condomini')
                    ->whereHas('condomini', function ($query) use ($comunicazione) {
                        $query->where('condominio_id', $comunicazione->condominio_id);
                    })->get();

                $recipients = $admins->merge($anagrafiche);

                // Queue notifications for better performance
                Notification::send($recipients, new NewComunicazioneNotification($comunicazione));

                Log::info("Notifications sent to admins and related anagrafiche for comunicaazione ID: {$comunicazione->id}");

            } 

        } catch (\Exception $e) {
            Log::error("Error sending notifications for comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
        }

    }

}
