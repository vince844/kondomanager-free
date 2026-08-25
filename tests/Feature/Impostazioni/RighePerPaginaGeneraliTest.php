<?php

/**
 * Il campo «righe per pagina» nelle impostazioni generali.
 *
 * ## Perché questo file esiste
 *
 * Alla beta.54 il modulo delle impostazioni generali non aveva **nessun** test, e questa beta ci ha
 * aggiunto un campo **obbligatorio**: se il modulo a video smettesse di mandarlo — un `v-model`
 * rinominato, un blocco spostato — il salvataggio fallirebbe con un 422 su *tutte* le impostazioni,
 * comprese quelle che nessuno ha toccato, e non ci sarebbe niente ad accorgersene.
 *
 * Non copre l'intero modulo, che resta scoperto: copre il campo introdotto qui e il fatto che
 * salvarlo non porti via con sé le altre impostazioni.
 */

use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(
        Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web'])
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole($ruolo);
});

/** Il modulo completo: le impostazioni si salvano tutte insieme, non una per volta. */
function moduloImpostazioni(array $modifiche = []): array
{
    return array_merge([
        'user_frontend_registration' => false,
        'force_comment_moderation'   => false,
        'language'                   => 'it',
        'app_name'                   => 'Kondomanager',
        'open_condominio_on_login'   => false,
        'default_condominio_id'      => null,
        'default_user_role'          => 'admin',
        'default_per_page'           => 30,
    ], $modifiche);
}

it('salva il valore scelto', function () {
    $this->actingAs($this->admin)
        ->post(route('impostazioni.generali.store'), moduloImpostazioni())
        ->assertSessionHasNoErrors();

    expect(app(GeneralSettings::class)->default_per_page)->toBe(30);
});

it('rifiuta un valore fuori dalla lista, invece di correggerlo in silenzio', function () {
    // ⚠️ Qui `Rule::in` è al posto giusto, al contrario che sul parametro d'indirizzo: questo è un
    // modulo che una persona compila, e un valore fuori lista va **detto**. Sull'indirizzo invece
    // si normalizza, perché un 422 su una `GET` di Inertia rimanda indietro senza spiegare niente.
    $this->actingAs($this->admin)
        ->post(route('impostazioni.generali.store'), moduloImpostazioni(['default_per_page' => 999]))
        ->assertSessionHasErrors('default_per_page');
});

it('non offre il 100, che il portale condòmino non saprebbe ridurre', function () {
    // Le pagine del portale mostrano schede e non hanno un selettore delle righe: con un valore
    // globale a 100, il condòmino si troverebbe cento schede da scorrere e nessun comando.
    $this->actingAs($this->admin)
        ->post(route('impostazioni.generali.store'), moduloImpostazioni(['default_per_page' => 100]))
        ->assertSessionHasErrors('default_per_page');
});

it('il campo mancante non passa inosservato', function () {
    $modulo = moduloImpostazioni();
    unset($modulo['default_per_page']);

    $this->actingAs($this->admin)
        ->post(route('impostazioni.generali.store'), $modulo)
        ->assertSessionHasErrors('default_per_page');
});

it('la pagina espone il valore e le opzioni che il modulo deve mostrare', function () {
    $risposta = $this->actingAs($this->admin)->get(route('impostazioni.generali'));

    $props = $risposta->viewData('page')['props'];

    expect($props['default_per_page'])->toBeInt()
        ->and($props['per_page_disponibili'])->toContain(10)
        ->and($props['per_page_disponibili'])->not->toContain(100);
});
