<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Factories\Gestionale;

use App\Models\Gestionale\Rata;
use App\Models\Gestionale\PianoRate;
use Illuminate\Database\Eloquent\Factories\Factory;

class RataFactory extends Factory
{
    protected $model = Rata::class;

    public function definition(): array
    {
        return [
            'piano_rate_id' => PianoRate::factory(),
            'numero_rata' => 1,
            'data_scadenza' => now()->addMonth(),
            'importo_totale' => 10000,
        ];
    }
}