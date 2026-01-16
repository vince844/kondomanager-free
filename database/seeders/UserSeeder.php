<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name'              => 'admin',
            'email'             => 'admin@km.com',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
        ])->assignRole(Role::AMMINISTRATORE->value); 

    /*     User::factory()
            ->count(50)
            ->create()
            ->each(function ($user) {
                $user->assignRole('utente');
            }); */
    }
}
