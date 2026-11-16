<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Database\Seeders;


use App\Models\Condominio;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CondominioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         Condominio::factory()
            ->count(5)
            ->create();
    }
}
