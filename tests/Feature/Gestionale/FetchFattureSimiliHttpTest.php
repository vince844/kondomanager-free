<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * L'endpoint HTTP della decisione D4 (1.11.0-beta.13): rotta, scoping al condominio,
 * autorizzazione. La regola dei duplicati in sé è provata in RicercaFattureSimiliTest.php.
 */
beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo([$permessoAdmin]);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

it('risponde con le fatture simili nel condominio giusto', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, , $fornitore, $capitolo] = $ctx;
    $prima = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'HTTP-100',
        'data_documento' => '2026-06-10',
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $risposta = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'fornitore_id' => $fornitore->id,
            'numero_documento' => 'HTTP-100',
        ])
    );

    // ⚠️ **Prima si asserivano solo `motivo` e `numero_documento`, trovato dalla revisione
    // avversariale della beta.13.** Un `÷100` su `totale_documento` in `FatturaSimile::toArray()`
    // — la classe di difetto del ×100 costato la beta.32 — lasciava verde l'intera cartella:
    // il banner avrebbe scritto «€ 12,20» al posto di «€ 1.220,00». `data_documento` e
    // `is_pregresso` non erano coperti da nessuna asserzione su questo contratto JSON.
    $risposta->assertOk()->assertJsonCount(1)
        ->assertJsonFragment([
            'motivo' => 'forte',
            'numero_documento' => 'HTTP-100',
            'totale_documento' => (int) $prima->totale_documento,
            'data_documento' => '2026-06-10',
            'is_pregresso' => false,
        ]);
});

it('livello standard: il payload viaggia intero fino all endpoint, importo e data compresi', function () {
    // ⚠️ **A3, trovato dalla revisione avversariale della beta.13.** Le richieste esistenti in
    // questo file non inviano mai `totale_documento_cents` né `data_documento`: il cablaggio
    // dei parametri della FormRequest dentro `FetchFattureSimiliController::__invoke()` non
    // aveva copertura HTTP, solo quella (diversa) del servizio sottostante.
    $ctx = setupContabile();
    [$condominio, $esercizio, , $fornitore, $capitolo] = $ctx;
    $prima = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'HTTP-STD-1',
        'data_documento' => '2026-06-10',
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $risposta = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'fornitore_id' => $fornitore->id,
            // Numero deliberatamente diverso: solo il livello standard può trovarla.
            'numero_documento' => 'TUTT-ALTRO-NUMERO',
            'totale_documento_cents' => (int) $prima->totale_documento,
            'data_documento' => '2026-06-14',
        ])
    );

    $risposta->assertOk()->assertJsonCount(1)
        ->assertJsonFragment(['motivo' => 'standard', 'numero_documento' => 'HTTP-STD-1']);
});

it('escludi_fattura_id arriva davvero al servizio: la fattura aperta non si segnala da sola via HTTP', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, , $fornitore, $capitolo] = $ctx;
    $prima = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'HTTP-ESCLUSA',
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $senzaEsclusione = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'fornitore_id' => $fornitore->id,
            'numero_documento' => 'HTTP-ESCLUSA',
        ])
    );
    $senzaEsclusione->assertOk()->assertJsonCount(1);

    $conEsclusione = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'fornitore_id' => $fornitore->id,
            'numero_documento' => 'HTTP-ESCLUSA',
            'escludi_fattura_id' => $prima->id,
        ])
    );
    $conEsclusione->assertOk()->assertJsonCount(0);
});

it('un utente senza accesso al pannello amministratore prende 403', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, , $fornitore] = $ctx;
    $senzaPermessi = User::factory()->create();

    $risposta = $this->actingAs($senzaPermessi)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'fornitore_id' => $fornitore->id,
        ])
    );

    $risposta->assertForbidden();
});

it('⚠️ ANTI-IDOR: il condominio nell URL vince, anche se la richiesta prova a dirne un altro', function () {
    // ⚠️ **Controprova eseguita, e la prima versione di questo test era debole.** Mutando il
    // controller per fargli leggere `condominio_id` dal client (`$request->input('condominio_id',
    // $condominio->id)`, l'errore che un futuro refactor potrebbe scrivere) il test restava
    // verde lo stesso — non perché lo scoping reggesse, ma perché l'attacco era incompleto:
    // mancava l'`esercizio_id` di A, che il livello forte richiede. Con l'attacco costruito bene
    // (stesso esercizio della fattura vera) il controller mutato **fa passare la fattura di A**:
    // `[{"numero_documento":"IDOR-DEBUG", ...,"motivo":"forte"}]`. Sul codice reale resta vuoto.
    $ctxA = setupContabile();
    $ctxB = setupContabile();
    [$condominioA, $esercizioA, , $fornitoreA, $capitoloA] = $ctxA;
    $fatturaA = registraFatturaServiceTest($ctxA, [
        'numero_documento' => 'IDOR-1',
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitoloA->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    [$condominioB] = $ctxB;

    $risposta = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', [
            'condominio' => $condominioB->id,
            // L'esercizio e il fornitore sono quelli VERI di A — un client che tentasse di
            // rileggere la fattura di A dall'URL di B userebbe esattamente questi valori, non
            // quelli (a lui ignoti) del condominio B.
            'esercizio_id' => $esercizioA->id,
            'fornitore_id' => $fornitoreA->id,
            'numero_documento' => $fatturaA->numero_documento,
            // Un campo che il controller non legge: c'è per dimostrare che aggiungerlo alla
            // richiesta non basta a farlo leggere, non perché serva davvero al servizio.
            'condominio_id' => $condominioA->id,
        ])
    );

    $risposta->assertOk()->assertJsonCount(0);
});

it('senza esercizio_id o fornitore_id risponde 422, non 500', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $risposta = $this->actingAs($this->user)->getJson(
        route('admin.gestionale.fetch-fatture-simili', ['condominio' => $condominio->id])
    );

    $risposta->assertStatus(422);
});

it('un condominio inesistente nell URL non arriva al controller', function () {
    $risposta = $this->actingAs($this->user)->getJson(
        '/admin/gestionale/999999/fetch-fatture-simili?esercizio_id=1&fornitore_id=1'
    );

    $risposta->assertNotFound();
});
