<?php

/**
 * # Si entra anche quando il database è ancora quello di prima
 *
 * ## Il difetto, segnalato il 22/08/2026
 *
 * Aggiornando da una beta.50 a una beta.63, subito dopo il login compariva:
 *
 *     500 — SQLSTATE[42S22]: Unknown column 'last_login_at' in 'field list'
 *     update `users` set `last_login_at` = … where `id` = 1
 *
 * La colonna nasce nella migrazione `2026_08_16_120000`, che gira **dalla pagina di aggiornamento
 * del database**. Ma a quella pagina si arriva **dopo aver fatto il login**, e il login emette
 * l'evento che scrive quella colonna.
 *
 * **L'aggiornamento richiedeva il login, e il login richiedeva l'aggiornamento.** Chi veniva da una
 * versione precedente restava chiuso fuori dal proprio gestionale, e l'unica uscita era una query a
 * mano sul database — cioè nessuna uscita, per un amministratore.
 *
 * ## Perché il perimetro è quello che è
 *
 * Misurato aprendo la beta.65:
 *
 * - `AggiornaUltimoAccesso` è **l'unico** ascoltatore dell'evento `Login`, e la sua è l'unica
 *   scrittura su quel percorso;
 * - `suspended_at`, che quel listener legge prima di scrivere, viene da una migrazione del **2025**
 *   e precede il salto 1.9.1 → 1.10;
 * - **leggere** una colonna che non c'è non rompe niente — Eloquent restituisce `null` — quindi la
 *   famiglia pericolosa è quella delle **scritture**;
 * - l'altra superficie che gira a ogni richiesta, `SetAppNameMiddleware`, legge le impostazioni ed
 *   è già protetta da un `try/catch`.
 *
 * ## Cosa questo file presidia davvero
 *
 * Non «che `last_login_at` funzioni»: quella è una riga. Presidia **l'invariante del percorso di
 * aggiornamento** — *si deve poter entrare con lo schema di prima* — perché ogni colonna nuova su
 * `users` è un'occasione per rimetterlo. Il difetto non è stato trovato da nessuna prova
 * automatica: l'ha trovato Vincenzo provando l'aggiornamento a mano.
 *
 * ## Cosa NON copre
 *
 * - **Non simula l'intero salto 1.9.1 → 1.10**: toglie le colonne che l'aggiornamento aggiunge alle
 *   tabelle toccate dal login, che è la parte che conta. Le altre tabelle non sono sul percorso.
 * - **Non copre la pagina di aggiornamento in sé**, che ha le sue prove.
 */

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as SpatiePermission;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // ⚠️ Il permesso deve **esistere** anche se non lo si concede a nessuno: dopo il login
    // `RedirectHelper` decide dove mandare l'utente con `hasPermissionTo()`, e Spatie **solleva**
    // se il permesso non è mai stato creato. Senza questa riga il login risponde 500 per una
    // ragione che non c'entra niente con quello che si sta provando, e la guardia sembrerebbe
    // rossa per il difetto giusto.
    SpatiePermission::findOrCreate(Permission::ACCESS_ADMIN_PANEL->value, 'web');
});

/**
 * Le colonne che l'aggiornamento aggiunge a `users` e che quindi, venendo da una versione
 * precedente, **non ci sono ancora**.
 *
 * ⚠️ Va tenuto allineato: una colonna nuova su `users` va aggiunta qui, altrimenti questa guardia
 * continua a essere verde su uno scenario che non è più quello vero. È lo stesso patto del dataset
 * di `UpgradeMigrationsRerunTest`.
 *
 * @return list<string>
 */
function colonneCheLAggiornamentoAggiungeAUsers(): array
{
    return ['last_login_at'];
}

/** Porta `users` allo stato in cui si trova chi non ha ancora aggiornato. */
function riportaUsersAllaVersionePrecedente(): void
{
    foreach (colonneCheLAggiornamentoAggiungeAUsers() as $colonna) {
        if (Schema::hasColumn('users', $colonna)) {
            Schema::table('users', function ($table) use ($colonna) {
                $table->dropColumn($colonna);
            });
        }
    }
}

it('lo scenario è quello vero: le colonne esistono prima, e sparite dopo', function () {
    // ⚠️ Senza questo, il giorno che una colonna cambia nome la guardia proverebbe uno scenario che
    // non esiste — verde per sempre, e per la peggiore delle ragioni.
    foreach (colonneCheLAggiornamentoAggiungeAUsers() as $colonna) {
        expect(Schema::hasColumn('users', $colonna))->toBeTrue(
            "La colonna «{$colonna}» non esiste più: l'elenco in questo file va aggiornato."
        );
    }

    riportaUsersAllaVersionePrecedente();

    foreach (colonneCheLAggiornamentoAggiungeAUsers() as $colonna) {
        expect(Schema::hasColumn('users', $colonna))->toBeFalse();
    }
});

it('⚠️ si entra anche con lo schema di prima dell\'aggiornamento', function () {
    // È il difetto. Prima della correzione questa richiesta rispondeva 500 e l'amministratore non
    // aveva nessun modo di arrivare alla pagina che avrebbe sistemato il database.
    $utente = User::factory()->create(['email_verified_at' => now()]);

    riportaUsersAllaVersionePrecedente();

    $risposta = $this->post('/login', [
        'email'    => $utente->email,
        'password' => 'password',
    ]);

    expect($risposta->status())->not->toBe(500,
        "Il login risponde 500 con lo schema precedente all'aggiornamento.\n\n".
        "È il cane che si morde la coda: per aggiornare il database bisogna entrare, e per entrare\n".
        "serviva il database già aggiornato. Chi viene da una versione precedente resta chiuso\n".
        "fuori dal proprio gestionale.\n\n".
        "Causa quasi certa: qualcosa sul percorso del login **scrive** su una tabella che\n".
        "l'aggiornamento modifica. Leggere non fa danno, scrivere sì."
    );

    $this->assertAuthenticated();
});

it('e con lo schema aggiornato la data viene registrata davvero', function () {
    // ⚠️ **La controprova, e non è un di più.** Senza, la guardia sopra si soddisfa anche
    // cancellando del tutto la registrazione dell'ultimo accesso: il login non risponderebbe più
    // 500 e nessun test se ne accorgerebbe. Qui si prova che la funzione c'è ancora.
    $utente = User::factory()->create(['email_verified_at' => now(), 'last_login_at' => null]);

    $this->post('/login', [
        'email'    => $utente->email,
        'password' => 'password',
    ]);

    expect(DB::table('users')->where('id', $utente->id)->value('last_login_at'))->not->toBeNull();
});
