<?php

/**
 * 1.11.0-beta.53 (Coda 244) — il cancello del defunto nel piano con i saldi del Wallet legati alla successione.
 *
 * Il caso di `Beta51PianoRateDefuntoNelPianoTest` (O-62-2): la successione di Ugo il 1/5/2026, eredi Anna 60 % e Bruno 40 %, arretrato agli
 * eredi; il «Preventivo 2026» (€ 1.200,00, applica i saldi) generato dopo il decesso calcola ancora per Ugo € 394,52 (120 giorni su 365), da
 * passare agli eredi: Anna € 236,71, Bruno € 157,81. La strada è il Wallet, con i saldi legati alla successione: € 394,52 a credito di Ugo,
 * € 236,71 a debito di Anna, € 157,81 a debito di Bruno.
 *
 * Il cancello confronta la parte con le sole righe legate alla successione: se lo sono (la cifra per ognuno, nel verso giusto, a meno di un
 * centesimo) dice che i saldi ci sono già, con i nomi di tutti, anche se qualcuno ha accanto un riporto che non è la parte. Senza righe legate
 * indica i saldi legati da registrare; con una gamba sola legata, solo quelli che mancano, e dice che gli altri ci sono già (la porta
 * rifiuterebbe una seconda riga legata). Con saldi di prima, non legati, che sono la parte e nessun riporto, dice come prima che ci sono già;
 * con saldi non legati di altra cifra dice che il programma non sa se comprendono la parte.
 *
 * Che cosa NON copre: il piano che non applica i saldi (le varianti V2 della .51 cambiano solo nelle parole della strada, fissate in
 * `Beta51PianoRateDefuntoNelPianoTest`); più successioni dello stesso defunto sulla stessa unità; le righe legate di un esercizio diverso da
 * quello del piano (il cancello le legge per gestione e unità, come la generazione); MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

const SL53D_RIGHE = '€ 394,52 a credito di Ugo, € 236,71 a debito di Anna e € 157,81 a debito di Bruno';
const SL53D_V4_LEGATE = 'I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.';
const SL53D_V1_LEGATE = 'Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026, sulla stessa gestione: ' . SL53D_RIGHE . '; il piano li assorbe alla prossima generazione.';

/** Il caso O-62-2 con il piano che applica i saldi: restituisce lo scenario e la successione. */
function sl53dCaso($test): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);

    return [$s, $succ];
}

/** Una riga legata alla successione dalla porta del Wallet; restituisce gli errori. */
function sl53dLegata($test, Beta51Scenario $s, string $chi, int $cents, Subentro $succ): array
{
    $r = $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
        'saldo_iniziale' => $cents, 'passaggio_id' => $succ->id,
    ]);

    return sl53Errori($r);
}

/** Le tre righe della parte legate alla successione, dalla porta; fallisce se una è rifiutata. */
function sl53dTreLegate($test, Beta51Scenario $s, Subentro $succ): void
{
    foreach ([['ugo', -39452], ['anna', 23671], ['bruno', 15781]] as [$chi, $cents]) {
        expect(sl53dLegata($test, $s, $chi, $cents, $succ))->toBe([], "La riga legata di {$chi} è rifiutata.");
    }
}

it('righe legate senza riporto: il cancello dice che i saldi ci sono già, con i nomi di tutti', function () {
    [$s, $succ] = sl53dCaso($this);
    sl53dTreLegate($this, $s, $succ);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)
        ->and($voce['frase'])->toContain(SL53D_V4_LEGATE)
        ->and($voce['frase'])->not->toContain('registra dal Wallet');
});

it('righe legate con il riporto di Ugo (€ 50,00) accanto: il cancello dice lo stesso che i saldi legati ci sono già', function () {
    [$s, $succ] = sl53dCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000);
    sl53dTreLegate($this, $s, $succ);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toContain(SL53D_V4_LEGATE)
        ->and($voce['frase'])->not->toContain('il programma non ne registra un secondo')
        ->and($voce['frase'])->not->toContain('fra le parti');
});

it('senza righe il cancello indica i saldi legati alla successione da registrare dal Wallet', function () {
    [$s] = sl53dCaso($this);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeTrue()
        ->and($voce['frase'])->toContain(SL53D_V1_LEGATE)
        ->and($voce['frase'])->not->toContain('registra un saldo manuale dal Wallet');
});

it('righe di prima, non legate, con il riporto di Ugo: il cancello dice che il programma non sa se comprendono la parte, e indica i saldi legati', function () {
    // Le righe sono la parte, ma Ugo ha accanto un riporto (+€ 50,00): la sua somma non è la parte. Prima il cancello diceva «fra le parti».
    [$s] = sl53dCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', 15781);

    $frase = (string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? '');

    expect(str_contains($frase, 'Ugo, Anna e Bruno hanno su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: se la comprendono non serve registrare altro; altrimenti registra dal Wallet i saldi legati alla successione del 1 maggio 2026 ('))->toBeTrue($frase)
        ->and(str_contains($frase, SL53D_RIGHE . '), che il piano assorbe alla prossima generazione.'))->toBeTrue($frase)
        ->and(str_contains($frase, 'il programma non ne registra un secondo'))->toBeFalse($frase);
});

it('controprova — righe di prima, non legate, che sono la parte, senza riporto: il cancello dice come prima che i saldi ci sono già (verde anche prima)', function () {
    [$s] = sl53dCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', 15781);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toContain('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.');
});

it('una gamba sola legata (Anna e Bruno, senza la riga di Ugo): la parte non è passata, e il cancello chiede solo la riga che manca, quella di Ugo', function () {
    [$s, $succ] = sl53dCaso($this);
    expect(sl53dLegata($this, $s, 'anna', 23671, $succ))->toBe([]);
    expect(sl53dLegata($this, $s, 'bruno', 15781, $succ))->toBe([]);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['frase'])->not->toContain('I saldi ci sono già')
        ->and($voce['frase'])->toContain('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 394,52 a credito di Ugo; il piano lo assorbe alla prossima generazione. Quelli di Anna e Bruno ci sono già.')
        ->and($voce['frase'])->not->toContain('€ 236,71 a debito di Anna')
        ->and($voce['frase'])->not->toContain('€ 157,81 a debito di Bruno');

    // Seguendo la frase la porta accetta la riga di Ugo, e il cancello dice che i saldi ci sono già.
    expect(sl53dLegata($this, $s, 'ugo', -39452, $succ))->toBe([]);
    expect(Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? '')->toContain(SL53D_V4_LEGATE);
});
