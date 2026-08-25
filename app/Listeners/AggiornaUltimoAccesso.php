<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *
 * ## ⚠️ Perché si controlla che la colonna esista — il difetto che ha chiuso fuori un amministratore
 *
 * Segnalato il 22/08/2026 aggiornando da una beta.50 a una beta.63: dopo il login compariva
 * **500 — `Unknown column 'last_login_at' in 'field list'`**, e da lì non si andava avanti.
 *
 * La colonna nasce nella migrazione `2026_08_16_120000`, che gira dalla pagina di aggiornamento del
 * database. Ma a quella pagina si arriva **dopo aver fatto il login**, e il login passa di qui. È un
 * cane che si morde la coda: **l'aggiornamento richiede il login, e il login richiedeva
 * l'aggiornamento.** Chi aggiornava da una versione precedente restava murato fuori dal proprio
 * gestionale, senza nessuna strada che non fosse una query a mano.
 *
 * Registrare l'ultimo accesso è **contabilità, non autenticazione**: se non si può fare, non deve
 * impedire di entrare. La guardia costa una domanda allo schema per login — non per richiesta — e
 * si può togliere quando la 1.10 sarà l'unica versione da cui si aggiorna.
 *
 * ⚠️ **La classe è più larga di questa colonna**, ed è quello che presidia
 * `tests/Feature/System/AggiornamentoDaVersioneVecchiaTest.php`: qualunque cosa giri **prima** della
 * pagina di aggiornamento e **scriva** su una tabella che l'aggiornamento modifica produce lo stesso
 * blocco. Leggere non fa danno — Eloquent restituisce `null` per una colonna che non c'è — a rompere
 * è solo la scrittura.
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

        // Vedi la nota nel blocco qui sopra: durante l'aggiornamento da una versione precedente
        // la colonna non esiste ancora, e senza questa riga il login risponde 500.
        if (! Schema::hasColumn('users', 'last_login_at')) {
            return;
        }

        DB::table('users')
            ->where('id', $event->user->getKey())
            ->update(['last_login_at' => now()]);
    }
}
