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
            CategoriaFornitoreSeeder::class,
        ]);
    }
}