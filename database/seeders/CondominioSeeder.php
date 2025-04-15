<?php

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
            ->count(50)
            ->create();
    }
}
