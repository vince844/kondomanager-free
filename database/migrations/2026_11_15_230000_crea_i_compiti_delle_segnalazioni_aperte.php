<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Le segnalazioni già aperte o in lavorazione hanno il loro compito nell'Inbox: prima di questa versione nessun punto del programma lo scriveva, e il riquadro
 * delle segnalazioni dell'Inbox restava a zero. Da qui in poi lo scrive la segnalazione stessa ({@see CompitoDellaSegnalazione}); questa migrazione lo scrive
 * per quelle che ci sono già, una volta sola, con il giorno della segnalazione.
 *
 * Solo dati, nessuna struttura. Rieseguibile: una segnalazione che ha già il suo compito si salta, e una seconda volta non trova niente. Nel dataset di
 * `UpgradeMigrationsRerunTest`. Tornando indietro i compiti restano: si chiudono con le loro segnalazioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('segnalazioni') || ! Schema::hasTable('eventi') || ! Schema::hasTable('condominio_evento')
            || ! Schema::hasColumns('eventi', ['meta', 'tipo', 'eventable_type', 'eventable_id', 'priorita'])) {
            return;
        }
        app(CompitoDellaSegnalazione::class)->creaPerLeAperte();
    }

    public function down(): void
    {
        // Niente: i compiti si chiudono con le loro segnalazioni.
    }
};
