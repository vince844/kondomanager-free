<?php

/**
 * Coda 349 (decisione 184) — il rimando del riquadro «Gestione saldi pregressi» porta a una pagina che risponde.
 *
 * Il rimando «Vai ai saldi iniziali» della pagina dei piani rate (`PianiRateList.test.ts`) punta a `gestionale/:condominio/saldi` e compare solo
 * se un esercizio del condominio è aperto. Qui si provano i due fatti su cui poggia, dalla rotta: l'indirizzo che la pagina costruisce è quello
 * della rotta dei saldi iniziali, la pagina dei saldi si apre con un esercizio aperto, e senza ne rimanda indietro con un messaggio, cioè il
 * rimando non si offre dove la pagina rifiuterebbe.
 */

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('Coda 349 — l\'indirizzo che la pagina dei piani rate costruisce, con il prefisso del ruolo, è quello della rotta dei saldi iniziali', function () {
    $condominio = Condominio::factory()->create();

    // `generatePath('gestionale/:condominio/saldi', { condominio })` con il prefisso `admin` di `getRolePrefix()`.
    expect(route('admin.gestionale.saldi.index', [$condominio], false))->toBe("/admin/gestionale/{$condominio->id}/saldi");
});

it('Coda 349 — con un esercizio aperto la pagina dei saldi iniziali si apre', function () {
    [$condominio] = setupContabile();

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.saldi.index', [$condominio]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('gestionale/saldi/SaldiList'));
});

it('Coda 349 — senza un esercizio aperto la pagina dei saldi rimanda indietro con «Nessun esercizio aperto trovato», e il rimando non si offre', function () {
    [$condominio, $esercizio] = setupContabile();
    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    $this->actingAs($this->user)
        ->from(route('admin.gestionale.esercizi.piani-rate.index', [$condominio, $esercizio]))
        ->get(route('admin.gestionale.saldi.index', [$condominio]))
        ->assertRedirect(route('admin.gestionale.esercizi.piani-rate.index', [$condominio, $esercizio]))
        ->assertSessionHas('message.message', 'Nessun esercizio aperto trovato.');
});
