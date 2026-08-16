<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Registra l'ultimo accesso riuscito di un utente.
 *
 * ## Perché sull'evento `Login` e non in un controller
 *
 * Le porte d'ingresso sono tre — modulo, sfida a due fattori, ripristino da cookie *remember me* —
 * e tutte passano da `SessionGuard`, che emette questo evento. Scriverlo nei controller avrebbe
 * significato ripeterlo tre volte e dimenticarlo alla quarta: è la stessa lezione della beta.54,
 * la correzione va nel punto condiviso.
 *
 * L'evento **non** scatta a ogni richiesta: registra l'accesso, non l'attività. Chi è collegato
 * *adesso* lo dice la tabella `sessions`, che è un'altra domanda.
 *
 * ## Perché una `update` diretta e non `$user->update()`
 *
 * Un `update()` sul model toccherebbe `updated_at`, che da quel momento direbbe «ultimo accesso»
 * invece di «ultima modifica alla scheda»: due informazioni diverse collassate in una, e quella
 * che si perde è l'unica che racconta chi ha messo mano all'utenza.
 */
class AggiornaUltimoAccesso
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // ⚠️ **Un accesso respinto non è un accesso.** La sospensione si verifica *dopo* le
        // credenziali — per non dire a un estraneo quali account esistono e in che stato sono —
        // quindi `Auth::attempt()` riesce, emette questo evento, e solo allora il login rimanda
        // indietro. Senza questa riga la colonna direbbe che è entrato qualcuno che è stato
        // rimbalzato, e sarebbe la peggiore delle date: falsa e credibile.
        if ($event->user->suspended()) {
            return;
        }

        DB::table('users')
            ->where('id', $event->user->getKey())
            ->update(['last_login_at' => now()]);
    }
}
