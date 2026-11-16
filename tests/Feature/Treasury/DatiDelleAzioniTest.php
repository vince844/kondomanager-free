<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I fatti delle altre azioni consigliate della tesoreria, nella sua risposta (`datiDelleAzioni`); le regole che ne fanno le azioni sono provate in
 * `azioniConsigliate.test.ts`.
 *
 * - Le rate non ancora emesse dei piani approvati, entro i 30 giorni o già scadute, per piano: la cifra di ogni rata è, per persona, la sua parte da pagare,
 *   mai sotto zero; il saldo «Con le rate in scadenza» non le conta (conta solo le rate emesse). Un piano in bozza no; una rata oltre i 30 giorni no.
 * - I bonifici segnalati da verificare e i deficit ereditati: i compiti aperti del condominio, quanti e la somma; un compito chiuso o di un altro condominio no.
 * - Le ritenute da versare: le ritenute non ancora in una delega alla scadenza della legge, poi la stessa cifra dalla delega (mai due volte); versata, più niente.
 * - Le banche e i contanti con il saldo di oggi (per la banca quello che dice la banca, con i fondi dentro, e la parte libera a parte); i fondi che non si
 *   possono usare con il tipo e il vincolo (il TFR e il fondo morosità ci sono, con il loro tipo: la regola li toglie); l'esercizio aperto di oggi.
 */

use App\Enums\EventoTipo;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Treasury\DatiDelleAzioni;
use App\Services\Treasury\TreasuryGuardianService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/TesoreriaScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function ddaTesoreria(int $condominioId): array
{
    return app(TreasuryGuardianService::class)->perCondominio($condominioId)->toArray();
}

/** Quattro persone (rossi € 400,00 a rata, bianchi € 300,00, verdi € 200,00, neri € 100,00), 12 rate mensili dal 05/01/2026; emesse quelle date; oggi il 20/05/2026. */
function ddaCondominio($test, array $emesse): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    lcdEmetti($test, $s, $emesse, '2026-01-02');
    $test->travelTo('2026-05-20 10:00:00');

    return $s;
}

function ddaCompito($test, int $condominioId, EventoTipo $tipo, array $meta, bool $chiuso = false): void
{
    $e = InboxService::createTask(tipo: $tipo, title: 'Compito '.$tipo->value, description: 'Prova', scadenza: Carbon::parse('2026-05-19 09:00'), createdByUserId: $test->user->id,
        condominioId: $condominioId, extraMeta: $meta);
    if ($chiuso) {
        $e->update(['is_completed' => true]);
    }
}

it('le rate non ancora emesse entro i 30 giorni o già scadute, per piano, con la cifra di ogni rata; il saldo con le rate in scadenza non le conta', function () {
    $s = ddaCondominio($this, [1, 2, 3]);
    $t = ddaTesoreria((int) $s['c']->id);
    $piani = $t['datiDelleAzioni']['rateDaEmettere'];

    // Le rate 4 (05/04) e 5 (05/05) sono già scadute, la 6 scade il 05/06; la 7 (05/07) è oltre il 19/06: € 1.000,00 ognuna.
    expect($piani)->toHaveCount(1)
        ->and($piani[0])->toMatchArray(['pianoId' => $s['piano']->id, 'piano' => 'Preventivo 2026', 'esercizioId' => $s['e']->id, 'quante' => 3, 'cifra' => 300000])
        ->and(array_column($piani[0]['rate'], 'numero'))->toBe([4, 5, 6])
        ->and(array_column($piani[0]['rate'], 'scadenza'))->toBe(['2026-04-05', '2026-05-05', '2026-06-05'])
        ->and(array_column($piani[0]['rate'], 'cifra'))->toBe([100000, 100000, 100000])
        ->and(array_column($piani[0]['rate'], 'rataId'))->toBe([lcdRata($s, 4), lcdRata($s, 5), lcdRata($s, 6)]);
    // Il saldo previsto conta solo le rate emesse: il 05/06 nessuna rata in scadenza; le rate scadute sono le tre emesse, non la 4 e la 5.
    expect(collect($t['saldoPrevisto']['giorni'])->firstWhere('giorno', '2026-06-05')['rate_in_scadenza'])->toBe(0)
        ->and($t['saldoPrevisto']['rateScaduteCents'])->toBe(300000);
});

it('la cifra di una rata è, per persona, la sua parte da pagare, mai sotto zero: il già pagato si toglie, una quota a credito non toglie denaro agli altri', function () {
    $s = ddaCondominio($this, [1, 2, 3, 4, 5]);
    // Rossi ha già pagato € 100,00 della rata 6; neri è a credito di € 50,00.
    DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 6))->update(['importo_pagato' => 10000]);
    DB::table('rate_quote')->where('id', lcdQuota($s, 'neri', 6))->update(['importo' => -5000]);
    $piani = ddaTesoreria((int) $s['c']->id)['datiDelleAzioni']['rateDaEmettere'];

    expect($piani[0]['rate'])->toBe([['rataId' => lcdRata($s, 6), 'numero' => 6, 'scadenza' => '2026-06-05', 'cifra' => 30000 + 30000 + 20000]])
        ->and($piani[0]['cifra'])->toBe(80000);
});

it('nessuna rata da emettere: tutte emesse, o il piano in bozza', function () {
    $s = ddaCondominio($this, range(1, 12));
    expect(ddaTesoreria((int) $s['c']->id)['datiDelleAzioni']['rateDaEmettere'])->toBe([]);

    $b = ddaCondominio($this, [1, 2, 3]);
    DB::table('piani_rate')->where('id', $b['piano']->id)->update(['stato' => 'bozza']);
    expect(ddaTesoreria((int) $b['c']->id)['datiDelleAzioni']['rateDaEmettere'])->toBe([]);
});

it('i bonifici segnalati e i deficit ereditati: i compiti aperti del condominio, quanti e la somma; un compito chiuso o di un altro condominio no', function () {
    $s = ddaCondominio($this, range(1, 12));
    $altro = ddaCondominio($this, range(1, 12));
    $cid = (int) $s['c']->id;
    ddaCompito($this, $cid, EventoTipo::VERIFICA_PAGAMENTO, ['importo_dichiarato' => 12000]);
    ddaCompito($this, $cid, EventoTipo::VERIFICA_PAGAMENTO, ['importo_dichiarato' => 23000]);
    ddaCompito($this, $cid, EventoTipo::VERIFICA_PAGAMENTO, ['importo_dichiarato' => 99900], chiuso: true);
    ddaCompito($this, (int) $altro['c']->id, EventoTipo::VERIFICA_PAGAMENTO, ['importo_dichiarato' => 50000]);
    ddaCompito($this, $cid, EventoTipo::PIANIFICA_RIPIANAMENTO_DEFICIT, ['importo_buco' => 50000]);
    $d = ddaTesoreria($cid)['datiDelleAzioni'];

    expect($d['bonifici'])->toBe(['quanti' => 2, 'cifra' => 35000, 'rimandati' => 0, 'rimandatiAl' => null, 'rimandatiGiorni' => 0])
        ->and($d['deficit'])->toBe(['quanti' => 1, 'cifra' => 50000])
        ->and(ddaTesoreria((int) $altro['c']->id)['datiDelleAzioni']['bonifici'])->toBe(['quanti' => 1, 'cifra' => 50000, 'rimandati' => 0, 'rimandatiAl' => null, 'rimandatiGiorni' => 0]);
});

it('le ritenute da versare: alla scadenza della legge prima della delega, poi dalla delega, mai due volte; versata, più niente; oltre i 30 giorni no', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100, 'natura_percipiente' => 'persona_fisica_irpef']);
    $ctx[3]->refresh();
    $this->travelTo('2026-03-10 10:00:00');
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true,
        'righe' => [['descrizione' => 'Servizio', 'importo_imponibile' => 20000, 'aliquota_iva' => 22, 'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false]]]);
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10']));
    $leggi = function (string $oggi) use ($condominio) {
        $o = CarbonImmutable::parse($oggi);

        return app(DatiDelleAzioni::class)->perCondominio($condominio, null, $o, $o->addDays(30), ['righe' => [], 'esercizio_id' => null])['ritenute']['scadenze'];
    };

    // € 20.000,00 al 4 %: € 800,00, sopra la soglia di € 500,00 a marzo: si versano entro il 16/04.
    expect($leggi('2026-03-20'))->toBe([['giorno' => '2026-04-16', 'cifra' => 80000]])
        ->and($leggi('2026-03-01'))->toBe([]);
    app(GeneraDelegheF24Action::class)->esegui($condominio, $esercizio->id);
    expect(DelegaF24::where('condominio_id', $condominio->id)->count())->toBe(1)
        ->and($leggi('2026-03-20'))->toBe([['giorno' => '2026-04-16', 'cifra' => 80000]])
        // Già scaduta: c'è ancora, al suo giorno.
        ->and($leggi('2026-04-20'))->toBe([['giorno' => '2026-04-16', 'cifra' => 80000]]);
    DelegaF24::where('condominio_id', $condominio->id)->update(['stato' => StatoDelegaF24::VERSATA->value]);
    expect($leggi('2026-04-20'))->toBe([]);
});

it('le banche e i contanti con il saldo di oggi; i fondi che non si possono usare con il tipo e il vincolo; l\'esercizio aperto di oggi', function () {
    $s = tsrTuttiITipi($this);
    // Un pagamento di € 8.000,00 dalla banca: la parte libera della banca va sotto zero di € 480,00. La banca è una sola, e i fondi (€ 2.350,00) e la cassa
    // virtuale (€ 80,00) sono nel suo conto corrente: la banca dice −€ 480,00 + € 2.430,00 = € 1.950,00.
    tsrScrittura($s, '2026-11-10', 'pagamento_fornitore', $s['debiti'], $s['banca']->conto_contabile_id, 800000);
    $d = ddaTesoreria((int) $s['c']->id)['datiDelleAzioni'];

    expect($d['conti'])->toBe([
        ['cassaId' => $s['banca']->id, 'nome' => 'Banca Intesa', 'tipo' => 'banca', 'saldo' => 195000, 'libero' => -48000, 'attiva' => true],
        ['cassaId' => $s['contanti']->id, 'nome' => 'Cassa contanti', 'tipo' => 'contanti', 'saldo' => 5000, 'libero' => 5000, 'attiva' => true],
    ]);
    expect(collect($d['fondi'])->keyBy('nome')->map(fn ($f) => [$f['saldo'], $f['sottotipo'], $f['vincolo']])->all())->toBe([
        'Fondo facciata' => [20000, 'vincolato_lavori', 'rifacimento della facciata'],
        'Fondo lavori' => [25000, 'vincolato_lavori', null],
        'TFR portiere' => [30000, 'tfr', null],
        'Fondo morosità' => [40000, 'morosita', null],
        'Fondo di riserva' => [60000, null, null],
    ])->and($d['esercizioId'])->toBe($s['e']->id);
});
