<?php

/**
 * Coda 314 — lo scadenzario F24 resta dentro il condominio dell'indirizzo, anche con le deleghe di tutti gli esercizi.
 *
 * La Coda 314 allarga l'elenco alle deleghe e alle ritenute di tutti gli esercizi del condominio (`Beta59FiscoScadenziarioF24Test`).
 * Questi test tengono il perimetro: con due condomini A e B, ognuno con la sua delega, l'indirizzo di A non mostra e non tocca niente di B.
 * Le rotte sono quelle di `routes/gestionale.php` sotto `/gestionale/{condominio}`, che legano la delega al condominio dell'indirizzo
 * (`Condominio::relazioniDeiFigliNelleRotte`); una delega di un altro condominio è un 404, prima di ogni scrittura.
 *
 * Lo storno di una fattura (Coda 313) passa al servizio l'id della fattura della rotta: la fattura di un altro condominio è un 404 e non
 * scrive nessuna nota di credito.
 *
 * Le cifre non contano qui: contano le righe che si vedono e le scritture che non si fanno (conteggi prima e dopo).
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');
});

/** Il contesto dei pagamenti (un condominio con il suo esercizio, la sua banca e un fornitore in appalto al 4 %, persona fisica). */
function b59perCtx(): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();

    return $ctx;
}

/** Una fattura con ritenuta pagata, e la delega in bozza che «Aggiorna scadenze» scrive per il condominio del contesto. */
function b59perDelega($test, array $ctx): DelegaF24
{
    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => 20000.00,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => now()->toDateString(),
        'esercizio_id' => $ctx[1]->id,
    ]));
    $test->actingAs($test->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();

    return DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
}

test('Coda 314 — l\'elenco dello scadenzario F24 di un condominio porta solo le sue deleghe, e la scheda della delega di un altro condominio è un 404', function () {
    $a = b59perCtx();
    $b = b59perCtx();
    expect($a[0]->id)->not->toBe($b[0]->id);
    $delegaB = b59perDelega($this, $b);
    $delegaA = b59perDelega($this, $a);

    // l'elenco di A: la delega di A e nessun'altra
    $this->actingAs($this->admin)->get(route('admin.gestionale.f24.index', $a[0]->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('deleghe.data', fn ($d) => collect($d)->pluck('id')->all() === [$delegaA->id]));

    // la scheda di B dall'indirizzo di A: 404; la controprova, la scheda di A dal suo indirizzo
    $this->actingAs($this->admin)->get(route('admin.gestionale.f24.show', [$a[0]->id, $delegaB->id]))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.gestionale.f24.show', [$a[0]->id, $delegaA->id]))->assertOk();
});

test('Coda 314 — versare, stornare o annullare la delega di un altro condominio dall\'indirizzo del primo è un 404 e non scrive niente; la delega del primo si versa dal suo indirizzo', function () {
    $a = b59perCtx();
    $b = b59perCtx();
    $delegaB = b59perDelega($this, $b);
    $delegaA = b59perDelega($this, $a);
    $scritture = DB::table('scritture_contabili')->count();
    $righe = DB::table('righe_scritture')->count();

    $versamento = ['data_versamento' => now()->toDateString(), 'conto_corrente_id' => $a[4]];
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.versa', [$a[0]->id, $delegaB->id]), $versamento)->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.storna', [$a[0]->id, $delegaB->id]), ['data_storno' => now()->toDateString(), 'motivo' => 'Prova'])->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.annulla', [$a[0]->id, $delegaB->id]), ['motivo' => 'Prova'])->assertNotFound();

    expect(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and(DB::table('righe_scritture')->count())->toBe($righe)
        ->and($delegaB->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);

    // controprova: la stessa richiesta sulla delega di A, dal suo indirizzo, passa
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.versa', [$a[0]->id, $delegaA->id]), $versamento)->assertSessionHasNoErrors();
    expect($delegaA->fresh()->stato)->toBe(StatoDelegaF24::VERSATA)
        ->and($delegaB->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);
});

test('Coda 313 — lo storno di una fattura di un altro condominio, dall\'indirizzo del primo, è un 404 e non scrive nessuna nota di credito', function () {
    $a = b59perCtx();
    $b = b59perCtx();
    b59perDelega($this, $b);
    b59perDelega($this, $a);
    $fatturaB = FatturaPassiva::where('condominio_id', $b[0]->id)->sole();
    $note = FatturaPassiva::where('tipo_documento', 'nota_credito')->count();
    $scritture = DB::table('scritture_contabili')->count();

    $this->actingAs($this->admin)->post(route('admin.gestionale.fatture.storno', [$a[0]->id, $fatturaB->id]))->assertNotFound();

    expect(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe($note)
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and((bool) $fatturaB->fresh()->is_stornata)->toBeFalse();
});
