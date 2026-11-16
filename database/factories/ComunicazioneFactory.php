<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Factories;

use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComunicazioneFactory extends Factory
{
    protected $model = Comunicazione::class;

    public function definition(): array
{
    $priority = $this->faker->randomElement(['bassa', 'media', 'alta', 'urgente']);

    return [
        'subject' => $this->faker->sentence,
        'description' => $this->faker->paragraphs(3, true),
        'created_by' => User::inRandomOrder()->value('id'),
        'priority' => $priority,
        'is_featured' => $this->faker->boolean(10),
        'is_private' => $this->faker->boolean(10),
        'is_published' => $this->faker->boolean(90),
        'is_approved' => $this->faker->boolean(95),
        'can_comment' => $this->faker->boolean(80),
        'slug' => \Illuminate\Support\Str::slug($this->faker->sentence),
        'reference' => $this->faker->uuid,
    ];
}
}
