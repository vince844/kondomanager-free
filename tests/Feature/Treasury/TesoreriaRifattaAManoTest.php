<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La tesoreria rifatta a mano sul condominio di `TesoreriaRifattaAManoScenario.php`: le quattro cifre, i tre scenari giorno per giorno, il semaforo, quanto
 * basta e le persone del recupero; «Disponibile oggi» uguale a «Si può usare» della scheda «Cassa». Le attese sono calcolate nei commenti.
 */

use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Treasury\TreasuryGuardianService;

require_once __DIR__.'/TesoreriaRifattaAManoScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('le quattro cifre, i tre scenari, quanto basta e le persone del recupero, rifatti a mano', function () {
    $s = ldnCondominio($this);
    $t = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    $sp = $t['saldoPrevisto'];

    // Tutto il denaro: incassi € 10.100,00 (€ 5.500 + € 3.000 + € 1.600) + il saldo iniziale della cassa non registrato a giornale (€ 1.000,00).
    // Il fondo vincolato trattiene € 2.000,00: disponibile oggi € 9.100,00.
    expect($t['liquiditaTotaleCents'])->toBe(1110000)
        ->and($t['liquiditaDisponibileCents'])->toBe(910000)
        ->and($t['liquiditaVincolataCents'])->toBe(200000);
    // Uscite previste: € 3.000,00 (scaduta, oggi) + € 7.000,00 (20/11); non la fattura del 20/12, non la pregressa, non la contestata.
    expect($t['uscitePredittiveCents'])->toBe(1000000)
        ->and($t['debitiPregressiScadutiCents'])->toBe(40000)
        // Entrate attese: € 900,00 scadute + € 1.000,00 in scadenza il 05/12.
        ->and($t['incassiScadutiCents'])->toBe(90000)
        ->and($t['incassiInScadenzaCents'])->toBe(100000)
        ->and($t['incassiAttesiCents'])->toBe(190000);
    // «Senza le rate»: oggi € 9.100 − € 3.000 = € 6.100,00; dal 20/11 −€ 900,00 fino alla fine. Fra 30 giorni −€ 900,00.
    expect(count($sp['giorni']))->toBe(31)
        ->and($sp['giorni'][0]['giorno'])->toBe('2026-11-16')
        ->and($sp['giorni'][30]['giorno'])->toBe('2026-12-16')
        ->and(ldnGiorno($t, '2026-11-16')['saldo'])->toBe(610000)
        ->and(ldnGiorno($t, '2026-11-19')['saldo'])->toBe(610000)
        ->and(ldnGiorno($t, '2026-11-20')['saldo'])->toBe(-90000)
        ->and($sp['primoSottoZero'])->toBe('2026-11-20')
        ->and($sp['minimoCents'])->toBe(-90000)
        ->and($sp['finaleCents'])->toBe(-90000)
        ->and(ldnGiorno($t, '2026-12-05')['rate_in_scadenza'])->toBe(100000)
        ->and(collect($sp['giorni'])->sum('rate_in_scadenza'))->toBe(100000)
        ->and($sp['rateScaduteCents'])->toBe(90000);
    // «Se pagano tutti» (tutto con le entrate) a fine finestra: −€ 900 + € 900 + € 1.000 = € 1.000,00; il minimo è € 0,00 (dal 20/11 al 04/12): mai sotto zero.
    expect($sp['finaleConEntrateCents'])->toBe(100000)
        ->and(ldnGiorno($t, '2026-11-20')['con_entrate'])->toBe(0);
    // Il semaforo: sotto zero senza le rate, mai sotto zero se pagano tutti → giallo.
    expect($t['livello'])->toBe('giallo');
    // Quanto basta: il giorno più basso di «Con le rate in scadenza» è −€ 900,00 (dal 20/11 al 04/12): servono Verdi (€ 600,00) e Bianchi (€ 300,00).
    expect($t['recupero'])->toMatchArray(['servono' => 2, 'servonoCents' => 90000, 'quante' => 2, 'cifra' => 90000])
        ->and(array_column($t['recupero']['persone'], 'nome'))->toBe(['Verdi', 'Bianchi'])
        ->and(array_column($t['recupero']['persone'], 'cifra'))->toBe([60000, 30000]);
});

it('«Disponibile oggi» è la cifra «Si può usare» della scheda «Cassa» alla stessa data', function () {
    $s = ldnCondominio($this);
    $d = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    $scheda = collect(app(RiepilogoFinanziarioService::class)->perEsercizio($s['e'], '2026-11-16')['conti'])->keyBy('cassa')->all();

    // Un conto solo (la banca, con il fondo dentro), nessuna registrazione dopo oggi: la stessa cifra, € 9.100,00.
    expect($scheda['Banca principale']['si_puo_usare'] ?? $scheda['Banca principale']['finale'])->toBe($d['liquiditaDisponibileCents']);
});
