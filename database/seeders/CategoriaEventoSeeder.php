<?php

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
