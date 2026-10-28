<?php

/**
 * # «Reinvita» ha le guardie di sospensione ed eliminazione (Coda 399)
 *
 * «Reinvita» azzera la password del bersaglio e gli manda un nuovo link del primo accesso: finché non lo apre, l'account
 * è chiuso fuori, come sospeso (una password vuota chiude anche le sue sessioni). Valgono quindi le
 * due invarianti di sospensione ed eliminazione (`UserPolicy::guardieDiIntegrita`): non su sé stessi, non sull'ultimo
 * amministratore attivo. Così l'installazione resta governabile anche se la posta non parte. Il rifiuto dice perché,
 * con le frasi di sospensione ed eliminazione; la password resta e nessun link parte.
 *
 * Cosa NON copre: chi può reinvitare chi per la portata (`PrivilegiUtentiTest.php`, `PorteLateraliTest.php`,
 * `ChiPuoToccareChiTest.php`); i dispositivi dell'utente reinvitato (`ReinviaTest.php`); il menù della riga
 * (`resources/js/components/users/DataTableRowActions.test.ts`).
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
});

function reinviaGuardieUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** «Reinvita» come lo manda il menù della riga (`DataTableRowActions.vue`). */
function reinviaGuardieChiama($test, User $chi, User $bersaglio)
{
    return $test->actingAs($chi)->from(route('utenti.index'))->postJson(route('utenti.reinvite', ['email' => $bersaglio->email]), ['email' => $bersaglio->email]);
}

test('Coda 399 — l\'ultimo amministratore attivo non reinvita sé stesso: la password resta e non parte nessun link', function () {
    $passwordPrima = $this->amministratore->password;

    reinviaGuardieChiama($this, $this->amministratore, $this->amministratore)
        ->assertForbidden()->assertJsonPath('message', __('policies.users_self_reinvite'));

    expect($this->amministratore->fresh()->password)->toBe($passwordPrima);
    Notification::assertNothingSent();
});

test('Coda 399 — nessuno reinvita sé stesso, nemmeno con un secondo amministratore in casa', function () {
    reinviaGuardieUtente(RoleEnum::AMMINISTRATORE->value);
    $passwordPrima = $this->amministratore->password;

    reinviaGuardieChiama($this, $this->amministratore, $this->amministratore)
        ->assertForbidden()->assertJsonPath('message', __('policies.users_self_reinvite'));

    expect($this->amministratore->fresh()->password)->toBe($passwordPrima);
    Notification::assertNothingSent();
});

test('Coda 399 — chi ha «Modifica utenti» non reinvita l\'ultimo amministratore attivo, e il rifiuto dice che è l\'ultimo', function () {
    $collaboratore = reinviaGuardieUtente(RoleEnum::COLLABORATORE->value);
    $passwordPrima = $this->amministratore->password;

    reinviaGuardieChiama($this, $collaboratore, $this->amministratore)
        ->assertForbidden()->assertJsonPath('message', __('policies.users_last_admin'));

    expect($this->amministratore->fresh()->password)->toBe($passwordPrima);
    Notification::assertNothingSent();
});

test('Coda 399 — con un secondo amministratore attivo, l\'amministratore reinvita l\'altro, e reinvita il collaboratore e il condòmino', function () {
    $secondo = reinviaGuardieUtente(RoleEnum::AMMINISTRATORE->value);
    $collaboratore = reinviaGuardieUtente(RoleEnum::COLLABORATORE->value);
    $condomino = reinviaGuardieUtente(RoleEnum::UTENTE->value);

    foreach ([$secondo, $collaboratore, $condomino] as $bersaglio) {
        $this->actingAs($this->amministratore)->from(route('utenti.index'))
            ->post(route('utenti.reinvite', ['email' => $bersaglio->email]), ['email' => $bersaglio->email])
            ->assertRedirect(route('utenti.index'))
            ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

        expect($bersaglio->fresh()->password)->toBeNull();
        Notification::assertSentTo($bersaglio, NewUserEmailNotification::class);
    }
});

test('Coda 399 — un amministratore sospeso non è l\'ultimo attivo: l\'amministratore lo reinvita', function () {
    $sospeso = reinviaGuardieUtente(RoleEnum::AMMINISTRATORE->value);
    $sospeso->update(['suspended_at' => now()]);

    $this->actingAs($this->amministratore)->from(route('utenti.index'))
        ->post(route('utenti.reinvite', ['email' => $sospeso->email]), ['email' => $sospeso->email])
        ->assertRedirect(route('utenti.index'));

    expect($sospeso->fresh()->password)->toBeNull();
});
