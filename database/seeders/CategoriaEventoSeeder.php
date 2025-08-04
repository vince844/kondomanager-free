<?php

namespace Database\Seeders;

use App\Models\CategoriaEvento;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriaEventoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
        $categories = [
            [
                'name' => 'Manutenzione',
                'description' => 'Attività tecniche programmate o urgenti finalizzate alla riparazione o al miglioramento delle strutture condominiali',
            ],
            [
                'name' => 'Assemblea',
                'description' => 'Incontri ufficiali tra i condomini per deliberare su questioni amministrative, economiche o gestionali',
            ],
            [
                'name' => 'Pulizia',
                'description' => 'Interventi di pulizia ordinaria o straordinaria delle aree comuni condominiali',
            ],
            [
                'name' => 'Generiche',
                'description' => 'Eventi, promemoria o comunicazioni non appartenenti a categorie specifiche ma rilevanti per il condominio',
            ],
            [
                'name' => 'Richieste di intervento',
                'description' => 'Segnalazioni e scadenze relative a richieste di manutenzione o assistenza tecnica da parte dei condomini',
            ]
        ];

        foreach ($categories as $category) {
            CategoriaEvento::create($category);
        }
    }
}
