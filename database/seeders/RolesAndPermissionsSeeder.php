<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Permissions list
        $permissions = [
            // Access
            ['name' => 'Accesso pannello amministratore', 'description' => 'Permette di dare accesso al layout amministratore'],

            // Utenti
            ['name' => 'Crea utenti', 'description' => 'Permette di creare nuovi utenti'],
            ['name' => 'Modifica utenti', 'description' => 'Permette di modificare gli utenti registrati'],
            ['name' => 'Elimina utenti', 'description' => 'Permette di eliminare gli utenti registrati'],
            ['name' => 'Visualizza utenti', 'description' => 'Permette di visualizzare gli utenti registrati'],

            // Condomini
            ['name' => 'Crea condomini', 'description' => 'Permette di creare nuovi condomini'],
            ['name' => 'Modifica condomini', 'description' => 'Permette di modificare i condomini registrati'],
            ['name' => 'Elimina condomini', 'description' => 'Permette di eliminare i condomini registrati'],
            ['name' => 'Visualizza condomini', 'description' => 'Permette di visualizzare i condomini registrati'],

            // Comunicazioni
            ['name' => 'Crea comunicazioni', 'description' => 'Permette di creare comunicazioni in bacheca'],
            ['name' => 'Approva comunicazioni', 'description' => 'Permette di approvare comunicazioni in bacheca'],
            ['name' => 'Pubblica comunicazioni', 'description' => 'Permette di pubblicare le comunicazioni in bacheca'],
            ['name' => 'Pubblica proprie comunicazioni', 'description' => 'Permette di pubblicare solo le proprie comunicazioni in bacheca'],
            ['name' => 'Modifica comunicazioni', 'description' => 'Permette di modificare le comunicazioni in bacheca'],
            ['name' => 'Modifica proprie comunicazioni', 'description' => 'Permette di modificare solo le proprie comunicazioni in bacheca'],
            ['name' => 'Elimina comunicazioni', 'description' => 'Permette di eliminare le comunicazioni in bacheca'],
            ['name' => 'Elimina proprie comunicazioni', 'description' => 'Permette di eliminare solo le proprie comunicazioni in bacheca'],
            ['name' => 'Visualizza comunicazioni', 'description' => 'Permette di visualizzare le comunicazioni in bacheca'],
            ['name' => 'Visualizza proprie comunicazioni', 'description' => 'Permette di visualizzare solo le proprie comunicazioni in bacheca'],

            // Commenti comunicazioni
            ['name' => 'Commenta comunicazioni', 'description' => 'Permette di commentare le comunicazioni in bacheca'],
            ['name' => 'Pubblica commenti comunicazioni', 'description' => 'Permette di pubblicare commenti lasciati in comunicazione in bacheca'],
            ['name' => 'Approva commenti comunicazioni', 'description' => 'Permette di approvare commenti lasciati in comunicazione in bacheca'],
            ['name' => 'Modifica commenti comunicazioni', 'description' => 'Permette di modificare commenti lasciati in comunicazione in bacheca'],
            ['name' => 'Elimina commenti comunicazioni', 'description' => 'Permette di eliminare un commento lasciato in comunicazione in bacheca'],
            ['name' => 'Visualizza commenti comunicazioni', 'description' => 'Permette di visualizzare i commenti delle comunicazioni in bacheca'],

            // Segnalazioni
            ['name' => 'Crea segnalazioni', 'description' => 'Permette di creare una segnalazione di guasto'],
            ['name' => 'Approva segnalazioni', 'description' => 'Permette di approvare una segnalazione di guasto'],
            ['name' => 'Pubblica segnalazioni', 'description' => 'Permette di pubblicare una segnalazione di guasto'],
            ['name' => 'Modifica segnalazioni', 'description' => 'Permette di modificare una segnalazione di guasto'],
            ['name' => 'Modifica proprie segnalazioni', 'description' => 'Permette di modificare solo le proprie segnalazioni di guasto'],
            ['name' => 'Elimina segnalazioni', 'description' => 'Permette di eliminare una segnalazione di guasto'],
            ['name' => 'Elimina proprie segnalazioni', 'description' => 'Permette di eliminare solo le proprie segnalazioni di guasto'],
            ['name' => 'Visualizza segnalazioni', 'description' => 'Permette di visualizzare una segnalazione di guasto'],
            ['name' => 'Visualizza proprie segnalazioni', 'description' => 'Permette di visualizzare solo le proprie segnalazione di guasto'],

            // Commenti segnalazioni
            ['name' => 'Commenta segnalazioni', 'description' => 'Permette di commentare le segnalazioni guasto'],
            ['name' => 'Modifica commenti segnalazioni', 'description' => 'Permette di modificare commenti lasciati in una segnalazione guasto'],
            ['name' => 'Modifica propri commenti segnalazioni', 'description' => 'Permette di modificare solo i propri commenti lasciati in una segnalazione guasto'],
            ['name' => 'Elimina commenti segnalazioni', 'description' => 'Permette di eliminare un commento lasciato in una segnalazione guasto'],
            ['name' => 'Elimina propri commenti segnalazioni', 'description' => 'Permette di eliminare solo i propri commenti lasciati in una segnalazione guasto'],
            ['name' => 'Pubblica commenti segnalazioni', 'description' => 'Permette di pubblicare commenti lasciati in una segnalazione guasto'],
            ['name' => 'Approva commenti segnalazioni', 'description' => 'Permette di approvare commenti lasciati in una segnalazione guasto'],
            ['name' => 'Visualizza commenti segnalazioni', 'description' => 'Permette di visualizzare commenti lasciati in una segnalazione guasto'],
        ];

        // Create or update permissions
        foreach ($permissions as $perm) {
            Permission::updateOrCreate(['name' => $perm['name']], ['description' => $perm['description']]);
        }

        // Create roles and assign permissions
        $admin = Role::updateOrCreate(['name' => 'amministratore'], [
            'description' => 'Questo è il ruolo di default amministratore'
        ]);
        $admin->syncPermissions(Permission::all());

        $collaboratorePermissions = [
            'Crea utenti', 'Modifica utenti', 'Visualizza utenti',
            'Crea condomini', 'Modifica condomini', 'Visualizza condomini',
            'Crea comunicazioni', 'Pubblica comunicazioni', 'Modifica comunicazioni', 'Visualizza comunicazioni',
            'Commenta comunicazioni', 'Pubblica commenti comunicazioni', 'Approva commenti comunicazioni',
            'Modifica commenti comunicazioni', 'Visualizza commenti comunicazioni',
            'Crea segnalazioni', 'Pubblica segnalazioni', 'Modifica segnalazioni', 'Visualizza segnalazioni',
            'Commenta segnalazioni', 'Modifica commenti segnalazioni',
            'Pubblica commenti segnalazioni', 'Approva commenti segnalazioni', 'Visualizza commenti segnalazioni',
        ];
        $collab = Role::updateOrCreate(['name' => 'collaboratore'], [
            'description' => 'Questo è il ruolo di default collaboratore'
        ]);
        $collab->syncPermissions($collaboratorePermissions);

        $fornitorePermissions = [
            'Visualizza comunicazioni', 'Commenta comunicazioni', 'Visualizza commenti comunicazioni',
            'Visualizza segnalazioni', 'Commenta segnalazioni', 'Visualizza commenti segnalazioni'
        ];
        $fornitore = Role::updateOrCreate(['name' => 'fornitore'], [
            'description' => 'Questo è il ruolo di default fornitore'
        ]);
        $fornitore->syncPermissions($fornitorePermissions);

        $utentePermissions = [
            'Visualizza comunicazioni', 'Commenta comunicazioni', 'Modifica commenti comunicazioni',
            'Visualizza commenti comunicazioni', 'Crea segnalazioni', 'Visualizza segnalazioni',
            'Commenta segnalazioni', 'Visualizza commenti segnalazioni'
        ];
        $utente = Role::updateOrCreate(['name' => 'utente'], [
            'description' => 'Questo è il ruolo di default utente'
        ]);
        $utente->syncPermissions($utentePermissions);

        // Clear cache again (optional)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
