<?php

namespace Database\Seeders;

use App\Models\Comunicazione;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ComunicazioneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Comunicazione::factory()
        ->count(5)
        ->hasCondomini(5)
        ->create();
        }
}
