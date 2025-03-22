<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Anagrafica>
 */
class AnagraficaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null, // Optionally you can link this to a user with a factory
            'nome' => fake()->name(),
            'indirizzo' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
            'email_secondaria' => fake()->unique()->safeEmail(),
            'pec' => fake()->unique()->safeEmail(),
            'codice_fiscale' => fake()->unique()->word(),
            'tipologia_documento' => fake()->randomElement(['passport', 'id_card']),
            'numero_documento' => fake()->unique()->word(),
            'scadenza_documento' => fake()->date(),
            'luogo_nascita' => fake()->city(),
            'data_nascita' => fake()->date(),
            'telefono' => fake()->phoneNumber(),
            'cellulare' => fake()->phoneNumber(),
        ];
    }
}
