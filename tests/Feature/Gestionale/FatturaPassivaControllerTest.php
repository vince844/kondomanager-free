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

test('il residuo budget sottrae il LORDO delle fatture registrate, non il solo imponibile', function () {
    // Tre fatture consecutive sullo stesso capitolo con aliquote diverse: è lo scenario in cui
    // una base di confronto sbagliata si accumula fattura dopo fattura.
    // Budget 500.000 − lordo registrato 260.200 = residuo 239.800.
    // Sui soli imponibili il residuo sarebbe 270.000: i 30.200 di differenza sono l'IVA, ed è la
    // ragione per cui il form deve confrontare il LORDO della fattura corrente contro questo residuo.
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;

    foreach ([[1000, 22], [500, 10], [800, 4]] as [$imponibile, $aliquota]) {
        registraFatturaServiceTest($ctx, [
            'righe' => [[
                'descrizione' => 'Servizio Test',
                'importo_imponibile' => $imponibile,
                'aliquota_iva' => $aliquota,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]);
    }

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.create', [$condominio]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('conti.0.id', $capitolo->id)
        ->where('conti.0.residuo_budget', 239800)
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

test('elenco fatture: filtro stato_pagamento isola le fatture per stato', function () {
    // NB: registraFatturaServiceTest() senza override di 'righe' usa internamente un
    // $capitolo mal destrutturato (punta al conto Fondo Riserva, non al capitolo di
    // spesa) — bug preesistente nell'helper condiviso, mascherato altrove perché ogni
    // altro test in questo file passa già un override esplicito di 'righe'. Facciamo
    // lo stesso qui invece di toccare l'helper (fuori scope).
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $righe = ['righe' => [[
        'descrizione' => 'Servizio Test',
        'importo_imponibile' => 1000,
        'aliquota_iva' => 22,
        'conto_id' => $capitolo->id,
        'is_sopravvenienza' => false,
    ]]];

    registraFatturaServiceTest($ctx, $righe);
    $pagata = registraFatturaServiceTest($ctx, $righe);
    DB::table('fatture_passive')->where('id', $pagata->id)->update(['stato_pagamento' => 'pagata']);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.index', [$condominio]).'?stato_pagamento=pagata');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('fatture.data', 1)
        ->where('fatture.data.0.id', $pagata->id)
    );
});

test('elenco fatture: filtro data_da/data_a isola le fatture per data documento', function () {
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $righeBase = [
        'descrizione' => 'Servizio Test',
        'importo_imponibile' => 1000,
        'aliquota_iva' => 22,
        'conto_id' => $capitolo->id,
        'is_sopravvenienza' => false,
    ];

    registraFatturaServiceTest($ctx, ['data_documento' => '2026-01-10', 'righe' => [$righeBase]]);
    $dentroRange = registraFatturaServiceTest($ctx, ['data_documento' => '2026-03-15', 'righe' => [$righeBase]]);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.index', [$condominio]).'?data_da=2026-03-01&data_a=2026-03-31');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('fatture.data', 1)
        ->where('fatture.data.0.id', $dentroRange->id)
    );
});
