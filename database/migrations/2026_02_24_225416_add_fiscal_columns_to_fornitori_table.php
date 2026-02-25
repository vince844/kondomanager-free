<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggiunge i campi fiscali e di pagamento alla tabella fornitori.
     * Tutti nullable o con default per non rompere i record esistenti.
     */
    public function up(): void
    {
        Schema::table('fornitori', function (Blueprint $table) {
            if (!Schema::hasColumn('fornitori', 'soggetto_ritenuta')) {
                $table->boolean('soggetto_ritenuta')->default(false)->after('id');
                $table->decimal('perc_ritenuta', 5, 2)->nullable()->after('soggetto_ritenuta');
                $table->decimal('perc_imponibile_ritenuta', 5, 2)->default(100)->after('perc_ritenuta');
                $table->string('codice_tributo', 10)->nullable()->after('perc_imponibile_ritenuta');
                $table->integer('giorni_scadenza')->default(30)->after('codice_tributo');
                $table->string('modalita_pagamento_default')->default('bonifico')->after('giorni_scadenza');
                $table->string('iban_principale')->nullable()->after('modalita_pagamento_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fornitori', function (Blueprint $table) {
            $table->dropColumn([
                'soggetto_ritenuta',
                'perc_ritenuta',
                'perc_imponibile_ritenuta',
                'codice_tributo',
                'giorni_scadenza',
                'modalita_pagamento_default',
                'iban_principale',
            ]);
        });
    }
};