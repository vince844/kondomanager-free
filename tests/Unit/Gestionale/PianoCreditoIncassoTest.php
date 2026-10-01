<?php

use App\Services\Gestionale\PianoCreditoIncasso;

/**
 * Il pianificatore del credito di un incasso (Coda 167, decisioni 30.7 e 30.11, 1.11.0-beta.40), sulla tabella che
 * condivide con il suo specchio nel modulo: `tests/Fixtures/piano_credito_incasso.json`, letta anche da
 * `resources/js/lib/gestionale/incassi/pianoCreditoIncasso.test.ts`. Se server e modulo smettono di calcolare uguale,
 * uno dei due diventa rosso.
 */
$casi = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/piano_credito_incasso.json'), true)['casi'];

test('il pianificatore del credito risponde come la tabella condivisa con il modulo', function (array $caso) {
    $piano = PianoCreditoIncasso::pianifica(
        $caso['righe'], $caso['crediti'], $caso['contante'], $caso['credito_prima'], $caso['fra_gestioni'],
    );

    $atteso = $caso['atteso'];
    $credito = array_map(fn ($perRiga) => array_map('intval', $perRiga), $atteso['credito']);

    expect($piano['credito'])->toEqual($credito)
        ->and($piano['contante'])->toEqual(array_map('intval', $atteso['contante']))
        ->and($piano['usato'])->toBe($atteso['usato'])
        ->and($piano['serve_scelta'])->toBe($atteso['serve_scelta']);
})->with(fn () => collect($casi)->mapWithKeys(fn ($c) => [$c['nome'] => [$c]])->all());

test('il credito usato e il contante sommano sempre le coperture richieste, quando il contante basta', function () {
    $piano = PianoCreditoIncasso::pianifica(
        [['chiave' => 0, 'importo' => 7001, 'gestione' => 1], ['chiave' => 1, 'importo' => 2999, 'gestione' => 2]],
        [['chiave' => 0, 'importo' => 3333, 'gestione' => 1], ['chiave' => 1, 'importo' => 1111, 'gestione' => 2]],
        5556, true, null,
    );

    $perRiga = [0 => 0, 1 => 0];
    foreach ($piano['credito'] as $righe) {
        foreach ($righe as $k => $c) {
            $perRiga[$k] += $c;
        }
    }

    expect($perRiga[0] + $piano['contante'][0])->toBe(7001)
        ->and($perRiga[1] + $piano['contante'][1])->toBe(2999)
        ->and($piano['usato'])->toBe(4444);
});
