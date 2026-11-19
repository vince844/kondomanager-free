<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisione 483: le successioni registrate prima prendono, per gli interessi del defunto dopo il decesso, la scelta di serie della loro strada
 * dell'arretrato, come gli accordi chiusi dalle successioni di prima hanno preso il di serie della decisione 454. Solo dati, nessuna struttura.
 *
 * - Con l'arretrato agli eredi: `al_decesso`, «si fermano al decesso». **È un cambio per chi aggiorna**: da qui la proposta degli interessi del defunto, sulle
 *   rate delle unità della successione, conta i giorni fino al giorno prima del decesso, anche per le successioni di prima (prima li contava fino alla data
 *   della proposta).
 * - Con l'arretrato a nome del defunto (e con il legato): `continuano`, «continuano a suo nome», come prima.
 *
 * La scelta va sul passaggio dell'unità e su quelli delle sue pertinenze. Le successioni annullate non si toccano, né quelle senza la strada nel registro
 * (non dovrebbero esserci: la strada si scrive dalla 1.11.0-beta.44, con la successione). Rieseguibile: tocca solo i passaggi senza la scelta. Nel dataset
 * di `UpgradeMigrationsRerunTest`. Tornando indietro non si toglie niente: la colonna se ne va con la migrazione di prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subentri') || ! Schema::hasColumn('subentri', 'interessi_del_defunto') || ! Schema::hasColumn('subentri', 'registro')) {
            return;
        }
        DB::table('subentri')->where('tipo_passaggio', 'successione')->whereNull('subentro_padre_id')->whereNull('annullato_il')->whereNull('interessi_del_defunto')
            ->orderBy('id')->select(['id', 'registro'])->chunkById(100, function ($blocco) {
                foreach ($blocco as $s) {
                    $registro = is_string($s->registro) ? (json_decode($s->registro, true) ?: []) : (array) $s->registro;
                    $scelta = match ($registro['arretrato']['scelta'] ?? null) {
                        'eredi' => 'al_decesso',
                        'defunto' => 'continuano',
                        default => null,
                    };
                    if ($scelta === null) {
                        continue;
                    }
                    DB::table('subentri')->where(fn ($q) => $q->where('id', $s->id)->orWhere('subentro_padre_id', $s->id))
                        ->whereNull('annullato_il')->whereNull('interessi_del_defunto')->update(['interessi_del_defunto' => $scelta]);
                }
            });
    }

    public function down(): void
    {
        // Niente: la scelta se ne va con la colonna, alla migrazione di prima.
    }
};
