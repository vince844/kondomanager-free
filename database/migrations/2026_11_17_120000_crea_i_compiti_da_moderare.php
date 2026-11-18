<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Gestionale\Inbox\CompitoDaModerare;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Il compito «da moderare» dell'Action inbox (decisioni 445 e 449): un compito per elemento che ha qualcosa in attesa ({@see CompitoDaModerare}). Prima di
 * questa versione i commenti e gli allegati in attesa avevano due compiti generici per elemento («Nuovo commento — …», «Allegati da approvare — …»), e un
 * commento pubblicato senza moderazione ne creava uno. Da qui in poi lo scrive il compito unico, che segue i modelli; questa migrazione, una volta sola, chiude i
 * compiti di prima e scrive quello unico per ciò che aspetta già: le segnalazioni e le comunicazioni con commenti o allegati in attesa, le comunicazioni, i
 * documenti dell'archivio e gli eventi dell'agenda da approvare. Chiude anche quelli degli elementi che non ci sono più.
 *
 * Scelta: una migrazione dei dati e non la sincronizzazione al primo cambiamento. Il compito vecchio di un elemento che nessuno tocca resterebbe aperto per
 * sempre (e un commento pubblicato non lo cambia più nessuno), e il numero del menu e la card mentirebbero finché qualcuno non ci passa sopra.
 *
 * Solo dati, nessuna struttura. Rieseguibile: la seconda volta non trova niente da cambiare. Nel dataset di `UpgradeMigrationsRerunTest`. Tornando indietro i
 * compiti restano: si chiudono da soli con ciò che aspettano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eventi') || ! Schema::hasTable('commenti') || ! Schema::hasTable('documenti') || ! Schema::hasTable('comunicazioni')
            || ! Schema::hasTable('segnalazioni') || ! Schema::hasTable('condominio_evento')
            || ! Schema::hasColumns('eventi', ['meta', 'tipo', 'eventable_type', 'eventable_id', 'priorita', 'is_approved'])) {
            return;
        }
        app(CompitoDaModerare::class)->sincronizzaTutti();
    }

    public function down(): void
    {
        // Niente: i compiti si chiudono da soli con ciò che aspettano.
    }
};
