<?php

namespace Database\Factories\Gestionale;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Condominio;
use Illuminate\Database\Eloquent\Factories\Factory;

class PianoRateFactory extends Factory
{
    protected $model = PianoRate::class;

    /**
     * `piani_rate` ha un vincolo UNIQUE su [gestione_id, nome]. Il nome usava il solo
     * `faker->word`, che pesca da un dizionario di poche centinaia di voci: due piani
     * creati nella stessa gestione collidevano ogni tanto, con test rossi a
     * intermittenza. Il progressivo rende il nome unico per costruzione.
     */
    protected static int $progressivo = 0;

    public function definition(): array
    {
        return [
            'gestione_id' => Gestione::factory(),
            'condominio_id' => Condominio::factory(),
            'nome' => 'Piano Rate ' . $this->faker->word . ' ' . (++self::$progressivo),
            'numero_rate' => 12,
            'stato' => 'bozza',
        ];
    }
}