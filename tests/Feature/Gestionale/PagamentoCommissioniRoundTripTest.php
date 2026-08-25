<?php

/**
 * Le commissioni bancarie di un pagamento fornitore, aperto in modifica e risalvato
 * senza toccare niente.
 *
 * È la metà lato server di un difetto che viveva nel frontend: `PagamentoEdit.vue`
 * inizializzava la casella delle commissioni con `importo_commissione * 100`, mentre la
 * prop arriva dalla `PagamentoFornitoreResource` ed è **già** in centesimi. Una commissione
 * di 2,50 € (250 a DB) si riapriva come 25.000,00 € a video e, al salvataggio, tornava a
 * DB moltiplicata per diecimila — senza che l'amministratore avesse toccato il campo.
 *
 * Il frontend è fissato da `resources/js/pages/gestionale/movimenti/pagamenti/PagamentoEdit.test.ts`.
 * Questi test fissano invece il **riferimento** su cui quel codice si appoggia: che la
 * schermata di modifica riceva i centesimi interi del database, e che rimandarglieli
 * indietro identici li lasci identici. Se un domani il PHP decidesse di esporre euro, è
 * questo file che si accende e ricorda che di là c'è una conversione che ci conta — la
 * regola imparata nella beta.35, quando lo stesso numero calcolato in due linguaggi
 * divergeva senza che nessuna delle due parti fosse sbagliata da sola.
 *
 * Il danno non era intercettabile dalla partita doppia: la cifra gonfiata entrava sia nel
 * DARE di «spese bancarie» sia nell'AVERE della banca, quindi la scrittura quadrava lo
 * stesso. Per questo l'ultimo test guarda l'uscita di cassa e non solo la quadratura.
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/** Le 2,50 € della segnalazione: 250 centesimi interi. */
const COMMISSIONI_CENTS = 250;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

/**
 * Registra un pagamento con commissioni e restituisce tutto ciò che serve a rifarne
 * l'update: il contesto, la fattura, il pagamento e il payload originale.
 */
function scenarioPagamentoConCommissioni(int $commissioniCents = COMMISSIONI_CENTS): array
{
    $ctx = setupPagamentiHttp();
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);

    $payload = [
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => 122000,
        'importo_netto_cents' => 122000,
        'importo_commissioni_cents' => $commissioniCents,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 122000,
        ]],
        'allow_overdraft' => true,
    ];

    $pagamento = (new PagamentoFornitoreService)->registraPagamento($payload);

    return [$ctx, $fattura, $pagamento, $payload];
}

// ════════════════════════════════════════════════════════════════════════════
// Il contratto: che unità di misura attraversa il confine verso il frontend
// ════════════════════════════════════════════════════════════════════════════

test('la schermata di modifica riceve le commissioni in centesimi interi, non in euro', function () {
    [$ctx, , $pagamento] = scenarioPagamentoConCommissioni();
    [$condominio] = $ctx;

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$condominio, $pagamento]));

    $response->assertStatus(200);

    $props = $response->viewData('page')['props'];

    // 250, non 2.5: è questo che autorizza `PagamentoEdit.vue` a dividere per 100.
    expect($props['pagamento']['importo_commissione'])->toEqual(COMMISSIONI_CENTS);
});

test('gli altri importi della stessa prop viaggiano nella medesima unità', function () {
    [$ctx, , $pagamento] = scenarioPagamentoConCommissioni();
    [$condominio] = $ctx;

    $props = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$condominio, $pagamento]))
        ->viewData('page')['props'];

    // Il difetto nasceva proprio qui: netto/lordo/ritenuta erano passati al form senza
    // conversione, le sole commissioni con un `* 100`. Un unico oggetto, due unità.
    expect($props['pagamento']['importo_netto'])->toEqual(122000)
        ->and($props['pagamento']['importo_lordo'])->toEqual(122000)
        ->and($props['pagamento']['importo_ritenuta'])->toEqual(0);
});

// ════════════════════════════════════════════════════════════════════════════
// Il giro completo: apro, non tocco niente, salvo
// ════════════════════════════════════════════════════════════════════════════

test('risalvare senza modifiche lascia le commissioni identiche a DB', function () {
    [$ctx, , $pagamento, $payload] = scenarioPagamentoConCommissioni();
    [$condominio] = $ctx;

    expect($pagamento->importo_commissione)->toEqual(COMMISSIONI_CENTS);

    // Il payload che il form produce quando l'amministratore apre e salva senza toccare
    // il campo: gli stessi centesimi che gli sono stati consegnati.
    $response = $this->actingAs($this->user)
        ->put(
            route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]),
            $payload
        );

    $response->assertSessionHasNoErrors();

    expect($pagamento->fresh()->importo_commissione)->toEqual(COMMISSIONI_CENTS);
});

test('due giri di apri-e-salva non fanno crescere le commissioni', function () {
    [$ctx, , $pagamento, $payload] = scenarioPagamentoConCommissioni();
    [$condominio] = $ctx;

    foreach ([1, 2] as $giro) {
        $this->actingAs($this->user)
            ->put(route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]), $payload)
            ->assertSessionHasNoErrors();

        expect($pagamento->fresh()->importo_commissione)
            ->toEqual(COMMISSIONI_CENTS, "Le commissioni sono cambiate al giro {$giro}.");
    }
});

// ════════════════════════════════════════════════════════════════════════════
// Ciò che la quadratura non avrebbe visto
// ════════════════════════════════════════════════════════════════════════════

test('dopo il risalvataggio la banca esce del netto più le sole commissioni vere', function () {
    [$ctx, , $pagamento, $payload] = scenarioPagamentoConCommissioni();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]), $payload)
        ->assertSessionHasNoErrors();

    $pagamento->refresh();
    $scritturaId = $pagamento->scrittura_contabile_id;

    // La scrittura quadrava anche col valore gonfiato — DARE spese bancarie e AVERE banca
    // crescevano insieme. Serve guardare l'importo, non l'equilibrio.
    assertQuadraturaPerfetta($scritturaId);

    $uscitaBanca = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $scritturaId)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoCorrenteId)
        ->sum('importo');

    expect($uscitaBanca)->toEqual(122000 + COMMISSIONI_CENTS);

    $contoSpese = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'spese_bancarie')
        ->first();

    $dareSpeseBancarie = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $scritturaId)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoSpese->id)
        ->sum('importo');

    expect($dareSpeseBancarie)->toEqual(COMMISSIONI_CENTS);
});

test('la modifica non moltiplica le commissioni nemmeno quando è il campo a cambiare', function () {
    [$ctx, , $pagamento, $payload] = scenarioPagamentoConCommissioni();
    [$condominio] = $ctx;

    // 3,10 €: l'amministratore corregge davvero la cifra. Deve arrivare 310, non 31.000.
    $this->actingAs($this->user)
        ->put(
            route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]),
            array_merge($payload, ['importo_commissioni_cents' => 310])
        )
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->importo_commissione)->toEqual(310);
});

test('un pagamento senza commissioni resta senza commissioni', function () {
    [$ctx, , $pagamento, $payload] = scenarioPagamentoConCommissioni(0);
    [$condominio] = $ctx;

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]), $payload)
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->importo_commissione)->toEqual(0);

    expect(PagamentoFornitore::whereKey($pagamento->id)->value('importo_commissione'))->toEqual(0);
});
