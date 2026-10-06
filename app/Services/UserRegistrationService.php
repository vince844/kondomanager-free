<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Users\RegisteredUserNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Enums\Role;

class UserRegistrationService
{
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {

            $user = User::create([
                'name'           => $data['name'],
                'email'          => $data['email'],
                'password'       => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ]);

            // 1. Assegnazione del ruolo dinamico dalle Impostazioni Generali
            $settings = app(GeneralSettings::class);
            $user->assignRole($settings->default_user_role);

            // 2. L'invito qui non si tocca: lo accetta solo il suo link, in
            //    `InvitoRegisteredUserController::store()`. Fino alla 1.11.0-beta.44 questo
            //    servizio accettava l'invito con la stessa email, e lo usa anche `POST /register`:
            //    con la registrazione pubblica accesa bastava l'email di un invito per consumarlo
            //    (resto del flusso di prima della 1.8.0, Coda 223).

            // 3. Notifica agli amministratori (escludendo l'utente appena registrato)
            $admins = User::role([Role::AMMINISTRATORE->value])
                ->where('id', '!=', $user->id)
                ->get();

            // 4. Invia la notifica solo se ci sono altri amministratori
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new RegisteredUserNotification());
            }

            return $user;
        });
    }
}