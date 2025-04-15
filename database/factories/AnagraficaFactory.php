<?php

namespace Database\Factories;

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnagraficaFactory extends Factory
{
    protected $model = Anagrafica::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nome' => $this->faker->name,
            'indirizzo' => $this->faker->address,
            'email' => $this->faker->unique()->safeEmail,
            'email_secondaria' => $this->faker->unique()->safeEmail,
            'pec' => $this->faker->unique()->safeEmail,
            'codice_fiscale' => $this->faker->unique()->bothify('??????##??##???'),
            'tipologia_documento' => $this->faker->randomElement(['passport', 'id_card']), // Ensure valid values
            // Use unique() to guarantee uniqueness of 'numero_documento'
            'numero_documento' => $this->faker->unique()->word,
            'scadenza_documento' => $this->faker->date(),
            'luogo_nascita' => $this->faker->city,
            'data_nascita' => $this->faker->date(),
            'telefono' => $this->faker->phoneNumber,
            'cellulare' => $this->faker->phoneNumber,
            'note' => $this->faker->sentence,
        ];
    }
}

