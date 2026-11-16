<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggiunge la colonna note_override alla tabella pagamenti_fornitori.
 *
 * Scopo: audit trail persistente per ogni override effettuato dall'amministratore
 * (saldo insufficiente, overpayment). La nota è obbligatoria a livello UI e viene
 * loggata anche in laravel.log per doppia tracciabilità.
 *
 * Esempio di contenuto: "Bonifico urgente per lavori in corso — incasso rate previsto 48h"
 * Visibile nel dettaglio del pagamento dopo giorni/mesi per giustificare la decisione.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pagamenti_fornitori', 'note_override')) {
            Schema::table('pagamenti_fornitori', function (Blueprint $table) {
                // Colonna nullable: valorizzata solo quando l'admin bypassa un blocco.
                // TEXT per non limitare la lunghezza della motivazione.
                $table->text('note_override')->nullable()->after('idempotency_key')
                    ->comment('Motivazione obbligatoria quando si bypassa un blocco (saldo, overpayment). Audit trail permanente.');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pagamenti_fornitori', function (Blueprint $table) {
            if (Schema::hasColumn('pagamenti_fornitori', 'note_override')) {
                $table->dropColumn('note_override');
            }
        });
    }
};
