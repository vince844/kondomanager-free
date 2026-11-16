<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le azioni consigliate caso per caso, la parte del server (`DatiDelleAzioni`) e i pulsanti verso le pagine. Le cifre rifatte a mano.
 */

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Treasury\DatiDelleAzioni;
use App\Services\Treasury\TreasuryGuardianService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/TesoreriaScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function ldmTesoreria(array $s): array
{
    return app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
}

/** Rossi 500, Bianchi 300, Verdi 200; 12 rate il 16 di ogni mese dal 16/01/2026, € 1.000,00 a rata; emesse 1–9 il 02/01/2026; nessun incasso. */
function ldmCondominio($test, string $oggi): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'B' => ['bianchi', 300], 'C' => ['verdi', 200]], 12, 'prima_rata', [], 1200000, '2026-01-16', 16);
    lcdEmetti($test, $s, range(1, 9), '2026-01-02');
    $test->travelTo($oggi);

    return $s;
}

it('C: le rate 10 (16/10) e 11 (16/11, oggi) non emesse, e la 12 del 16/12 = oggi + 30, al bordo della finestra: € 1.000,00 ognuna; il saldo previsto non le conta', function () {
    $s = ldmCondominio($this, '2026-11-16 10:00:00');
    $t = ldmTesoreria($s);
    $p = $t['datiDelleAzioni']['rateDaEmettere'];

    expect($p)->toHaveCount(1)
        ->and(array_column($p[0]['rate'], 'numero'))->toBe([10, 11, 12])
        ->and(array_column($p[0]['rate'], 'scadenza'))->toBe(['2026-10-16', '2026-11-16', '2026-12-16'])
        ->and(array_column($p[0]['rate'], 'cifra'))->toBe([100000, 100000, 100000])
        ->and($p[0]['cifra'])->toBe(300000)
        ->and($p[0]['esercizioId'])->toBe((int) $s['e']->id);
    // Le rate emesse 1–9, tutte scadute e non pagate: € 9.000,00; nessuna rata emessa in scadenza.
    expect($t['saldoPrevisto']['rateScaduteCents'])->toBe(900000)
        ->and(collect($t['saldoPrevisto']['giorni'])->sum('rate_in_scadenza'))->toBe(0);
});

it('C: un giorno prima (oggi il 15/11) la rata del 16/12 è oltre la finestra (15/12); la rata di domani sì', function () {
    $s = ldmCondominio($this, '2026-11-15 10:00:00');
    $p = ldmTesoreria($s)['datiDelleAzioni']['rateDaEmettere'];

    expect(array_column($p[0]['rate'], 'numero'))->toBe([10, 11]);
});

it('C: il centesimo e la quota a credito: Verdi ha già pagato € 0,01 della 12, Bianchi è a credito di € 50,00 → € 500,00 + € 0,00 + € 199,99', function () {
    $s = ldmCondominio($this, '2026-11-16 10:00:00');
    DB::table('rate_quote')->where('id', lcdQuota($s, 'verdi', 12))->update(['importo_pagato' => 1]);
    DB::table('rate_quote')->where('id', lcdQuota($s, 'bianchi', 12))->update(['importo' => -5000]);
    $p = ldmTesoreria($s)['datiDelleAzioni']['rateDaEmettere'];

    expect(collect($p[0]['rate'])->firstWhere('numero', 12)['cifra'])->toBe(69999)
        ->and($p[0]['cifra'])->toBe(269999);
});

it('(f) i pulsanti portano a pagine che si aprono: il piano delle rate da emettere e la creazione del piano straordinario nell\'esercizio aperto', function () {
    $s = ldmCondominio($this, '2026-11-16 10:00:00');
    $d = ldmTesoreria($s)['datiDelleAzioni'];

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $s['c']->id, 'esercizio' => $d['rateDaEmettere'][0]['esercizioId'],
        'pianoRate' => $d['rateDaEmettere'][0]['pianoId']]))->assertOk();
    expect($d['esercizioId'])->toBe((int) $s['e']->id);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $s['c']->id, 'esercizio' => $d['esercizioId'], 'tipo' => 'straordinario']))
        ->assertOk();
});

it('(f) G: la banca a −€ 1.500,00 e i contanti a € 500,00; il giroconto si apre con i due conti e € 500,00 già scelti', function () {
    $s = ldmCondominio($this, '2026-11-16 10:00:00');
    $passate = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->value('id');
    $debiti = tsrConto($s['c']->id, 'debiti_fornitori', '2201', 'passivo', 'debiti');
    $contanti = tsrCassa($s['c']->id, 'contanti', 'Cassa contanti', '1011');
    tsrScrittura($s, '2026-01-01', 'apertura', $contanti->conto_contabile_id, $passate, 50000);
    tsrScrittura($s, '2026-11-10', 'pagamento_fornitore', $debiti, $s['cassa']->conto_contabile_id, 150000);
    $d = ldmTesoreria($s)['datiDelleAzioni'];

    expect(collect($d['conti'])->keyBy('nome')->map(fn ($c) => $c['saldo'])->all())->toBe(['Banca principale' => -150000, 'Cassa contanti' => 50000]);
    $this->actingAs($this->user)->get(route('admin.gestionale.giroconti.create', ['condominio' => $s['c']->id, 'origine' => $contanti->id, 'destinazione' => $s['cassa']->id,
        'importo' => 50000]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('preselezione.origine', $contanti->id)->where('preselezione.destinazione', $s['cassa']->id)
            ->where('preselezione.importo_cents', 50000)->etc());
});

it('(f) F: «Registra lo sblocco» apre la pagina del fondo', function () {
    $s = tsrTuttiITipi($this);
    $this->actingAs($this->user)->get(route('admin.gestionale.casse.edit', ['condominio' => $s['c']->id, 'cassa' => $s['fondi']['Fondo facciata']->id]))->assertOk();
});

it('H: il 16 del mese dopo; versata con la conferma del versamento, la ritenuta non c\'è più, anche se un compito restasse aperto', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100, 'natura_percipiente' => 'persona_fisica_irpef']);
    $ctx[3]->refresh();
    $leggi = function (string $oggi) use ($condominio) {
        $o = CarbonImmutable::parse($oggi);

        return app(DatiDelleAzioni::class)->perCondominio($condominio, null, $o, $o->addDays(30), ['righe' => [], 'esercizio_id' => null])['ritenute']['scadenze'];
    };
    // Due pagamenti da € 20.000,00 di imponibile al 4 %: € 800,00 di ritenuta ognuno (sopra la soglia): uno il 31/10, uno il 01/11.
    $this->travelTo('2026-10-31 10:00:00');
    foreach (['2026-10-31', '2026-11-01'] as $giorno) {
        $this->travelTo($giorno.' 10:00:00');
        $f = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true, 'numero_documento' => 'R-'.$giorno,
            'righe' => [['descrizione' => 'Servizio', 'importo_imponibile' => 20000, 'aliquota_iva' => 22, 'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false]]]);
        (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $f, ['data_pagamento' => $giorno]));
    }
    // Oggi il 16/11 (finestra fino al 16/12): € 800,00 entro il 16/11 (oggi) e € 800,00 entro il 16/12 (il bordo).
    expect($leggi('2026-11-16'))->toBe([['giorno' => '2026-11-16', 'cifra' => 80000], ['giorno' => '2026-12-16', 'cifra' => 80000]])
        // Il 15/11 la finestra arriva al 15/12: solo la prima.
        ->and($leggi('2026-11-15'))->toBe([['giorno' => '2026-11-16', 'cifra' => 80000]]);
    $this->travelTo('2026-11-16 10:00:00');
    app(GeneraDelegheF24Action::class)->esegui($condominio, $esercizio->id);
    $prima = DelegaF24::where('condominio_id', $condominio->id)->orderBy('data_scadenza')->first();
    expect($prima->data_scadenza->toDateString())->toBe('2026-11-16');
    $cassa = (int) DB::table('casse')->where('condominio_id', $condominio->id)->where('conto_contabile_id', $ctx[4])->value('id');
    app(ConfermaVersamentoF24Action::class)->esegui($prima, '2026-11-16', (int) $ctx[4], $cassa ?: null);
    expect($leggi('2026-11-16'))->toBe([['giorno' => '2026-12-16', 'cifra' => 80000]]);
});
