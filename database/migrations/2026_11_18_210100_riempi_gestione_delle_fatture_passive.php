<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coda 687 (decisione 507): la gestione delle fatture che ci sono, letta da dove sta oggi. Solo dati, solo dove la colonna è vuota.
 *
 * In ordine, la prima fonte che risponde:
 *  1. la scrittura di competenza della fattura (la pivot `fattura_scrittura` di tipo «competenza»): è la gestione su cui la fattura è a giornale;
 *  2. un'altra scrittura collegata alla fattura, se la competenza non c'è;
 *  3. le voci delle righe, quando stanno tutte nel piano dei conti di una sola gestione (voce → piano dei conti → gestione);
 *  4. la voce delle coperture di una pregressa, alla stessa condizione.
 * Altrimenti la colonna resta vuota, e chi la legge ripiega (`FatturaPassiva::gestioneDellaFattura`).
 *
 * **Non corregge niente.** Una fattura che una modifica ha già spostato sull'ordinaria (prova B4) prende la gestione della sua scrittura, cioè quella su cui
 * è a giornale oggi: le scritture e le righe non si toccano. Quelle fatture le elenca il controllo in sola lettura (`kondomanager:verifica-gestione-fatture`,
 * e l'avviso nell'elenco delle fatture); la correzione la decide l'amministratore.
 *
 * Rieseguibile: scrive solo dove `gestione_id` è vuota, quindi la seconda volta non trova niente. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fatture_passive') || ! Schema::hasColumn('fatture_passive', 'gestione_id')) {
            return;
        }

        $gestioneEsiste = 'EXISTS (SELECT 1 FROM gestioni g WHERE g.id = %s)';

        // 1. La scrittura di competenza (la prima, non cancellata, con la gestione).
        if (Schema::hasTable('fattura_scrittura') && Schema::hasTable('scritture_contabili')) {
            foreach (["AND fs.tipo = 'competenza'", ''] as $filtro) {
                DB::table('fatture_passive')->whereNull('gestione_id')->update(['gestione_id' => DB::raw(
                    '(SELECT sc.gestione_id FROM fattura_scrittura fs JOIN scritture_contabili sc ON sc.id = fs.scrittura_contabile_id'
                    . ' WHERE fs.fattura_passiva_id = fatture_passive.id ' . $filtro
                    . ' AND sc.gestione_id IS NOT NULL AND sc.deleted_at IS NULL AND ' . sprintf($gestioneEsiste, 'sc.gestione_id')
                    . ' ORDER BY sc.id LIMIT 1)'
                )]);
            }
        }

        // 3. Le voci delle righe, se sono di una gestione sola.
        if (Schema::hasTable('righe_fattura') && Schema::hasTable('conti') && Schema::hasTable('piani_conti')) {
            DB::table('fatture_passive')->whereNull('gestione_id')->update(['gestione_id' => DB::raw(
                '(SELECT MIN(pc.gestione_id) FROM righe_fattura r JOIN conti c ON c.id = r.conto_id JOIN piani_conti pc ON pc.id = c.piano_conto_id'
                . ' WHERE r.fattura_passiva_id = fatture_passive.id AND pc.condominio_id = fatture_passive.condominio_id'
                . ' AND ' . sprintf($gestioneEsiste, 'pc.gestione_id')
                . ' HAVING COUNT(DISTINCT pc.gestione_id) = 1)'
            )]);
        }

        // 4. La voce delle coperture di una pregressa, alla stessa condizione.
        if (Schema::hasTable('fattura_coperture') && Schema::hasColumn('fattura_coperture', 'conto_id')) {
            DB::table('fatture_passive')->whereNull('gestione_id')->update(['gestione_id' => DB::raw(
                '(SELECT MIN(pc.gestione_id) FROM fattura_coperture fc JOIN conti c ON c.id = fc.conto_id JOIN piani_conti pc ON pc.id = c.piano_conto_id'
                . ' WHERE fc.fattura_passiva_id = fatture_passive.id AND pc.condominio_id = fatture_passive.condominio_id'
                . ' AND ' . sprintf($gestioneEsiste, 'pc.gestione_id')
                . ' HAVING COUNT(DISTINCT pc.gestione_id) = 1)'
            )]);
        }
    }

    /** Solo dati: all'indietro non si toglie niente (la colonna la toglie la sua migrazione). */
    public function down(): void
    {
    }
};
