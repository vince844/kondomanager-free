<?php

/**
 * # I fornitori del pannello chiedono i permessi delle anagrafiche (Coda 402)
 *
 * L'elenco, la scheda, i moduli, i referenti, i documenti e la situazione debitoria di un fornitore mostrano partita
 * IVA, codice fiscale, telefoni, email, IBAN e i dati delle persone che lo rappresentano; le azioni scrivono l'archivio
 * dei fornitori. Entrare nel pannello non basta: `FornitorePolicy` chiede i permessi delle anagrafiche, gli stessi con
 * cui il menù mostra la voce «Fornitori» (`AppHeader.vue`, «Visualizza anagrafiche»):
 *
 * - leggere (l'elenco, la scheda, i referenti, i documenti, la situazione debitoria): «Visualizza anagrafiche»;
 * - creare un fornitore (il modulo nuovo e il salvataggio, anche dalla registrazione di una fattura): «Crea anagrafiche»;
 * - modificarlo (il modulo di modifica e il salvataggio, aggiungere e togliere un referente, caricare, modificare ed
 *   eliminare un suo documento, registrare e togliere un debito pregresso): «Modifica anagrafiche»;
 * - eliminarlo: «Elimina anagrafiche».
 *
 * I ruoli di serie: l'amministratore ha tutto; il collaboratore ha «Visualizza», «Crea» e «Modifica anagrafiche» e non
 * «Elimina anagrafiche»: legge, crea e modifica i fornitori, e non li elimina, come le anagrafiche.
 *
 * Cosa NON copre: le categorie dei fornitori (`CategorieFornitorePermessiTest.php`); i comandi delle pagine
 * (`resources/js/components/fornitori/*.test.ts`); il gestionale, che mostra i fornitori nelle sue pagine con il solo
 * accesso al pannello (Coda 110).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
    ]);
    $gestione = Gestione::create(['condominio_id' => $this->condominio->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $this->fornitore = Fornitore::create([
        'ragione_sociale' => "Idraulica D'Àngelo S.r.l.", 'partita_iva' => '01234567890', 'codice_fiscale' => '01234567890',
        'email' => 'idraulica@example.com', 'telefono' => '0698765432', 'iban_principale' => 'IT60X0542811101000000123456',
        'stato' => 'attivo', 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);

    $this->referente = Anagrafica::create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RSSMRA80A01H501U', 'cellulare' => '3331234567']);
    $this->fornitore->referenti()->attach($this->referente->id, ['ruolo' => 'titolare']);
    $this->altraPersona = Anagrafica::create(['nome' => 'Anna Verdi', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'VRDNNA85C41H501Z']);

    Storage::disk('local')->put('documenti/durc.pdf', '%PDF-1.4 durc');
    $this->documento = $this->fornitore->documenti()->create([
        'name' => 'DURC Idraulica 2026', 'description' => 'Regolarità contributiva', 'path' => 'documenti/durc.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 13, 'created_by' => User::factory()->create()->id,
        'is_published' => false, 'is_approved' => true,
    ]);

    $this->debito = Saldo::create([
        'condominio_id' => $this->condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'fornitore_id' => $this->fornitore->id, 'anagrafica_id' => null, 'immobile_id' => null,
        'saldo_iniziale' => -38000, 'descrizione' => 'Fattura idraulico 2025 non saldata', 'origine' => 'manuale', 'is_applicato' => true,
    ]);
});

function fornPermUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function fornPermSuMisura(array $permessi): User
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return fornPermUtente('Su misura');
}

/** Il carico minimo che le schede del fornitore mandano a ogni salvataggio. */
function fornPermScheda(array $modifiche = []): array
{
    return array_merge([
        'ragione_sociale' => "Idraulica D'Àngelo S.r.l.", 'stato' => 'attivo', 'modalita_pagamento_default' => 'bonifico',
        'giorni_scadenza' => 30, 'soggetto_ritenuta' => false, 'certificazione_iso' => false, 'residente_fiscale' => true,
        'regime_forfetario' => false, 'provvigioni_base_ridotta' => false,
    ], $modifiche);
}

/** Le pagine che leggono un fornitore. */
function fornPermPagineDaLeggere($test): array
{
    return [
        route('admin.fornitori.index'),
        route('admin.fornitori.show', $test->fornitore),
        route('admin.fornitori.anagrafiche.index', $test->fornitore),
        route('admin.fornitori.documenti.index', $test->fornitore),
        route('admin.fornitori.situazione-debitoria.index', $test->fornitore),
    ];
}

/** I moduli: quello nuovo chiede «Crea anagrafiche», gli altri «Modifica anagrafiche». */
function fornPermModuliDiModifica($test): array
{
    return [
        route('admin.fornitori.edit', $test->fornitore),
        route('admin.fornitori.anagrafiche.create', $test->fornitore),
        route('admin.fornitori.documenti.create', $test->fornitore),
        route('admin.fornitori.documenti.edit', [$test->fornitore, $test->documento]),
    ];
}

/** Ogni scrittura che modifica un fornitore, come la mandano le sue pagine; restituisce le risposte per nome. */
function fornPermModifiche($test, User $chi): array
{
    return [
        'scheda' => $test->actingAs($chi)->put(route('admin.fornitori.update', $test->fornitore), fornPermScheda(['ragione_sociale' => 'Idraulica Rinominata S.r.l.'])),
        'referente aggiunto' => $test->actingAs($chi)->post(route('admin.fornitori.anagrafiche.store', $test->fornitore), ['anagrafica_id' => $test->altraPersona->id, 'ruolo' => 'tecnico']),
        'referente tolto' => $test->actingAs($chi)->delete(route('admin.fornitori.anagrafiche.destroy', [$test->fornitore, $test->referente])),
        'documento caricato' => $test->actingAs($chi)->post(route('admin.fornitori.documenti.store', $test->fornitore), [
            'name' => 'Visura camerale', 'description' => 'Aggiornata', 'is_published' => false,
            'file' => UploadedFile::fake()->create('visura.pdf', 10, 'application/pdf'),
        ]),
        'documento modificato' => $test->actingAs($chi)->post(route('admin.fornitori.documenti.update', [$test->fornitore, $test->documento]).'?_method=PUT', [
            'name' => 'DURC rinominato', 'description' => 'x', 'is_published' => false, 'is_approved' => true,
        ]),
        'debito registrato' => $test->actingAs($chi)->post(route('admin.fornitori.situazione-debitoria.store', $test->fornitore), [
            'condominio_id' => $test->condominio->id, 'descrizione' => 'Saldo lavori 2024', 'importo' => '1.500,00',
        ]),
        'debito tolto' => $test->actingAs($chi)->delete(route('admin.fornitori.situazione-debitoria.destroy', [$test->fornitore, $test->debito])),
        'documento eliminato' => $test->actingAs($chi)->delete(route('admin.fornitori.documenti.destroy', [$test->fornitore, $test->documento])),
    ];
}

/** L'archivio del fornitore com'era all'inizio? */
function fornPermIntatto($test): void
{
    expect($test->fornitore->fresh()->ragione_sociale)->toBe("Idraulica D'Àngelo S.r.l.")
        ->and($test->fornitore->referenti()->pluck('anagrafiche.id')->all())->toBe([$test->referente->id])
        ->and($test->fornitore->documenti()->pluck('name')->all())->toBe(['DURC Idraulica 2026'])
        ->and(Saldo::where('fornitore_id', $test->fornitore->id)->pluck('descrizione')->all())->toBe(['Fattura idraulico 2025 non saldata']);
    Storage::disk('local')->assertExists('documenti/durc.pdf');
}

test('Coda 402 — con il solo accesso al pannello non si aprono l\'elenco, la scheda, i moduli, i referenti, i documenti e la situazione debitoria dei fornitori', function () {
    $solo = fornPermSuMisura([]);

    foreach ([...fornPermPagineDaLeggere($this), route('admin.fornitori.create'), ...fornPermModuliDiModifica($this)] as $url) {
        $risposta = $this->actingAs($solo)->get($url);
        $risposta->assertForbidden();
        foreach (['01234567890', 'idraulica@example.com', 'IT60X0542811101000000123456', 'RSSMRA80A01H501U', 'DURC Idraulica 2026', 'Fattura idraulico 2025'] as $dato) {
            expect($risposta->getContent())->not->toContain($dato);
        }
    }
});

test('Coda 402 — con il solo accesso al pannello non si crea, non si modifica e non si elimina un fornitore, né i suoi referenti, documenti e debiti', function () {
    $solo = fornPermSuMisura([]);

    $this->actingAs($solo)->post(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))->assertForbidden();
    $this->actingAs($solo)->postJson(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))
        ->assertForbidden()->assertJson(['message' => __('fornitori.permessi.create')]);
    foreach (fornPermModifiche($this, $solo) as $risposta) {
        $risposta->assertForbidden();
    }
    $this->actingAs($solo)->delete(route('admin.fornitori.destroy', $this->fornitore))->assertForbidden();

    expect(Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->exists())->toBeFalse()
        ->and(Fornitore::whereKey($this->fornitore->id)->exists())->toBeTrue();
    fornPermIntatto($this);
});

test('Coda 402 — con «Visualizza anagrafiche» si aprono l\'elenco, la scheda, i referenti, i documenti e la situazione debitoria; i moduli e le scritture no', function () {
    $consulta = fornPermSuMisura([Permission::VIEW_ANAGRAFICHE->value]);

    foreach (fornPermPagineDaLeggere($this) as $url) {
        $this->actingAs($consulta)->get($url)->assertOk();
    }
    expect(json_encode($this->actingAs($consulta)->get(route('admin.fornitori.show', $this->fornitore))->viewData('page')['props']['fornitore'], JSON_UNESCAPED_UNICODE))
        ->toContain("Idraulica D'Àngelo S.r.l.");

    foreach ([route('admin.fornitori.create'), ...fornPermModuliDiModifica($this)] as $url) {
        $this->actingAs($consulta)->get($url)->assertForbidden();
    }
    $this->actingAs($consulta)->post(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))->assertForbidden();
    foreach (fornPermModifiche($this, $consulta) as $risposta) {
        $risposta->assertForbidden();
    }
    $this->actingAs($consulta)->delete(route('admin.fornitori.destroy', $this->fornitore))->assertForbidden();

    expect(Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->exists())->toBeFalse();
    fornPermIntatto($this);
});

test('Coda 402 — «Crea anagrafiche» crea un fornitore, dal modulo e dalla registrazione di una fattura; «Modifica anagrafiche» no', function () {
    $crea = fornPermSuMisura([Permission::CREATE_ANAGRAFICHE->value]);

    $this->actingAs($crea)->get(route('admin.fornitori.create'))->assertOk();
    $this->actingAs($crea)->post(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.fornitori.index'));
    // La registrazione della fattura crea il fornitore letto dall'XML senza lasciare la pagina (`ModalCreaFornitoreDaXml.vue`).
    $json = $this->actingAs($crea)->postJson(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Ditta dall\'XML S.r.l.']))->assertCreated();
    expect(array_keys($json->json()))->toBe(['id', 'ragione_sociale']);

    $modifica = fornPermSuMisura([Permission::EDIT_ANAGRAFICHE->value]);
    $this->actingAs($modifica)->get(route('admin.fornitori.create'))->assertForbidden();
    $this->actingAs($modifica)->postJson(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Terza Ditta S.r.l.']))
        ->assertForbidden()->assertJson(['message' => __('fornitori.permessi.create')]);

    expect(Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->exists())->toBeTrue()
        ->and(Fornitore::where('ragione_sociale', 'Ditta dall\'XML S.r.l.')->exists())->toBeTrue()
        ->and(Fornitore::where('ragione_sociale', 'Terza Ditta S.r.l.')->exists())->toBeFalse();
});

test('Coda 402 — «Modifica anagrafiche» apre i moduli e modifica il fornitore, i suoi referenti, i suoi documenti e la situazione debitoria', function () {
    $modifica = fornPermSuMisura([Permission::EDIT_ANAGRAFICHE->value]);

    foreach (fornPermModuliDiModifica($this) as $url) {
        $this->actingAs($modifica)->get($url)->assertOk();
    }
    foreach (fornPermModifiche($this, $modifica) as $azione => $risposta) {
        expect($risposta->status(), $azione)->toBe(302);
        $risposta->assertSessionHasNoErrors();
    }

    expect($this->fornitore->fresh()->ragione_sociale)->toBe('Idraulica Rinominata S.r.l.')
        ->and($this->fornitore->referenti()->pluck('anagrafiche.id')->all())->toBe([$this->altraPersona->id])
        ->and($this->fornitore->documenti()->pluck('name')->all())->toBe(['Visura camerale'])
        ->and(Saldo::where('fornitore_id', $this->fornitore->id)->pluck('descrizione')->all())->toBe(['Saldo lavori 2024']);
});

test('Coda 402 — «Elimina anagrafiche» elimina un fornitore; «Modifica anagrafiche» non basta', function () {
    $this->actingAs(fornPermSuMisura([Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value]))
        ->delete(route('admin.fornitori.destroy', $this->fornitore))->assertForbidden();
    expect(Fornitore::whereKey($this->fornitore->id)->exists())->toBeTrue();

    $this->actingAs(fornPermSuMisura([Permission::DELETE_ANAGRAFICHE->value]))->from(route('admin.fornitori.index'))
        ->delete(route('admin.fornitori.destroy', $this->fornitore))
        ->assertRedirect(route('admin.fornitori.index'))->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    expect(Fornitore::whereKey($this->fornitore->id)->exists())->toBeFalse();
});

test('Coda 402 — l\'amministratore legge, crea, modifica ed elimina i fornitori', function () {
    $admin = fornPermUtente(Role::AMMINISTRATORE->value);

    foreach ([...fornPermPagineDaLeggere($this), route('admin.fornitori.create'), ...fornPermModuliDiModifica($this)] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
    $this->actingAs($admin)->post(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))->assertSessionHasNoErrors();
    foreach (fornPermModifiche($this, $admin) as $azione => $risposta) {
        expect($risposta->status(), $azione)->toBe(302);
    }
    $this->actingAs($admin)->delete(route('admin.fornitori.destroy', $this->fornitore))->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect(Fornitore::whereKey($this->fornitore->id)->exists())->toBeFalse()
        ->and(Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->exists())->toBeTrue();
});

test('Coda 402 — il collaboratore legge, crea e modifica i fornitori, e non li elimina, come le anagrafiche', function () {
    $collaboratore = fornPermUtente(Role::COLLABORATORE->value);

    foreach ([...fornPermPagineDaLeggere($this), route('admin.fornitori.create'), ...fornPermModuliDiModifica($this)] as $url) {
        $this->actingAs($collaboratore)->get($url)->assertOk();
    }
    $this->actingAs($collaboratore)->post(route('admin.fornitori.store'), fornPermScheda(['ragione_sociale' => 'Nuova Ditta S.r.l.']))->assertSessionHasNoErrors();
    foreach (fornPermModifiche($this, $collaboratore) as $azione => $risposta) {
        expect($risposta->status(), $azione)->toBe(302);
    }

    $this->actingAs($collaboratore)->delete(route('admin.fornitori.destroy', $this->fornitore))->assertForbidden();
    expect(Fornitore::whereKey($this->fornitore->id)->exists())->toBeTrue()
        ->and(Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->exists())->toBeTrue();
});
