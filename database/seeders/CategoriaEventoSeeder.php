<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Seeders;

use App\Enums\CategoriaEventoEnum;
use App\Models\CategoriaEvento;
use Illuminate\Database\Seeder;

class CategoriaEventoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (CategoriaEventoEnum::cases() as $categoriaEnum) {
            CategoriaEvento::updateOrCreate(
                ['name' => $categoriaEnum->value],
                ['description' => $categoriaEnum->description()]
            );
        }
    }
}
