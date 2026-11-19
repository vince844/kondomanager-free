<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisioni 489, 493 e 494: gli interessi sul debito ereditato agli eredi dal decesso, con le otto scelte dell'amministratore.
 *
 * - Su `impostazioni_condominio`, le otto scelte del condominio («Recupero dei crediti», sezione «Gli interessi degli eredi»), una colonna ciascuna con il
 *   suo valore di serie: senza la riga del condominio valgono quelli ({@see \App\Models\ImpostazioniCondominio::DI_SERIE}).
 * - Su `subentri`, `interessi_eredi`: le otto scelte cambiate per una sola successione («Cambia per questa successione»), in JSON; vuota quando valgono le
 *   regole del condominio, per gli altri passaggi e per i passaggi annullati (il registro le ricorda).
 *
 * Rieseguibile: una guardia per colonna, all'andata e al ritorno (su MySQL ogni colonna è uno statement a sé, e il DDL non è transazionale). Nessuna chiave
 * esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Colonna => [valore di serie, commento]. */
    private const SCELTE = [
        'eredi_interessi_sugli_interessi' => [false, 'Gli interessi degli eredi contano anche sugli interessi e sulle spese già addebitati al defunto.'],
        'eredi_dal_decesso' => [true, 'Gli interessi degli eredi partono dal giorno del decesso; falso: dal giorno dopo.'],
        'eredi_tolleranza_rata_nuova' => [false, 'Il debito ereditato ha la tolleranza della rata nuova; falso: conta fino alla fine della tolleranza.'],
        'eredi_rata_per_rata' => [true, 'Su più rate il debito ereditato scende rata per rata; falso: si ferma tutto alla prima rata.'],
        'eredi_versamento_prima' => [true, 'Un versamento dell\'erede copre prima il debito ereditato; falso: lo copre per ultimo.'],
        'eredi_anche_accordo' => [true, 'Anche il debito dell\'accordo di rientro in regola del defunto prende interessi dal decesso.'],
        'eredi_buco_continuano' => [false, 'Con «continuano a suo nome» gli eredi contano gli interessi fra l\'emissione e la scadenza della rata nuova.'],
        'eredi_interessi_defunto_agli_eredi' => [true, 'Gli interessi del defunto fino al decesso registrati dopo la successione vanno agli eredi per quota.'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('impostazioni_condominio')) {
            foreach (self::SCELTE as $colonna => [$diSerie, $commento]) {
                if (! Schema::hasColumn('impostazioni_condominio', $colonna)) {
                    Schema::table('impostazioni_condominio', fn (Blueprint $table) => $table->boolean($colonna)->default($diSerie)->comment($commento));
                }
            }
        }
        if (Schema::hasTable('subentri') && ! Schema::hasColumn('subentri', 'interessi_eredi')) {
            Schema::table('subentri', fn (Blueprint $table) => $table->json('interessi_eredi')->nullable()
                ->comment('Successione: le otto scelte sugli interessi degli eredi cambiate per questa sola successione; vuota quando valgono le regole del condominio.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subentri') && Schema::hasColumn('subentri', 'interessi_eredi')) {
            Schema::table('subentri', fn (Blueprint $table) => $table->dropColumn('interessi_eredi'));
        }
        if (Schema::hasTable('impostazioni_condominio')) {
            foreach (array_keys(self::SCELTE) as $colonna) {
                if (Schema::hasColumn('impostazioni_condominio', $colonna)) {
                    Schema::table('impostazioni_condominio', fn (Blueprint $table) => $table->dropColumn($colonna));
                }
            }
        }
    }
};
