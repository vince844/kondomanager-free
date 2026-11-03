<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * L'amministratore di un'installazione senza wizard, quando nessun altro l'ha creato
 * (`DatabaseSeeder` lo chiama solo se non c'è un amministratore e il wizard è spento).
 *
 * Coda 266: la password `password` è scritta nel README e nelle guide dei contenitori Docker di
 * sviluppo, quindi vale solo in `local`. Altrove (un server installato a mano con `db:seed`, o un
 * seeder partito per errore su un'istanza vera) la password è casuale, stampata una volta in chiusura
 * e salvata solo come impronta: non esiste una porta che chiunque conosca. Sul server la strada del
 * README è `kondomanager:installa`, che crea l'amministratore con i dati di chi installa prima di
 * chiamare i seeder, e questo non parte.
 */
class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sviluppo = app()->environment('local');
        $password = $sviluppo ? 'password' : Str::password(20, symbols: false);

        User::create([
            'name'              => 'admin',
            'email'             => 'admin@km.com',
            'password'          => Hash::make($password),
            'email_verified_at' => now(),
        ])->assignRole(Role::AMMINISTRATORE->value);

        if (! $sviluppo) {
            $this->command?->warn("Amministratore creato: admin@km.com — password: {$password}");
            $this->command?->warn('Annotala adesso: non viene mostrata di nuovo. Cambiala dal profilo dopo il primo accesso.');
        }
    }
}
