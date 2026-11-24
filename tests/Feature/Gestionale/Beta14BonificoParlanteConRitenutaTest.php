<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (2), decisione 696 (D1): il bonifico parlante di una fattura registrata con la ritenuta si ferma e lo dice.
 *
 * Edilnord ripara la facciata: fattura n. 12 di € 1.220,00 (€ 1.000,00 + IVA 22 %), registrata con la ritenuta d'appalto del 4 %
 * (€ 40,00). La fattura scrive i € 40,00 su «Debiti v/Erario» già alla registrazione e il debito verso il fornitore al netto,
 * € 1.180,00. Fino alla beta.13 il pagamento con la spunta «Bonifico Parlante» metteva a zero la ritenuta del pagamento ma lasciava la
 * fattura com'era: uscivano € 1.180,00, il lordo era rifiutato come eccedenza, e i € 40,00 restavano sull'Erario senza che nessun F24 li
 * versasse (le deleghe leggono la ritenuta dei pagamenti). La pagina diceva «Al fornitore uscirà l'importo pieno».
 *
 * Con la decisione la ritenuta si decide in un posto solo, la fattura: il pagamento si rifiuta, in registrazione e in modifica, con
 * la frase che manda alla modifica della fattura (motivo «Bonifico parlante»); poi esce l'importo pieno, € 1.220,00, e nessuna delle
 * pagine che leggono la ritenuta della fattura (le deleghe, il promemoria, la situazione dei debiti, lo Stato patrimoniale) cambia.
 *
 * Tutto dalle rotte: `fatture.store`, `fatture.update`, `pagamenti-fornitori.store` e `.update`.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\MetodoPagamento;
use App\Enums\Role;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Pagamenti\BonificoParlanteConRitenutaException;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\F24\PromemoriaDelleRitenute;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    $this->admin = $this->admin->fresh();
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Promemoria tecnici per l\'amministratore']);
    $this->travelTo(Carbon::parse('2026-03-20 10:00', 'Europe/Rome')->utc());
});

const B14BP_FRASE = 'La fattura n. 12 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e il '
    .'condominio non la trattiene: togli prima la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»), poi registra il pagamento '
    .'di € 1.220,00.';

/** La stessa frase quando la fattura ha già il pagamento di € 1.180,00 (il giro di correzioni della tappa 6): la modifica si rifiuta, si storna prima. */
const B14BP_FRASE_CON_PAGAMENTO = 'La fattura n. 12 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e il '
    .'condominio non la trattiene: la fattura ha già il pagamento di € 1.180,00 del 20/03/2026; per pagarla con il bonifico parlante storna prima quel '
    .'pagamento, poi togli la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante») e registra il pagamento di € 1.220,00.';

/** Il condominio con la banca, e Edilnord in appalto al 4 % (codice 1019). Gli eventi del pagamento girano veri. */
function b14bpCtx(): array
{
    $ctx = setupPagamentiHttp();
    $ctx[3]->update([
        'ragione_sociale' => 'Edilnord',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();

    return $ctx;
}

/** Il corpo della fattura della facciata: € 1.000,00 + IVA 22 %. Con `$motivo` la fattura nasce senza la ritenuta, con quel motivo. */
function b14bpCorpoFattura(array $ctx, string $numero = '12', ?string $motivo = null, float $imponibile = 1000): array
{
    [, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    return [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => $numero,
        'data_documento' => '2026-03-10',
        'data_scadenza' => '2026-04-10',
        'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'applica_ritenuta' => $motivo === null,
        'dati_extra' => ['fiscal' => $motivo === null ? [] : ['motivo_esclusione_ritenuta' => $motivo], 'competenza' => null, 'override_budget' => null],
        'righe' => [[
            'descrizione' => 'Riparazione della facciata',
            'importo_imponibile' => $imponibile,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ];
}

/** Registra la fattura dalla rotta `fatture.store`. */
function b14bpFattura($test, array $ctx, string $numero = '12', ?string $motivo = null, float $imponibile = 1000): FatturaPassiva
{
    $test->actingAs($test->admin)
        ->post(route('admin.gestionale.fatture.store', ['condominio' => $ctx[0]->id]), b14bpCorpoFattura($ctx, $numero, $motivo, $imponibile))
        ->assertSessionHasNoErrors();

    return FatturaPassiva::where('condominio_id', $ctx[0]->id)->where('numero_documento', $numero)->firstOrFail();
}

/** Il corpo del pagamento di una o più fatture, come lo manda la pagina «Nuovo pagamento». */
function b14bpCorpoPagamento(array $ctx, array $allocazioni, bool $parlante): array
{
    [, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    return [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => '2026-03-20',
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => 'IT60X0542811101000000123456',
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allow_overdraft' => true,
        'bonifico_parlante' => $parlante,
        'tipo_detrazione' => $parlante ? 'ristrutturazione' : null,
        'allocazioni' => array_map(fn ($a) => [
            'fattura_id' => $a[0]->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $a[1],
        ], $allocazioni),
    ];
}

function b14bpPaga($test, array $ctx, array $allocazioni, bool $parlante)
{
    return $test->actingAs($test->admin)
        ->from(route('admin.gestionale.pagamenti-fornitori.create', [$ctx[0]]))
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$ctx[0]]), b14bpCorpoPagamento($ctx, $allocazioni, $parlante));
}

/** Il saldo di «Debiti v/Erario» del condominio, in avere (positivo = debito verso l'Erario). */
function b14bpErario(array $ctx): int
{
    $conto = DB::table('conti_contabili')->where('condominio_id', $ctx[0]->id)->where('ruolo', 'debiti_erario_ritenute')->value('id');
    $avere = (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto)->where('tipo_riga', 'avere')->sum('importo');
    $dare = (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto)->where('tipo_riga', 'dare')->sum('importo');

    return $avere - $dare;
}

function b14bpScrittureDiPagamento(array $ctx): int
{
    return DB::table('scritture_contabili')->where('condominio_id', $ctx[0]->id)->where('tipo_movimento', 'pagamento_fornitore')->count();
}

/** I promemoria delle ritenute aperti del condominio. */
function b14bpPromemoriaAperti(array $ctx): int
{
    return Evento::where('meta->type', PromemoriaDelleRitenute::TIPO)
        ->where('is_completed', false)
        ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $ctx[0]->id))
        ->count();
}

// ── (1) R2 e R4: il pagamento con il bonifico parlante si ferma ──────────────────────────────────────────────────────────────────

it('R2: il bonifico parlante della fattura con la ritenuta, per il netto che la pagina offre, si ferma con la frase e non scrive niente', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);

    expect((int) $fattura->importo_ritenuta)->toBe(4000)
        ->and((int) $fattura->netto_a_pagare)->toBe(118000)
        ->and(b14bpErario($ctx))->toBe(4000);

    b14bpPaga($this, $ctx, [[$fattura, 118000]], true)
        ->assertRedirect(route('admin.gestionale.pagamenti-fornitori.create', [$ctx[0]]))
        ->assertSessionHasErrors(['bonifico_parlante' => B14BP_FRASE]);

    expect(PagamentoFornitore::count())->toBe(0)
        ->and(b14bpScrittureDiPagamento($ctx))->toBe(0)
        ->and(b14bpErario($ctx))->toBe(4000)
        ->and($fattura->fresh()->stato_pagamento)->toBe(StatoPagamentoFattura::APERTA);
});

it('R2: anche per l\'importo pieno, € 1.220,00, il rifiuto è quello del bonifico parlante e non quello dell\'eccedenza', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);

    $risposta = b14bpPaga($this, $ctx, [[$fattura, 122000]], true)
        ->assertSessionHasErrors(['bonifico_parlante' => B14BP_FRASE]);

    $risposta->assertSessionDoesntHaveErrors(['overpayment']);
    expect(PagamentoFornitore::count())->toBe(0)
        ->and(b14bpErario($ctx))->toBe(4000);
});

it('il pagamento cumulativo con una fattura con la ritenuta e una senza si ferma e nomina solo quella con la ritenuta', function () {
    $ctx = b14bpCtx();
    $conRitenuta = b14bpFattura($this, $ctx);
    $senza = b14bpFattura($this, $ctx, '13', 'fuori_campo', 500);

    expect((int) $senza->importo_ritenuta)->toBe(0);

    b14bpPaga($this, $ctx, [[$conRitenuta, 118000], [$senza, 61000]], true)
        ->assertSessionHasErrors(['bonifico_parlante' => B14BP_FRASE]);

    expect(PagamentoFornitore::count())->toBe(0)
        ->and(b14bpScrittureDiPagamento($ctx))->toBe(0);
});

// ── (2) In modifica: la spunta messa su un pagamento della fattura con la ritenuta ──────────────────────────────────────────────

it('in modifica, la spunta del bonifico parlante messa sul pagamento di una fattura con la ritenuta si ferma con la stessa frase', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);
    b14bpPaga($this, $ctx, [[$fattura, 118000]], false)->assertSessionHasNoErrors();
    $pagamento = PagamentoFornitore::firstOrFail();

    expect((int) $pagamento->importo_ritenuta)->toBe(4000);

    $this->actingAs($this->admin)
        ->from(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0], $pagamento]))
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $pagamento]), [
            'conto_corrente_id' => $ctx[4],
            'data_pagamento' => '2026-03-20',
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'importo_lordo_cents' => 122000,
            'importo_ritenuta_cents' => 4000,
            'importo_netto_cents' => 118000,
            'importo_commissioni_cents' => 0,
            'bonifico_parlante' => true,
            'tipo_detrazione' => 'ristrutturazione',
            'allow_overdraft' => true,
        ])
        ->assertSessionHasErrors(['bonifico_parlante' => B14BP_FRASE_CON_PAGAMENTO]);

    $pagamento->refresh();
    expect($pagamento->bonifico_parlante)->toBeFalse()
        ->and((int) $pagamento->importo_ritenuta)->toBe(4000)
        ->and(b14bpErario($ctx))->toBe(4000);
});

// ── (3) La controprova, e la catena da un capo all'altro ─────────────────────────────────────────────────────────────────────────

it('la fattura registrata con il motivo «Bonifico parlante» si paga con la spunta per € 1.220,00: nessuna ritenuta, Erario a zero, fattura pagata', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx, '12', 'bonifico_parlante');

    expect((int) $fattura->importo_ritenuta)->toBe(0)
        ->and((int) $fattura->netto_a_pagare)->toBe(122000);

    b14bpPaga($this, $ctx, [[$fattura, 122000]], true)->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::firstOrFail();
    expect($pagamento->bonifico_parlante)->toBeTrue()
        ->and((int) $pagamento->importo_ritenuta)->toBe(0)
        ->and((int) $pagamento->importo_netto)->toBe(122000)
        ->and(b14bpErario($ctx))->toBe(0)
        ->and(app(GeneraDelegheF24Action::class)->ritenuteDaVersare($ctx[0]))->toBeEmpty()
        ->and($fattura->fresh()->stato_pagamento)->toBe(StatoPagamentoFattura::PAGATA);
});

it('la catena: rifiuto, la fattura modificata con il motivo, il pagamento pieno; niente da versare, nessun promemoria F24, Erario a zero', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);

    // 1. Il bonifico parlante si ferma, e manda alla modifica della fattura.
    b14bpPaga($this, $ctx, [[$fattura, 118000]], true)->assertSessionHasErrors(['bonifico_parlante' => B14BP_FRASE]);

    // 2. La fattura si modifica dalla sua rotta: niente ritenuta, motivo «Bonifico parlante».
    $this->actingAs($this->admin)
        ->put(route('admin.gestionale.fatture.update', [$ctx[0]->id, $fattura->id]), b14bpCorpoFattura($ctx, '12', 'bonifico_parlante'))
        ->assertSessionHasNoErrors();
    $fattura->refresh();
    expect((int) $fattura->importo_ritenuta)->toBe(0)
        ->and((int) $fattura->netto_a_pagare)->toBe(122000)
        ->and(b14bpErario($ctx))->toBe(0);

    // 3. Il pagamento con la spunta per l'importo pieno passa.
    b14bpPaga($this, $ctx, [[$fattura, 122000]], true)->assertSessionHasNoErrors();
    app(PromemoriaDelleRitenute::class)->allinea($ctx[0]->fresh());

    $pagamento = PagamentoFornitore::firstOrFail();
    $banca = (int) DB::table('righe_scritture')->where('scrittura_id', $pagamento->scrittura_contabile_id)
        ->where('conto_contabile_id', $ctx[4])->where('tipo_riga', 'avere')->sum('importo');

    expect((int) $pagamento->importo_ritenuta)->toBe(0)
        ->and($banca)->toBe(122000)
        ->and(app(GeneraDelegheF24Action::class)->ritenuteDaVersare($ctx[0]))->toBeEmpty()
        ->and(b14bpPromemoriaAperti($ctx))->toBe(0)
        ->and(b14bpErario($ctx))->toBe(0)
        ->and($fattura->fresh()->stato_pagamento)->toBe(StatoPagamentoFattura::PAGATA);
});

// ── (4) La ritenuta senza bonifico parlante resta com'è (R1) ─────────────────────────────────────────────────────────────────────

it('R1: senza bonifico parlante escono € 1.180,00, la ritenuta del pagamento è € 40,00 e resta da versare con l\'F24', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);

    b14bpPaga($this, $ctx, [[$fattura, 118000]], false)->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::firstOrFail();
    expect((int) $pagamento->importo_ritenuta)->toBe(4000)
        ->and((int) $pagamento->importo_netto)->toBe(118000)
        ->and(b14bpErario($ctx))->toBe(4000)
        ->and(app(GeneraDelegheF24Action::class)->ritenuteDaVersare($ctx[0])->sum('importo'))->toBe(4000)
        ->and($fattura->fresh()->stato_pagamento)->toBe(StatoPagamentoFattura::PAGATA);
});

// ── La frase per una pregressa, e per più fatture ────────────────────────────────────────────────────────────────────────────────

it('per una fattura pregressa la frase dice di stornarla e registrarla di nuovo con il motivo', function () {
    $pregressa = new FatturaPassiva(['numero_documento' => 'P-7', 'importo_ritenuta' => 4000, 'netto_a_pagare' => 118000, 'is_pregresso' => true]);

    expect(BonificoParlanteConRitenutaException::perLeFatture(collect([$pregressa]), 'Edilnord')->getMessage())->toBe(
        'La fattura n. P-7 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e il '
        .'condominio non la trattiene: la fattura è pregressa e non si modifica, quindi stornala e registrala di nuovo senza la ritenuta, '
        .'con il motivo «Bonifico parlante»; poi registra il pagamento di € 1.220,00.'
    );
});

it('con due fatture con la ritenuta la frase le nomina tutte e due', function () {
    $a = new FatturaPassiva(['numero_documento' => '12', 'importo_ritenuta' => 4000, 'netto_a_pagare' => 118000, 'is_pregresso' => false]);
    $b = new FatturaPassiva(['numero_documento' => '14', 'importo_ritenuta' => 2000, 'netto_a_pagare' => 59000, 'is_pregresso' => false]);

    expect(BonificoParlanteConRitenutaException::perLeFatture(collect([$a, $b]), 'Edilnord')->getMessage())->toBe(
        'Le fatture n. 12 (€ 40,00 di ritenuta) e n. 14 (€ 20,00 di ritenuta) di Edilnord sono registrate con la ritenuta. Con il bonifico '
        .'parlante la ritenuta la opera la banca e il condominio non la trattiene: togli prima la ritenuta dalle fatture (Modifica, motivo '
        .'«Bonifico parlante»), poi registra il pagamento dell\'importo pieno.'
    );
});

// ── La pagina lo dice prima dell'invio: la stessa frase, dal server ──────────────────────────────────────────────────────────────

it('le fatture da pagare portano la ritenuta, se sono pregresse e la frase del rifiuto, uguale a quella dell\'invio', function () {
    $ctx = b14bpCtx();
    $conRitenuta = b14bpFattura($this, $ctx);
    $senza = b14bpFattura($this, $ctx, '13', 'bonifico_parlante', 500);

    $righe = collect($this->actingAs($this->admin)
        ->getJson(route('admin.gestionale.pagamenti-fornitori.pendenze', [$ctx[0]]).'?fornitore_id='.$ctx[3]->id)
        ->assertOk()->json('pendenze'))->keyBy('id');

    expect($righe[$conRitenuta->id])->toMatchArray(['importo_ritenuta' => 4000, 'is_pregresso' => false, 'bonifico_parlante_frase' => B14BP_FRASE])
        ->and($righe[$senza->id])->toMatchArray(['importo_ritenuta' => 0, 'is_pregresso' => false, 'bonifico_parlante_frase' => null]);
});

it('la modifica del pagamento riceve le sue fatture con la ritenuta, con la frase del rifiuto', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);
    b14bpPaga($this, $ctx, [[$fattura, 118000]], false)->assertSessionHasNoErrors();
    $pagamento = PagamentoFornitore::firstOrFail();

    $props = $this->actingAs($this->admin)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0], $pagamento]))
        ->assertOk()->viewData('page')['props'];

    expect($props['fatture_con_ritenuta'])->toBe([[
        'id' => $fattura->id,
        'numero_documento' => '12',
        'importo_ritenuta' => 4000,
        'importo_pieno' => 122000,
        'is_pregresso' => false,
        'frase' => B14BP_FRASE_CON_PAGAMENTO,
    ]]);
});

// ── I dati già scritti: il comando in sola lettura ───────────────────────────────────────────────────────────────────────────────

/** Un pagamento come lo scriveva la beta.13: il bonifico parlante sulla fattura con la ritenuta, la ritenuta del pagamento a zero, uscito il netto. */
function b14bpPagamentoDiPrima($test, array $ctx, FatturaPassiva $fattura): PagamentoFornitore
{
    b14bpPaga($test, $ctx, [[$fattura, (int) $fattura->netto_a_pagare]], false)->assertSessionHasNoErrors();
    $pagamento = PagamentoFornitore::latest('id')->firstOrFail();
    DB::table('pagamenti_fornitori')->where('id', $pagamento->id)
        ->update(['bonifico_parlante' => true, 'tipo_detrazione' => 'ristrutturazione', 'importo_ritenuta' => 0, 'importo_lordo' => (int) $fattura->netto_a_pagare]);

    return $pagamento->fresh();
}

it('il comando elenca per condominio i pagamenti con il bonifico parlante su fatture con la ritenuta, con la ritenuta rimasta sull\'Erario, e non scrive niente', function () {
    $ctx = b14bpCtx();
    $ctx[0]->update(['nome' => 'Via Roma 12']);
    $fattura = b14bpFattura($this, $ctx);
    b14bpPagamentoDiPrima($this, $ctx, $fattura);
    // La controprova nello stesso condominio: la fattura con il motivo «Bonifico parlante», pagata con la spunta, non compare.
    $giusta = b14bpFattura($this, $ctx, '13', 'bonifico_parlante', 500);
    b14bpPaga($this, $ctx, [[$giusta, 61000]], true)->assertSessionHasNoErrors();

    $prima = [DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('pagamenti_fornitori')->count(), DB::table('fatture_passive')->sum('importo_ritenuta')];

    expect(Artisan::call('kondomanager:verifica-bonifici-parlanti'))->toBe(0);
    $uscita = preg_replace('/\s+/u', ' ', Artisan::output());

    expect($uscita)->toContain('Via Roma 12 (#'.$ctx[0]->id.')')
        ->toContain('Edilnord')
        ->toContain('n. 12')
        ->toContain('1.180,00')
        ->toContain('40,00')
        ->toContain('Via Roma 12 (#'.$ctx[0]->id.'): 1 pagamento, € 40,00 di ritenuta rimasta sull\'Erario, che nessun F24 versa.')
        ->toContain('Niente è stato corretto.')
        ->not->toContain('n. 13');

    expect([DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('pagamenti_fornitori')->count(), DB::table('fatture_passive')->sum('importo_ritenuta')])->toBe($prima);
});

it('il comando senza pagamenti così lo dice, e con --condominio guarda solo quel condominio', function () {
    $ctx = b14bpCtx();
    $fattura = b14bpFattura($this, $ctx);
    b14bpPagamentoDiPrima($this, $ctx, $fattura);

    $this->artisan('kondomanager:verifica-bonifici-parlanti', ['--condominio' => $ctx[0]->id + 999])
        ->expectsOutputToContain('Nessun pagamento con il bonifico parlante su una fattura con la ritenuta.')
        ->assertExitCode(0);
});
