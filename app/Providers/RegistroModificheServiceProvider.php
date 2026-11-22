<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Providers;

use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\ImpostazioniNelRegistro;
use App\Services\RegistroModifiche\RigheRimandate;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Il registro delle modifiche (Coda 679): il contesto da cui una riga legge il suo autore quando non c'è una persona in sessione. Un comando lanciato da
 * terminale scrive «comando lanciato da terminale: …», un lavoro del pianificatore «lavoro pianificato: …» ({@see ContestoDelRegistro::autore()}).
 */
class RegistroModificheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CommandStarting::class, fn (CommandStarting $e) => ContestoDelRegistro::comandoIniziato($e->command));
        Event::listen(CommandFinished::class, fn () => ContestoDelRegistro::comandoFinito());
        // Un lavoro saltato (`ScheduledTaskSkipped`) non è mai cominciato: non si conta.
        Event::listen(ScheduledTaskStarting::class, fn () => ContestoDelRegistro::pianificatoIniziato());
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn () => ContestoDelRegistro::pianificatoFinito());
        // Gesto 8 (P20): le impostazioni dello studio, un ascoltatore solo, dentro la transazione del salvataggio.
        Event::listen(SavingSettings::class, fn (SavingSettings $e) => ImpostazioniNelRegistro::salvataggio($e));
        // Le righe rimandate alla fine della transazione (decisioni 554 e 556): la «Creata» con i suoi legami, la scrittura con la sua cifra.
        Event::listen(TransactionCommitting::class, fn (TransactionCommitting $e) => RigheRimandate::primaDelCommit($e));
        Event::listen(TransactionCommitted::class, fn (TransactionCommitted $e) => RigheRimandate::dopoIlCommit($e));
        Event::listen(TransactionRolledBack::class, fn (TransactionRolledBack $e) => RigheRimandate::dopoIlRollback($e));
    }
}
