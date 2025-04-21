<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Notifications\NewComunicazioneNotification;
use Illuminate\Support\Facades\Notification;

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
}
