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
    /**
     * ⚠️ **Quattro nomi erano dichiarati due volte, e `firstOrCreate` ha chiave sul solo `nome`:
     * vince sempre la prima dichiarazione e la seconda non viene mai applicata.** Accertato sul
     * database il 15/08/2026, non dedotto.
     *
     * - `Magazzino` (riga 30 `unita_non_abitativa`, riga 38 `pertinenza`) e `Deposito` (righe 31 e
     *   53) restavano `unita_non_abitativa`: **non sono mai stati pertinenze**, su nessuna
     *   installazione, malgrado il seeder dichiarasse il contrario.
     * - `Ufficio` era dichiarato **`unita_abitativa`** alla riga 18 e `unita_non_abitativa` alla
     *   28. Vinceva il primo, ed è un errore di classificazione vero: un ufficio non è
     *   un'abitazione. Non è pignoleria — la distinzione fra abitativo e non abitativo è
     *   esattamente quella che serve alla comunicazione delle spese sulle parti comuni.
     * - `Negozio` era duplicato con la **stessa** categoria: innocuo, tolto per pulizia.
     *
     * Le voci ambigue restano dove sono, e la ragione sta nell'interfaccia. `Magazzino` e
     * `Deposito` sono legittimamente l'una cosa e l'altra — un deposito che serve un appartamento
     * è una pertinenza, un magazzino affittato a un'impresa è un'unità autonoma — e la categoria
     * **non decide se il legame di pertinenza si possa dichiarare**: decide solo quanto il campo
     * sia in evidenza. Cambiarle significherebbe toccare una classificazione già vista dagli
     * amministratori per guadagnare un'enfasi.
     */
    public function run(): void
    {
        $tipologie = [

            ['nome' => 'Abitazione', 'categoria' => 'unita_abitativa'],

            ['nome' => 'Ambulatorio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Banca', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Bar', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Negozio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Pizzeria', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Capannone', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Ufficio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Laboratorio', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Magazzino', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Deposito', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale commerciale', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale artigianale', 'categoria' => 'unita_non_abitativa'],
            ['nome' => 'Locale industriale', 'categoria' => 'unita_non_abitativa'],

            ['nome' => 'Box', 'categoria' => 'pertinenza'],
            ['nome' => 'Box esterno', 'categoria' => 'pertinenza'],
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

        ];

        foreach ($tipologie as $tipologia) {
            TipologiaImmobile::firstOrCreate(
                ['nome' => $tipologia['nome']],
                ['categoria' => $tipologia['categoria'] ?? null]
            );
        }
    }
}
