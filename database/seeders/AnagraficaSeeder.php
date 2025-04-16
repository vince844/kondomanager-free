<?php

namespace Database\Seeders;

use App\Models\Anagrafica;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AnagraficaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Anagrafica::factory()
            ->count(5)
            ->create();  
    }
}
