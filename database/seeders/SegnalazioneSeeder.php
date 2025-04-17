<?php

namespace Database\Seeders;

use App\Models\Segnalazione;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SegnalazioneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Segnalazione::factory()
        ->count(1000)
        ->create();  
    }
}
