<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il permesso si controlla prima dei campi.
 *
 * Chi non può creare un utente, un condominio o un fornitore, o modificarlo, riceve il rifiuto (403) anche se manda un modulo
 * con un'email, un nome o una partita IVA già usati: la risposta non dice se quel valore c'è già. Chi ha il permesso riceve
 * l'errore sul campo, come sempre. Una cassa si salva solo dal condominio a cui appartiene: da un altro la rotta non la trova
 * (404: le rotte del gestionale cercano la cassa dentro il condominio), prima dei campi. Nessuna scrittura in nessun caso rifiutato.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    // Un ruolo su misura che entra nel pannello e guarda, ma non crea e non modifica.
    $osservatore = Role::create(['name' => 'osservatore', 'guard_name' => 'web', 'description' => 'Guarda soltanto']);
    $osservatore->givePermissionTo([
        PermissionEnum::ACCESS_ADMIN_PANEL->value,
        PermissionEnum::VIEW_USERS->value,
        PermissionEnum::VIEW_CONDOMINI->value,
        PermissionEnum::VIEW_ANAGRAFICHE->value,
    ]);
    $this->osservatore = User::factory()->create(['email_verified_at' => now()]);
    $this->osservatore->assignRole('osservatore');

    $this->giaUsata = User::factory()->create(['email' => 'gia.usata@example.com', 'email_verified_at' => now()]);
    $this->utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
});

function moduloCondominio(array $modifiche = []): array
{
    return array_merge([
        'nome'           => 'Condominio Aurora',
        'codice_fiscale' => '97000000001',
        'indirizzo'      => 'Via Roma 1',
        'cap'            => '00100',
        'comune'         => 'Roma',
        'provincia'      => 'RM',
    ], $modifiche);
}

// ── Utenti ───────────────────────────────────────────────────────────────────────────────────────────────

it('chi non può creare utenti riceve 403 anche con un\'email già usata, e nessun utente nasce', function () {
    $prima = User::count();

    $this->actingAs($this->osservatore)
        ->post(route('utenti.store'), ['name' => 'Mario', 'email' => 'gia.usata@example.com', 'roles' => $this->utente->id])
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors('email');

    expect(User::count())->toBe($prima);
});

it('chi può creare utenti riceve l\'errore sull\'email già usata', function () {
    $this->actingAs($this->collaboratore)
        ->post(route('utenti.store'), ['name' => 'Mario', 'email' => 'gia.usata@example.com', 'roles' => $this->utente->id])
        ->assertRedirect()
        ->assertSessionHasErrors('email');
});

// ── Condomini ────────────────────────────────────────────────────────────────────────────────────────────

it('chi non può creare condomini riceve 403 anche con un nome già usato, e nessun condominio nasce', function () {
    Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $prima = Condominio::count();

    $this->actingAs($this->osservatore)
        ->post(route('condomini.store'), moduloCondominio())
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors('nome');

    expect(Condominio::count())->toBe($prima);
});

it('chi può creare condomini riceve l\'errore sul nome già usato', function () {
    Condominio::factory()->create(['nome' => 'Condominio Aurora']);

    $this->actingAs($this->collaboratore)
        ->post(route('condomini.store'), moduloCondominio())
        ->assertRedirect()
        ->assertSessionHasErrors('nome');
});

it('chi non può modificare condomini riceve 403 anche con il nome di un altro, e il condominio resta com\'era', function () {
    Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $belvedere = Condominio::factory()->create(['nome' => 'Condominio Belvedere']);

    $this->actingAs($this->osservatore)
        ->put(route('condomini.update', $belvedere), moduloCondominio())
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors('nome');

    expect($belvedere->fresh()->nome)->toBe('Condominio Belvedere');
});

it('chi può modificare condomini riceve l\'errore sul nome di un altro', function () {
    Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $belvedere = Condominio::factory()->create(['nome' => 'Condominio Belvedere']);

    $this->actingAs($this->collaboratore)
        ->put(route('condomini.update', $belvedere), moduloCondominio())
        ->assertRedirect()
        ->assertSessionHasErrors('nome');
});

// ── Fornitori ────────────────────────────────────────────────────────────────────────────────────────────

it('chi non può creare fornitori riceve 403 anche con una ragione sociale già usata, e nessun fornitore nasce', function () {
    Fornitore::create(['ragione_sociale' => 'Idraulica Rossi']);
    $prima = Fornitore::count();

    $this->actingAs($this->osservatore)
        ->post(route('admin.fornitori.store'), ['ragione_sociale' => 'Idraulica Rossi'])
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors('ragione_sociale');

    expect(Fornitore::count())->toBe($prima);
});

it('chi può creare fornitori riceve l\'errore sulla ragione sociale già usata', function () {
    Fornitore::create(['ragione_sociale' => 'Idraulica Rossi']);

    $this->actingAs($this->collaboratore)
        ->post(route('admin.fornitori.store'), ['ragione_sociale' => 'Idraulica Rossi'])
        ->assertRedirect()
        ->assertSessionHasErrors('ragione_sociale');
});

it('chi non può modificare fornitori riceve 403 anche con la ragione sociale di un altro, e il fornitore resta com\'era', function () {
    Fornitore::create(['ragione_sociale' => 'Idraulica Rossi']);
    $bianchi = Fornitore::create(['ragione_sociale' => 'Elettrica Bianchi']);

    $this->actingAs($this->osservatore)
        ->put(route('admin.fornitori.update', $bianchi), ['ragione_sociale' => 'Idraulica Rossi'])
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors('ragione_sociale');

    expect($bianchi->fresh()->ragione_sociale)->toBe('Elettrica Bianchi');
});

it('chi può modificare fornitori riceve l\'errore sulla ragione sociale di un altro', function () {
    Fornitore::create(['ragione_sociale' => 'Idraulica Rossi']);
    $bianchi = Fornitore::create(['ragione_sociale' => 'Elettrica Bianchi']);

    $this->actingAs($this->collaboratore)
        ->put(route('admin.fornitori.update', $bianchi), ['ragione_sociale' => 'Idraulica Rossi'])
        ->assertRedirect()
        ->assertSessionHasErrors('ragione_sociale');
});

// ── Casse ────────────────────────────────────────────────────────────────────────────────────────────────

function cassaDi(int $condominioId, string $nome): Cassa
{
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominioId, 'ruolo' => 'ppc_'.uniqid(), 'codice' => '1300.'.uniqid(),
        'nome' => 'Conto', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create(['condominio_id' => $condominioId, 'nome' => $nome, 'tipo' => 'contanti', 'conto_contabile_id' => $conto, 'saldo_iniziale' => 0,
        'attiva' => true]);
}

it('una cassa di un altro condominio non si trova, prima dei campi, e resta com\'era', function () {
    [$aurora] = setupContabile();
    [$belvedere] = setupContabile();
    cassaDi($aurora->id, 'Cassa contanti');
    $diBelvedere = cassaDi($belvedere->id, 'Cassa di Belvedere');

    $this->actingAs($this->collaboratore)
        ->put(route('admin.gestionale.casse.update', [$aurora, $diBelvedere]), ['nome' => 'Cassa contanti', 'tipo' => 'contanti'])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors('nome');

    expect($diBelvedere->fresh()->nome)->toBe('Cassa di Belvedere');
});

it('una cassa del suo condominio riceve l\'errore sul nome già usato', function () {
    [$aurora] = setupContabile();
    cassaDi($aurora->id, 'Cassa contanti');
    $altra = cassaDi($aurora->id, 'Cassa piccola');

    $this->actingAs($this->collaboratore)
        ->put(route('admin.gestionale.casse.update', [$aurora, $altra]), ['nome' => 'Cassa contanti', 'tipo' => 'contanti'])
        ->assertRedirect()
        ->assertSessionHasErrors('nome');
});
