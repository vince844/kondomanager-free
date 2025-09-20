<?php

namespace Database\Seeders;

use App\Models\TipologiaImmobile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TipologieImmobiliSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
       public function run(): void
    {
        $tipologie = [
            
            ['nome' => 'Ufficio', 'categoria' => 'unita_abitativa'],
            ['nome' => 'Abitazione', 'categoria' => 'unita_abitativa'],

            ['nome' => 'Ambulatorio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Banca', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Bar', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Negozio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Pizzeria', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Capannone', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Negozio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Ufficio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Laboratorio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Magazzino', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Deposito', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale commerciale', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale artigianale', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale industriale', 'categoria' => 'unita_non_abitativa'],

            ['nome' => 'Box', 'categoria' => 'pertinenza'],
            ['nome' => 'Box esterno', 'categoria' => 'pertinenza'],
            ['nome' => 'Magazzino', 'categoria' => 'pertinenza'],
            ['nome' => 'Garage', 'categoria' => 'pertinenza'],
            ['nome' => 'Lastrico solare', 'categoria' => 'pertinenza'],
            ['nome' => 'Posto auto', 'categoria' => 'pertinenza'],
            ['nome' => 'Cantina', 'categoria' => 'pertinenza'],
            ['nome' => 'Giardino', 'categoria' => 'pertinenza'],
            ['nome' => 'Fondaco', 'categoria' => 'pertinenza'],
            ['nome' => 'Portico', 'categoria' => 'pertinenza'],
            ['nome' => 'Area urbana', 'categoria' => 'pertinenza'],
            ['nome' => 'Pertinenza', 'categoria' => 'pertinenza'],
            ['nome' => 'Ripostiglio', 'categoria' => 'pertinenza'],
            ['nome' => 'Sottotetto', 'categoria' => 'pertinenza'],
            ['nome' => 'Taverna', 'categoria' => 'pertinenza'],
            ['nome' => 'Terreno', 'categoria' => 'pertinenza'],
            ['nome' => 'Soffitta', 'categoria' => 'pertinenza'],
            ['nome' => 'Deposito', 'categoria' => 'pertinenza'],
            
        ];

        foreach ($tipologie as $tipologia) {
            TipologiaImmobile::firstOrCreate(
                ['nome' => $tipologia['nome']],
                ['categoria' => $tipologia['categoria'] ?? null]
            );
        }
    }
}
