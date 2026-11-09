<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le carte dello studio: come esce la testata delle stampe. Una carta è a campi (lo studio e il condominio scritti dal programma) o una carta
 * intestata caricata (`aspetto` `carta`, con il file, il tipo, le pagine e i margini); ha il logo sì o no e il colore dei titoli e delle righe,
 * nullo per i colori di oggi di ogni stampa. Una sola è la predefinita: la usano i gruppi di documenti senza una carta scelta
 * (`print.carta_per_gruppo`). La tabella nasce con la «Carta dello studio»: a campi, con il logo, il colore nullo, predefinita, cioè le stampe
 * come sono.
 *
 * Rieseguibile: su MySQL la tabella, ogni colonna aggiunta e l'indice sono statement distinti (il DDL non è transazionale), quindi una guardia
 * per ognuno; la «Carta dello studio» si scrive solo se la tabella è vuota. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const INDICE = 'carte_stampa_predefinita_index';

    public function up(): void
    {
        if (! Schema::hasTable('carte_stampa')) {
            Schema::create('carte_stampa', function (Blueprint $table) {
                $table->id();
                $table->string('nome', 100);
                $table->timestamps();
            });
        }

        foreach ($this->colonne() as $colonna => $aggiungi) {
            if (! Schema::hasColumn('carte_stampa', $colonna)) {
                Schema::table('carte_stampa', fn (Blueprint $table) => $aggiungi($table));
            }
        }

        if (! Schema::hasIndex('carte_stampa', self::INDICE)) {
            Schema::table('carte_stampa', fn (Blueprint $table) => $table->index('predefinita', self::INDICE));
        }

        if (! DB::table('carte_stampa')->exists()) {
            DB::table('carte_stampa')->insert([
                'nome' => 'Carta dello studio', 'aspetto' => 'campi', 'mostra_logo' => true, 'colore' => null, 'predefinita' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carte_stampa');
    }

    /** @return array<string, \Closure(Blueprint): mixed> */
    private function colonne(): array
    {
        return [
            'aspetto' => fn (Blueprint $t) => $t->string('aspetto', 10)->default('campi')->comment('campi: lo studio scritto dal programma; carta: la carta intestata caricata.'),
            'mostra_logo' => fn (Blueprint $t) => $t->boolean('mostra_logo')->default(true),
            'colore' => fn (Blueprint $t) => $t->string('colore', 7)->nullable()->comment('Il colore dei titoli e delle righe, #rrggbb; nullo: i colori di oggi di ogni stampa.'),
            'carta_path' => fn (Blueprint $t) => $t->string('carta_path')->nullable()->comment('La carta intestata, sul disco dei file pubblici.'),
            'carta_tipo' => fn (Blueprint $t) => $t->string('carta_tipo', 10)->nullable()->comment('pdf o immagine.'),
            'carta_pagine' => fn (Blueprint $t) => $t->unsignedTinyInteger('carta_pagine')->nullable()->comment('1 o 2: la prima pagina e le seguenti.'),
            'margine_alto' => fn (Blueprint $t) => $t->decimal('margine_alto', 5, 1)->nullable()->comment('I margini della carta intestata, in millimetri.'),
            'margine_basso' => fn (Blueprint $t) => $t->decimal('margine_basso', 5, 1)->nullable(),
            'margine_sinistro' => fn (Blueprint $t) => $t->decimal('margine_sinistro', 5, 1)->nullable(),
            'margine_destro' => fn (Blueprint $t) => $t->decimal('margine_destro', 5, 1)->nullable(),
            'predefinita' => fn (Blueprint $t) => $t->boolean('predefinita')->default(false),
        ];
    }
};
