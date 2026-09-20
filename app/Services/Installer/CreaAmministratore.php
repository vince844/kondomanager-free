<?php

namespace App\Services\Installer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Il passo «amministratore» del wizard, senza il wizard: le stesse regole di `CreateAdmin`
 * (nome di almeno due caratteri, email unica, password di almeno sei), lo stesso utente
 * (verificato se la configurazione lo chiede), lo stesso ruolo se esiste a database.
 */
class CreaAmministratore
{
    /**
     * C'è già un amministratore? È il segno di un'installazione fatta, con o senza wizard e
     * anche senza il file di lock (dai sorgenti: `migrate` + `db:seed`). Se le tabelle non ci
     * sono ancora, o il database non risponde, la risposta è «no», senza eccezioni.
     */
    public function esisteUnAmministratore(): bool
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasTable(config('permission.table_names.roles', 'roles'))) {
                return false;
            }

            return User::role(config('installer.spatie.admin_role', 'amministratore'))->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws ValidationException
     */
    public function esegui(string $name, string $email, string $password): User
    {
        Validator::make(
            ['name' => trim($name), 'email' => trim($email), 'password' => $password],
            [
                'name' => 'required|string|min:2',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|min:6',
            ],
        )->validate();

        $userData = [
            'name' => trim($name),
            'email' => trim($email),
            'password' => Hash::make($password),
        ];

        if (config('installer.options.verify_admin_email', true)) {
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        $spatieConfig = config('installer.spatie');

        if (($spatieConfig['enabled'] ?? false) && method_exists($user, 'assignRole')) {
            $roleTable = config('permission.table_names.roles', 'roles');
            $roleExists = DB::table($roleTable)
                ->where('name', $spatieConfig['admin_role'])
                ->exists();

            if ($roleExists) {
                $user->assignRole($spatieConfig['admin_role']);
            }
        }

        return $user;
    }
}
