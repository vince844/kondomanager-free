<?php

namespace Database\Seeders;

use App\Models\CategoriaDocumento;
use Illuminate\Database\Seeder;

class CategoriaDocumentoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
        $categorie = [
            ['name' => 'Bilanci', 'description' => 'Documenti relativi ai bilanci economici'],
            ['name' => 'Verbali', 'description' => 'Verbali delle assemblee e riunioni'],
            ['name' => 'Avvisi', 'description' => 'Comunicazioni e avvisi generali'],
            ['name' => 'Contratti', 'description' => 'Contratti stipulati con fornitori o terzi'],
            ['name' => 'Fatture', 'description' => 'Fatture passive e note di credito dei fornitori']
        ];

        foreach ($categorie as $categoria) {
            CategoriaDocumento::firstOrCreate(
                ['name' => $categoria['name']], 
                ['description' => $categoria['description']]
            );
        }

    }
}
