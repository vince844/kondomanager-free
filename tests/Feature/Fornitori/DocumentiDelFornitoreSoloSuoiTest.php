<?php

/**
 * # I documenti di un fornitore si aprono, si modificano e si eliminano solo dal fornitore a cui appartengono (Coda 407)
 *
 * Le rotte `admin.fornitori.documenti.*` hanno due parametri, il fornitore e il documento, che Laravel risolve ognuno per
 * conto suo: il documento nell'indirizzo può essere di un'altra persona, di un altro fornitore o dell'archivio. La
 * scheda dei documenti del fornitore lavora soltanto sui documenti del fornitore dell'indirizzo, come la scheda dei
 * documenti della persona (`AnagraficaDocumentoController::appartiene`): per qualunque altro documento le tre azioni
 * rispondono 404 e il documento, con il suo file, resta com'è. Il modulo di modifica apre il documento dell'indirizzo, non
 * il primo del fornitore.
 *
 * Il permesso è quello della scheda del fornitore («Modifica anagrafiche», `FornitorePolicy::update`): i ruoli di serie
 * che lo hanno caricano, rinominano ed eliminano i documenti del fornitore.
 *
 * Cosa NON copre: i permessi della scheda (`FornitoriPermessiDelPannelloTest.php`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->autore = User::factory()->create();
    $this->fornitore = docFornCreaFornitore('Idraulica Prova S.r.l.');
    $this->altroFornitore = docFornCreaFornitore('Elettricità Bianchi S.r.l.');

    $this->durc = $this->fornitore->documenti()->create(docFornDati('DURC 2026', 'durc', $this->autore));
    $this->visura = $this->fornitore->documenti()->create(docFornDati('Visura camerale', 'visura', $this->autore));
    $this->polizza = $this->altroFornitore->documenti()->create(docFornDati('Polizza Bianchi', 'polizza', $this->autore));

    // Un documento dell'archivio (nessun proprietario) e la copia del documento d'identità di una persona.
    $this->verbale = Documento::create(docFornDati('Verbale assemblea', 'verbale', $this->autore));
    $this->mario = Anagrafica::create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RSSMRA80A01H501U']);
    $this->carta = $this->mario->documentiPropri()->create(docFornDati('Carta d\'identità di Mario', 'carta', $this->autore));
    // La persona ha lo stesso numero del fornitore: il solo id del proprietario non basta a dire che il documento è suo.
    expect($this->mario->id)->toBe($this->fornitore->id);
});

function docFornCreaFornitore(string $ragioneSociale): Fornitore
{
    return Fornitore::create([
        'ragione_sociale' => $ragioneSociale, 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);
}

/** I campi di un documento, con il suo file già nell'archivio. */
function docFornDati(string $nome, string $file, User $autore): array
{
    Storage::disk('local')->put("documenti/{$file}.pdf", "%PDF-1.4 {$file}");

    return [
        'name' => $nome, 'description' => 'Descrizione', 'path' => "documenti/{$file}.pdf", 'mime_type' => 'application/pdf',
        'file_size' => 20, 'created_by' => $autore->id, 'is_published' => true, 'is_approved' => true,
    ];
}

function docFornUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Il documento è rimasto com'era: la riga (nome, file, autore) e il file. */
function docFornIntatto($test, Documento $documento, string $nome, string $file): void
{
    $ora = $documento->fresh();

    expect($ora)->not->toBeNull()
        ->and($ora->name)->toBe($nome)
        ->and($ora->path)->toBe("documenti/{$file}.pdf")
        ->and($ora->created_by)->toBe($test->autore->id);
    Storage::disk('local')->assertExists("documenti/{$file}.pdf");
}

/** I documenti che non sono del fornitore `$this->fornitore`. */
function docFornDocumentiAltrui($test): array
{
    return [
        'un documento dell\'archivio' => [$test->verbale, 'Verbale assemblea', 'verbale'],
        'la copia del documento d\'identità di una persona' => [$test->carta, 'Carta d\'identità di Mario', 'carta'],
        'un documento di un altro fornitore' => [$test->polizza, 'Polizza Bianchi', 'polizza'],
    ];
}

test('Coda 407 — dalla scheda di un fornitore non si elimina un documento che non è suo, con il collaboratore di serie', function () {
    $collaboratore = docFornUtente(Role::COLLABORATORE->value);

    foreach (docFornDocumentiAltrui($this) as $caso => [$documento, $nome, $file]) {
        $this->actingAs($collaboratore)->delete(route('admin.fornitori.documenti.destroy', [$this->fornitore, $documento]))
            ->assertNotFound();
        docFornIntatto($this, $documento, $nome, $file);
    }

    // Anche con una richiesta JSON.
    $this->actingAs($collaboratore)->deleteJson(route('admin.fornitori.documenti.destroy', [$this->fornitore, $this->verbale]))->assertNotFound();
    docFornIntatto($this, $this->verbale, 'Verbale assemblea', 'verbale');
});

test('Coda 407 — dalla scheda di un fornitore non si rinomina e non si sostituisce un documento che non è suo', function () {
    $collaboratore = docFornUtente(Role::COLLABORATORE->value);

    foreach (docFornDocumentiAltrui($this) as $caso => [$documento, $nome, $file]) {
        $this->actingAs($collaboratore)
            ->post(route('admin.fornitori.documenti.update', [$this->fornitore, $documento]).'?_method=PUT', [
                'name' => 'Rinominato', 'description' => 'x', 'is_published' => true, 'is_approved' => true,
                'file' => UploadedFile::fake()->create('nuovo.pdf', 10, 'application/pdf'),
            ])
            ->assertNotFound();

        docFornIntatto($this, $documento, $nome, $file);
    }
});

test('Coda 407 — il modulo di modifica di un documento che non è del fornitore non si apre', function () {
    $collaboratore = docFornUtente(Role::COLLABORATORE->value);

    foreach (docFornDocumentiAltrui($this) as $caso => [$documento]) {
        $risposta = $this->actingAs($collaboratore)->get(route('admin.fornitori.documenti.edit', [$this->fornitore, $documento]));

        $risposta->assertNotFound();
        expect($risposta->getContent(), $caso)->not->toContain('RSSMRA80A01H501U');
    }
});

test('Coda 407 — il modulo di modifica apre il documento dell\'indirizzo, non il primo del fornitore', function () {
    $collaboratore = docFornUtente(Role::COLLABORATORE->value);

    $props = $this->actingAs($collaboratore)->get(route('admin.fornitori.documenti.edit', [$this->fornitore, $this->visura]))
        ->assertOk()->viewData('page')['props'];

    expect($props['documento']['id'])->toBe($this->visura->id)
        ->and($props['documento']['name'])->toBe('Visura camerale')
        ->and($props['fornitore']['id'])->toBe($this->fornitore->id);
});

test('Coda 407 — i ruoli di serie modificano ed eliminano i documenti del fornitore, ognuno il suo', function (string $ruolo) {
    $chi = docFornUtente($ruolo);

    $this->actingAs($chi)->get(route('admin.fornitori.documenti.edit', [$this->fornitore, $this->durc]))->assertOk();
    $this->actingAs($chi)
        ->post(route('admin.fornitori.documenti.update', [$this->fornitore, $this->visura]).'?_method=PUT', [
            'name' => 'Visura aggiornata', 'description' => 'x', 'is_published' => true, 'is_approved' => true,
        ])
        ->assertRedirect(route('admin.fornitori.documenti.index', $this->fornitore))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect($this->visura->fresh()->name)->toBe('Visura aggiornata')
        ->and($this->durc->fresh()->name)->toBe('DURC 2026');

    $this->actingAs($chi)->delete(route('admin.fornitori.documenti.destroy', [$this->fornitore, $this->visura]))
        ->assertRedirect(route('admin.fornitori.documenti.index', $this->fornitore))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect(Documento::whereKey($this->visura->id)->exists())->toBeFalse()
        ->and($this->fornitore->documenti()->pluck('name')->all())->toBe(['DURC 2026']);
    Storage::disk('local')->assertMissing('documenti/visura.pdf');
    Storage::disk('local')->assertExists('documenti/durc.pdf');
    docFornIntatto($this, $this->polizza, 'Polizza Bianchi', 'polizza');
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('Coda 407 — un ruolo su misura con «Modifica anagrafiche» e senza i permessi dell\'archivio non tocca i documenti degli altri', function () {
    \Spatie\Permission\Models\Role::findOrCreate('Rubrica', 'web')
        ->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::EDIT_ANAGRAFICHE->value]);
    $rubrica = docFornUtente('Rubrica');

    $this->actingAs($rubrica)->delete(route('admin.fornitori.documenti.destroy', [$this->fornitore, $this->carta]))->assertNotFound();
    $this->actingAs($rubrica)->delete(route('admin.documenti.destroy', $this->carta))->assertForbidden();
    docFornIntatto($this, $this->carta, 'Carta d\'identità di Mario', 'carta');

    $this->actingAs($rubrica)->delete(route('admin.fornitori.documenti.destroy', [$this->fornitore, $this->durc]))->assertRedirect();
    expect(Documento::whereKey($this->durc->id)->exists())->toBeFalse();
});
