<?php

namespace Database\Seeders;

use App\Models\Comunicazione;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComunicazioneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         Comunicazione::factory()
        ->count(1000)
         ->hasCondomini(5)
       /*  ->hasAnagrafiche(5) */
        ->create(); 
    }
}
