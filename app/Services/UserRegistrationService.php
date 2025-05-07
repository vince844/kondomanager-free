<?php

namespace App\Services;

use App\Models\Invito;
use App\Models\User;
use App\Notifications\Users\RegisteredUserNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class UserRegistrationService
{
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ]);

            $user->assignRole('utente'); // Use constant or enum if available

            $invito = Invito::where('email', $user->email)->first();
            if ($invito) {
                $invito->accepted_at = now();
                $invito->save();
            }

            $admins = User::role(['amministratore'])->get();
            Notification::send($admins, new RegisteredUserNotification());

            return $user;
        });
    }
}
