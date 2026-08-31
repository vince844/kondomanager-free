<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Sempre per primo: Sincronizza Ruoli e Permessi
        $this->call(RolesAndPermissionsSeeder::class);

        // 2. Controllo SICURO per lo UserSeeder
        if (!config('installer.run_installer')) {
            // Verifichiamo che il ruolo esista fisicamente nel DB prima di usarlo per evitare crash "RoleDoesNotExist"
            $adminRoleExists = Role::where('name', RoleEnum::AMMINISTRATORE->value)->exists();
            
            if ($adminRoleExists && User::role(RoleEnum::AMMINISTRATORE->value)->count() === 0) {
                $this->call(UserSeeder::class);
            }
        }

        // 3. Tabelle Master: Le facciamo girare sempre
        $this->call([          
            CategoriaEventoSeeder::class,
            TipologieImmobiliSeeder::class,
            // ⚠️ **Due seeder di categorie sono stati tolti, e non sostituiti da altri seeder.**
            //
            // `CategoriaFornitoreSeeder` nella 1.11.0-beta.9, `CategoriaDocumentoSeeder` nella
            // .10: le loro righe iniziali le scrivono ora le migrazioni `seed_categorie_fornitore`
            // e `seed_categorie_documento`. Il motivo è che l'amministratore può **cancellare**
            // quelle categorie, e un seeder con `firstOrCreate` **le farebbe risorgere** al primo
            // `db:seed` — senza dirlo. Una migrazione gira una volta sola per costruzione.
            //
            // Per i documenti il difetto non era teorico: la voce «Elimina» sulle categorie
            // dell'archivio esiste da prima, quindi la risurrezione poteva già succedere.
            //
            // ⚠️ **Restano questi due qui sopra**, ed è la Coda 103: `CategoriaEventoSeeder` è il
            // peggiore dei quattro, perché usa `updateOrCreate` e quindi riscrive anche le
            // descrizioni che l'amministratore ha cambiato.
            ComuniSeeder::class,
            // ⚠️ Come per i Comuni, questo aggancio da solo **non basta**: copre la prima
            // installazione, non l'aggiornamento. Il secondo è in `SystemFinalizer`.
            AtecoSeeder::class,
        ]);
    }
}