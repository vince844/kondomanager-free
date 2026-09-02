<?php

/**
 * Decisione D2 (1.11.0-beta.13): prima di questa correzione una collisione contro l'indice
 * `unique_ft_condominio` (D1, stessa beta) finiva nel `catch (\Exception)` generico di
 * `FatturaPassivaController` — la sessione riceveva l'errore sotto la chiave `error`, ma
 * nessuna schermata (`FatturaRegisterNew.vue`, `FatturaRegisterEdit.vue`) la renderizza:
 * il salvataggio falliva senza che l'amministratore vedesse niente.
 *
 * Trovato mentre si scriveva `UpdateFatturaNumeroCollidenteTest.php`: quel file aveva un test
 * verde per il motivo sbagliato, perché la sua asserzione controllava solo la chiave
 * `numero_documento` e la vera collisione (stessa data per coincidenza) finiva sotto `error`.
 * Questo file prova direttamente il contratto della D2: il messaggio è di dominio, arriva
 * sotto `numero_documento`, e mai sotto `error`, sia in creazione sia in modifica.
 */

use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

function payloadCreazione($condominio, $esercizio, $gestione, $fornitore, $capitolo, string $numeroDocumento, string $dataDocumento): array
{
    return [
        'fornitore_id'       => $fornitore->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'tipo_documento'     => 'fattura',
        'numero_documento'   => $numeroDocumento,
        'data_documento'     => $dataDocumento,
        'data_scadenza'      => date('Y-m-d', strtotime($dataDocumento.' +30 days')),
        'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'righe' => [[
            'descrizione'        => 'Servizio Test',
            'importo_imponibile' => 100,
            'aliquota_iva'       => 22,
            'importo_iva'        => 22,
            'conto_id'           => $capitolo->id,
        ]],
    ];
}

test('registrare due fatture identiche (fornitore, numero, data) restituisce un messaggio di dominio, non l\'errore grezzo del database', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $this->actingAs($this->user)
        ->post(
            route('admin.gestionale.fatture.store', $condominio),
            payloadCreazione($condominio, $esercizio, $gestione, $fornitore, $capitolo, 'FT-DUP-INDICE', '2026-05-10')
        )
        ->assertSessionDoesntHaveErrors();

    $response = $this->actingAs($this->user)
        ->post(
            route('admin.gestionale.fatture.store', $condominio),
            payloadCreazione($condominio, $esercizio, $gestione, $fornitore, $capitolo, 'FT-DUP-INDICE', '2026-05-10')
        );

    $response->assertSessionHasErrors(['numero_documento']);
    $response->assertSessionDoesntHaveErrors(['error']);

    $messaggio = session('errors')->get('numero_documento')[0];
    expect($messaggio)
        ->not->toContain('SQLSTATE')
        ->not->toContain('Duplicate entry')
        ->not->toContain('UNIQUE constraint');

    // La seconda POST non deve aver scritto niente: resta una sola fattura con quel numero.
    expect(DB::table('fatture_passive')
        ->where('condominio_id', $condominio->id)
        ->where('numero_documento', 'FT-DUP-INDICE')
        ->count())->toBe(1);
});

test('in modifica, una collisione sull\'indice unico fra esercizi diversi (fuori dalla guardia D4) restituisce comunque un messaggio di dominio', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $esercizioAltro = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato'         => 'aperto',
    ]);
    $ctxAltro = $ctx;
    $ctxAltro[1] = $esercizioAltro;

    // Stessa data apposta: la guardia D4-forte guarda solo l'esercizio e qui lascia passare,
    // ma l'indice `unique_ft_condominio` (D1) è scoped al condominio e blocca comunque.
    $fatturaAltroEsercizio = registraFatturaServiceTest($ctxAltro, [
        'numero_documento' => 'FT-DUP-CROSS-ESERCIZIO',
        'data_documento'   => '2026-05-10',
        'righe' => [[
            'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);
    $fattura = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'FT-DUP-QUESTO-ESERCIZIO',
        'data_documento'   => '2026-05-10',
        'righe' => [[
            'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio, $fattura]), [
            'gestione_id'         => $gestione->id,
            'numero_documento'    => $fatturaAltroEsercizio->numero_documento,
            'data_documento'      => $fattura->data_documento->format('Y-m-d'),
            'data_scadenza'       => $fattura->data_scadenza->format('Y-m-d'),
            'modalita_pagamento'  => 'bonifico',
            'righe' => [[
                'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
            ]],
        ]);

    $response->assertSessionHasErrors(['numero_documento']);
    $response->assertSessionDoesntHaveErrors(['error']);

    $messaggio = session('errors')->get('numero_documento')[0];
    expect($messaggio)->not->toContain('SQLSTATE');
});
