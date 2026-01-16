<?php

namespace App\Services;

use App\Models\Invito;
use App\Models\User;
use App\Notifications\Users\RegisteredUserNotification;
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

            $user->assignRole(Role::UTENTE->value);

            $invito = Invito::where('email', $user->email)->first();
            if ($invito) {
                $invito->accepted_at = now();
                $invito->save();
            }

            $admins = User::role([Role::AMMINISTRATORE->value])->get();
            Notification::send($admins, new RegisteredUserNotification());

            return $user;
        });
    }
}
