<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un fondo che non si può usare toglie dal denaro che si può usare solo ciò che tiene: il suo saldo, se è sopra zero. Un fondo vincolato che ha pagato più di
 * quanto aveva va in rosso con denaro preso dalla parte libera: quel rosso è già nel totale, e non si toglie una seconda volta con il segno sbagliato.
 * «Disponibile oggi» della tesoreria e «Si può usare» della scheda «Cassa» non superano mai tutto il denaro, e sono la stessa cifra dello Stato patrimoniale
 * (la liquidità meno quella che i fondi trattengono).
 *
 * La scheda «Cassa» legge le registrazioni dell'esercizio, anche quelle con una data dopo oggi; la tesoreria fino a oggi. Con un conto solo e senza
 * registrazioni dopo oggi le due cifre sono uguali; con una registrazione dopo oggi la differenza è quella registrazione. Con i contanti la scheda li tiene in
 * una riga sua, e la tesoreria li somma.
 */

use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\Treasury\DisponibilitaDiOggi;
use App\Services\Treasury\TreasuryGuardianService;

require_once __DIR__.'/TesoreriaScenario.php';

function dfrOggi(array $s): array
{
    return app(DisponibilitaDiOggi::class)->perCondominio($s['c'], \App\Helpers\DateHelper::oggiUtente());
}

/** Le righe dei conti della scheda «Cassa», per nome della cassa. */
function dfrScheda(array $s, ?string $alla = '2026-11-15'): array
{
    return collect(app(RiepilogoFinanziarioService::class)->perEsercizio($s['e'], $alla)['conti'])->keyBy('cassa')->all();
}

/** € 10.000,00 in banca; il fondo del tipo dato riceve € 200,00 e paga una fattura dell'importo dato. */
function dfrFondoCheHaPagato($test, ?string $sottotipo, int $paga): array
{
    $s = tsrBase($test);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 1000000);
    $fondo = tsrCassa($s['c']->id, 'fondo', 'Fondo lavori', '1300');
    tsrTipo($fondo, $sottotipo, $sottotipo === 'vincolato_lavori' ? 'facciata' : null);
    tsrScrittura($s, '2026-06-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 20000);
    tsrScrittura($s, '2026-07-01', 'pagamento_fornitore', $s['debiti'], $fondo->conto_contabile_id, $paga);

    return $s;
}

it('un fondo vincolato in rosso non fa salire «Disponibile oggi» né «Si può usare» sopra tutto il denaro', function () {
    // Il fondo lavori, con € 200,00, paga € 700,00: va a −€ 500,00, e tutto il denaro è € 9.300,00.
    $s = dfrFondoCheHaPagato($this, 'vincolato_lavori', 70000);
    $d = dfrOggi($s);
    $sp = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], '2026-11-15')['liquidita'];
    $scheda = dfrScheda($s)['Conto corrente'];

    expect($d['totale'])->toBe(930000)
        ->and($d['cifra'])->toBe(930000)
        ->and($d['tolte'])->toBe([])
        ->and($scheda['finale'])->toBe(930000)
        ->and($scheda['si_puo_usare'])->toBe(930000)
        ->and($sp['liquidita_totale'] - $sp['vincolata'])->toBe(930000)
        ->and(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->liquiditaDisponibileCents)->toBe(930000);
    // Le righe di «Da dove viene» sommano ancora tutto il denaro, e il fondo vi compare con il suo rosso.
    expect(array_sum(array_column($d['righe'], 'saldo')))->toBe($d['totale'])
        ->and(collect($d['righe'])->firstWhere('voce', 'Fondo lavori')['saldo'])->toBe(-50000);
});

it('un fondo vincolato sopra zero si toglie com\'era; un fondo che si può usare in rosso è già nel totale', function (?string $sottotipo, int $paga, int $totale, int $disponibile) {
    $s = dfrFondoCheHaPagato($this, $sottotipo, $paga);
    $d = dfrOggi($s);
    $sp = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], '2026-11-15')['liquidita'];

    expect($d['totale'])->toBe($totale)
        ->and($d['cifra'])->toBe($disponibile)
        ->and(dfrScheda($s)['Conto corrente']['si_puo_usare'])->toBe($disponibile)
        ->and($sp['liquidita_totale'] - $sp['vincolata'])->toBe($disponibile)
        ->and($d['cifra'])->toBeLessThanOrEqual($d['totale']);
})->with([
    // € 200,00 nel fondo, ne paga € 150,00: restano € 50,00 vincolati.
    'vincolato, sopra zero' => ['vincolato_lavori', 15000, 985000, 980000],
    'senza tipo (vale vincolato), in rosso' => [null, 70000, 930000, 930000],
    'generico, in rosso' => ['generico', 70000, 930000, 930000],
]);

it('la scheda «Cassa» legge anche le registrazioni dopo oggi: la differenza con «Disponibile oggi» è quella registrazione', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 1000000);
    $sbloccato = tsrCassa($s['c']->id, 'fondo', 'Sbloccato', '1300');
    tsrScrittura($s, '2026-06-01', 'giroconto', $sbloccato->conto_contabile_id, $banca->conto_contabile_id, 50000);
    tsrTipo($sbloccato, 'vincolato_lavori', 'tetto', true, 'urgenza');
    $vecchio = tsrCassa($s['c']->id, 'fondo', 'Vecchio', '1301');
    tsrScrittura($s, '2026-06-01', 'giroconto', $vecchio->conto_contabile_id, $banca->conto_contabile_id, 60000);
    tsrTipo($vecchio, null);
    $d = dfrOggi($s);
    expect($d['cifra'])->toBe(dfrScheda($s)['Conto corrente']['si_puo_usare'])->and($d['cifra'])->toBe(940000);

    // Un incasso di € 300,00 con la data di dopodomani (oggi è il 15/11).
    tsrScrittura($s, '2026-11-17', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 30000);
    $d = dfrOggi($s);

    expect($d['cifra'])->toBe(940000)
        ->and($d['futuri']['cifra'])->toBe(30000)
        ->and(dfrScheda($s, '2026-11-15')['Conto corrente']['si_puo_usare'])->toBe(970000)
        ->and(dfrScheda($s, null)['Conto corrente']['si_puo_usare'])->toBe($d['cifra'] + $d['futuri']['cifra']);
});

it('con i contanti la scheda «Cassa» li tiene in una riga sua, e «Disponibile oggi» somma le righe', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    $contanti = tsrCassa($s['c']->id, 'contanti', 'Cassa contanti', '1011');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 1000000);
    tsrScrittura($s, '2026-02-01', 'giroconto', $contanti->conto_contabile_id, $banca->conto_contabile_id, 5000);
    $fondo = tsrCassa($s['c']->id, 'fondo', 'Generico', '1300');
    tsrScrittura($s, '2026-06-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 10000);
    tsrTipo($fondo, 'generico');
    $scheda = dfrScheda($s);

    expect($scheda['Conto corrente']['si_puo_usare'])->toBe(995000)
        ->and($scheda['Cassa contanti']['si_puo_usare'])->toBeNull()
        ->and($scheda['Cassa contanti']['finale'])->toBe(5000)
        ->and(dfrOggi($s)['cifra'])->toBe(collect($scheda)->sum(fn ($c) => $c['si_puo_usare'] ?? $c['finale']))
        ->and(dfrOggi($s)['cifra'])->toBe(1000000);
});
