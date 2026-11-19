<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Factories;

use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Segnalazione>
 */
class SegnalazioneFactory extends Factory
{
    protected $model = Segnalazione::class;

    public function definition(): array
    {
        $priorities = ['bassa', 'media', 'alta', 'urgente'];
        $statuses = ['aperta', 'in lavorazione', 'chiusa'];

        return [
            'subject' => $this->faker->sentence(6),
            'description' => $this->faker->paragraph,
            'created_by' => User::inRandomOrder()->value('id'),
            'assigned_to' => null,
            'condominio_id' => Condominio::inRandomOrder()->value('id'),
            'priority' => $this->faker->randomElement($priorities),
            'stato' => $this->faker->randomElement($statuses),
            'is_resolved' => $this->faker->boolean,
            'is_locked' => $this->faker->boolean,
            'is_featured' => $this->faker->boolean,
            'is_private' => $this->faker->boolean,
            'is_published' => $this->faker->boolean,
            'is_approved' => $this->faker->boolean,
            'can_comment' => $this->faker->boolean,
        ];
    }
}
