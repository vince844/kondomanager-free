<?php

/**
 * «Disponibile oggi» della tesoreria e «Da dove viene»: la cifra viene dalla fotografia dello Stato
 * patrimoniale a oggi, scomposta voce per voce, meno i fondi che non si possono usare con la regola di `TipoDelFondo`; le righe sommano la cifra.
 *
 * Le cifre a mano nello scenario `tsrTuttiITipi()`: tutto il denaro € 10.030,00; i fondi che non si possono usare € 1.750,00; disponibile € 8.280,00.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\NotaSintetica;
use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Gestionale\SaldoCassaService;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\Gestionale\StatoPatrimonialeService;
use App\Services\Treasury\DisponibilitaDiOggi;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TesoreriaScenario.php';

function ddoOggi(array $s): array
{
    return app(DisponibilitaDiOggi::class)->perCondominio($s['c'], \App\Helpers\DateHelper::oggiUtente());
}

it('la cifra di oggi è la liquidità dello Stato patrimoniale meno i fondi vincolati, allo stesso giorno, voce per voce', function () {
    $s = tsrTuttiITipi($this);
    $d = ddoOggi($s);
    $sp = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], '2026-11-15')['liquidita'];

    expect($d['totale'])->toBe(1003000)
        ->and($d['cifra'])->toBe(828000)
        ->and($d['cifra'])->toBe($sp['liquidita_totale'] - $sp['vincolata'])
        ->and(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->liquiditaDisponibileCents)->toBe(828000);
    // Voce per voce: le casse reali, le altre voci, i fondi; e le stesse cifre dello Stato patrimoniale.
    $righe = collect($d['righe'])->keyBy('voce');
    expect($righe->map(fn ($r) => [$r['tipo'], $r['saldo']])->all())->toMatchArray([
        'Banca Intesa' => ['banca', 752000], 'Cassa contanti' => ['contanti', 5000], 'PayPal' => ['virtuale', 8000], '1020 Banca Posta' => ['senza_cassa', 3000],
        'Fondo spese generiche' => ['fondo', 10000], 'Fondo facciata' => ['fondo', 20000], 'Fondo lavori' => ['fondo', 25000], 'TFR portiere' => ['fondo', 30000],
        'Fondo morosità' => ['fondo', 40000], 'Fondo tetto' => ['fondo', 50000], 'Fondo di riserva' => ['fondo', 60000],
    ])->and($righe->count())->toBe(11)
        ->and($sp['libera'])->toBe(757000)
        ->and($sp['vincolata'])->toBe(175000)
        ->and($sp['generica'])->toBe(60000);
});

it('le righe di «Da dove viene» sommano tutto il denaro; tolti i fondi vincolati, ognuno con il suo nome e la sua cifra, danno la cifra della casella', function () {
    $s = tsrTuttiITipi($this);
    $d = ddoOggi($s);

    expect(array_sum(array_column($d['righe'], 'saldo')))->toBe($d['totale'])
        ->and($d['totale'] - array_sum(array_column($d['tolte'], 'saldo')))->toBe($d['cifra'])
        ->and(collect($d['tolte'])->pluck('saldo', 'voce')->all())->toBe([
            'Fondo facciata' => 20000, 'Fondo lavori' => 25000, 'TFR portiere' => 30000, 'Fondo morosità' => 40000, 'Fondo di riserva' => 60000,
        ]);
    // Le etichette sono quelle della scheda «Cassa» (`TipoDelFondo::etichetta`).
    $etichette = collect($d['righe'])->where('tipo', 'fondo')->mapWithKeys(fn ($r) => [$r['voce'] => $r['etichetta']['testo']])->all();
    expect($etichette)->toMatchArray([
        'Fondo spese generiche' => 'generico, si può usare', 'Fondo facciata' => 'vincolato ai lavori: rifacimento della facciata',
        'Fondo tetto' => 'vincolato, sbloccato dall\'assemblea: urgenza approvata il 10/03', 'Fondo di riserva' => 'tipo non indicato: conta come vincolato',
    ]);
});

it('la regola dei fondi è una sola: lo sbloccato e il generico si possono usare anche nello Stato patrimoniale e nella tesoreria', function () {
    $s = tsrTuttiITipi($this);
    $foto = app(StatoPatrimonialeService::class)->calcola($s['c'], allaData: '2026-11-15');
    $l = app(\App\Services\Gestionale\LiquiditaVincolataService::class)->allaData($s['c'], $foto['attivo']['voci'], $foto['liquidita_non_contabilizzata'], 'aperto');
    $fondi = collect($l['fondi'])->keyBy('cassa');

    // `LiquiditaVincolataService` contava lo sbloccato fra i vincolati; la tesoreria contava vincolati tutti i fondi, anche il generico.
    expect($fondi['Fondo tetto']['vincolato'])->toBeFalse()
        ->and($fondi['Fondo tetto']['sbloccato'])->toBeTrue()
        ->and($fondi['Fondo spese generiche']['vincolato'])->toBeFalse()
        ->and($fondi['Fondo di riserva']['vincolato'])->toBeTrue()
        ->and($fondi->map(fn ($f) => $f['vincolato'])->all())->toBe($fondi->map(fn ($f) => ! Cassa::find($f['cassa_id'])->is_utilizzabile_per_imprevisti)->all())
        ->and(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->liquiditaVincolataCents)->toBe(175000);
});

it('«Disponibile oggi» è «Si può usare» della scheda «Cassa» alla stessa data, con un conto in banca e i fondi di ogni tipo', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 1000000);
    tsrScrittura($s, '2026-04-01', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 120000);
    tsrScrittura($s, '2026-05-01', 'pagamento_fornitore', $s['debiti'], $banca->conto_contabile_id, 45000);
    foreach ([['Generico', 10000, 'generico'], ['Lavori', 20000, 'vincolato_lavori', 'facciata'], ['TFR', 30000, 'tfr'], ['Morosi', 40000, 'morosita'],
        ['Sbloccato', 50000, 'vincolato_lavori', 'tetto', true, 'urgenza'], ['Vecchio', 60000, null]] as $i => $f) {
        $fondo = tsrCassa($s['c']->id, 'fondo', $f[0], (string) (1300 + $i));
        tsrScrittura($s, '2026-06-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, $f[1]);
        tsrTipo($fondo, $f[2], $f[3] ?? null, $f[4] ?? false, $f[5] ?? null);
    }
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($s['e'], '2026-11-15');
    $conto = collect($r['conti'])->firstWhere('cassa', 'Conto corrente');

    // 10.000 + 1.200 − 450 = 10.750 nel conto; i fondi che non si possono usare 200 + 300 + 400 + 600 = 1.500; si può usare 9.250.
    expect($conto['si_puo_usare'])->toBe(925000)
        ->and(ddoOggi($s)['cifra'])->toBe($conto['si_puo_usare'])
        ->and(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->liquiditaDisponibileCents)->toBe(925000);
});

it('per ogni cassa attiva, con l\'apertura a giornale e senza movimenti dopo oggi, il saldo è quello di `SaldoCassaService`', function () {
    $s = tsrTuttiITipi($this);
    $righe = collect(ddoOggi($s)['righe'])->filter(fn ($r) => $r['cassa_id'] !== null)->keyBy('cassa_id');
    $casse = Cassa::where('condominio_id', $s['c']->id)->where('attiva', true)->get();

    expect($casse)->toHaveCount(10);
    foreach ($casse as $cassa) {
        expect($righe[$cassa->id]['saldo'])->toBe(app(SaldoCassaService::class)->saldoDisponibile($cassa), $cassa->nome);
    }
});

it('la fotografia di oggi più i movimenti dopo oggi è la fotografia senza data', function () {
    $s = tsrTuttiITipi($this);
    $banca = $s['banca']->conto_contabile_id;
    tsrScrittura($s, '2026-11-25', 'incasso_rata', $banca, $s['crediti'], 30000);
    tsrScrittura($s, '2026-12-01', 'pagamento_fornitore', $s['debiti'], $banca, 12000);
    tsrScrittura($s, '2027-02-10', 'incasso_rata', $banca, $s['crediti'], 7000);
    // Un accantonamento nel fondo di riserva (vincolato) dopo oggi: il denaro resta, la cifra che si può usare scende.
    tsrScrittura($s, '2026-11-20', 'giroconto', $s['fondi']['Fondo di riserva']->conto_contabile_id, $banca, 5000);
    $d = ddoOggi($s);
    $senzaData = collect(app(StatoPatrimonialeService::class)->calcola($s['c'])['attivo']['voci'])->where('categoria', 'liquidita');
    $vincolatiSenzaData = (int) $senzaData->whereIn('id', collect($s['fondi'])->except(['Fondo spese generiche', 'Fondo tetto'])->pluck('conto_contabile_id')->all())->sum('saldo');

    expect($d['cifra'])->toBe(828000)
        ->and($d['futuri'])->toMatchArray(['quanti' => 4, 'cifra' => 30000 - 12000 + 7000 - 5000, 'primo' => '2026-11-20', 'nel_flusso_quanti' => 3, 'nel_flusso_cifra' => 30000 - 12000 - 5000])
        ->and($d['cifra'] + $d['futuri']['cifra'])->toBe((int) $senzaData->sum('saldo') - $vincolatiSenzaData);
});

it('un condominio che comincia fra 20 giorni, con una cassa da € 5.000,00: disponibile oggi € 5.000,00, e nel flusso nessuna entrata da € 5.000,00', function () {
    $s = tsrBase($this, '2026-12-11 10:00:00');
    DB::table('esercizi')->where('id', $s['e']->id)->update(['nome' => 'Esercizio 2027', 'data_inizio' => '2026-12-31', 'data_fine' => '2027-12-30']);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010', true, ['saldo_iniziale' => 500000]);
    expect(app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($banca))->toBe(\App\Enums\EsitoAperturaCassa::REGISTRATA);
    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'apertura')->value('data_competenza'))->toStartWith('2026-12-31');

    $d = ddoOggi($s);
    $t = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    expect($d['cifra'])->toBe(500000)
        ->and($d['futuri']['quanti'])->toBe(0)
        ->and($t['liquiditaDisponibileCents'])->toBe(500000)
        ->and(collect($t['saldoPrevisto']['giorni'])->sum('registrati'))->toBe(0)
        ->and($t['saldoPrevisto']['finaleCents'])->toBe(500000)
        ->and($t['livello'])->toBe('verde');
});

it('su SQLite il confine del giorno: un incasso con la data di oggi è nella cifra, uno con la data di domani no', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-11-15', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 2500);
    tsrScrittura($s, '2026-11-16', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 4000);
    // SQLite scrive la data come testo con l'ora: «minore del giorno dopo», non «minore o uguale».
    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->orderBy('id')->pluck('data_competenza')->last())->toContain('2026-11-16');

    $d = ddoOggi($s);
    expect($d['cifra'])->toBe(102500)
        ->and($d['futuri'])->toMatchArray(['quanti' => 1, 'cifra' => 4000, 'primo' => '2026-11-16']);
});

it('il nome della casella: con i soli conti correnti «sul conto corrente»; con i contanti o un fondo generico no', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    $riserva = tsrCassa($s['c']->id, 'fondo', 'Fondo di riserva', '1300');
    tsrScrittura($s, '2026-02-01', 'giroconto', $riserva->conto_contabile_id, $banca->conto_contabile_id, 10000);
    tsrTipo($riserva, 'vincolato_lavori');
    expect(ddoOggi($s)['solo_conti_correnti'])->toBeTrue();

    $contanti = tsrCassa($s['c']->id, 'contanti', 'Cassa contanti', '1011');
    tsrScrittura($s, '2026-02-02', 'giroconto', $contanti->conto_contabile_id, $banca->conto_contabile_id, 5000);
    expect(ddoOggi($s)['solo_conti_correnti'])->toBeFalse();
});

it('una cassa disattivata con un saldo resta nella cifra e lo dice; il saldo della banca scritto nella nota del rendiconto arriva con la sua data', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    $vecchio = tsrCassa($s['c']->id, 'banca', 'Vecchio conto', '1015');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-01-01', 'apertura', $vecchio->conto_contabile_id, $s['passate'], 12000);
    DB::table('casse')->where('id', $vecchio->id)->update(['attiva' => false]);
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $e2025->id, 'contenuto' => ['banca' => [(string) $banca->id => ['data' => '2025-12-31', 'saldo' => 470000]]]]);
    NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'contenuto' => ['banca' => [(string) $banca->id => ['data' => '2026-06-30', 'saldo' => 480000]]]]);

    $d = ddoOggi($s);
    expect(collect($d['righe'])->firstWhere('voce', 'Vecchio conto'))->toMatchArray(['saldo' => 12000, 'attiva' => false])
        ->and($d['cifra'])->toBe(112000)
        ->and($d['banca'])->toBe([['cassa_id' => $banca->id, 'data' => '2026-06-30', 'saldo' => 480000, 'esercizio' => 'Esercizio 2026', 'cassa' => 'Banca Intesa']])
        ->and($d['esercizio_id'])->toBe($s['e']->id);
});
