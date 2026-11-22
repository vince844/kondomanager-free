<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (decisione 588): la regola del passo proposto con le lettere per PEC dalla casella dello studio. Una lettera partita per PEC è «partita per
 * PEC», non «per email»; con la consegna provata (la ricevuta del gestore, o il giorno scritto a mano con la prova) il termine «N giorni dal ricevimento» si
 * conta dal giorno della consegna, e la frase lo dice. Senza la consegna, dall'invio, come per l'email. Oggi è il 14/11/2026.
 */

use App\Services\Gestionale\Debiti\PassoProposto;

const PPP_OGGI = '2026-11-14';

/** La lettera del registro, come la dà `per_tipo`, con il canale e la consegna. */
function pppLettera(string $tipo, string $data, array $altro = []): array
{
    return $altro + ['id' => 7, 'tipo' => $tipo, 'stato' => 'inviata', 'data' => $data, 'canale' => 'pec', 'spedizione' => null, 'consegna' => null, 'termine' => 15,
        'termine_forma' => 'giorni', 'termine_data' => null, 'righe' => ['12|1', '13|1']];
}

/** La persona con due rate scadute da € 100,00 (la data dei sei mesi lontana) e la lettera data. */
function pppPer(string $chiave, array $lettera): array
{
    $rata = fn (string $k) => ['chiave' => $k, 'residuo' => 10000, 'scadenza' => '2026-09-05', 'giorni_ritardo' => 70, 'data' => '2027-06-30', 'dispensa' => null,
        'senza_data' => null, 'in_accordo' => 0, 'fuori_dalle_lettere' => false];

    return PassoProposto::per(['rate' => [$rata('12|1'), $rata('13|1')], 'agito' => [], 'tornati' => [], 'credito' => null, 'accordi' => [],
        'lettere' => ['diffida' => null, 'sollecito' => null, 'promemoria' => null, $chiave => $lettera], 'fascicolo' => null, 'tolleranza' => null, 'salita' => null],
        PPP_OGGI);
}

it('la diffida partita per PEC senza la ricevuta: «partita per PEC», e il termine contato dall\'invio', function () {
    $p = pppPer('diffida', pppLettera('diffida', '2026-11-03'));

    expect($p['caso'])->toBe('R8')
        ->and($p['frase'])->toBe('Diffida partita per PEC il 03/11/2026: il termine è il 18/11/2026.')
        ->and($p['termine'])->toBe('15 giorni dal ricevimento: contati dall\'invio del 03/11/2026.');
});

it('la diffida con la ricevuta di consegna: il termine si conta dalla consegna, e la frase dice la consegna per PEC con la ricevuta', function () {
    $p = pppPer('diffida', pppLettera('diffida', '2026-10-28', ['consegna' => ['giorno' => '2026-10-30', 'a_mano' => false]]));

    expect($p['caso'])->toBe('R8')
        ->and($p['frase'])->toBe('Diffida consegnata per PEC il 30/10/2026: il termine è il 14/11/2026.')
        ->and($p['termine'])->toBe('15 giorni dal ricevimento: contati dalla consegna del 30/10/2026, dalla ricevuta.');

    // Un giorno prima: dall'invio il termine sarebbe passato; dalla consegna no.
    $passato = pppPer('diffida', pppLettera('diffida', '2026-10-28'));
    expect($passato['caso'])->toBe('R9');
});

it('il sollecito con la consegna scritta a mano, con la prova: contati dalla consegna, scritta a mano', function () {
    $p = pppPer('sollecito', pppLettera('sollecito', '2026-11-02', ['canale' => 'pec_e_carta', 'consegna' => ['giorno' => '2026-11-04', 'a_mano' => true]]));

    expect($p['caso'])->toBe('R12')
        ->and($p['frase'])->toBe('Sollecito consegnato per PEC il 04/11/2026: il termine è il 19/11/2026.')
        ->and($p['termine'])->toBe('15 giorni dal ricevimento: contati dalla consegna del 04/11/2026, scritta a mano con la prova.');
});
