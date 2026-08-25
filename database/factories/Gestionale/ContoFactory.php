<?php

namespace Database\Factories\Gestionale;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Gestionale\Conto>
 */
class ContoFactory extends Factory
{
    protected $model = Conto::class;

    public function definition(): array
    {
        return [
            'piano_conto_id' => PianoConto::factory(),
            'parent_id' => null, // Di default è una radice
            'is_capitolo' => false, // esplicito: la fixture di default è una voce, non un capitolo
            'nome' => ucfirst($this->faker->word()),
            'importo' => 0, // Default 0
            'tipo' => 'spesa', // Assumo tu abbia un campo tipo, se no rimuovilo
        ];
    }
}
