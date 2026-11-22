<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Sala\ContiDellaSala;

/**
 * I conti della sala (R3), sullo stesso file di casi che legge la pagina (`tests/Fixtures/sala/conti.json`, letto anche da
 * `resources/js/lib/assemblee/sala/conti.test.ts`): la costituzione per convocazione, l'esito proposto per maggioranza, il conflitto determinante, il quinto
 * delle deleghe, e l'esito del voto per condizione (le teste e il valore, una riga ciascuna, con la soglia e quanto manca), che dice a parole i numeri
 * dell'esito e non lo rifà: su ogni caso dell'esito le due condizioni passano tutte e due solo quando la proposta è «approvata». I numeri della bozza
 * approvata: 11 su 26 e 484,60; servono 9 e 333,34; 9 su 12 e 506,40; 466,80; 3 su 5 e 125,70 su 200,00; Greco a 200,70 rifiutato. Le soglie con
 * moltiplicazioni intere, mai divisioni: i millesimi sono centomillesimi interi. I quattro confini della costituzione (decisione 640 (4)): 13 su 26 in prima
 * non costituisce (la metà esatta delle teste non è la maggioranza); 9 su 27 in seconda costituisce (un terzo esatto); in prima 666,66667 su 1.000
 * costituisce e 666,66666 no.
 *
 * Che cosa non copre: chi è presente e chi vota (lo provano i test della fotografia, delle teste e del voto).
 */
function cdsCasi(string $tipo): array
{
    $casi = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/sala/conti.json'), true, 512, JSON_THROW_ON_ERROR);

    return array_values(array_map(fn ($c) => [$c['che_cosa'], $c], array_filter($casi, fn ($c) => $c['tipo'] === $tipo)));
}

it('il file dei casi ha tutti e quattro i conti', function () {
    expect(count(cdsCasi('costituzione')))->toBeGreaterThanOrEqual(10)
        ->and(count(cdsCasi('esito')))->toBeGreaterThanOrEqual(10)
        ->and(count(cdsCasi('conflitto')))->toBeGreaterThanOrEqual(2)
        ->and(count(cdsCasi('quinto')))->toBeGreaterThanOrEqual(5)
        ->and(count(cdsCasi('condizioni')))->toBeGreaterThanOrEqual(10);
});

it('la costituzione', function (string $cheCosa, array $c) {
    $r = ContiDellaSala::costituzione($c['convocazione'], $c['totale_teste'], $c['totale_valore'], $c['teste'], $c['valore']);
    expect(array_intersect_key($r, $c['atteso']))->toBe($c['atteso']);
})->with(cdsCasi('costituzione'));

it('l\'esito proposto', function (string $cheCosa, array $c) {
    $esito = ContiDellaSala::esito($c['maggioranza'], $c['convocazione'], $c['totale_teste'], $c['totale_valore'], $c['intervenuti'], $c['favorevoli_teste'],
        $c['favorevoli_valore']);
    expect($esito)->toBe($c['atteso']['esito']);
})->with(cdsCasi('esito'));

it('l\'esito per condizione: le teste e il valore, ognuna con la soglia e quanto manca', function (string $cheCosa, array $c) {
    expect(ContiDellaSala::condizioni($c['maggioranza'], $c['convocazione'], $c['totale_teste'], $c['totale_valore'], $c['intervenuti'], $c['favorevoli_teste'],
        $c['favorevoli_valore']))->toBe($c['atteso']);
})->with(cdsCasi('condizioni'));

it('le condizioni dicono l\'esito proposto, non lo rifanno: tutte e due sì solo quando è «approvata»; nessuna quando decide il presidente', function (string $cheCosa, array $c) {
    $condizioni = ContiDellaSala::condizioni($c['maggioranza'], $c['convocazione'], $c['totale_teste'], $c['totale_valore'], $c['intervenuti'], $c['favorevoli_teste'],
        $c['favorevoli_valore']);
    if (! in_array($c['atteso']['esito'], ['approvata', 'respinta'], true)) {
        expect($condizioni)->toBeNull();

        return;
    }
    expect($condizioni)->toHaveCount(2)
        ->and(array_column($condizioni, 'condizione'))->toBe(['teste', 'valore'])
        ->and($condizioni[0]['passa'] && $condizioni[1]['passa'])->toBe($c['atteso']['esito'] === 'approvata');
})->with(cdsCasi('esito'));

it('il conflitto d\'interessi determinante', function (string $cheCosa, array $c) {
    $r = ContiDellaSala::conflitto($c['maggioranza'], $c['convocazione'], $c['totale_teste'], $c['totale_valore'], $c['intervenuti'], $c['favorevoli_teste'],
        $c['favorevoli_valore'], $c['conflitto_teste'], $c['conflitto_valore']);
    expect(array_intersect_key($r, $c['atteso']))->toBe($c['atteso']);
})->with(cdsCasi('conflitto'));

it('quando una delega conta: per alcuni punti nella costituzione sì, sul voto solo dei suoi punti; «da decidere» mai', function (string $cheCosa, array $c) {
    expect(\App\Services\Assemblee\Sala\PresenzeDellaSala::delegaConta($c['punti'], $c['da_decidere'], $c['punto']))->toBe($c['atteso']);
})->with(cdsCasi('delega_conta'));

it('il quinto delle deleghe', function (string $cheCosa, array $c) {
    $r = ContiDellaSala::quinto($c['totale_teste'], $c['totale_valore'], $c['teste'], $c['valore']);
    expect(array_intersect_key($r, $c['atteso']))->toBe($c['atteso']);
})->with(cdsCasi('quinto'));

it('i millesimi si scrivono all\'italiana, e quelli che servono per eccesso', function () {
    expect(ContiDellaSala::millesimi(48460000))->toBe('484,60')
        ->and(ContiDellaSala::millesimi(100000000))->toBe('1.000,00')
        ->and(ContiDellaSala::millesimi(3960000))->toBe('39,60')
        ->and(ContiDellaSala::millesimi(33333334))->toBe('333,33')
        ->and(ContiDellaSala::millesimiPerEccesso(33333334))->toBe('333,34')
        ->and(ContiDellaSala::millesimiPerEccesso(33280000))->toBe('332,80');
});
