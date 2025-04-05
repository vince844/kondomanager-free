<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class UserReinviteController extends Controller
{
    public function reinviteUser(string $email): RedirectResponse
    {

        try {

            $user = User::where('email', $email)->first();
            $user->notify(new NewUserEmailNotification($user));
    
            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "L'invito è stato inviato con successo!"
                ]
            ]);

        } catch (Exception $e) {

            Log::error('Error reinviting user: ' . $e->getMessage());

            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'invio dell'invito!"
                ]
            ]);
        
        }
    }

}
