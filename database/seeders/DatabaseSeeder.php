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
            CategoriaDocumentoSeeder::class,
            CategoriaEventoSeeder::class,
            TipologieImmobiliSeeder::class,
            // ⚠️ `CategoriaFornitoreSeeder` è stato **tolto** nella 1.11.0-beta.9, e non sostituito:
            // le nove categorie iniziali le scrive la migrazione `seed_categorie_fornitore`.
            // Il motivo è che da quella beta l'amministratore può cancellarle, e un seeder con
            // `firstOrCreate` **le farebbe risorgere** al primo `db:seed`. Una migrazione gira una
            // volta sola per costruzione. Vedi la Coda 103: le altre tre tabelle master hanno
            // ancora quel difetto.
            ComuniSeeder::class,
            // ⚠️ Come per i Comuni, questo aggancio da solo **non basta**: copre la prima
            // installazione, non l'aggiornamento. Il secondo è in `SystemFinalizer`.
            AtecoSeeder::class,
        ]);
    }
}