<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Factories\Gestionale;

use App\Models\Condominio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Gestionale\PianoConto>
 */
class PianoContoFactory extends Factory
{
   protected $model = PianoConto::class;

    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'gestione_id' => Gestione::factory(), // Crea una gestione se non passata
            'nome' => 'Piano dei Conti ' . $this->faker->year(),
            'descrizione' => $this->faker->sentence(),
        ];
    }
}
