<?php

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

test('pagina di modifica fattura passiva espone tutte le prop di contesto budget richieste dal form', function () {
    $ctx = setupContabile();
    [$condominio, , , $fornitore, $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.edit', [$condominio, $fattura]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/fatture/FatturaRegisterEdit')
        ->has('fornitori')
        ->where('fornitori.0.id', $fornitore->id)
        ->has('esercizi')
        ->has('debiti_patrimoniali')
        ->has('fatture_pregresse_registrate')
        ->has('fondi_riserva')
        ->has('capienza_rata_zero')
        ->has('incassato_rata_zero')
        ->has('conti')
        ->where('conti.0.id', $capitolo->id)
        ->has('conti.0.residuo_budget')
        ->has('conti.0.is_capiente')
    );
});

test('pagina di creazione fattura passiva espone lo stesso contesto budget della modifica', function () {
    $ctx = setupContabile();
    [$condominio, , , $fornitore, $capitolo] = $ctx;

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.create', [$condominio]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/fatture/FatturaRegisterNew')
        ->has('fornitori')
        ->where('fornitori.0.id', $fornitore->id)
        ->has('conti')
        ->where('conti.0.id', $capitolo->id)
        ->has('conti.0.residuo_budget')
    );
});

test('in modifica il residuo budget esclude la fattura stessa: nessun falso sforamento', function () {
    // Il capitolo di setupContabile ha budget 500.000 cent; la fattura vale 122.000 cent
    // (1.000 € imponibile + 22% IVA). Se il residuo esposto in edit sottraesse anche la
    // fattura in modifica, il frontend — che risomma l'importo digitato — vedrebbe
    // 378.000 di residuo contro 122.000 di spesa e segnalerebbe uno sforamento inesistente.
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.edit', [$condominio, $fattura]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('conti.0.id', $capitolo->id)
        ->where('conti.0.residuo_budget', 500000)
        ->where('conti.0.is_capiente', true)
    );
});

test('in creazione il residuo budget continua a contare le fatture già registrate', function () {
    // Contro-prova del test precedente: l'esclusione vale SOLO per la fattura in modifica,
    // altrimenti in creazione il budget risulterebbe sempre intatto.
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.create', [$condominio]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('conti.0.id', $capitolo->id)
        ->where('conti.0.residuo_budget', 378000)
    );
});

test('una fattura stornata resta leggibile: stato_pagamento=stornata non crasha il cast enum', function () {
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    DB::table('fatture_passive')->where('id', $fattura->id)->update([
        'stato_pagamento' => 'stornata',
        'dati_extra' => json_encode(['is_stornata' => true]),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.show', [$condominio, $fattura]));

    $response->assertOk();
});

test('la modifica di una fattura stornata reindirizza al dettaglio con un messaggio chiaro invece di renderizzare il form', function () {
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    DB::table('fatture_passive')->where('id', $fattura->id)->update([
        'stato_pagamento' => 'stornata',
        'dati_extra' => json_encode(['is_stornata' => true]),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.edit', [$condominio, $fattura]));

    $response->assertRedirect(route('admin.gestionale.fatture.show', [$condominio, $fattura]));
    $response->assertSessionHas('message.type', 'error');
});

test('il salvataggio di una modifica bloccata restituisce un errore nella errors bag, non un successo silenzioso', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    DB::table('fatture_passive')->where('id', $fattura->id)->update([
        'stato_pagamento' => 'stornata',
        'dati_extra' => json_encode(['is_stornata' => true]),
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio, $fattura]), [
            'gestione_id' => $gestione->id,
            'numero_documento' => $fattura->numero_documento,
            'data_documento' => $fattura->data_documento->format('Y-m-d'),
            'data_scadenza' => $fattura->data_scadenza->format('Y-m-d'),
            'modalita_pagamento' => 'bonifico',
            'righe' => [[
                'descrizione' => 'Servizio Test',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
            ]],
        ]);

    $response->assertSessionHasErrors(['modifica_vietata']);
    $response->assertSessionDoesntHaveErrors(['error']);
});
