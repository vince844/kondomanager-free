<?php

/**
 * # Le categorie dei fornitori chiedono i permessi delle anagrafiche (Coda 402)
 *
 * La pagina delle categorie dei fornitori (`Fornitori\Categorie\CategoriaFornitoreController`) elenca ogni categoria
 * con i fornitori che la usano; creare, rinominare ed eliminare una categoria cambia la classificazione dell'archivio
 * dei fornitori. `CategoriaFornitorePolicy` chiede i permessi delle anagrafiche, come i fornitori: l'elenco
 * «Visualizza anagrafiche»; creare una categoria «Crea anagrafiche» o «Modifica anagrafiche»; rinominarla ed eliminarla
 * «Modifica anagrafiche». Creare basta «Crea anagrafiche» perché il «+» accanto alla tendina «Categoria fornitore» sta
 * anche nel modulo «Nuovo fornitore», che si apre con «Crea anagrafiche»: chi può compilare quel modulo può aggiungere
 * la categoria che manca, come per la categoria al volo nei moduli dei documenti. Chi non ha il permesso riceve il
 * rifiuto della policy prima della validazione, quindi nessun esito della regola di unicità del nome, e il rifiuto dice
 * l'azione che manca: creare, oppure rinominare ed eliminare (chi ha il solo «Crea anagrafiche» crea e non rinomina).
 *
 * I ruoli di serie: l'amministratore e il collaboratore hanno «Visualizza», «Crea» e «Modifica anagrafiche», e fanno
 * tutto ciò che la regola permette.
 *
 * Cosa NON copre: i comandi delle pagine (`resources/js/pages/fornitori/ComandiDellePagineDeiFornitori.test.ts`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CategoriaFornitore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    // Un nome che la migrazione iniziale non spedisce.
    $this->vetraio = CategoriaFornitore::create(['name' => 'Vetraio', 'description' => 'Vetri e specchi']);
});

function catFornUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function catFornSuMisura(array $permessi): User
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return catFornUtente('Su misura');
}

/** Crea, rinomina ed elimina come le mandano `CategorieList.vue` e `NuovaCategoriaFornitore.vue`. */
function catFornScritture($test, User $chi, CategoriaFornitore $daEliminare): array
{
    return [
        'crea' => $test->actingAs($chi)->from(route('admin.categorie-fornitore.index'))->post(route('admin.categorie-fornitore.store'), ['name' => 'Antennista di prova', 'description' => null]),
        'rinomina' => $test->actingAs($chi)->from(route('admin.categorie-fornitore.index'))->put(route('admin.categorie-fornitore.update', $test->vetraio), ['name' => 'Vetraio e specchiaio', 'description' => 'Vetri e specchi']),
        'elimina' => $test->actingAs($chi)->from(route('admin.categorie-fornitore.index'))->delete(route('admin.categorie-fornitore.destroy', $daEliminare)),
    ];
}

test('Coda 402 — con il solo accesso al pannello non si apre l\'elenco delle categorie dei fornitori e non si crea, rinomina o elimina una categoria', function () {
    $solo = catFornSuMisura([]);
    $daEliminare = CategoriaFornitore::create(['name' => 'Da eliminare di prova']);

    $this->actingAs($solo)->get(route('admin.categorie-fornitore.index'))->assertForbidden();
    foreach (catFornScritture($this, $solo, $daEliminare) as $risposta) {
        $risposta->assertForbidden();
    }

    expect(CategoriaFornitore::where('name', 'Antennista di prova')->exists())->toBeFalse()
        ->and($this->vetraio->fresh()->name)->toBe('Vetraio')
        ->and(CategoriaFornitore::whereKey($daEliminare->id)->exists())->toBeTrue();
});

test('Coda 402 — con «Visualizza anagrafiche» si apre l\'elenco delle categorie dei fornitori; creare, rinominare ed eliminare chiedono «Modifica anagrafiche»', function () {
    $consulta = catFornSuMisura([Permission::VIEW_ANAGRAFICHE->value]);
    $daEliminare = CategoriaFornitore::create(['name' => 'Da eliminare di prova']);

    $categorie = $this->actingAs($consulta)->get(route('admin.categorie-fornitore.index'))->assertOk()->viewData('page')['props']['categorie'];
    expect(collect($categorie)->pluck('name')->all())->toContain('Vetraio');

    foreach (catFornScritture($this, $consulta, $daEliminare) as $risposta) {
        $risposta->assertForbidden();
    }
    expect($this->vetraio->fresh()->name)->toBe('Vetraio')
        ->and(CategoriaFornitore::whereKey($daEliminare->id)->exists())->toBeTrue();
});

test('Coda 402 — con «Modifica anagrafiche» si crea, si rinomina e si elimina una categoria dei fornitori', function (string $chiSalva) {
    $chi = $chiSalva === 'Su misura' ? catFornSuMisura([Permission::EDIT_ANAGRAFICHE->value]) : catFornUtente($chiSalva);
    $daEliminare = CategoriaFornitore::create(['name' => 'Da eliminare di prova']);

    foreach (catFornScritture($this, $chi, $daEliminare) as $risposta) {
        $risposta->assertRedirect(route('admin.categorie-fornitore.index'));
        $risposta->assertSessionHasNoErrors();
    }

    expect(CategoriaFornitore::where('name', 'Antennista di prova')->exists())->toBeTrue()
        ->and($this->vetraio->fresh()->name)->toBe('Vetraio e specchiaio')
        ->and(CategoriaFornitore::whereKey($daEliminare->id)->exists())->toBeFalse();
})->with([
    'un ruolo su misura con il solo «Modifica anagrafiche»' => ['Su misura'],
    'il collaboratore di serie' => [Role::COLLABORATORE->value],
]);

test('Coda 402 — l\'amministratore apre l\'elenco e crea, rinomina ed elimina le categorie dei fornitori', function () {
    $admin = catFornUtente(Role::AMMINISTRATORE->value);
    $daEliminare = CategoriaFornitore::create(['name' => 'Da eliminare di prova']);

    $this->actingAs($admin)->get(route('admin.categorie-fornitore.index'))->assertOk();
    foreach (catFornScritture($this, $admin, $daEliminare) as $risposta) {
        $risposta->assertSessionHasNoErrors();
    }

    expect(CategoriaFornitore::where('name', 'Antennista di prova')->exists())->toBeTrue()
        ->and(CategoriaFornitore::whereKey($daEliminare->id)->exists())->toBeFalse();
});

test('Coda 402 — chi non può creare o rinominare una categoria dei fornitori riceve il rifiuto prima della validazione, anche con un nome già usato', function (array $permessi) {
    $chi = catFornSuMisura($permessi);

    $this->actingAs($chi)->post(route('admin.categorie-fornitore.store'), ['name' => 'Vetraio'])->assertForbidden();
    expect(session('errors'))->toBeNull();
    $altra = CategoriaFornitore::create(['name' => 'Fabbro di prova']);
    $this->actingAs($chi)->put(route('admin.categorie-fornitore.update', $altra), ['name' => 'Vetraio'])->assertForbidden();
    expect(session('errors'))->toBeNull();

    $this->actingAs($chi)->postJson(route('admin.categorie-fornitore.store'), ['name' => 'Vetraio'])
        ->assertForbidden()->assertJson(['message' => 'Non hai permessi sufficienti per creare le categorie dei fornitori!'])->assertJsonMissingPath('errors');
    $this->actingAs($chi)->putJson(route('admin.categorie-fornitore.update', $altra), ['name' => 'Vetraio'])
        ->assertForbidden()->assertJson(['message' => __('fornitori.categorie.permessi.update')])->assertJsonMissingPath('errors');

    expect($altra->fresh()->name)->toBe('Fabbro di prova');
})->with([
    'il solo accesso' => [[]],
    'la sola consultazione' => [[Permission::VIEW_ANAGRAFICHE->value]],
    '«Crea utenti» e «Modifica utenti»' => [[Permission::CREATE_USERS->value, Permission::EDIT_USERS->value]],
]);

/** Il «+» del modulo «Nuovo fornitore»: la categoria si crea e si torna al modulo, da cui la tendina si rinfresca. */
function catFornPiu($test, User $chi, string $nome): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($chi)->from(route('admin.fornitori.create'))->post(route('admin.categorie-fornitore.store'), ['name' => $nome, 'description' => null]);
}

test('Coda 402 — «Crea anagrafiche» basta per creare una categoria dal «+» del modulo «Nuovo fornitore»', function () {
    $crea = catFornSuMisura([Permission::CREATE_ANAGRAFICHE->value]);

    // Il modulo si apre col solo «Crea anagrafiche», e la categoria nuova compare nella sua tendina.
    $this->actingAs($crea)->get(route('admin.fornitori.create'))->assertOk();
    catFornPiu($this, $crea, 'Antennista di prova')->assertRedirect(route('admin.fornitori.create'))->assertSessionHasNoErrors();

    $categorie = $this->actingAs($crea)->get(route('admin.fornitori.create'))->assertOk()->viewData('page')['props']['categorie'];
    expect(CategoriaFornitore::where('name', 'Antennista di prova')->exists())->toBeTrue()
        ->and(collect($categorie)->pluck('name')->all())->toContain('Antennista di prova', 'Vetraio');

    // Il nome già usato si rifiuta con la regola di unicità, che per chi può creare viene dopo il permesso.
    catFornPiu($this, $crea, 'Vetraio')->assertSessionHasErrors('name');
    expect(CategoriaFornitore::where('name', 'Vetraio')->count())->toBe(1);
});

test('Coda 402 — «Crea anagrafiche» non basta per rinominare né eliminare una categoria dei fornitori, e nemmeno per aprire l\'elenco', function () {
    $crea = catFornSuMisura([Permission::CREATE_ANAGRAFICHE->value]);
    $daEliminare = CategoriaFornitore::create(['name' => 'Da eliminare di prova']);

    $this->actingAs($crea)->get(route('admin.categorie-fornitore.index'))->assertForbidden();
    $this->actingAs($crea)->from(route('admin.categorie-fornitore.index'))->put(route('admin.categorie-fornitore.update', $this->vetraio), ['name' => 'Vetraio e specchiaio'])->assertForbidden();
    $this->actingAs($crea)->from(route('admin.categorie-fornitore.index'))->delete(route('admin.categorie-fornitore.destroy', $daEliminare))->assertForbidden();
    // Il rifiuto viene prima della validazione, anche con un nome già usato, e dice che cosa manca a chi può creare.
    $this->actingAs($crea)->putJson(route('admin.categorie-fornitore.update', $this->vetraio), ['name' => 'Vetraio'])
        ->assertForbidden()->assertJson(['message' => 'Non hai permessi sufficienti per rinominare o eliminare le categorie dei fornitori!'])->assertJsonMissingPath('errors');
    $this->actingAs($crea)->deleteJson(route('admin.categorie-fornitore.destroy', $daEliminare))
        ->assertForbidden()->assertJson(['message' => 'Non hai permessi sufficienti per rinominare o eliminare le categorie dei fornitori!']);

    expect($this->vetraio->fresh()->name)->toBe('Vetraio')
        ->and(CategoriaFornitore::whereKey($daEliminare->id)->exists())->toBeTrue();
});

test('Coda 402 — il collaboratore di serie crea una categoria dei fornitori dal «+» del modulo «Nuovo fornitore»', function () {
    $collaboratore = catFornUtente(Role::COLLABORATORE->value);

    catFornPiu($this, $collaboratore, 'Antennista di prova')->assertRedirect(route('admin.fornitori.create'))->assertSessionHasNoErrors();

    expect(CategoriaFornitore::where('name', 'Antennista di prova')->exists())->toBeTrue();
});
