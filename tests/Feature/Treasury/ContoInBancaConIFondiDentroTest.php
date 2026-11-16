<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il saldo di un conto in banca nei dati delle azioni consigliate è quello che dice la banca: la parte libera più i fondi che stanno nello stesso conto
 * corrente, con la regola della scheda «Cassa» e del controllo della banca (`FondiNelConto`, `SaldiDelleBanche`): con un conto in banca solo tutte le
 * partizioni sono sue; con più conti, gli accantonamenti partiti da lui. Un conto positivo in banca non è «sotto zero», e un fondo vincolato più grande della
 * parte libera non è un caso da giroconto. La parte libera resta nel dato (`libero`): è ciò che un conto può dare a un altro.
 */

use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Treasury\TreasuryGuardianService;

require_once __DIR__.'/TesoreriaScenario.php';

function cbfConti(array $s): \Illuminate\Support\Collection
{
    return collect(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['datiDelleAzioni']['conti'])->keyBy('nome');
}

it('un conto in banca solo, con il fondo dentro: il saldo è quello della banca e della scheda «Cassa», non la parte libera', function () {
    // La banca con € 1.000,00 di apertura; i contanti € 500,00; il fondo facciata (vincolato) riceve € 2.000,00 dalla banca il 01/03.
    // La banca dice € 1.000,00; la parte libera è −€ 1.000,00.
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    $contanti = tsrCassa($s['c']->id, 'contanti', 'Cassa contanti', '1011');
    $fondo = tsrCassa($s['c']->id, 'fondo', 'Fondo facciata', '1300');
    tsrTipo($fondo, 'vincolato_lavori', 'facciata');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-01-01', 'apertura', $contanti->conto_contabile_id, $s['passate'], 50000);
    tsrScrittura($s, '2026-03-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 200000);
    $conti = cbfConti($s);
    $scheda = collect(app(RiepilogoFinanziarioService::class)->perEsercizio($s['e'], '2026-11-15')['conti'])->keyBy('cassa');

    expect($scheda['Conto corrente']['finale'])->toBe(100000)
        ->and($conti['Conto corrente']['saldo'])->toBe($scheda['Conto corrente']['finale'])
        ->and($conti['Conto corrente']['libero'])->toBe(-100000)
        ->and($conti['Cassa contanti']['saldo'])->toBe(50000)
        ->and($conti['Cassa contanti']['libero'])->toBe(50000);
});

it('un conto in banca davvero scoperto, anche con il fondo dentro, resta sotto zero della cifra che dice la banca', function () {
    // La banca con € 1.000,00; il fondo riceve € 2.000,00; la banca paga € 500,00 e poi € 800,00. Parte libera: € 1.000,00 − € 2.000,00 − € 500,00 −
    // € 800,00 = −€ 2.300,00; con il fondo dentro la banca dice −€ 2.300,00 + € 2.000,00 = −€ 300,00.
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Conto corrente', '1010');
    $fondo = tsrCassa($s['c']->id, 'fondo', 'Fondo facciata', '1300');
    tsrTipo($fondo, 'vincolato_lavori', 'facciata');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-03-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 200000);
    tsrScrittura($s, '2026-06-01', 'pagamento_fornitore', $s['debiti'], $banca->conto_contabile_id, 50000);
    tsrScrittura($s, '2026-07-01', 'pagamento_fornitore', $s['debiti'], $banca->conto_contabile_id, 80000);

    expect(cbfConti($s)['Conto corrente'])->toMatchArray(['saldo' => -30000, 'libero' => -230000]);
});

it('con due conti in banca, ognuno ha dentro gli accantonamenti partiti da lui', function () {
    // Intesa € 1.000,00 di apertura, accantona € 2.000,00 nel fondo: la banca dice € 1.000,00, la parte libera −€ 1.000,00. Posta € 300,00, nessun fondo.
    $s = tsrBase($this);
    $intesa = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    $posta = tsrCassa($s['c']->id, 'banca', 'Banca Posta', '1012');
    $fondo = tsrCassa($s['c']->id, 'fondo', 'Fondo facciata', '1300');
    tsrTipo($fondo, 'vincolato_lavori', 'facciata');
    tsrScrittura($s, '2026-01-01', 'apertura', $intesa->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-01-01', 'apertura', $posta->conto_contabile_id, $s['passate'], 30000);
    tsrScrittura($s, '2026-03-01', 'giroconto', $fondo->conto_contabile_id, $intesa->conto_contabile_id, 200000);
    $conti = cbfConti($s);

    expect($conti['Banca Intesa'])->toMatchArray(['saldo' => 100000, 'libero' => -100000])
        ->and($conti['Banca Posta'])->toMatchArray(['saldo' => 30000, 'libero' => 30000]);
});
