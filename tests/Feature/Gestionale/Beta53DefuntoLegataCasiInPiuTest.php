<?php

/**
 * 1.11.0-beta.53 (Coda 244) — il cancello del defunto nel piano: quali righe legate contano come la parte che passa agli eredi.
 *
 * Il caso di `Beta53DefuntoLegataTest` (O-62-2): la successione di Ugo il 1/5/2026, eredi Anna 60 % e Bruno 40 %, arretrato agli eredi; il
 * «Preventivo 2026» (€ 1.200,00, applica i saldi) calcola ancora per Ugo € 394,52, da passare agli eredi con € 394,52 a credito di Ugo,
 * € 236,71 a debito di Anna e € 157,81 a debito di Bruno.
 *
 * Contano le righe legate alla successione che questa generazione assorbe: libere nel Wallet o già di questo piano. Le stesse cifre legate a
 * un altro passaggio dell'unità (un inizio di locazione scelto per sbaglio nel modale) non sono la parte legata alla successione; le righe
 * legate alla successione che un altro piano della gestione ha già assorbito non le assorbe questo piano. In tutti e due i casi il cancello
 * non dice che i saldi ci sono già; nel secondo dice in quale piano sono e non le chiede di nuovo (le strade, seguite dalle rotte, sono in
 * `Beta53DefuntoPianoSenzaSaldiTest`).
 *
 * Le righe miste: il confronto della parte è per persona. Chi ha righe legate alla successione si confronta con quelle; chi non ne ha, con i
 * saldi non legati, come prima della 1.11.0-beta.53 (tutti quelli senza righe legate devono avere la loro parte, altrimenti il programma non
 * sa se i loro saldi la comprendono). La frase chiede solo ciò che manca: le righe di chi non ha niente, al condizionale quelle di chi ha saldi
 * non legati che non si sa se comprendono la parte, la correzione di una riga legata che non è la parte; e dice di chi la parte c'è già.
 * Seguendo la frase, nessuna parte entra due volte.
 *
 * Che cosa NON copre: il piano che non applica i saldi (`Beta53DefuntoPianoSenzaSaldiTest`); più successioni dello stesso defunto; MySQL.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

/** Il caso O-62-2 con il piano che applica i saldi: lo scenario e la successione. */
function sl53yCaso($test): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);

    return [$s, $succ];
}

/** Le tre righe della parte dalla porta del Wallet, legate al passaggio dato; fallisce se una è rifiutata. Restituisce gli id. */
function sl53yTreRighe($test, Beta51Scenario $s, Subentro $passaggio): array
{
    foreach ([['ugo', -39452], ['anna', 23671], ['bruno', 15781]] as [$chi, $cents]) {
        $r = $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
            'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
            'saldo_iniziale' => $cents, 'passaggio_id' => $passaggio->id,
        ]);
        expect(sl53Errori($r))->toBe([], "La riga legata di {$chi} è rifiutata.");
    }

    return Saldo::where('passaggio_id', $passaggio->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

it('le cifre della parte legate a un altro passaggio dell\'unità non sono la parte legata alla successione: il cancello le indica ancora', function () {
    [$s] = sl53yCaso($this);
    $inizio = $s->passaggio('inizio_locazione', 'A', null, 'luca', '2026-06-01');
    expect($inizio->tipo_passaggio)->toBe('inizio_locazione');
    sl53yTreRighe($this, $s, $inizio);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)
        ->and(str_contains($voce['frase'], 'I saldi ci sono già'))->toBeFalse($voce['frase'])
        ->and(str_contains($voce['frase'], 'registra dal Wallet i saldi legati alla successione del 1 maggio 2026'))->toBeTrue($voce['frase']);
});

it('le righe legate alla successione che un altro piano della gestione ha già assorbito: il cancello non dice che ci sono già, dice dove sono e non le chiede di nuovo', function () {
    [$s, $succ] = sl53yCaso($this);
    $ids = sl53yTreRighe($this, $s, $succ);
    $altro = PianoRate::create([
        'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Conguaglio 2026', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    // Le tre righe stanno nel «Conguaglio 2026»: il lucchetto è chiuso dall'altro piano.
    Saldo::whereIn('id', $ids)->update(['is_applicato' => true, 'piano_rate_id' => $altro->id]);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));

    expect($voce)->not->toBeNull();
    expect(str_contains($voce['frase'], 'I saldi ci sono già'))->toBeFalse($voce['frase'])
        ->and(str_contains($voce['frase'], 'sono già nel piano «Conguaglio 2026», che non è ancora emesso'))->toBeTrue($voce['frase'])
        ->and(str_contains($voce['frase'], 'Per passare la parte agli eredi registra dal Wallet'))->toBeFalse($voce['frase'])
        // Che questo piano le assorba lo dice solo come esito di una strada: eliminato prima l'altro piano.
        ->and(str_contains($voce['frase'], 'questo piano li assorbe'))->toBe(str_contains($voce['frase'], 'elimina «Conguaglio 2026» dalla sua pagina: tornano liberi nel Wallet, e questo piano li assorbe'));
});

/** Una riga legata alla successione dalla porta; fallisce se è rifiutata. */
function sl53yLegata($test, Beta51Scenario $s, string $chi, int $cents, Subentro $succ): void
{
    $r = $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
        'saldo_iniziale' => $cents, 'passaggio_id' => $succ->id,
    ]);
    expect(sl53Errori($r))->toBe([], "La riga legata di {$chi} è rifiutata: " . json_encode(sl53Errori($r), JSON_UNESCAPED_UNICODE));
}

/** La frase della voce del defunto nel cancello del «Preventivo 2026». */
function sl53yFrase(Beta51Scenario $s): string
{
    return (string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? '');
}

/** Le righe di saldo dell'unità senza le righe dei passaggi, per persona: [nome, centesimi, legata]. */
function sl53yRighe(Beta51Scenario $s): array
{
    $nomi = collect($s->persone)->mapWithKeys(fn ($a, string $chi) => [(int) $a->id => $chi])->all();

    return Saldo::whereNull('subentro_id')->where('immobile_id', $s->unita['A']->id)->orderBy('id')->get()
        ->map(fn (Saldo $x) => [$nomi[(int) $x->anagrafica_id] ?? null, (int) $x->saldo_iniziale, $x->passaggio_id !== null])->all();
}

it('righe miste — Ugo e Anna con i saldi di prima, non legati, che sono la loro parte, e Bruno con la riga legata: i saldi ci sono già, e non si registra niente due volte', function () {
    [$s, $succ] = sl53yCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    sl53yLegata($this, $s, 'bruno', 15781, $succ);

    $frase = sl53yFrase($s);

    expect(str_contains($frase, 'I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quello di Bruno legato alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.'))->toBeTrue($frase)
        ->and(str_contains($frase, 'registra dal Wallet'))->toBeFalse($frase)
        ->and(sl53yRighe($s))->toBe([['ugo', -39452, false], ['anna', 23671, false], ['bruno', 15781, true]]);
});

it('righe miste — Ugo con un riporto non legato, Anna senza niente, Bruno con la riga legata: il programma non sa del riporto di Ugo, chiede la riga di Anna e, al condizionale, quella di Ugo; seguendo la frase i saldi ci sono già', function () {
    [$s, $succ] = sl53yCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000);
    sl53yLegata($this, $s, 'bruno', 15781, $succ);

    $frase = sl53yFrase($s);

    expect(str_contains($frase, 'Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 236,71 a debito di Anna) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; il piano li assorbe alla prossima generazione. Quello di Bruno c\'è già.'))->toBeTrue($frase)
        ->and(str_contains($frase, 'a debito di Bruno'))->toBeFalse($frase);

    // Il riporto di Ugo non è la parte: si registrano la riga di Anna e quella di Ugo, legate.
    sl53yLegata($this, $s, 'anna', 23671, $succ);
    sl53yLegata($this, $s, 'ugo', -39452, $succ);
    $dopo = sl53yFrase($s);

    expect(str_contains($dopo, 'I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità'))->toBeTrue($dopo)
        ->and(sl53yRighe($s))->toBe([['ugo', 5000, false], ['bruno', 15781, true], ['anna', 23671, true], ['ugo', -39452, true]]);
});

it('una riga legata che non è la parte (Anna € 100,00 invece di € 236,71): il cancello chiede di correggerla, non di registrarla di nuovo, e dice che le altre ci sono già', function () {
    [$s, $succ] = sl53yCaso($this);
    sl53yLegata($this, $s, 'ugo', -39452, $succ);
    sl53yLegata($this, $s, 'anna', 10000, $succ);
    sl53yLegata($this, $s, 'bruno', 15781, $succ);

    $frase = sl53yFrase($s);

    expect(str_contains($frase, 'Per Anna il saldo legato alla successione è di € 100,00 a debito invece di € 236,71 a debito: correggilo dal Wallet. Quelli di Ugo e Bruno ci sono già.'))->toBeTrue($frase)
        ->and(str_contains($frase, 'registra dal Wallet'))->toBeFalse($frase)
        ->and(str_contains($frase, 'I saldi ci sono già'))->toBeFalse($frase);
});

it('senza righe legate, Ugo con un riporto e Anna e Bruno senza niente: il cancello chiede le righe di Anna e Bruno, e quella di Ugo solo se il riporto non comprende la parte', function () {
    [$s] = sl53yCaso($this);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 10000);

    $frase = sl53yFrase($s);

    expect(str_contains($frase, 'Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano (€ 236,71 a debito di Anna e € 157,81 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; il piano li assorbe alla prossima generazione.'))->toBeTrue($frase)
        ->and(str_contains($frase, 'se la comprendono non serve registrare altro'))->toBeFalse($frase);
});
