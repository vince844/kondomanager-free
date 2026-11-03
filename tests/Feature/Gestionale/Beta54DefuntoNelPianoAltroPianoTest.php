<?php

/**
 * 1.11.0-beta.54 — il defunto nel piano: le righe legate alla successione già in un altro piano della gestione.
 *
 * Ugo muore il 1/5, eredi Anna 60 % e Bruno 40 %, con l'arretrato agli eredi. Il «Preventivo 2026» calcola ancora per Ugo i suoi 120 giorni
 * (120000 × 120/365 = 39452,05 → € 394,52), e il cancello chiede di passare la parte agli eredi con le tre righe del Wallet legate alla successione:
 * Ugo −39452, Anna +23671, Bruno +15781 (39452 × 60 % e 40 % con i resti maggiori). Le tre righe possono essere già assorbite da un altro piano della
 * stessa gestione («Conguaglio 2026»). Se quel piano è in bozza si riscrive alla prossima generazione, e la frase dice dove sono (V3). Se è emesso non
 * si riscrive più: le righe sono di quel piano, e il cancello chiede le tre righe da registrare, perché il piano del defunto non le assorbe (V1).
 * Questo test fissa il secondo caso: era detto da una condizione («il piano si riscrive ancora») che nessun test faceva diventare falsa, perché l'altro
 * piano dei test della beta.53 è sempre in bozza.
 *
 * Fonte dell'atteso: il conto a mano sopra, e le tre righe che la porta accetta.
 *
 * Che cosa NON copre: le righe legate in un piano in bozza (`Beta53DefuntoLegataCasiInPiuTest`); il caso del piano emesso con una sola riga o con una
 * riga diversa dalla parte (gli oracoli del laboratorio sul cancello del defunto).
 */

use App\Models\Saldo;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';

/** O-62-2 con le tre righe legate alla successione già assorbite da un altro piano della stessa gestione, generato; con `$emesso` emesso fino al 5/11. */
function dap54Defunto($test, bool $emesso): array
{
    sl53Amministratore($test);
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    foreach ([['ugo', -39452], ['anna', 23671], ['bruno', 15781]] as [$chi, $cents]) {
        $r = $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
            'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
            'saldo_iniziale' => $cents, 'passaggio_id' => $succ->id,
        ]);
        expect(sl53Errori($r))->toBe([]);
    }
    $s->pianoOrdinario('Conguaglio 2026', 'Ordinaria 2026', numeroRate: 1, primaScadenza: '2026-11-05', applicaSaldi: true);
    $s->genera('Conguaglio 2026');
    if ($emesso) {
        $s->emetti('Conguaglio 2026', '2026-11-05');
    }

    return [$s, $succ];
}

it('le righe legate alla successione già in un altro piano che non si riscrive più (emesso) sono di quel piano: il cancello chiede le tre righe, e non dice che il piano «non è ancora emesso»; seguita, la porta le accetta', function () {
    [$s, $succ] = dap54Defunto($this, emesso: true);
    $altro = $s->piano('Conguaglio 2026');
    expect($altro->eImmutabile())->toBeTrue()
        ->and(Saldo::where('passaggio_id', $succ->id)->where('piano_rate_id', $altro->id)->count())->toBe(3);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull()
        ->and($voce['cifra'])->toBe(39452)
        ->and($voce['frase'])->toContain('Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026, sulla stessa gestione: '
            . '€ 394,52 a credito di Ugo, € 236,71 a debito di Anna e € 157,81 a debito di Bruno; il piano li assorbe alla prossima generazione.')
        ->and($voce['frase'])->not->toContain('che non è ancora emesso');

    // Un minuto dopo, come dal Wallet: un saldo scritto nello stesso secondo delle quote del «Conguaglio 2026» sarebbe già nelle sue quote.
    $this->travel(1)->minutes();
    foreach ([['ugo', -39452], ['anna', 23671], ['bruno', 15781]] as [$chi, $cents]) {
        $r = $this->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
            'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
            'saldo_iniziale' => $cents, 'passaggio_id' => $succ->id,
        ]);
        expect(sl53Errori($r))->toBe([], "La porta rifiuta la riga di {$chi} che il cancello chiede.");
    }
});
