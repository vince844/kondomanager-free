<?php

namespace Database\Seeders;

use App\Models\CategoriaFornitore;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriaFornitoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Elettricista',
                'description' => 'Professionisti specializzati in impianti elettrici, installazioni e manutenzioni elettriche civili e industriali.',
            ],
            [
                'name' => 'Idraulico',
                'description' => 'Fornitori che operano nel settore termo-idraulico, tubazioni, caldaie, impianti sanitari e manutenzioni.',
            ],
            [
                'name' => 'Muratore',
                'description' => 'Aziende o artigiani specializzati in lavori edili, ristrutturazioni, opere murarie e manutenzioni strutturali.',
            ],
            [
                'name' => 'Giardiniere',
                'description' => 'Servizi di manutenzione aree verdi, potature, cura giardini, irrigazione e gestione del verde pubblico o privato.',
            ],
            [
                'name' => 'Servizi di pulizia',
                'description' => 'Imprese specializzate nella pulizia di spazi privati, condominiali e commerciali, ordinaria e straordinaria.',
            ],
            [
                'name' => 'Sicurezza e antincendio',
                'description' => 'Fornitori specializzati in sistemi di sicurezza, antincendio, manutenzioni estintori e impianti di rilevazione.',
            ],
            [
                'name' => 'Ascensorista',
                'description' => "Tecnici e aziende dedicate all'installazione e manutenzione di ascensori, montacarichi e piattaforme elevatrici.",
            ],
            [
                'name' => 'Azienda multiservizi',
                'description' => 'Fornitori che offrono servizi multipli: manutenzione, pulizie, assistenza tecnica e gestione strutture.',
            ]
        ];

        foreach ($categories as $category) {
            CategoriaFornitore::create($category);
        }
    }
}
