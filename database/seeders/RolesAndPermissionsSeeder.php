<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
          // Reset cached roles and permissions
          app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

          // create permissions
          Permission::create(['name' => 'Crea utenti',
                              'description' => 'Permette di creare nuovi utenti']);
          Permission::create(['name' => 'Modifica utenti',
                              'description' => 'Permette di modificare gli utenti registrati']);
          Permission::create(['name' => 'Elimina utenti',
                              'description' => 'Permette di eliminare gli utenti registrati']);
          Permission::create(['name' => 'Visualizza utenti',
                              'description' => 'Permette di visualizzare gli utenti registrati']);
  
          Permission::create(['name' => 'Crea condomini',
                              'description' => 'Permette di creare nuovi condomini']);
          Permission::create(['name' => 'Modifica condomini',
                              'description' => 'Permette di modificare i condomini registrati']);
          Permission::create(['name' => 'Elimina condomini',
                              'description' => 'Permette di eliminare i condomini registrati']);
          Permission::create(['name' => 'Visualizza condomini',
                              'description' => 'Permette di visualizzare i condomini registrati']);
  
          Permission::create(['name' => 'Crea comunicazioni',
                              'description' => 'Permette di creare comunicazioni in bacheca']);
          Permission::create(['name' => 'Pubblica comunicazioni',
                              'description' => 'Permette di pubblicare le comunicazioni in bacheca']);
          Permission::create(['name' => 'Pubblica proprie comunicazioni',
                              'description' => 'Permette di pubblicare solo le proprie comunicazioni in bacheca']);
          Permission::create(['name' => 'Modifica comunicazioni',
                              'description' => 'Permette di modificare le comunicazioni in bacheca']);
          Permission::create(['name' => 'Modifica proprie comunicazioni',
                              'description' => 'Permette di modificare solo le proprie comunicazioni in bacheca']);
          Permission::create(['name' => 'Elimina comunicazioni',
                              'description' => 'Permette di eliminare le comunicazioni in bacheca']);
          Permission::create(['name' => 'Elimina proprie comunicazioni',
                              'description' => 'Permette di eliminare solo le proprie comunicazioni in bacheca']);
          Permission::create(['name' => 'Visualizza comunicazioni',
                              'description' => 'Permette di visualizzare le comunicazioni in bacheca']);
  
          Permission::create(['name' => 'Commenta comunicazioni',
                              'description' => 'Permette di commentare le comunicazioni in bacheca']);
          Permission::create(['name' => 'Pubblica commenti comunicazioni',
                              'description' => 'Permette di pubblicare commenti lasciati in comunicazione in bacheca']);
          Permission::create(['name' => 'Approva commenti comunicazioni',
                              'description' => 'Permette di approvare commenti lasciati in comunicazione in bacheca']);
          Permission::create(['name' => 'Modifica commenti comunicazioni',
                              'description' => 'Permette di modificare commenti lasciati in comunicazione in bacheca']);
          Permission::create(['name' => 'Elimina commenti comunicazioni',
                              'description' => 'Permette di eliminare un commento lasciato in comunicazione in bacheca']);
          Permission::create(['name' => 'Visualizza commenti comunicazioni',
                              'description' => 'Permette di visualizzare i commenti delle comunicazioni in bacheca']);
  
          Permission::create(['name' => 'Crea segnalazioni',
                              'description' => 'Permette di creare una segnalazione di guasto']);
          Permission::create(['name' => 'Pubblica segnalazioni',
                              'description' => 'Permette di pubblicare una segnalazione di guasto']);
          Permission::create(['name' => 'Modifica segnalazioni',
                              'description' => 'Permette di modificare una segnalazione di guasto']);
          Permission::create(['name' => 'Modifica proprie segnalazioni',
                              'description' => 'Permette di modificare solo le proprie segnalazioni di guasto']);
          Permission::create(['name' => 'Elimina segnalazioni',
                              'description' => 'Permette di eliminare una segnalazione di guasto']);
          Permission::create(['name' => 'Elimina proprie segnalazioni',
                              'description' => 'Permette di eliminare solo le proprie segnalazioni di guasto']);
          Permission::create(['name' => 'Visualizza segnalazioni',
                              'description' => 'Permette di visualizzare una segnalazione di guasto']);
  
          Permission::create(['name' => 'Commenta segnalazioni',
                              'description' => 'Permette di commentare le segnalazioni guasto']);
          Permission::create(['name' => 'Modifica commenti segnalazioni',
                              'description' => 'Permette di modificare commenti lasciati in una segnalazione guasto']);
          Permission::create(['name' => 'Modifica propri commenti segnalazioni',
                              'description' => 'Permette di modificare solo i propri commenti lasciati in una segnalazione guasto']);
          Permission::create(['name' => 'Elimina commenti segnalazioni',
                              'description' => 'Permette di eliminare un commento lasciato in una segnalazione guasto']);
          Permission::create(['name' => 'Elimina propri commenti segnalazioni',
                              'description' => 'Permette di eliminare solo i propri commenti lasciati in una segnalazione guasto']);
          Permission::create(['name' => 'Pubblica commenti segnalazioni',
                              'description' => 'Permette di pubblicare commenti lasciati in una sengalazione guasto']);
          Permission::create(['name' => 'Approva commenti segnalazioni',
                              'description' => 'Permette di approvare commenti lasciati in una segnalazione guasto']);
          Permission::create(['name' => 'Visualizza commenti segnalazioni',
                              'description' => 'Permette di visualizzare commenti lasciati in una segnalazione guasto']);
  
          // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
          app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
  
          // create roles and assign permissions
          Role::create([
            'name' => 'amministratore',
            'description' => 'Questo è il ruolo di default amministratore'
          ])->givePermissionTo(Permission::all());

          $collaboratore_permissions = [
            'Crea utenti',
            'Modifica utenti',
            'Visualizza utenti',
            'Crea condomini',
            'Modifica condomini',
            'Visualizza condomini',
            'Crea comunicazioni',
            'Pubblica comunicazioni',
            'Modifica comunicazioni',
            'Visualizza comunicazioni',
            'Commenta comunicazioni',
            'Pubblica commenti comunicazioni',
            'Approva commenti comunicazioni',
            'Modifica commenti comunicazioni',
            'Visualizza commenti comunicazioni',
            'Crea segnalazioni',
            'Pubblica segnalazioni',
            'Modifica segnalazioni',
            'Visualizza segnalazioni',
            'Commenta segnalazioni',
            'Modifica commenti segnalazioni',
            'Pubblica commenti segnalazioni',
            'Approva commenti segnalazioni',
            'Visualizza commenti segnalazioni'
          ];

          Role::create([
            'name' => 'collaboratore',
            'description' => 'Questo è il ruolo di default collaboratore'
          ])->givePermissionTo($collaboratore_permissions);

          $fornitore_permissions = [
            'Visualizza comunicazioni',
            'Commenta comunicazioni',
            'Visualizza commenti comunicazioni',
            'Visualizza segnalazioni',
            'Commenta segnalazioni',
            'Visualizza commenti segnalazioni'
          ];

          Role::create([
            'name' => 'fornitore',
            'description' => 'Questo è il ruolo di default fornitore'
          ])->givePermissionTo($fornitore_permissions);

          $utente_permissions = [
            'Visualizza comunicazioni',
            'Commenta comunicazioni',
            'Modifica commenti comunicazioni',
            'Visualizza commenti comunicazioni',
            'Crea segnalazioni',
            'Visualizza segnalazioni',
            'Commenta segnalazioni',
            'Visualizza commenti segnalazioni'
          ];

          Role::create([
            'name' => 'utente',
            'description' => 'Questo è il ruolo di default utente'
          ])->givePermissionTo($utente_permissions);

    }
}
