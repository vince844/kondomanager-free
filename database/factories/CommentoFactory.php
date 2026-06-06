<?php

namespace Database\Factories;

use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Commento>
 */
class CommentoFactory extends Factory
{
    protected $model = Commento::class;

    public function definition(): array
    {
        return [
            'commentable_type' => Segnalazione::class,
            'commentable_id'   => Segnalazione::factory(),
            'user_id'          => User::factory(),
            'condominio_id'    => null,
            'corpo'            => $this->faker->paragraph(),
            'stato'            => 'pubblicato',
            'moderato_da'      => null,
            'moderato_at'      => null,
            'modificato_at'    => null,
        ];
    }

    /**
     * Stato in_attesa (richiede approvazione).
     */
    public function inAttesa(): static
    {
        return $this->state(['stato' => 'in_attesa']);
    }

    /**
     * Stato nascosto (rimosso da moderatore).
     */
    public function nascosto(): static
    {
        return $this->state(['stato' => 'nascosto']);
    }
}
