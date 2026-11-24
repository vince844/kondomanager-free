<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (10) — la delega F24 confermata e il pagamento che la riempie.
 *
 * Con «Conferma la delega» (decisione 696, D6) la delega confermata resta com'è anche dopo «Aggiorna scadenze». Ma il pagamento che
 * porta la sua ritenuta si stornava lo stesso: la confermata teneva la ritenuta del pagamento stornato, il pagamento registrato di
 * nuovo nasceva in una bozza nuova, e lo scadenzario chiedeva € 1.600,00 per una ritenuta di € 800,00; la confermata si versava senza
 * obiezioni. Ora:
 *
 * - lo storno di un pagamento la cui ritenuta è in una delega **confermata** si ferma e lo dice («riportala in bozza, poi storna il
 *   pagamento»): decide l'amministratore, il programma non la riporta in bozza da sé. Lo stesso per la modifica che ne cambia la data
 *   o la ritenuta;
 * - con una delega **già versata** lo storno e la modifica passano come prima, e il programma avvisa (decisione 704): lo storno dice che la
 *   ritenuta è già stata versata e di non versarla di nuovo, e la delega nuova del pagamento rifatto lo dice nella scheda e nello
 *   scadenzario. Prima non lo diceva nessuno: il pagamento rifatto faceva nascere una bozza di € 800,00 con l'Erario già a € 0,00;
 * - il versamento rifiuta una delega che porta la ritenuta di un pagamento stornato, e «Conferma la delega» pure;
 * - «Aggiorna scadenze» senza niente di nuovo distingue le ritenute «già nella delega confermata» da quelle «già versate».
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\StatoPagamentoFornitore;
use App\Models\CategoriaEvento;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('amministratore');
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Promemoria tecnici per l\'amministratore']);
});

/** Un fornitore in appalto al 4 %, un pagamento con € 800,00 di ritenuta (€ 20.000,00 × 4 %) e la sua delega in bozza di € 800,00. */
function b14csDelega($test): array
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
    $ctx[0]->update(['codice_fiscale' => '80012345678']);

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
    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => now()->toDateString(),
        'esercizio_id' => $ctx[1]->id,
    ]));
    $test->actingAs($test->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();

    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    expect((int) $delega->totale_debito)->toBe(80000);

    return [$ctx, $delega, $pagamento];
}

function b14csConferma($test, array $ctx, DelegaF24 $delega): void
{
    $test->actingAs($test->admin)->post(route('admin.gestionale.f24.conferma-delega', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA);
}

function b14csStorno($test, array $ctx, PagamentoFornitore $pagamento, string $motivo = 'Data del pagamento sbagliata')
{
    return $test->actingAs($test->admin)
        ->from(route('admin.gestionale.pagamenti-fornitori.index', $ctx[0]->id))
        ->post(route('admin.gestionale.pagamenti-fornitori.storno', [$ctx[0]->id, $pagamento->id]), ['motivo' => $motivo]);
}

function b14csVersa($test, array $ctx, DelegaF24 $delega)
{
    return $test->actingAs($test->admin)
        ->from(route('admin.gestionale.f24.show', [$ctx[0]->id, $delega->id]))
        ->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
            'data_versamento' => now()->toDateString(),
            'conto_corrente_id' => $ctx[4],
        ]);
}

/** Il debito verso l'Erario per le ritenute (avere − dare del conto 2202), in centesimi. */
function b14csErario(int $condominioId): int
{
    $conto = DB::table('conti_contabili')->where('condominio_id', $condominioId)->where('ruolo', 'debiti_erario_ritenute')->value('id');

    return (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto)
        ->selectRaw("SUM(CASE WHEN tipo_riga='avere' THEN importo ELSE -importo END) as s")->value('s');
}

/** Le deleghe ancora da versare del condominio: [stato, totale]. */
function b14csAperte(int $condominioId): array
{
    return DelegaF24::where('condominio_id', $condominioId)->aperte()->orderBy('id')->get()
        ->map(fn (DelegaF24 $d) => [$d->stato->value, (int) $d->totale_debito])->all();
}

/** Il corpo della modifica come lo manda la pagina «Modifica pagamento», con i cambi dell'amministratore. */
function b14csCorpoModifica($test, array $ctx, PagamentoFornitore $pagamento, array $cambi = []): array
{
    $props = $test->actingAs($test->admin)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0]->id, $pagamento->id]))
        ->assertOk()
        ->viewData('page')['props'];
    $p = $props['pagamento'];

    return array_merge([
        'esercizio_id' => $props['esercizio']['id'],
        'conto_corrente_id' => $p['conto_corrente']['id'],
        'data_pagamento' => $p['data_pagamento'],
        'metodo_pagamento' => $p['metodo_pagamento'],
        'iban_beneficiario' => '',
        'importo_commissioni_cents' => $p['importo_commissione'],
        'importo_lordo_cents' => $p['importo_lordo'],
        'importo_ritenuta_cents' => $p['importo_ritenuta'],
        'importo_netto_cents' => $p['importo_netto'],
        'bonifico_parlante' => (bool) $p['bonifico_parlante'],
        'tipo_detrazione' => null,
        'beneficiari_detrazione' => [],
        'allow_overdraft' => true,
        'allow_overpayment' => false,
        'iban_confermato_manualmente' => false,
        'conferma_duplicato_verificato' => false,
        'note_override' => null,
        'causale_bonifico' => '',
        'riferimento_bancario' => '',
    ], $cambi);
}

test('T6_754_10 — il pagamento con la ritenuta in una delega confermata non si storna: si ferma e dice di riportarla in bozza; riportata in bozza, lo scadenzario chiede € 800,00 e non € 1.600,00', function () {
    [$ctx, $delega, $pagamento] = b14csDelega($this);
    b14csConferma($this, $ctx, $delega);
    $scadenza = $delega->fresh()->data_scadenza->format('d/m/Y');
    $scritture = DB::table('scritture_contabili')->count();

    // Lo storno si ferma e dice che cosa fare: decide l'amministratore.
    b14csStorno($this, $ctx, $pagamento)
        ->assertRedirect(route('admin.gestionale.pagamenti-fornitori.index', $ctx[0]->id))
        ->assertSessionHas('message', [
            'type' => 'error',
            'message' => "La ritenuta di questo pagamento è nella delega F24 confermata in scadenza il {$scadenza}: riportala in bozza, poi storna il pagamento.",
        ]);
    expect($pagamento->fresh()->stato)->toBe(StatoPagamentoFornitore::CONFERMATO)
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA)
        ->and((int) $delega->fresh()->totale_debito)->toBe(80000);

    // L'amministratore la riporta in bozza: lo storno passa.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();
    b14csStorno($this, $ctx, $pagamento)->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    expect($pagamento->fresh()->stato)->toBe(StatoPagamentoFornitore::STORNATO);

    // Il pagamento giusto, registrato di nuovo; «Aggiorna scadenze».
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, FatturaPassiva::firstOrFail()->fresh(), [
        'data_pagamento' => now()->toDateString(),
        'esercizio_id' => $ctx[1]->id,
    ]));
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();

    // Una delega sola da versare, di € 800,00: quella del pagamento nuovo.
    expect(b14csAperte($ctx[0]->id))->toBe([['bozza', 80000]]);
    $indice = $this->actingAs($this->admin)->get(route('admin.gestionale.f24.index', $ctx[0]->id))->assertOk()->viewData('page')['props'];
    expect($indice['riepilogo']['da_versare_cents'])->toBe(80000);

    // Versata, il debito verso l'Erario torna a zero: € 800,00 dati una volta sola.
    $nuova = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    b14csVersa($this, $ctx, $nuova)->assertSessionHasNoErrors();
    expect($nuova->fresh()->stato)->toBe(StatoDelegaF24::VERSATA)
        ->and(b14csErario($ctx[0]->id))->toBe(0)
        ->and(b14csAperte($ctx[0]->id))->toBe([]);
});

test('T6_754_10 — la modifica del pagamento nella delega confermata: la data nuova si ferma con la stessa frase; la nota passa e la delega resta com\'è', function () {
    [$ctx, $delega, $pagamento] = b14csDelega($this);
    b14csConferma($this, $ctx, $delega);
    $scadenza = $delega->fresh()->data_scadenza->format('d/m/Y');
    $dataDiPrima = $pagamento->fresh()->data_pagamento->toDateString();

    // La data cambia il mese di riferimento della riga e la scadenza: si ferma.
    $this->actingAs($this->admin)
        ->from(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0]->id, $pagamento->id]))
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0]->id, $pagamento->id]),
            b14csCorpoModifica($this, $ctx, $pagamento, ['data_pagamento' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors([
            'modifica_vietata' => "La ritenuta di questo pagamento è nella delega F24 confermata in scadenza il {$scadenza}: riportala in bozza, poi modifica il pagamento.",
        ]);
    expect($pagamento->fresh()->data_pagamento->toDateString())->toBe($dataDiPrima);

    // Una nota non tocca la delega: passa.
    $this->actingAs($this->admin)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0]->id, $pagamento->id]),
            b14csCorpoModifica($this, $ctx, $pagamento, ['note_override' => 'Bonifico del SAL n. 2']))
        ->assertSessionHasNoErrors();
    expect($pagamento->fresh()->note_override)->toBe('Bonifico del SAL n. 2')
        ->and($pagamento->fresh()->data_pagamento->toDateString())->toBe($dataDiPrima)
        ->and($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA)
        ->and((int) $delega->fresh()->totale_debito)->toBe(80000);

    // Riportata in bozza, la data si cambia.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0]->id, $pagamento->id]),
            b14csCorpoModifica($this, $ctx, $pagamento, ['data_pagamento' => now()->subDay()->toDateString()]))
        ->assertSessionHasNoErrors();
    expect($pagamento->fresh()->data_pagamento->toDateString())->toBe(now()->subDay()->toDateString());
});

test('T6_754_10 — il versamento rifiuta una delega con la ritenuta di un pagamento stornato, in bozza e confermata; «Conferma la delega» pure', function () {
    [$ctx, $delega, $pagamento] = b14csDelega($this);
    $giorno = $pagamento->fresh()->data_pagamento->format('d/m/Y');
    $fornitore = $ctx[3]->ragione_sociale;

    // In bozza lo storno passa: la bozza è una proposta, «Aggiorna scadenze» la rifà.
    b14csStorno($this, $ctx, $pagamento)->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    $erario = b14csErario($ctx[0]->id);
    $scritture = DB::table('scritture_contabili')->count();

    // Ma la bozza vecchia, versata senza «Aggiorna scadenze», pagherebbe all'Erario la ritenuta di un pagamento stornato.
    $fatto = "Nella delega c'è la ritenuta di € 800,00 del pagamento a {$fornitore} del {$giorno}, che è stato stornato.";
    $rimedio = 'Premi «Aggiorna scadenze»: rifà le deleghe con le sole ritenute da versare.';
    b14csVersa($this, $ctx, $delega)->assertSessionHasErrors(['versamento' => "{$fatto} {$rimedio}"]);
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::BOZZA)
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and(b14csErario($ctx[0]->id))->toBe($erario);

    // «Conferma la delega» non la tiene ferma così.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.conferma-delega', [$ctx[0]->id, $delega->id]))
        ->assertSessionHasErrors(['stato_delega' => "{$fatto} {$rimedio}"]);
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);

    // Una confermata con dentro il pagamento stornato (scritta prima di questa regola): il versamento si ferma lo stesso.
    $delega->forceFill(['stato' => StatoDelegaF24::CONFERMATA])->save();
    b14csVersa($this, $ctx, $delega)->assertSessionHasErrors([
        'versamento' => "{$fatto} Riportala in bozza e premi «Aggiorna scadenze»: rifà le deleghe con le sole ritenute da versare.",
    ]);
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA)
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and(b14csErario($ctx[0]->id))->toBe($erario);

    // Come dice la frase: in bozza, «Aggiorna scadenze» la rifà senza la ritenuta stornata, e non resta niente da versare.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();
    expect(b14csAperte($ctx[0]->id))->toBe([]);
});

test('T6_754_10 — «Aggiorna scadenze» senza niente di nuovo: con la delega confermata dice la confermata e la sua scadenza, non «già versate»', function () {
    [$ctx, $delega] = b14csDelega($this);
    b14csConferma($this, $ctx, $delega);
    $scadenza = $delega->fresh()->data_scadenza->format('d/m/Y');

    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', [
            'type' => 'success',
            'message' => "Nessuna ritenuta nuova: quelle non ancora versate sono già nella delega confermata in scadenza il {$scadenza} (€ 800,00).",
        ]);

    // Versata, il messaggio di prima torna vero.
    b14csVersa($this, $ctx, $delega)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))
        ->assertSessionHas('message', [
            'type' => 'success',
            'message' => 'Nessuna ritenuta da versare: tutte quelle operate risultano già versate.',
        ]);
});

// ── La delega già versata: si storna come prima, e il programma avvisa (decisione 704) ───────────────────────────────

test('T6_754_10 — il pagamento con la ritenuta già versata si storna e si modifica come prima, e lo storno avvisa di non versarla di nuovo', function () {
    [$ctx, $delega, $pagamento] = b14csDelega($this);
    b14csVersa($this, $ctx, $delega)->assertSessionHasNoErrors();
    $versata = $delega->fresh()->data_versamento->format('d/m/Y');

    // La modifica della data passa come prima: la delega versata non la ferma.
    $this->actingAs($this->admin)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0]->id, $pagamento->id]),
            b14csCorpoModifica($this, $ctx, $pagamento, ['data_pagamento' => now()->subDay()->toDateString()]))
        ->assertSessionHasNoErrors();
    expect($pagamento->fresh()->data_pagamento->toDateString())->toBe(now()->subDay()->toDateString());

    // Lo storno passa, e il messaggio dice la ritenuta già versata e che cosa non fare.
    b14csStorno($this, $ctx, $pagamento)->assertSessionHas('message', [
        'type' => 'warning',
        'message' => 'Storno eseguito con successo. Le fatture collegate sono state riaperte e il saldo del conto è stato ripristinato. '
            ."La ritenuta di questo pagamento (€ 800,00) è già stata versata con la delega F24 del {$versata}: quando rifai il pagamento, non versarla di nuovo.",
    ]);
    expect($pagamento->fresh()->stato)->toBe(StatoPagamentoFornitore::STORNATO)
        ->and($delega->fresh()->stato)->toBe(StatoDelegaF24::VERSATA)
        ->and(b14csErario($ctx[0]->id))->toBe(0);
});

test('T6_754_10 — la delega nuova del pagamento rifatto dice, nella scheda e nello scadenzario, che quella ritenuta è già stata versata', function () {
    [$ctx, $delega, $pagamento] = b14csDelega($this);
    $scheda = fn (DelegaF24 $d) => $this->actingAs($this->admin)->get(route('admin.gestionale.f24.show', [$ctx[0]->id, $d->id]))
        ->assertOk()->viewData('page')['props'];
    $scadenzario = fn () => $this->actingAs($this->admin)->get(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->assertOk()->viewData('page')['props'];

    // Una delega senza quella storia non ha avvisi.
    expect($scheda($delega)['avvisi_ritenute_gia_versate'])->toBe([])
        ->and($scadenzario()['avvisi_ritenute_gia_versate'])->toBe([]);

    b14csVersa($this, $ctx, $delega)->assertSessionHasNoErrors();
    $versata = $delega->fresh()->data_versamento->format('d/m/Y');
    b14csStorno($this, $ctx, $pagamento)->assertSessionHasNoErrors();
    $fattura = FatturaPassiva::firstOrFail();
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura->fresh(), [
        'data_pagamento' => now()->toDateString(),
        'esercizio_id' => $ctx[1]->id,
    ]));
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();
    $nuova = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();

    $avviso = "La ritenuta della fattura n. {$fattura->numero_documento} di {$ctx[3]->ragione_sociale} è già stata versata con la delega del {$versata} "
        .'(il pagamento è stato poi stornato): non versarla di nuovo.';
    expect($scheda($nuova)['avvisi_ritenute_gia_versate'])->toBe([$avviso])
        ->and($scadenzario()['avvisi_ritenute_gia_versate'])->toBe([['delega_id' => $nuova->id, 'messaggio' => $avviso]])
        // La versata stessa non avvisa di sé.
        ->and($scheda($delega)['avvisi_ritenute_gia_versate'])->toBe([]);

    // Confermata, lo dice ancora.
    b14csConferma($this, $ctx, $nuova);
    expect($scheda($nuova)['avvisi_ritenute_gia_versate'])->toBe([$avviso])
        ->and($scadenzario()['avvisi_ritenute_gia_versate'])->toBe([['delega_id' => $nuova->id, 'messaggio' => $avviso]]);
});
