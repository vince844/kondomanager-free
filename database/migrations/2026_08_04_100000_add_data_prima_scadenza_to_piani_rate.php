<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LE RATE SCADONO QUANDO DICI TU (beta.42) — Fase 1 di `calendario_rate.md`.
 *
 * Fino a oggi la prima rata di un piano cade dove cade l'inizio della gestione, e non c'è modo
 * di dirlo altrimenti: la partenza è cablata su `gestione.data_inizio` in tre punti
 * (`GenerateDateRateAction:25` e `:57`, `PianoRateCreatorService:47`).
 *
 * Le parole di un amministratore che l'ha chiesto, che descrivono il difetto meglio di
 * qualunque riformulazione: *«nel Piano rate come posso settare che la prima rata è in una data
 * specifica? Di default mi prende come data l'inizio della contabilità»*. Non sta chiedendo una
 * comodità: sta descrivendo un comportamento che subisce e di cui chiede la ragione. In tre
 * l'hanno chiesto.
 *
 * ## Perché nullable, e perché nessun backfill
 *
 * `NULL` non è un dato mancante: significa **«parte dall'inizio della gestione»**, cioè
 * esattamente il comportamento di oggi. Riempire la colonna sui piani esistenti li
 * congelerebbe su una data calcolata adesso, e il giorno in cui qualcuno spostasse l'inizio
 * della gestione quei piani smetterebbero di seguirlo — passando da un default vivo a una
 * copia morta. Chi non tocca niente non si accorge di questa versione, ed è la cosa giusta.
 *
 * Nessuna riparazione dati, quindi, e nessuna riga da leggere all'indietro.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una colonna sola, nullable, senza foreign key: basta una guardia. Le due guardie
        // separate servono quando `constrained()` produce due statement e l'interruzione può
        // cadere in mezzo — qui lo statement è uno solo e non c'è mezzo in cui cadere.
        if (! Schema::hasColumn('piani_rate', 'data_prima_scadenza')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->date('data_prima_scadenza')
                    ->nullable()
                    ->after('giorno_scadenza');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('piani_rate', 'data_prima_scadenza')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('data_prima_scadenza');
            });
        }
    }
};
