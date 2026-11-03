<?php

/**
 * # Le rotte dell'archivio lavorano solo sui documenti dell'archivio
 *
 * Nel pannello le rotte `admin.documenti.*` (scaricare, aprire la modifica, salvare, eliminare, approvare) sono quelle
 * dell'archivio documenti, e i loro permessi («Visualizza», «Modifica», «Elimina», «Approva documenti archivio») valgono
 * sui documenti dell'archivio: quelli senza un proprietario (`documentable_type` vuoto, come li mostrano l'elenco
 * dell'archivio e la dashboard). Un documento che ha un proprietario (la copia del documento d'identità di una persona,
 * un documento di un fornitore, di un'unità o di una fattura) per queste rotte non c'è: rispondono 404, e il documento,
 * con il suo file, resta com'è.
 *
 * Un documento con un proprietario si apre e si cambia dalla pagina del proprietario, con il suo permesso: il documento
 * del fornitore si scarica con la scheda del fornitore («Visualizza anagrafiche», `FornitorePolicy::view`) e si approva
 * modificandolo («Modifica anagrafiche») con «Approva documenti archivio»; il documento dell'unità si scarica e si
 * approva dalla scheda dell'unità, con «Visualizza» e «Approva documenti archivio» come gli allegati delle fatture
 * (`FatturaPassivaController::download`). Il documento di un altro fornitore o di un'altra unità, da quelle rotte, non
 * c'è (404). Lo storico dei passaggi dell'unità porta il titolo di provenienza alla rotta dell'unità.
 *
 * Le rotte dei documenti del portale (`user.documenti.*`) seguono la stessa regola: il portale si apre anche a chi ha il
 * pannello e un'anagrafica, e per lui i permessi dell'archivio valgono sui documenti dell'archivio, non su quelli con un
 * proprietario (404).
 *
 * I ruoli di serie: l'amministratore e il collaboratore scaricano e approvano i documenti dell'archivio, dei fornitori e
 * delle unità; l'amministratore elimina quelli dell'archivio.
 *
 * Cosa NON copre: i documenti della persona e del fornitore dalle loro schede (`DocumentiDellaPersonaPermessiTest.php`,
 * `DocumentiDelFornitoreSoloSuoiTest.php`); il portale per chi non ha il pannello (`DocumentoUnitaEScaricamentoTest.php`); i
 * comandi delle pagine (`resources/js/components/fornitori/documenti/columns.test.ts`,
 * `gestionale/immobili/documenti/columns.test.ts`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->autore = User::factory()->create();
    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    Esercizio::factory()->create(['condominio_id' => $this->aurora->id]);
    PianoConto::factory()->create(['condominio_id' => $this->aurora->id]);

    $this->interno7 = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Interno 7', 'descrizione' => 'Prova', 'interno' => '7']);
    $this->interno8 = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Interno 8', 'descrizione' => 'Prova', 'interno' => '8']);
    $this->fornitore = Fornitore::create([
        'ragione_sociale' => 'Idraulica Prova S.r.l.', 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);
    $this->altroFornitore = Fornitore::create([
        'ragione_sociale' => 'Elettricità Bianchi S.r.l.', 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);
    $this->mario = Anagrafica::create(['nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U']);

    $this->verbale = Documento::create(archDati('Verbale assemblea', 'verbale', $this->autore));
    $this->verbale->condomini()->attach($this->aurora->id);
    $this->carta = $this->mario->documentiPropri()->create(archDati("Carta d'identità di Mario", 'carta', $this->autore));
    $this->durc = $this->fornitore->documenti()->create(archDati('DURC 2026', 'durc', $this->autore));
    $this->polizza = $this->altroFornitore->documenti()->create(archDati('Polizza Bianchi', 'polizza', $this->autore));
    $this->rogito = $this->interno7->documenti()->create(archDati('Titolo di provenienza', 'rogito', $this->autore));
    $this->contratto = $this->interno8->documenti()->create(archDati('Contratto di locazione', 'contratto', $this->autore));
    // Un allegato di fattura: il proprietario è la fattura, che qui non serve aprire.
    $this->allegato = new Documento(archDati('Fattura idraulico', 'fattura', $this->autore));
    $this->allegato->forceFill(['documentable_type' => FatturaPassiva::class, 'documentable_id' => 999])->save();
});

/** I campi di un documento, con il suo file già nell'archivio. */
function archDati(string $nome, string $file, User $autore): array
{
    Storage::disk('local')->put("documenti/{$file}.pdf", "%PDF-1.4 {$file}");

    return [
        'name' => $nome, 'description' => 'Descrizione', 'path' => "documenti/{$file}.pdf", 'mime_type' => 'application/pdf',
        'file_size' => 20, 'created_by' => $autore->id, 'is_published' => true, 'is_approved' => true,
    ];
}

function archUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function archSuMisura(string $nome, array $permessi): User
{
    RuoloSpatie::findOrCreate($nome, 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return archUtente($nome);
}

/** Il documento è rimasto com'era: la riga (nome, file, approvazione) e il file. */
function archIntatto(Documento $documento, string $nome, string $file): void
{
    $ora = $documento->fresh();

    expect($ora)->not->toBeNull()
        ->and($ora->name)->toBe($nome)
        ->and($ora->path)->toBe("documenti/{$file}.pdf")
        ->and((bool) $ora->is_approved)->toBeTrue();
    Storage::disk('local')->assertExists("documenti/{$file}.pdf");
}

/** I documenti che hanno un proprietario. */
function archConProprietario($test): array
{
    return [
        'la copia del documento d\'identità di una persona' => [$test->carta, "Carta d'identità di Mario", 'carta'],
        'un documento di un fornitore' => [$test->durc, 'DURC 2026', 'durc'],
        'un documento di un\'unità' => [$test->rogito, 'Titolo di provenienza', 'rogito'],
        'un allegato di una fattura' => [$test->allegato, 'Fattura idraulico', 'fattura'],
    ];
}

test('le rotte dell\'archivio non scaricano, non aprono, non salvano, non eliminano e non approvano un documento che ha un proprietario', function (string $chi) {
    $utente = $chi === 'archivio' ? archSuMisura('Archivio completo', [
        Permission::VIEW_ARCHIVE_DOCUMENTS->value, Permission::EDIT_ARCHIVE_DOCUMENTS->value, Permission::DELETE_ARCHIVE_DOCUMENTS->value,
        Permission::APPROVE_ARCHIVE_DOCUMENTS->value,
    ]) : archUtente($chi);

    // Prima il permesso della rotta, poi il documento: chi ha il permesso riceve 404, chi non lo ha il rifiuto del permesso
    // (il collaboratore di serie non ha «Elimina documenti archivio»).
    $atteso = fn (Permission $permesso) => $utente->hasPermissionTo($permesso->value) ? 404 : 403;

    foreach (archConProprietario($this) as $caso => [$documento, $nome, $file]) {
        $this->actingAs($utente)->get(route('admin.documenti.download', $documento))->assertStatus($atteso(Permission::VIEW_ARCHIVE_DOCUMENTS));
        $this->actingAs($utente)->get(route('admin.documenti.edit', $documento))->assertStatus($atteso(Permission::EDIT_ARCHIVE_DOCUMENTS));
        $this->actingAs($utente)->from(route('admin.documenti.index'))->put(route('admin.documenti.update', $documento), ['name' => 'Rinominato'])->assertStatus($atteso(Permission::EDIT_ARCHIVE_DOCUMENTS));
        $this->actingAs($utente)->from(route('admin.documenti.index'))->delete(route('admin.documenti.destroy', $documento))->assertStatus($atteso(Permission::DELETE_ARCHIVE_DOCUMENTS));
        $this->actingAs($utente)->from(route('admin.documenti.index'))->put(route('admin.documenti.toggle-approval', $documento))->assertStatus($atteso(Permission::APPROVE_ARCHIVE_DOCUMENTS));
        $this->actingAs($utente)->deleteJson(route('admin.documenti.destroy', $documento))->assertStatus($atteso(Permission::DELETE_ARCHIVE_DOCUMENTS));

        archIntatto($documento, $nome, $file);
    }
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
    'un ruolo su misura con tutti i permessi dell\'archivio e senza le anagrafiche' => ['archivio'],
]);

test('sui documenti dell\'archivio le rotte dell\'archivio scaricano, aprono e approvano, per l\'amministratore e il collaboratore', function (string $ruolo) {
    $utente = archUtente($ruolo);

    $this->actingAs($utente)->get(route('admin.documenti.download', $this->verbale))->assertOk();
    $this->actingAs($utente)->get(route('admin.documenti.edit', $this->verbale))->assertOk();
    $this->actingAs($utente)->from(route('admin.documenti.index'))->put(route('admin.documenti.toggle-approval', $this->verbale))->assertRedirect(route('admin.documenti.index'));

    expect((bool) $this->verbale->fresh()->is_approved)->toBeFalse();
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('l\'amministratore elimina un documento dell\'archivio dalla sua rotta', function () {
    $this->actingAs(archUtente(Role::AMMINISTRATORE->value))->from(route('admin.documenti.index'))
        ->delete(route('admin.documenti.destroy', $this->verbale))->assertRedirect(route('admin.documenti.index'));

    expect(Documento::whereKey($this->verbale->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('documenti/verbale.pdf');
});

test('il documento del fornitore si scarica e si approva dalla scheda del fornitore, per l\'amministratore e il collaboratore', function (string $ruolo) {
    $utente = archUtente($ruolo);
    $indice = route('admin.fornitori.documenti.index', $this->fornitore);

    $scaricato = $this->actingAs($utente)->get(route('admin.fornitori.documenti.download', ['fornitore' => $this->fornitore, 'documento' => $this->durc]));
    $scaricato->assertOk();
    expect($scaricato->streamedContent())->toBe('%PDF-1.4 durc');

    $this->actingAs($utente)->from($indice)
        ->put(route('admin.fornitori.documenti.toggle-approval', ['fornitore' => $this->fornitore, 'documento' => $this->durc]))
        ->assertRedirect($indice);
    expect((bool) $this->durc->fresh()->is_approved)->toBeFalse();

    $this->actingAs($utente)->from($indice)
        ->put(route('admin.fornitori.documenti.toggle-approval', ['fornitore' => $this->fornitore, 'documento' => $this->durc]))
        ->assertRedirect($indice);
    expect((bool) $this->durc->fresh()->is_approved)->toBeTrue();
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('dalla scheda di un fornitore non si scarica né si approva un documento che non è suo', function () {
    $amministratore = archUtente(Role::AMMINISTRATORE->value);
    $altrui = [
        'un documento dell\'archivio' => [$this->verbale, 'Verbale assemblea', 'verbale'],
        'la copia del documento d\'identità di una persona' => [$this->carta, "Carta d'identità di Mario", 'carta'],
        'un documento di un altro fornitore' => [$this->polizza, 'Polizza Bianchi', 'polizza'],
        'un documento di un\'unità' => [$this->rogito, 'Titolo di provenienza', 'rogito'],
    ];

    foreach ($altrui as $caso => [$documento, $nome, $file]) {
        $indirizzo = ['fornitore' => $this->fornitore, 'documento' => $documento];
        $this->actingAs($amministratore)->get(route('admin.fornitori.documenti.download', $indirizzo))->assertNotFound();
        $this->actingAs($amministratore)->put(route('admin.fornitori.documenti.toggle-approval', $indirizzo))->assertNotFound();

        archIntatto($documento, $nome, $file);
    }
});

test('il documento del fornitore si scarica con la scheda del fornitore e si approva con «Modifica anagrafiche» e «Approva documenti archivio»', function () {
    $indirizzo = ['fornitore' => $this->fornitore, 'documento' => $this->durc];
    $consulta = archSuMisura('Consultazione', [Permission::VIEW_ANAGRAFICHE->value]);
    $soloArchivio = archSuMisura('Solo archivio', [Permission::VIEW_ARCHIVE_DOCUMENTS->value, Permission::APPROVE_ARCHIVE_DOCUMENTS->value]);
    $modificaSenzaApprova = archSuMisura('Modifica senza approva', [Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value]);
    $modificaEApprova = archSuMisura('Modifica e approva', [
        Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value, Permission::APPROVE_ARCHIVE_DOCUMENTS->value,
    ]);

    $this->actingAs($consulta)->get(route('admin.fornitori.documenti.download', $indirizzo))->assertOk();
    $this->actingAs($soloArchivio)->get(route('admin.fornitori.documenti.download', $indirizzo))->assertForbidden();

    $this->actingAs($consulta)->put(route('admin.fornitori.documenti.toggle-approval', $indirizzo))->assertForbidden();
    $this->actingAs($soloArchivio)->put(route('admin.fornitori.documenti.toggle-approval', $indirizzo))->assertForbidden();
    $this->actingAs($modificaSenzaApprova)->put(route('admin.fornitori.documenti.toggle-approval', $indirizzo))->assertForbidden();
    expect((bool) $this->durc->fresh()->is_approved)->toBeTrue();

    $this->actingAs($modificaEApprova)->from(route('admin.fornitori.documenti.index', $this->fornitore))
        ->put(route('admin.fornitori.documenti.toggle-approval', $indirizzo))->assertRedirect();
    expect((bool) $this->durc->fresh()->is_approved)->toBeFalse();
});

test('il documento dell\'unità si scarica e si approva dalla scheda dell\'unità, per l\'amministratore e il collaboratore', function (string $ruolo) {
    $utente = archUtente($ruolo);
    $indirizzo = ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $this->rogito];
    $indice = route('admin.gestionale.immobili.documenti.index', ['condominio' => $this->aurora, 'immobile' => $this->interno7]);

    $scaricato = $this->actingAs($utente)->get(route('admin.gestionale.immobili.documenti.download', $indirizzo));
    $scaricato->assertOk();
    expect($scaricato->streamedContent())->toBe('%PDF-1.4 rogito');

    $this->actingAs($utente)->from($indice)->put(route('admin.gestionale.immobili.documenti.toggle-approval', $indirizzo))->assertRedirect($indice);
    expect((bool) $this->rogito->fresh()->is_approved)->toBeFalse();
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('dalla scheda di un\'unità non si scarica né si approva un documento che non è suo', function () {
    $amministratore = archUtente(Role::AMMINISTRATORE->value);

    foreach ([
        'un documento di un\'altra unità' => [$this->contratto, 'Contratto di locazione', 'contratto'],
        'un documento dell\'archivio' => [$this->verbale, 'Verbale assemblea', 'verbale'],
        'un documento di un fornitore' => [$this->durc, 'DURC 2026', 'durc'],
    ] as $caso => [$documento, $nome, $file]) {
        $indirizzo = ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $documento];
        $this->actingAs($amministratore)->get(route('admin.gestionale.immobili.documenti.download', $indirizzo))->assertNotFound();
        $this->actingAs($amministratore)->put(route('admin.gestionale.immobili.documenti.toggle-approval', $indirizzo))->assertNotFound();

        archIntatto($documento, $nome, $file);
    }
});

test('il documento dell\'unità si scarica con «Visualizza documenti archivio» e si approva con «Approva documenti archivio», come gli allegati delle fatture', function () {
    $indirizzo = ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $this->rogito];
    $soloPannello = archSuMisura('Solo pannello', []);
    $consulta = archSuMisura('Consulta archivio', [Permission::VIEW_ARCHIVE_DOCUMENTS->value]);

    $this->actingAs($soloPannello)->get(route('admin.gestionale.immobili.documenti.download', $indirizzo))->assertForbidden();
    $this->actingAs($consulta)->get(route('admin.gestionale.immobili.documenti.download', $indirizzo))->assertOk();
    $this->actingAs($consulta)->put(route('admin.gestionale.immobili.documenti.toggle-approval', $indirizzo))->assertForbidden();

    expect((bool) $this->rogito->fresh()->is_approved)->toBeTrue();
});

test('lo storico dei passaggi dell\'unità porta il titolo di provenienza alla rotta dell\'unità, che lo scarica', function () {
    $amministratore = archUtente(Role::AMMINISTRATORE->value);
    $anna = Anagrafica::create(['nome' => 'Anna Verdi', 'indirizzo' => 'Via Po 2']);
    $this->interno7->anagrafiche()->attach($this->mario->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => false, 'data_inizio' => '2020-01-01', 'data_fine' => '2026-05-31']);
    $this->interno7->anagrafiche()->attach($anna->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-06-01']);
    $riga = (int) \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('anagrafica_id', $this->mario->id)->value('id');
    \App\Models\Gestionale\Subentro::create([
        'condominio_id' => $this->aurora->id, 'immobile_id' => $this->interno7->id, 'anagrafica_uscente_id' => $this->mario->id,
        'anagrafica_entrante_id' => $anna->id, 'riga_uscente_id' => $riga, 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita',
        'decorrenza' => '2026-06-01', 'utente_id' => $amministratore->id, 'documento_id' => $this->rogito->id,
    ]);

    $storico = $this->actingAs($amministratore)
        ->get(route('admin.gestionale.immobili.anagrafiche.index', ['condominio' => $this->aurora, 'immobile' => $this->interno7]))
        ->assertOk()->viewData('page')['props']['storico'];
    $atteso = route('admin.gestionale.immobili.documenti.download', ['condominio' => $this->aurora->id, 'immobile' => $this->interno7->id, 'documento' => $this->rogito->id]);

    expect(array_column($storico['subentri'], 'documento_url'))->toBe([$atteso]);
    $url = collect($storico['righe'])->pluck('subentro.documento_url')->filter()->unique()->values()->all();
    expect($url)->toBe([$atteso]);
    $this->actingAs($amministratore)->get($atteso)->assertOk();
});

test('l\'elenco dell\'archivio e la dashboard mostrano solo i documenti dell\'archivio', function () {
    $amministratore = archUtente(Role::AMMINISTRATORE->value);

    $elenco = $this->actingAs($amministratore)->get(route('admin.documenti.index'))->assertOk()->viewData('page')['props']['documenti'];
    $dashboard = $this->actingAs($amministratore)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['documenti'];

    expect(array_column($elenco, 'id'))->toBe([$this->verbale->id])
        ->and(collect($dashboard)->pluck('id')->all())->toBe([$this->verbale->id]);
});

test('dal portale, chi ha il pannello e un\'anagrafica non scarica, non apre, non salva e non elimina un documento che ha un proprietario', function () {
    // Il portale si apre a chi ha un'anagrafica, anche con il pannello (`CheckHasAnagrafica`), e le sue rotte dei documenti
    // hanno i permessi dell'archivio: valgono sui documenti dell'archivio, come nel pannello.
    $utente = archSuMisura('Archivio completo e anagrafica', [
        Permission::VIEW_ARCHIVE_DOCUMENTS->value, Permission::EDIT_ARCHIVE_DOCUMENTS->value, Permission::DELETE_ARCHIVE_DOCUMENTS->value,
    ]);
    Anagrafica::create(['nome' => 'Luca Bianchi', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => 'BNCLCU80A01H501X', 'user_id' => $utente->id])
        ->condomini()->attach($this->aurora->id);

    foreach (archConProprietario($this) as $caso => [$documento, $nome, $file]) {
        $this->actingAs($utente)->get(route('user.documenti.download', $documento))->assertNotFound();
        $this->actingAs($utente)->get(route('user.documenti.edit', $documento))->assertNotFound();
        $this->actingAs($utente)->put(route('user.documenti.update', $documento), ['name' => 'Rinominato'])->assertNotFound();
        $this->actingAs($utente)->delete(route('user.documenti.destroy', $documento))->assertNotFound();

        archIntatto($documento, $nome, $file);
    }

    // Il documento dell'archivio del suo palazzo si scarica dal portale.
    $scaricato = $this->actingAs($utente)->get(route('user.documenti.download', $this->verbale));
    $scaricato->assertOk();
    expect($scaricato->streamedContent())->toBe('%PDF-1.4 verbale');
});
