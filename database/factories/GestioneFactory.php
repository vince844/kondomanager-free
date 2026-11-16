<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Factories;

use App\Models\Gestione;
use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Database\Eloquent\Factories\Factory;

class GestioneFactory extends Factory
{
    protected $model = Gestione::class;

    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'nome' => $this->faker->word . ' ' . $this->faker->year,
            'tipo' => 'ordinaria',
            'saldo_applicato' => false, 
            'nota_saldo' => null,
            'attiva' => true, 
        ];
    }
}