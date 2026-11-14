<?php

/**
 * La competenza scritta sulla fattura di una gestione ordinaria, nel consuntivo per persona: dove la fattura la dice, la sua spesa va a chi era
 * titolare in quei giorni; dove non la dice, il capitolo e i giorni dell'esercizio, come il preventivo. Una competenza su due anni si taglia
 * sull'esercizio; una tutta fuori dall'esercizio resta com'è scritta.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaScenario.php`): U1 di Neri 500, U2 di Bianchi fino al 30/06 e di Rossi dal 01/07 (181 e 184 giorni), U3 di
 * Verdi 200, sulla tabella «Generali». Manutenzioni € 300,00 per una riparazione del 15/03: la parte di U2 è 300,00 × 0,3 = € 90,00. Le cifre a mano
 * sono nel commento di ogni test.
 */

use App\Models\Gestionale\Conto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/ConsuntivoPerPersonaScenario.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';

/** La spesa di una persona su una voce nella lettura, sommata sulle sue unità. */
function ccsxDi(array $lettura, array $s, string $persona, Conto $voce): int
{
    $totale = 0;
    foreach ($lettura['persone'][$s['gestione']->id][$s['persone'][$persona]->id] ?? [] as $unita) {
        $totale += $unita['voci'][$voce->id] ?? 0;
    }

    return $totale;
}

/** Le quattro persone su una voce, nell'ordine Neri, Bianchi, Rossi, Verdi. */
function ccsxQuattro(array $lettura, array $s, Conto $voce): array
{
    return array_map(fn ($p) => ccsxDi($lettura, $s, $p, $voce), ['Neri', 'Bianchi', 'Rossi', 'Verdi']);
}

/** Via Verdi con le Manutenzioni sulla tabella «Generali» e una spesa per ognuna delle competenze date (null: senza competenza scritta). */
function ccsxCaso(array $spese): array
{
    $s = cpmViaVerdi();
    $giornale = cpmGiornale($s);
    $manutenzioni = cpmVoce($s, 'Manutenzioni', [[$s['generali'], 100]]);
    foreach ($spese as [$importo, $dal, $al]) {
        cpmSpesa($s, $giornale, $manutenzioni, $importo, ['competenza_dal' => $dal, 'competenza_al' => $al]);
    }

    return [$s, $manutenzioni, (new ConsuntivoPerPersona())->perEsercizio($s['esercizio'], [], '2026-12-31')];
}

it('la fattura con la competenza scritta del 15/03: la parte di U2 è tutta di Bianchi, che era titolare quel giorno', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2026-03-15', '2026-03-15']]);

    // € 300,00: Neri 500/1000 = 150,00; U2 300/1000 = 90,00, il 15/03 di Bianchi: 90,00 a lui, 0 a Rossi; Verdi 200/1000 = 60,00.
    expect(ccsxQuattro($lettura, $s, $manutenzioni))->toBe([15000, 9000, 0, 6000])
        ->and($lettura['gestioni'][$s['gestione']->id]['uscite'][$manutenzioni->id])->toBe(30000)
        ->and($lettura['gestioni'][$s['gestione']->id]['non_attribuite'])->toBe([]);
});

it('senza la competenza scritta la stessa fattura va per giorni: 44,63 a Bianchi e 45,37 a Rossi', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, null, null]]);

    // U2 € 90,00 per giorni: Bianchi 9.000 × 181/365 = 4.463,01 centesimi, Rossi 4.536,99; il centesimo del resto a Rossi (,99 contro ,01).
    expect(ccsxQuattro($lettura, $s, $manutenzioni))->toBe([15000, 4463, 4537, 6000]);
});

it('due fatture sulla stessa voce, una con la competenza scritta e una senza: ognuna con la sua regola, e la voce torna alle uscite', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2026-03-15', '2026-03-15'], [60000, null, null]]);

    // La datata: 150,00 / 90,00 / 0 / 60,00. L'altra, € 600,00: Neri 300,00, Verdi 120,00, U2 180,00 per giorni: Bianchi 18.000 × 181/365 = 8.926,03,
    // Rossi 9.073,97 → 89,26 e 90,74. In tutto: Neri 450,00, Bianchi 179,26, Rossi 90,74, Verdi 180,00 = € 900,00.
    expect(ccsxQuattro($lettura, $s, $manutenzioni))->toBe([45000, 17926, 9074, 18000])
        ->and($lettura['gestioni'][$s['gestione']->id]['uscite'][$manutenzioni->id])->toBe(90000);
});

it('una competenza su due anni si taglia sull\'esercizio: il 2025 non conta nei giorni del 2026', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2025-01-01', '2026-12-31']]);

    // Tagliata sul 2026 è l'anno intero: Bianchi 181 giorni, Rossi 184, come senza competenza: 44,63 e 45,37. Senza il taglio, sui 730 giorni,
    // Bianchi ne avrebbe 546 (tutto il 2025 e 181 del 2026): 9.000 × 546/730 = 67,32, Rossi 22,68.
    expect(ccsxQuattro($lettura, $s, $manutenzioni))->toBe([15000, 4463, 4537, 6000]);
});

it('una competenza tutta in un altro esercizio resta com\'è scritta: la riparazione del 15/03/2025 è di chi era titolare quel giorno', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2025-03-15', '2025-03-15']]);

    // Il 15/03/2025 U2 era di Bianchi: 90,00 a lui. Tagliata sul 2026 non resterebbe nessun giorno.
    expect(ccsxQuattro($lettura, $s, $manutenzioni))->toBe([15000, 9000, 0, 6000]);
});

it('la riga della persona dice la spesa con la competenza scritta: il giorno, la voce e la sua parte, anche zero a chi non era titolare', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2026-03-15', '2026-03-15'], [60000, null, null]]);
    $g = $s['gestione']->id;
    $bianchi = $lettura['persone'][$g][$s['persone']['Bianchi']->id][$s['unita']['U2']->id]['competenze_scritte'];
    $rossi = $lettura['persone'][$g][$s['persone']['Rossi']->id][$s['unita']['U2']->id]['competenze_scritte'];
    $neri = $lettura['persone'][$g][$s['persone']['Neri']->id][$s['unita']['U1']->id]['competenze_scritte'];

    expect($bianchi)->toBe([['conto_id' => $manutenzioni->id, 'dal' => '2026-03-15', 'al' => '2026-03-15', 'spese' => 1, 'importo' => 9000, 'unita' => 9000]])
        ->and($rossi)->toBe([['conto_id' => $manutenzioni->id, 'dal' => '2026-03-15', 'al' => '2026-03-15', 'spese' => 1, 'importo' => 0, 'unita' => 9000]])
        ->and($neri)->toBe([['conto_id' => $manutenzioni->id, 'dal' => '2026-03-15', 'al' => '2026-03-15', 'spese' => 1, 'importo' => 15000, 'unita' => 15000]]);
});

it('il foglio della persona lo dice: «di cui Manutenzioni, spesa del 15/03/2026: a chi era titolare quel giorno, come scritto sulla fattura»', function () {
    [$s, $manutenzioni] = ccsxCaso([[30000, '2026-03-15', '2026-03-15'], [60000, null, null]]);
    $this->travelTo('2027-01-10 10:00:00');
    $letto = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class)->leggi($s['esercizio'], [], true);
    $fogli = app(\App\Services\Gestionale\FogliDelConguaglio::class);
    $testi = [];
    foreach (['Bianchi', 'Rossi'] as $chi) {
        $f = $fogli->datiDelFoglio($s['condominio'], $s['esercizio'], $letto, $s['persone'][$chi]->id);
        // La chiave della riga, con il nome della voce, come la passa il foglio (la riga di `FogliDelConguaglio::datiDelFoglio`).
        $f['gestioni'][0]['unita'][0]['competenze_scritte'] = array_map(fn ($c) => $c + ['voce' => 'Manutenzioni'],
            $letto['persone'][$s['gestione']->id][$s['persone'][$chi]->id][$s['unita']['U2']->id]['competenze_scritte']);
        $testi[$chi] = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['condominio'], 'esercizio' => $s['esercizio'], 'foglio' => $f])->render());
    }

    // Bianchi: la riparazione del 15/03 è sua, € 90,00; Rossi, entrato il 01/07: € 0,00, e il foglio dice perché.
    expect($testi['Bianchi'])->toContain('di cui Manutenzioni, spesa del 15/03/2026: a chi era titolare quel giorno, come scritto sulla fattura € 90,00')
        ->and($testi['Rossi'])->toContain('di cui Manutenzioni, spesa del 15/03/2026: a chi era titolare quel giorno, come scritto sulla fattura € 0,00');
});

it('più spese con la competenza scritta sulla stessa voce: una riga sola, sommata, con il primo e l\'ultimo giorno', function () {
    [$s, $manutenzioni, $lettura] = ccsxCaso([[30000, '2026-03-15', '2026-03-15'], [10000, '2026-09-10', '2026-09-10']]);
    $g = $s['gestione']->id;

    // Il 15/03 U2 è di Bianchi, 90,00; il 10/09 di Rossi, 100,00 × 0,3 = 30,00. Sull'unità 120,00 in due spese, dal 15/03 al 10/09.
    expect($lettura['persone'][$g][$s['persone']['Bianchi']->id][$s['unita']['U2']->id]['competenze_scritte'])
        ->toBe([['conto_id' => $manutenzioni->id, 'dal' => '2026-03-15', 'al' => '2026-09-10', 'spese' => 2, 'importo' => 9000, 'unita' => 12000]])
        ->and($lettura['persone'][$g][$s['persone']['Rossi']->id][$s['unita']['U2']->id]['competenze_scritte'][0]['importo'])->toBe(3000);
});
