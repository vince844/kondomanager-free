<?php

namespace Database\Factories;

use App\Models\Esercizio;
use App\Models\Condominio;
use Illuminate\Database\Eloquent\Factories\Factory;

class EsercizioFactory extends Factory
{
    /**
     * Il nome del modello associato alla factory.
     */
    protected $model = Esercizio::class;

    /**
     * Anno progressivo usato per generare nomi univoci.
     *
     * Gli esercizi hanno un indice unico su (condominio_id, nome): usare un anno
     * casuale (faker->unique()->year) rendeva i test non deterministici, perché
     * prima o poi veniva estratto un anno già usato da un esercizio creato a mano
     * nei test (es. "Esercizio 2026" in GestionaleTestHelpers).
     * Si parte dal 2100 proprio per non collidere con quegli anni "reali".
     */
    private static int $annoProgressivo = 2100;

    public function definition(): array
    {
        $anno = self::$annoProgressivo++;

        return [
            'condominio_id' => Condominio::factory(), // Crea automaticamente un condominio se non passato
            'nome' => 'Esercizio ' . $anno,
            'data_inizio' => $anno . '-01-01',
            'data_fine' => $anno . '-12-31',
            'stato' => 'aperto', // Default
        ];
    }
}