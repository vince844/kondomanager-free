<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le rate senza la data dei sei mesi, nel riquadro: per ogni persona il nome, le unità, quanto non ha versato, perché la data manca (con una spiegazione) e la
 * prossima azione che quel perché chiede: «Dichiara l'origine» per un saldo riportato senza l'origine o con l'origine «prima del primo esercizio», «Crea
 * l'esercizio» quando manca l'esercizio che contiene la data, il piano rate quando manca la delibera o l'esercizio di competenza. Mai un rimando generico.
 */

use App\Services\Gestionale\Debiti\SeiMesi;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** Il condominio importato: Neri ha un saldo di € 100,00 portato a nuovo, senza l'origine; gli altri sono in pari. */
function smsdScenario($test): array
{
    $test->travelTo('2026-01-20 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'D' => ['neri', 500]], 12, 'rata_zero', [['neri', 'D', 10000]], 120000, '2026-01-31', 31);
    lcdEmetti($test, $s, [0], '2026-01-20');
    $test->travelTo('2026-02-05 10:00:00');

    return $s;
}

it('una persona con il saldo senza l\'origine: chi è, quanto, perché e «Dichiara l\'origine»', function () {
    $s = smsdScenario($this);
    $r = smaRiquadro($s);

    expect($r['senza_data'])->toMatchArray(['rate' => 1, 'persone' => 1, 'cifra' => 10000])
        ->and($r['senza_data']['righe'])->toBe([[
            'anagrafica_id' => $s['persone']['neri']->id, 'nome' => 'Neri', 'unita' => ['Int. D'], 'cifra' => 10000,
            'motivi' => [['caso' => 'origine_non_dichiarata', 'perche' => 'Saldo riportato senza l\'origine.',
                'spiegazione' => 'Il programma non sa di quale esercizio è il debito, quindi non sa da quando contare.',
                'azione' => ['tipo' => 'origine', 'etichetta' => 'Dichiara l\'origine']]],
        ]]);
});

it('con l\'origine «prima del primo esercizio»: il perché lo dice, e la prossima azione resta «Dichiara l\'origine»', function () {
    $s = smsdScenario($this);
    $saldo = (int) DB::table('saldi')->where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone']['neri']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.origine', ['condominio' => $s['c']->id]), ['saldi' => [$saldo], 'origine' => 'prima_del_primo'])
        ->assertSessionHasNoErrors();
    $motivo = smaRiquadro($s)['senza_data']['righe'][0]['motivi'][0];

    expect($motivo)->toBe(['caso' => 'prima_del_primo', 'perche' => 'Saldo riportato con l\'origine «prima del primo esercizio».',
        'spiegazione' => 'Il programma non ha quell\'esercizio, quindi non sa quando chiude.', 'azione' => ['tipo' => 'origine', 'etichetta' => 'Dichiara l\'origine']]);
});

it('ogni perché ha la sua prossima azione: l\'esercizio che manca, la delibera, la competenza, la data prima degli esercizi', function () {
    $voce = fn (string $stato, array $altro = []) => ['data' => null, 'stato' => $stato] + $altro;
    $esito = fn (array $scadenza, array $competenza) => ['dalla_scadenza' => $scadenza, 'dalla_competenza' => $competenza, 'piu_vicina' => null];

    expect(SeiMesi::senzaData($esito($voce('manca_esercizio', ['data_cercata' => '2027-01-15']), $voce('senza_delibera'))))->toBe([
        'caso' => 'manca_esercizio', 'perche' => 'L\'esercizio che contiene il 15/01/2027 non c\'è.',
        'spiegazione' => 'I sei mesi si contano dalla chiusura di quell\'esercizio: quando c\'è, la data si calcola.', 'azione' => ['tipo' => 'esercizio', 'etichetta' => 'Crea l\'esercizio']])
        ->and(SeiMesi::senzaData($esito($voce('prima_degli_esercizi', ['data_cercata' => '2019-03-05']), $voce('senza_delibera')))['azione'])
        ->toBe(['tipo' => 'piano', 'etichetta' => 'Apri il piano rate'])
        ->and(SeiMesi::senzaData($esito($voce('prima_degli_esercizi', ['data_cercata' => '2019-03-05']), $voce('senza_delibera')))['perche'])
        ->toBe('Il 05/03/2019 viene prima del primo esercizio del programma.')
        ->and(SeiMesi::senzaData(['dalla_scadenza' => ['data' => '2026-06-30', 'stato' => 'ok'], 'dalla_competenza' => $voce('senza_delibera'), 'piu_vicina' => '2026-06-30']))->toBeNull()
        ->and(SeiMesi::senzaData(['dalla_scadenza' => $voce('senza_delibera'), 'dalla_competenza' => $voce('senza_delibera'), 'piu_vicina' => null]))->toBe([
            'caso' => 'senza_delibera', 'perche' => 'Il piano straordinario non ha la data della delibera.',
            'spiegazione' => 'Per la straordinaria i sei mesi si contano dall\'esercizio della delibera.', 'azione' => ['tipo' => 'piano', 'etichetta' => 'Scrivi la data della delibera']])
        ->and(SeiMesi::senzaData(['dalla_scadenza' => $voce('senza_competenza'), 'dalla_competenza' => $voce('senza_competenza'), 'piu_vicina' => null])['azione'])
        ->toBe(['tipo' => 'piano', 'etichetta' => 'Apri il piano rate']);
});
