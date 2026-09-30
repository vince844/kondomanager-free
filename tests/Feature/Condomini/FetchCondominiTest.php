<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39 (PR #48 di kta1kri, portato con `git cherry-pick` e poi
 * corretto): `/fetch-condomini` restituiva l'elenco completo di ogni condominio a chiunque avesse
 * fatto il login, condòmini e fornitori compresi (`GET fetch-condomini` con solo `auth,verified`).
 *
 * La correzione della PR aggiungeva un ramo nel controller («l'amministratore vede tutto, gli
 * altri solo i propri condomìni»). La revisione ha trovato che l'unico chiamante di questa rotta
 * sono due schermate del pannello (`BuildingsDropdown.vue`, `useCondomini.ts`), quindi il modo più
 * semplice e più sicuro è spostare il controllo sulla rotta stessa — lo stesso predicato già usato
 * per `comuni.cerca` — e lasciare il controller con il suo corpo originale: chi ci arriva vede
 * sempre l'elenco intero.
 *
 * **Cosa resta scoperto**: il contenuto dell'elenco (nome e id) non cambia con questa beta, non è
 * riprovato qui; il comportamento di `role_or_permission` in sé, già coperto altrove.
 */

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->condomini = Condominio::factory()->count(3)->create();
});

test('l\'amministratore riceve tutti i condomini ordinati per nome', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::AMMINISTRATORE->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertOk();
    $nomi = collect($response->json())->pluck('nome')->all();
    expect($nomi)->toBe(collect($nomi)->sort()->values()->all());
    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe($this->condomini->pluck('id')->sort()->values()->all());
    expect(array_keys($response->json()[0]))->toEqualCanonicalizing(['id', 'nome']);
});

test('il collaboratore riceve tutti i condomini', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::COLLABORATORE->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertOk();
    expect($response->json())->toHaveCount(3);
});

test('un ruolo personalizzato con solo l\'accesso al pannello riceve comunque tutti i condomini', function () {
    // È il caso F1 della revisione: un ruolo su misura, con «Accesso pannello amministratore» ma
    // senza «Visualizza condomini» diretto, deve continuare a vedere il menu dei condomìni sulla
    // dashboard — la stessa guardia usata da comuni.cerca.
    SpatieRole::create(['name' => 'segreteria']);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('segreteria');
    $user->givePermissionTo(\App\Enums\Permission::ACCESS_ADMIN_PANEL->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertOk();
    expect($response->json())->toHaveCount(3);
});

test('il permesso diretto «Visualizza condomini» senza ruolo basta', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->givePermissionTo(\App\Enums\Permission::VIEW_CONDOMINI->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertOk();
    expect($response->json())->toHaveCount(3);
});

test('il condòmino non riceve l\'elenco dei condomìni', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertForbidden();
});

test('il fornitore non riceve l\'elenco dei condomìni', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::FORNITORE->value);

    $response = $this->actingAs($user)->getJson(route('fetch-condomini'));

    $response->assertForbidden();
});

test('un ospite viene rimandato al login', function () {
    $response = $this->get(route('fetch-condomini'));

    $response->assertRedirect(route('login'));
});

test('un utente non verificato viene rimandato alla verifica', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $user->assignRole(Role::AMMINISTRATORE->value);

    $response = $this->actingAs($user)->get(route('fetch-condomini'));

    $response->assertRedirect(route('verification.notice'));
});
