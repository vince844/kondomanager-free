<?php

/**
 * # I documenti della persona chiedono i permessi delle anagrafiche (Coda 402)
 *
 * La scheda «Documenti» di un'anagrafica (`Anagrafiche\Documenti\AnagraficaDocumentoController`) archivia i documenti
 * della persona: la copia del documento d'identità, una delega, un contratto. L'elenco porta con sé la persona intera
 * (codice fiscale, telefoni, email). Entrare nel pannello non basta: l'elenco chiede la `view` di `AnagraficaPolicy`
 * sulla persona («Visualizza anagrafiche», come la scheda); il modulo nuovo, quello di modifica, il caricamento, la
 * modifica e l'eliminazione chiedono la sua `update` («Modifica anagrafiche», come il modulo della persona). Chi non
 * ha il permesso riceve il rifiuto della policy prima della validazione, con la sua frase e nessun esito delle regole.
 *
 * I ruoli di serie: l'amministratore ha tutto; il collaboratore ha «Visualizza» e «Modifica anagrafiche»: li ha tutti e
 * due.
 *
 * Cosa NON copre: il documento di un'altra persona aperto dalla scheda sbagliata (`SchedaAnagraficaTest.php`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Documento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->mario = Anagrafica::create([
        'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U',
        'cellulare' => '3331234567', 'email' => 'mario.dangelo@example.com',
    ]);

    Storage::disk('local')->put('documenti/carta.pdf', '%PDF-1.4 carta');
    $this->carta = Documento::create([
        'name' => "Carta d'identità di Mario", 'description' => 'Fronte e retro', 'path' => 'documenti/carta.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 14, 'created_by' => User::factory()->create()->id,
        'is_published' => false, 'is_approved' => true,
    ]);
    $this->carta->documentable()->associate($this->mario)->save();
});

function docPersonaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function docPersonaSuMisura(array $permessi): User
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return docPersonaUtente('Su misura');
}

/** Il caricamento come lo manda `anagrafiche/documenti/DocumentiNew.vue`. */
function docPersonaNuovo(array $modifiche = []): array
{
    return array_merge([
        'name' => 'Delega assemblea', 'description' => 'Per marzo', 'is_published' => false, 'is_approved' => true,
        'file' => UploadedFile::fake()->create('delega.pdf', 12, 'application/pdf'),
    ], $modifiche);
}

/** La modifica come la manda `anagrafiche/documenti/DocumentiEdit.vue`: un POST con `_method: 'put'`. */
function docPersonaModifica(array $modifiche = []): array
{
    return array_merge(['_method' => 'put', 'name' => 'Carta rinominata', 'description' => 'x', 'is_published' => false, 'is_approved' => true], $modifiche);
}

function docPersonaRotte($test): array
{
    return [
        'elenco' => route('admin.anagrafiche.documenti.index', $test->mario),
        'nuovo' => route('admin.anagrafiche.documenti.create', $test->mario),
        'modifica' => route('admin.anagrafiche.documenti.edit', [$test->mario, $test->carta]),
    ];
}

test('Coda 402 — con il solo accesso al pannello non si aprono l\'elenco, il modulo nuovo e quello di modifica dei documenti della persona', function () {
    $solo = docPersonaSuMisura([]);

    foreach (docPersonaRotte($this) as $url) {
        $risposta = $this->actingAs($solo)->get($url);
        $risposta->assertForbidden();
        expect($risposta->getContent())->not->toContain('DNGMRA80A01H501U')->not->toContain("Carta d'identità di Mario");
    }
});

test('Coda 402 — con il solo accesso al pannello non si carica, non si rinomina e non si elimina un documento della persona', function () {
    $solo = docPersonaSuMisura([]);

    $this->actingAs($solo)->post(route('admin.anagrafiche.documenti.store', $this->mario), docPersonaNuovo())->assertForbidden();
    $this->actingAs($solo)->post(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), docPersonaModifica())->assertForbidden();
    $this->actingAs($solo)->delete(route('admin.anagrafiche.documenti.destroy', [$this->mario, $this->carta]))->assertForbidden();

    expect(Documento::where('name', 'Delega assemblea')->exists())->toBeFalse()
        ->and($this->carta->fresh()?->name)->toBe("Carta d'identità di Mario");
    Storage::disk('local')->assertExists('documenti/carta.pdf');
});

test('Coda 402 — con «Visualizza anagrafiche» si apre l\'elenco dei documenti della persona; il modulo nuovo, quello di modifica e le scritture chiedono «Modifica anagrafiche»', function () {
    $consulta = docPersonaSuMisura([Permission::VIEW_ANAGRAFICHE->value]);

    $props = $this->actingAs($consulta)->get(route('admin.anagrafiche.documenti.index', $this->mario))->assertOk()->viewData('page')['props'];
    expect(collect($props['documenti'])->pluck('name')->all())->toBe(["Carta d'identità di Mario"]);

    $this->actingAs($consulta)->get(route('admin.anagrafiche.documenti.create', $this->mario))->assertForbidden();
    $this->actingAs($consulta)->get(route('admin.anagrafiche.documenti.edit', [$this->mario, $this->carta]))->assertForbidden();
    $this->actingAs($consulta)->post(route('admin.anagrafiche.documenti.store', $this->mario), docPersonaNuovo())->assertForbidden();
    $this->actingAs($consulta)->post(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), docPersonaModifica())->assertForbidden();
    $this->actingAs($consulta)->delete(route('admin.anagrafiche.documenti.destroy', [$this->mario, $this->carta]))->assertForbidden();

    expect(Documento::where('name', 'Delega assemblea')->exists())->toBeFalse()
        ->and($this->carta->fresh()?->name)->toBe("Carta d'identità di Mario");
});

test('Coda 402 — con «Modifica anagrafiche» si aprono i moduli e si carica, si rinomina e si elimina un documento della persona', function (array $permessi) {
    $chi = docPersonaSuMisura($permessi);

    $this->actingAs($chi)->get(route('admin.anagrafiche.documenti.create', $this->mario))->assertOk();
    $this->actingAs($chi)->get(route('admin.anagrafiche.documenti.edit', [$this->mario, $this->carta]))->assertOk();

    $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.store', $this->mario), docPersonaNuovo())
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.documenti.index', $this->mario));
    $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), docPersonaModifica())
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.documenti.index', $this->mario));
    expect($this->carta->fresh()->name)->toBe('Carta rinominata');

    $this->actingAs($chi)->delete(route('admin.anagrafiche.documenti.destroy', [$this->mario, $this->carta]))
        ->assertRedirect(route('admin.anagrafiche.documenti.index', $this->mario));

    expect(Documento::where('name', 'Delega assemblea')->where('documentable_id', $this->mario->id)->exists())->toBeTrue()
        ->and(Documento::find($this->carta->id))->toBeNull();
    Storage::disk('local')->assertMissing('documenti/carta.pdf');
})->with([
    'con «Visualizza» e «Modifica anagrafiche»' => [[Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value]],
    'con il solo «Modifica anagrafiche»' => [[Permission::EDIT_ANAGRAFICHE->value]],
]);

test('Coda 402 — l\'amministratore e il collaboratore aprono, caricano, rinominano ed eliminano i documenti della persona', function (string $ruolo) {
    $chi = docPersonaUtente($ruolo);

    foreach (docPersonaRotte($this) as $url) {
        $this->actingAs($chi)->get($url)->assertOk();
    }
    $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.store', $this->mario), docPersonaNuovo())->assertSessionHasNoErrors();
    $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), docPersonaModifica())->assertSessionHasNoErrors();
    $this->actingAs($chi)->delete(route('admin.anagrafiche.documenti.destroy', [$this->mario, $this->carta]))->assertRedirect();

    expect(Documento::where('name', 'Delega assemblea')->exists())->toBeTrue()
        ->and(Documento::find($this->carta->id))->toBeNull();
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('Coda 402 — chi non può caricare né modificare un documento della persona riceve il rifiuto prima della validazione', function (array $permessi) {
    $chi = docPersonaSuMisura($permessi);

    // Corpi che la validazione respingerebbe: nessun file, un file che non è un PDF, il nome vuoto.
    foreach ([
        [],
        ['name' => 'Foto', 'is_published' => false, 'is_approved' => true, 'file' => UploadedFile::fake()->image('foto.jpg')],
    ] as $corpo) {
        $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.store', $this->mario), $corpo)->assertForbidden();
        expect(session('errors'))->toBeNull();
    }
    $this->actingAs($chi)->post(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), ['_method' => 'put', 'name' => ''])->assertForbidden();
    expect(session('errors'))->toBeNull();

    $this->actingAs($chi)->postJson(route('admin.anagrafiche.documenti.store', $this->mario), [])
        ->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.update')])->assertJsonMissingPath('errors');
    $this->actingAs($chi)->putJson(route('admin.anagrafiche.documenti.update', [$this->mario, $this->carta]), ['name' => ''])
        ->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.update')])->assertJsonMissingPath('errors');
})->with([
    'il solo accesso' => [[]],
    'la sola consultazione' => [[Permission::VIEW_ANAGRAFICHE->value]],
]);

test('Coda 402 — chi non consulta le anagrafiche riceve il rifiuto dell\'elenco dei documenti prima della validazione dei filtri', function () {
    $solo = docPersonaSuMisura([]);

    $this->actingAs($solo)->getJson(route('admin.anagrafiche.documenti.index', ['anagrafica' => $this->mario, 'page' => 'zero', 'sort' => 'colonna_che_non_esiste']))
        ->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.view')])->assertJsonMissingPath('errors');
});
