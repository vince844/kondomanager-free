<?php

/**
 * # Togliere la verifica dell'email ha le guardie di sospensione ed eliminazione (Coda 399)
 *
 * Lo scudo della verifica, nella colonna del nome dell'elenco utenti, manda `PUT utenti/{user}/toggle-verification`
 * (`UserVerifyController`). Il middleware `verified` sta su tutto il programma: un account senza verifica non arriva da
 * nessuna parte finché non apre il link che riceve per posta, come dopo «Reinvia». Per questo togliere la verifica ha le
 * stesse guardie di sospensione, eliminazione e «Reinvia» (`UserPolicy::revocaVerifica`): non sul proprio account, non
 * sull'ultimo amministratore attivo, e chi non è amministratore solo su un account nella sua portata. Il rifiuto dice
 * perché, e la verifica resta; sul proprio account nomina l'azione chiesta (togliere la verifica, reinvitare). Rimettere
 * la verifica non chiude fuori nessuno, e resta come la chiede la scheda.
 *
 * Cosa NON copre: la portata e il permesso della rotta (`ChiPuoToccareChiTest.php`, `PorteLateraliTest.php`); lo scudo
 * della pagina (`resources/js/components/users/columns.test.ts`).
 */

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->amministratore = verificaUtente(Role::AMMINISTRATORE->value);
});

function verificaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function verificaScudo($test, User $chi, User $bersaglio)
{
    return $test->actingAs($chi)->from('/utenti')->put(route('utenti.toggle-verification', $bersaglio));
}

test('Coda 399 — l\'ultimo amministratore attivo non si toglie la verifica dell\'email, e il pannello resta suo', function () {
    expect($this->amministratore->isUltimoAmministratoreAttivo())->toBeTrue();

    verificaScudo($this, $this->amministratore, $this->amministratore)->assertForbidden();

    expect($this->amministratore->fresh()->email_verified_at)->not->toBeNull();
    $this->actingAs($this->amministratore->fresh())->get(route('admin.dashboard'))->assertOk();
});

test('Coda 399 — nessuno si toglie la verifica dell\'email da solo, nemmeno l\'amministratore con un secondo amministratore attivo', function () {
    verificaUtente(Role::AMMINISTRATORE->value);
    $collaboratore = verificaUtente(Role::COLLABORATORE->value);

    $this->actingAs($this->amministratore)->putJson(route('utenti.toggle-verification', $this->amministratore))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_self_unverify'));
    // Il collaboratore non ha il proprio account nella sua portata (Coda 258): i propri dati si correggono dal profilo.
    $this->actingAs($collaboratore)->putJson(route('utenti.toggle-verification', $collaboratore))->assertForbidden();

    expect($this->amministratore->fresh()->email_verified_at)->not->toBeNull()
        ->and($collaboratore->fresh()->email_verified_at)->not->toBeNull();
});

test('Coda 399 — con un secondo amministratore attivo, l\'amministratore toglie e rimette la verifica all\'altro', function () {
    $secondo = verificaUtente(Role::AMMINISTRATORE->value);

    verificaScudo($this, $this->amministratore, $secondo)->assertRedirect('/utenti');
    expect($secondo->fresh()->email_verified_at)->toBeNull();

    verificaScudo($this, $this->amministratore, $secondo->fresh())->assertRedirect('/utenti');
    expect($secondo->fresh()->email_verified_at)->not->toBeNull();
});

test('Coda 399 — l\'amministratore e il collaboratore tolgono e rimettono la verifica a un condòmino', function (string $ruolo) {
    $chi = verificaUtente($ruolo);
    $condomino = verificaUtente(Role::UTENTE->value);

    verificaScudo($this, $chi, $condomino)->assertRedirect('/utenti');
    expect($condomino->fresh()->email_verified_at)->toBeNull();

    verificaScudo($this, $chi, $condomino->fresh())->assertRedirect('/utenti');
    expect($condomino->fresh()->email_verified_at)->not->toBeNull();
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('Coda 399 — sul proprio account il rifiuto nomina l\'azione che non si fa: togliere la verifica e reinvitare', function () {
    verificaUtente(Role::AMMINISTRATORE->value);

    $this->actingAs($this->amministratore)->putJson(route('utenti.toggle-verification', $this->amministratore))
        ->assertForbidden()
        ->assertJsonPath('message', "Non puoi togliere la verifica dell'email al tuo stesso account.");
    $this->actingAs($this->amministratore)
        ->postJson(route('utenti.reinvite', ['email' => $this->amministratore->email]), ['email' => $this->amministratore->email])
        ->assertForbidden()
        ->assertJsonPath('message', "Non puoi reinvitare il tuo stesso account: la password si cambia nelle impostazioni, in «Password».");

    expect($this->amministratore->fresh()->email_verified_at)->not->toBeNull();
});
