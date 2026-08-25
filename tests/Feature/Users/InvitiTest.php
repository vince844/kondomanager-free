<?php

/**
 * Chi può invitare qualcuno dentro l'installazione.
 *
 * ## Perché questo file esiste
 *
 * `Route::resource('/inviti')` monta solo `auth` e `verified`, e `InvitoController` non contiene
 * **nessun** `Gate::authorize`. Un invito non è una scalata di privilegi — chi accetta si registra
 * con il ruolo predefinito delle impostazioni, e la tabella `inviti` non ha nemmeno una colonna
 * per il ruolo — ma restano tre azioni di governo alla portata di chiunque:
 *
 * 1. **leggere** l'elenco degli invitati, cioè gli indirizzi email di persone in ingresso;
 * 2. **spedire** email di invito a indirizzi scelti da chi vuole, con il nostro dominio;
 * 3. **cancellare** un invito in sospeso, che è sabotaggio dell'accoglienza.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la registrazione via invito (`/invito/register`), che passa da un link firmato e dal
 * ruolo predefinito, né la scadenza degli inviti.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Invito;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

it('un condòmino non può leggere l\'elenco degli invitati', function () {
    $this->actingAs($this->condomino)
        ->get(route('inviti.index'))
        ->assertForbidden();
});

it('un condòmino non può spedire un invito', function () {
    $this->actingAs($this->condomino)
        ->post(route('inviti.store'), [
            'email'          => 'estraneo@example.test',
            'building_codes' => [],
        ])
        ->assertForbidden();

    expect(Invito::where('email', 'estraneo@example.test')->exists())->toBeFalse();
});

it('un condòmino non può cancellare un invito in sospeso', function () {
    $invito = Invito::create([
        'email'          => 'in.arrivo@example.test',
        'building_codes' => [],
        'expires_at'     => now()->addWeek(),
    ]);

    $this->actingAs($this->condomino)
        ->delete(route('inviti.destroy', ['inviti' => $invito->id]))
        ->assertForbidden();

    expect(Invito::find($invito->id))->not->toBeNull();
});

it('l\'amministratore continua a vedere l\'elenco', function () {
    $this->actingAs($this->amministratore)
        ->get(route('inviti.index'))
        ->assertOk();
});
