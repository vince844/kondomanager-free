<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.11.0-beta.51 — le frasi del conguaglio: la Coda 160 (il già versato della persona e dell'unità sullo stesso conto) e la Coda
 * 251-c (le frasi con un netto negativo).
 *
 * CODA 160. Con il già versato della persona **e** dell'unità sullo stesso conto il piano toglie prima la parte dell'unità, sui lordi, e poi quella di ciascuna
 * persona dalla sua riga già ridotta, come il conguaglio del passaggio: il conguaglio della vendita (piano generato ed emesso prima) e il piano generato DOPO la
 * vendita danno le stesse cifre, e il pannello non confronta le due strade.
 *   Il confronto, persona per persona: il netto (le quote pure del piano più le righe in saldi del passaggio) meno il già versato che avanza a suo nome
 *   (`getEccedenzeCopertura`). Dove il piano dopo si ferma a zero con un'eccedenza, il conguaglio dà un credito: è la stessa cifra.
 *   Scenario: «Spese generali» € 1.200,00 sul 100 % della tabella, tutto al proprietario; Ugo; già versato sul conto: Ugo € 300,00 e l'unità (senza persona)
 *   € 200,00; vendita Ugo → Elsa l'1/5.
 *   Conto a mano: giorni Ugo 120 (1/1–30/4), Elsa 245 (365 in tutto): lordi 120000 × 120/365 = 39452 e 80548.
 *   - conguaglio (piano emesso: 120000 − 20000 − 30000 = 70000 a Ugo): l'unità si toglie dal totale prima dei giorni (120000 − 20000 = 100000): Ugo 32877,
 *     Elsa 67123; poi la persona dalla parte di Ugo: 32877 − 30000 = 2877. Elsa € 671,23, Ugo € 28,77.
 *   - piano generato dopo: prima l'unità sui lordi, 20000 su [39452, 80548]: 6575,3 → 6575 e 13424,7 → 13425 (il resto): Ugo 32877, Elsa 67123; poi la
 *     persona sulla sua riga: Ugo 32877 − 30000 = 2877. Le stesse cifre.
 *
 * CODA 251-c. Una cifra negativa si dice con le parole («un credito di € 1.208,22»), mai «€ -1.208,22»; e «coprono più dei suoi giorni» si dice solo
 * se parte e bozze hanno lo stesso segno e le bozze superano la parte in valore assoluto.
 *   Scenario (misurato): Ugo proprietario al 100 %; «Spese generali» € 1.200,00 e «Affitto antenna» entrata € 3.000,00 sul 100 % del proprietario;
 *   piano generato ed emesso fino al 31/3; vendita Ugo → Elsa l'1/5; otto bozze (maggio–dicembre) passano a Elsa.
 *   Conto a mano: parte annua 120000 − 300000 = −180000; Elsa 245/365 → −180000 × 245/365 = −120821,92 → −120822; bozze che passano
 *   8 × (−15000) = −120000; netto −120822 − (−120000) = −822 (credito € 8,22 a Elsa). Le bozze (€ 1.200,00) coprono MENO della parte di Elsa
 *   (€ 1.208,22) in valore assoluto: «coprono più dei suoi giorni» è falso.
 *
 * Con la chiamata che è una parte della voce (acconto e saldo su due chiamate: il capitolo del piano forzato sotto l'importo del conto) il piano applica
 * una frazione del già versato, e le due strade la applicano uguale (O-160-ACCONTO).
 *
 * Che cosa NON copre: la vendita di una parte della quota di chi vende; la pagina Vue.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\QuotaDellaChiamata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/**
 * Lo scenario della Coda 160: la voce tutta al proprietario (o come si dice), nessun inquilino, il già versato sul conto di Ugo (`$persona`) e
 * dell'unità, senza persona (`$unita`), in centesimi. Il piano non è ancora generato.
 */
function b51aVersato(?int $persona, ?int $unita, array $ripartizione = ['proprietario' => 100], array $inquilini = [], bool $conPulizie = false): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['ripartizione' => $ripartizione, 'inquilini' => $inquilini]);
    b51aPersone($s, ['Elsa']);
    // Una seconda voce senza già versato, tutta al proprietario: con la prima interamente coperta fa nascere comunque delle rate da conguagliare.
    if ($conPulizie) {
        b51aCapitolo($s, 'Pulizie', 'spesa', 60000, null, null, ['proprietario' => 100]);
    }
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    foreach ([[$persona, $s['persone']['Ugo']->id], [$unita, null]] as [$importo, $chi]) {
        if ($importo) {
            DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $conto->id,
                'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    return $s;
}

/** L'anteprima della vendita dell'1/5 con il piano generato ed emesso prima (la strada del conguaglio). */
function b51aVendita160($test, ?int $persona, ?int $unita, array $ripartizione = ['proprietario' => 100], array $inquilini = [], bool $conPulizie = false, string $vende = 'Elsa'): array
{
    $s = b51aVersato($persona, $unita, $ripartizione, $inquilini, $conPulizie);
    fclGenera($s);
    fclEmetti($test, $s, '2026-12-31');

    return [$s, fclAnteprima($test, $s, fds50ModuloVendita($s, 'Ugo', $vende, '2026-05-01'))];
}

/** I netti per persona del piano generato DOPO la vendita (la strada del motore): lo stesso scenario, la vendita registrata e poi il piano. */
function b51aNettiDelPianoDopo($test, ?int $persona, ?int $unita, array $ripartizione = ['proprietario' => 100], array $inquilini = [], bool $conPulizie = false): array
{
    $s = b51aVersato($persona, $unita, $ripartizione, $inquilini, $conPulizie);
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));
    fclGenera($s);

    return fclNetti($s);
}

/** Il già versato che avanza a nome di ciascuno (le persone e «unità») sul piano dello scenario, come lo dice il motore, in centesimi. */
function b51aAvanzi(array $s): array
{
    $motore = new CalcoloQuoteService();
    $motore->calcolaPerGestione($s['g'], $s['piano']->fresh(), false, app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['e']));
    $nomi = fclNomi($s);
    $out = [];
    foreach ($motore->getEccedenzeCopertura() as $e) {
        $chi = isset($e['anagrafica_id']) ? $nomi[(int) $e['anagrafica_id']] : 'unità';
        $out[$chi] = ($out[$chi] ?? 0) + (int) $e['eccedenza'];
    }
    ksort($out);

    return $out;
}

/**
 * Le due strade sullo stesso scenario (`$scenario` lo crea daccapo): il conguaglio (piano generato ed emesso, poi la vendita registrata) e il piano generato
 * dopo la vendita registrata. Per ognuna i netti per persona e il già versato avanzato; e l'anteprima della prima, con le coppie e le frasi del pannello.
 *
 * @return array{coppie: list<array>, frasi: list<string>, conguaglio: array<string,int>, avanzo_conguaglio: array<string,int>, dopo: array<string,int>, avanzo_dopo: array<string,int>}
 */
function b51aDueStrade($test, Closure $scenario, string $compra = 'Elsa', float|int $quota = 100): array
{
    $s = $scenario();
    fclGenera($s);
    fclEmetti($test, $s, '2026-12-31');
    $avanzoEmesso = b51aAvanzi($s);
    $modulo = fds50ModuloVendita($s, 'Ugo', $compra, '2026-05-01', $quota);
    $anteprima = fclAnteprima($test, $s, $modulo);
    fds50Registra($test, $s, $modulo);

    $poi = $scenario();
    fds50Registra($test, $poi, fds50ModuloVendita($poi, 'Ugo', $compra, '2026-05-01', $quota));
    fclGenera($poi);

    return ['coppie' => fclCoppie($anteprima), 'frasi' => $anteprima['rate']['conguaglio']['frasi'], 'unita' => (int) $s['unita']->id,
        'conguaglio' => fclNetti($s), 'avanzo_conguaglio' => $avanzoEmesso, 'dopo' => fclNetti($poi), 'avanzo_dopo' => b51aAvanzi($poi)];
}

/** Il conguaglio e il piano dopo danno a ciascuno la stessa cifra (netto meno il già versato avanzato a suo nome), entro il centesimo dell'arrotondamento. */
function b51aCoincidono(array $d): void
{
    $nomi = array_unique(array_merge(array_keys($d['conguaglio']), array_keys($d['avanzo_conguaglio']), array_keys($d['dopo']), array_keys($d['avanzo_dopo'])));
    foreach ($nomi as $nome) {
        $conguaglio = ($d['conguaglio'][$nome] ?? 0) - ($d['avanzo_conguaglio'][$nome] ?? 0);
        $dopo = ($d['dopo'][$nome] ?? 0) - ($d['avanzo_dopo'][$nome] ?? 0);
        expect(abs($conguaglio - $dopo))->toBeLessThanOrEqual(1, "Le due strade non coincidono per {$nome}: conguaglio {$conguaglio}, piano dopo {$dopo}.");
    }
}

/** Il pannello non confronta il conguaglio con un piano generato dopo il passaggio. */
function b51aNessunConfronto(array $frasi): void
{
    $confronti = array_values(array_filter($frasi, fn (string $t) => str_contains($t, 'differenza') || str_contains($t, 'piano generato dopo') || str_contains($t, 'vale il conguaglio')));
    expect($confronti)->toBe([], 'Il pannello confronta il conguaglio con un piano generato dopo: ' . implode(' | ', $confronti));
}

// --- Coda 160 ---------------------------------------------------------------------------------------------------------------------------------------

it('O-160 — con € 300,00 versati da Ugo e € 200,00 dall\'unità il conguaglio (Elsa € 671,23, Ugo € 28,77) e il piano generato dopo la vendita danno le stesse cifre, e il pannello non le confronta', function () {
    // Il conto a mano è in testa al file.
    $d = b51aDueStrade($this, fn () => b51aVersato(30000, 20000));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 67123]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 67123, 'Ugo' => 2877])
        ->and($d['dopo'])->toBe(['Elsa' => 67123, 'Ugo' => 2877])
        ->and($d['avanzo_conguaglio'])->toBe([])
        ->and($d['avanzo_dopo'])->toBe([]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

/** Il conguaglio e il piano generato dopo la vendita, per un caso della 160: [anteprima, netti del piano dopo, Elsa nel conguaglio, frasi del conguaglio]. */
function b51aConfrontoDelVersato($test, ?int $persona, ?int $unita): array
{
    [, $a] = b51aVendita160($test, $persona, $unita);

    return [$a, b51aNettiDelPianoDopo($test, $persona, $unita), fclCoppie($a)[0][2], $a['rate']['conguaglio']['frasi']];
}

it('sentinella — O-160-uno: con una parte sola del già versato (nessuno, solo la persona, solo l\'unità) le due strade coincidono al centesimo e non c\'è una frase che le confronta', function (?int $persona, ?int $unita) {
    // Fonte: SF6 e misura su TEST: con una parte sola il conguaglio e il piano generato dopo danno Elsa € 805,48 (solo la persona, Ugo € 94,52), € 671,23 (solo
    // l'unità, Ugo € 328,77), € 805,48 e Ugo € 394,52 (nessun versato).
    [, $nettiDopo, $elsaConguaglio, $frasi] = b51aConfrontoDelVersato($this, $persona, $unita);

    expect(abs($elsaConguaglio - ($nettiDopo['Elsa'] ?? 0)))->toBeLessThanOrEqual(1)
        ->and(collect($frasi)->contains(fn ($t) => str_contains($t, 'differenza')))->toBeFalse('C\'è una frase di confronto dove le due strade coincidono: ' . implode(' | ', $frasi));
})->with([
    'nessun versato' => [null, null],
    'solo la persona (€ 300,00)' => [30000, null],
    'solo l\'unità (€ 200,00)' => [null, 20000],
]);

it('O-160-PERSONA — il già versato di Ugo (€ 500,00) supera la sua parte: il conguaglio gli dà un credito di € 171,23 e il piano dopo lo lascia a zero con € 171,23 avanzati a suo nome; Elsa € 671,23 in tutti e due', function () {
    // Conto a mano. Piano emesso: l'unità 20000 (120000 → 100000), poi Ugo 50000: rate di 50000.
    //   - conguaglio: (120000 − 20000) × 245/365 = 67123,3 → Elsa 67123; Ugo 50000 − 67123 = −17123.
    //   - piano dopo: l'unità sui lordi [39452, 80548] → 6575 e 13425: Ugo 32877, Elsa 67123; Ugo 32877 − 50000 → 0, e 17123 avanzano a suo nome.
    $d = b51aDueStrade($this, fn () => b51aVersato(50000, 20000));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 67123]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 67123, 'Ugo' => -17123])
        ->and($d['dopo'])->toBe(['Elsa' => 67123])
        ->and($d['avanzo_conguaglio'])->toBe([])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 17123]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('O-160-MISTA — la voce divisa 30 % inquilino e 70 % proprietario, senza un inquilino: le due strade danno le cifre della voce tutta al proprietario (Elsa € 671,23, Ugo € 28,77)', function () {
    // Conto a mano: senza un inquilino la parte del 30 % ricade sul proprietario di quei giorni (ripiego), quindi le righe di Ugo e di Elsa sommano come
    // nella voce tutta al proprietario: 39452 e 80548 nel piano dopo; nel conguaglio 30000 × 245/365 + 70000 × 245/365 = 20137 + 46986 = 67123.
    $d = b51aDueStrade($this, fn () => b51aVersato(30000, 20000, ['inquilino' => 30, 'proprietario' => 70], []));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 67123]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 67123, 'Ugo' => 2877])
        ->and($d['dopo'])->toBe(['Elsa' => 67123, 'Ugo' => 2877])
        ->and($d['avanzo_dopo'])->toBe([]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('O-160-MISTA — con Ines inquilina per tutto l\'anno: Elsa € 469,86 nel conguaglio e € 469,87 nel piano dopo, Ugo a credito di € 69,86 (avanzo di € 69,87), e Ines paga € 300,00 in tutti e due', function () {
    // Conto a mano (voce 30 % inquilino = 36000 a Ines, 70 % proprietario = 84000).
    //   Piano emesso: l'unità 20000 sui lordi [84000, 36000] → 14000 e 6000: Ugo 70000, Ines 30000; poi Ugo 70000 − 30000 = 40000.
    //   - conguaglio: 70000 × 245/365 = 46986,3 → Elsa 46986; Ugo 40000 − 46986 = −6986.
    //   - piano dopo: lordi Ugo 84000 × 120/365 = 27616, Elsa 56384, Ines 36000. L'unità 20000: 4602,7, 9397,3 e 6000, il resto a Ugo → 4603, 9397, 6000:
    //     Ugo 23013, Elsa 46987, Ines 30000; Ugo 23013 − 30000 → 0, e 6987 avanzano. Il centesimo di scarto è dell'arrotondamento.
    $d = b51aDueStrade($this, fn () => b51aVersato(30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]]));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 46986]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 46986, 'Ines' => 30000, 'Ugo' => -6986])
        ->and($d['dopo'])->toBe(['Elsa' => 46987, 'Ines' => 30000])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 6987]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

/** Lo scenario dell'acconto: la voce da € 1.200,00 con il capitolo del piano forzato a `$capitolo` (una chiamata che ne chiede una parte), già versato Ugo € 300,00 e l'unità € 200,00. */
function b51aVersatoAcconto(int $capitolo = 60000): array
{
    $s = b51aVersato(30000, 20000);
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$conto->id => ['importo' => $capitolo]]);

    return $s;
}

it('O-160-ACCONTO — il piano chiede metà della voce (capitolo a € 600,00 su € 1.200,00): il già versato che applica è la metà (Ugo € 150,00, unità € 100,00), e le due strade danno Elsa € 335,62 e Ugo € 14,38', function () {
    // Conto a mano: la quota della chiamata è 60000/120000 = 0,5: il piano applica 10000 dell'unità e 15000 della persona. Emesso: 60000 − 10000 − 15000 = 35000.
    // Vendita Ugo → Elsa l'1/5, giorni 120 e 245: lordi 60000 × 120/365 = 19726 e 40274.
    //   - conguaglio: (60000 − 10000) × 245/365 = 33561,6 → Elsa 33562; Ugo 35000 − 33562 = 1438.
    //   - piano dopo: l'unità sui lordi, 10000 su [19726, 40274] → 3287,7 e 6712,3, il resto a Ugo → 3288 e 6712: Ugo 16438, Elsa 33562;
    //     poi Ugo 16438 − 15000 = 1438.
    $d = b51aDueStrade($this, fn () => b51aVersatoAcconto());

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 33562]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 33562, 'Ugo' => 1438])
        ->and($d['dopo'])->toBe(['Elsa' => 33562, 'Ugo' => 1438])
        ->and(collect($d['frasi'])->first(fn ($t) => str_contains($t, 'già versati da Ugo')))->toContain('€ 150,00 già versati da Ugo restano suoi');
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('O-160-ACCONTO — il capitolo forzato all\'importo del conto è l\'intera voce: le due strade sono quelle di sempre, con il già versato intero (Elsa € 671,23, Ugo € 28,77)', function (int $capitolo) {
    $d = b51aDueStrade($this, fn () => b51aVersatoAcconto($capitolo));

    expect($d['conguaglio'])->toBe(['Elsa' => 67123, 'Ugo' => 2877])
        ->and($d['dopo'])->toBe(['Elsa' => 67123, 'Ugo' => 2877]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
})->with(['uguale' => [120000]]);

it('O-160-ACCONTO — la frazione della chiamata: 1 senza un importo forzato più basso, metà con il capitolo a metà, e nessuna risposta dove non si può dire: piano non generato, o capitolo padre diviso fra i sottoconti in un modo che non è la loro somma', function () {
    $s = b51aVersato(30000, 20000);
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $dellaChiamata = fn (int $contoId) => QuotaDellaChiamata::perConto((int) $s['piano']->id, $contoId);

    // Senza un importo forzato diverso: l'intera voce. Con il capitolo a metà: 0 distribuito finché il piano non è generato (non si può dire).
    expect($dellaChiamata((int) $conto->id))->toBe(1.0);
    $s['piano']->capitoli()->syncWithoutDetaching([$conto->id => ['importo' => 60000]]);
    expect($dellaChiamata((int) $conto->id))->toBeNull();
    fclGenera($s);
    expect($dellaChiamata((int) $conto->id))->toBe(0.5);

    // Un sottoconto del capitolo forzato: vale 1 solo se l'importo forzato è la somma dei sottoconti, altrimenti non si dice.
    $figlio = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $conto->id, 'nome' => 'Voce figlia', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 100000]);
    expect($dellaChiamata((int) $figlio->id))->toBeNull();
    $s['piano']->capitoli()->syncWithoutDetaching([$conto->id => ['importo' => 100000]]);
    expect($dellaChiamata((int) $figlio->id))->toBe(1.0);
});

it('O-160-TERZI — Ugo e Anna al 50 %, Ugo vende la sua metà a Elsa l\'1/5 (€ 300,00 di Ugo, € 200,00 dell\'unità): Anna paga € 500,00 nelle due strade, Elsa € 335,61 nel conguaglio e € 335,62 nel piano dopo, Ugo è a credito di € 135,61', function () {
    // Conto a mano: la voce vale 120000, 60000 a testa, divisa 30 % inquilino e 70 % proprietario senza un inquilino (ripiego sul proprietario): due righe a testa,
    // 18000 e 42000. Piano emesso: l'unità 20000 su [60000, 60000] → 10000 e 10000: Ugo 50000, Anna 50000; poi Ugo 20000.
    //   - conguaglio: voce per voce, i 10000 dell'unità di Ugo sulle sue due righe (3000 e 7000): 15000 × 245/365 = 10068,5 → 10068 e 35000 × 245/365 =
    //     23493,2 → 23493: Elsa 33561; Ugo 20000 − 33561 = −13561. Il centesimo di scarto col piano dopo è dell'arrotondamento.
    //   - piano dopo: lordi Ugo 19726, Elsa 40274, Anna 60000; l'unità 20000: 3287,7, 6712,3 e 10000, il resto a Ugo → 3288, 6712, 10000:
    //     Ugo 16438, Elsa 33562, Anna 50000; Ugo 16438 − 30000 → 0, e 13562 avanzano a suo nome.
    $scenario = function (): array {
        $s = fclScenario([['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], ['inquilini' => []]);
        b51aPersone($s, ['Elsa']);
        $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
        $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
        foreach ([[30000, $s['persone']['Ugo']->id], [20000, null]] as [$importo, $chi]) {
            DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $conto->id,
                'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione',
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $s;
    };
    $d = b51aDueStrade($this, $scenario, 'Elsa', 50);

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 33561]])
        ->and($d['conguaglio'])->toBe(['Anna' => 50000, 'Elsa' => 33561, 'Ugo' => -13561])
        ->and($d['dopo'])->toBe(['Anna' => 50000, 'Elsa' => 33562])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 13562]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('sentinella — O-160-ECCESSO: con il già versato dell\'unità (€ 1.300,00) maggiore dell\'intera spesa (€ 1.200,00) la spesa è già coperta, nessuna rata è emessa e il piano dopo non dà niente: non c\'è un conguaglio né un confronto', function (?int $persona) {
    // Conto a mano: la copertura applicata è al più la spesa (120000), le righe vanno a zero e non nasce nessuna quota: né il conguaglio né il piano
    // generato dopo la vendita hanno una cifra, e le due strade coincidono. L'eccedenza (€ 100,00) è denaro dell'unità.
    [, $a] = b51aVendita160($this, $persona, 130000);

    expect(fclCoppie($a))->toBe([])
        ->and(b51aNettiDelPianoDopo($this, $persona, 130000))->toBe([]);
    b51aNessunConfronto($a['rate']['conguaglio']['frasi'] ?? []);
})->with(['senza il versato della persona' => [null], 'con € 300,00 della persona' => [30000]]);

it('O-160-ECCESSO — con la spesa coperta dal già versato (Ugo € 300,00, unità € 1.300,00) e una seconda voce da € 600,00: le due strade danno Elsa € 402,74 e Ugo € 197,26, con gli stessi avanzi', function () {
    // Conto a mano: l'unità assorbe tutti i 120000 della prima voce (avanzano 10000 all'unità) e a Ugo non resta niente da togliere: i suoi 30000 avanzano.
    // La prima voce non ha rata né conguaglio; restano le Pulizie, 60000: Elsa 60000 × 245/365 = 40274, Ugo 19726, nel conguaglio e nel piano dopo.
    $d = b51aDueStrade($this, fn () => b51aVersato(30000, 130000, conPulizie: true));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 40274]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 40274, 'Ugo' => 19726])
        ->and($d['dopo'])->toBe(['Elsa' => 40274, 'Ugo' => 19726])
        ->and($d['avanzo_conguaglio'])->toBe(['Ugo' => 30000, 'unità' => 10000])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 30000, 'unità' => 10000]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('O-160-ECCESSO — il già versato di Ugo (€ 500,00) supera la spesa che l\'unità (€ 1.000,00) lascia: le due strade danno Elsa € 536,99, e il credito di Ugo del conguaglio è l\'avanzo del piano dopo', function () {
    // Conto a mano. Piano emesso: l'unità 100000 sulla prima voce (120000 → 20000), poi di Ugo se ne applicano 20000 e 30000 avanzano; restano le Pulizie, 60000 a Ugo.
    //   - conguaglio: prima voce (120000 − 100000) × 245/365 = 13424,7 → Elsa 13425; Pulizie 40274: Elsa 53699, Ugo 60000 − 53699 = 6301 (con 30000 avanzati).
    //   - piano dopo: l'unità 100000 su [39452, 80548] → 32877 e 67123: Ugo 6575, Elsa 13425; Ugo 6575 − 50000 → 0, e 43425 avanzano. Pulizie: Elsa 40274,
    //     Ugo 19726. Elsa 53699, Ugo 19726.
    //   Per Ugo: 6301 − 30000 = −23699 nel conguaglio, 19726 − 43425 = −23699 nel piano dopo.
    $d = b51aDueStrade($this, fn () => b51aVersato(50000, 100000, conPulizie: true));

    expect($d['coppie'])->toBe([[$d['unita'], 'Elsa', 53699]])
        ->and($d['conguaglio'])->toBe(['Elsa' => 53699, 'Ugo' => 6301])
        ->and($d['dopo'])->toBe(['Elsa' => 53699, 'Ugo' => 19726])
        ->and($d['avanzo_conguaglio'])->toBe(['Ugo' => 30000])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 43425]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

it('O-160-COMPRA-L-INQUILINO — chi compra è già sul piano (Ines, inquilina per tutto l\'anno): le sue righe di prima e di dopo si fondono in una, e le due strade danno le stesse cifre entro il centesimo', function () {
    // Conto a mano: piano emesso Ugo 40000, Ines 30000 (come sopra con Ines inquilina). Conguaglio: Ines 70000 × 245/365 = 46986 → Ines 76986, Ugo −6986.
    //   Piano dopo: Ines 36000 + 56384 = 92384, Ugo 27616; l'unità 20000: 4602,7 e 15397,3, il resto a Ugo → 4603 e 15397: Ugo 23013, Ines 76987;
    //   Ugo 23013 − 30000 → 0, e 6987 avanzano.
    $d = b51aDueStrade($this, fn () => b51aVersato(30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]]), 'Ines');

    expect($d['conguaglio'])->toBe(['Ines' => 76986, 'Ugo' => -6986])
        ->and($d['dopo'])->toBe(['Ines' => 76987])
        ->and($d['avanzo_dopo'])->toBe(['Ugo' => 6987]);
    b51aCoincidono($d);
    b51aNessunConfronto($d['frasi']);
});

// --- Coda 251-c -------------------------------------------------------------------------------------------------------------------------------------

/** Lo scenario della 251-c: la voce tutta al proprietario e l'antenna in entrata di € 3.000,00 (sul 100 % del proprietario), piano emesso fino al 31/3. */
function b51aEntrataGrande($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['ripartizione' => ['proprietario' => 100], 'inquilini' => []]);
    b51aPersone($s, ['Elsa', 'Anna', 'Bruno']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 300000, null, null, ['proprietario' => 100]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-03-31');

    return $s;
}

it('O-251C — vendita con un netto negativo: il credito di Elsa è € 8,22, e le cifre negative si dicono con le parole («un credito di € 1.208,22»), senza «€ -» e senza «coprono più dei suoi giorni»', function () {
    $s = b51aEntrataGrande($this);
    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));

    // La coppia non cambia: Elsa −822 (€ 8,22 di credito).
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', -822]]);
    $conguaglio = implode(' ', $a['rate']['conguaglio']['frasi']);
    expect(b51aFrasiConMenoEuro(['c' => $a['rate']['conguaglio']['frasi'], 'r' => $a['rate']['frasi']]))->toBe([], 'Il conguaglio dice una cifra con «€ -».')
        ->and(preg_match('/credito di € 1\.208,22/u', $conguaglio))->toBe(1, 'Il conguaglio non dice «un credito di € 1.208,22»: ' . $conguaglio)
        ->and(preg_match('/credito di € 1\.200,00/u', $conguaglio))->toBe(1, 'Il conguaglio non dice «un credito di € 1.200,00» per le bozze: ' . $conguaglio)
        ->and(str_contains($conguaglio, '€ 8,22'))->toBeTrue('Il conguaglio non dice il credito di € 8,22: ' . $conguaglio)
        // Le bozze (€ 1.200,00) coprono meno della parte (€ 1.208,22) in valore assoluto: «coprono più dei suoi giorni» sarebbe falso.
        ->and(str_contains($conguaglio, 'coprono più dei suoi giorni'))->toBeFalse('Il conguaglio dice «coprono più dei suoi giorni» con le bozze che coprono meno: ' . $conguaglio);
});

it('O-251C-EREDI — successione di Ugo il 1/5 ad Anna e Bruno al 50 % con la parte negativa: nessun «€ -» e nessun «coprono più dei giorni degli eredi»; le sostituzioni di testo restano valide («che passano ad Anna», «la divisione per erede»)', function () {
    // Fonte: stessa scena di O-251C con la parte degli eredi −120822 (€ -1.208,22, divisa per quota: € 604,11 ad Anna e a Bruno) e le bozze
    // −120000 che vanno ad Anna. Le sostituzioni di `AnteprimaPassaggio` (r.1776 str_replace, r.1780–1788 strtr, r.2569–2571 fatti e calcolo
    // per prefisso) leggono le sottostringhe: le frasi nuove le mantengono.
    $s = b51aEntrataGrande($this);
    $a = fclAnteprima($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-05-01'));
    $conguaglio = implode(' ', $a['rate']['conguaglio']['frasi']);

    expect(b51aFrasiConMenoEuro(['c' => $a['rate']['conguaglio']['frasi'], 'r' => $a['rate']['frasi']]))->toBe([], 'Il conguaglio della successione dice una cifra con «€ -».')
        ->and(str_contains($conguaglio, '1.208,22'))->toBeTrue('Non dice la parte degli eredi (€ 1.208,22): ' . $conguaglio)
        ->and(str_contains($conguaglio, '604,11'))->toBeTrue('Non dice la parte di ciascun erede (€ 604,11): ' . $conguaglio)
        ->and(str_contains($conguaglio, 'coprono più dei giorni degli eredi'))->toBeFalse('Dice «coprono più dei giorni degli eredi» con le bozze che coprono meno: ' . $conguaglio)
        ->and(str_contains($conguaglio, 'che passano ad Anna'))->toBeTrue('La sostituzione «che passano ad Anna» non c\'è più: ' . $conguaglio)
        ->and(str_contains($conguaglio, 'la divisione per erede'))->toBeTrue('Manca «la divisione per erede»: ' . $conguaglio);
});

it('O-251C-LETTORI — il legato (un solo destinatario, Anna) con la parte negativa: nessun «€ -», la parte è «di Anna» (strtr del legato), non «degli eredi»', function () {
    $s = b51aEntrataGrande($this);
    $modulo = fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-05-01');
    $modulo['sottotipo'] = Subentro::LEGATO;
    $modulo['eredi'] = [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 100]];
    $modulo['erede_di_riferimento'] = null;
    $a = fclAnteprima($this, $s, $modulo);
    $conguaglio = implode(' ', $a['rate']['conguaglio']['frasi']);

    expect(b51aFrasiConMenoEuro(['c' => $a['rate']['conguaglio']['frasi'], 'r' => $a['rate']['frasi']]))->toBe([], 'Il conguaglio del legato dice una cifra con «€ -».')
        ->and(str_contains($conguaglio, 'la parte di Anna'))->toBeTrue('Il legato non dice «la parte di Anna»: ' . $conguaglio)
        ->and(str_contains($conguaglio, 'degli eredi'))->toBeFalse('Il legato parla ancora «degli eredi»: ' . $conguaglio)
        ->and(str_contains($conguaglio, 'coprono più dei suoi giorni'))->toBeFalse('Il legato dice «coprono più dei suoi giorni» con le bozze che coprono meno: ' . $conguaglio);
});

it('O-251C-GUARDIA — in nessuna scena con una voce di entrata (vendita, successione, legato, cambio d\'inquilino con «Scrivi» e «Non scriverlo», fine di una locazione, inizio) una frase del pannello, dello storico o degli avvisi dice una cifra con «€ -»', function () {
    // Fonte: misurato su TEST prima della correzione: 26 frasi con «€ -» nelle sole anteprime (le sei frasi della quota ordinaria, le bozze che
    // passano, «credito € -209,26 a Ines, debito € -209,26 a Marco», le righe «· Affitto antenna: € -300,00 … → € -300,00 a chi entra»). Lo storico e gli
    // avvisi non ne avevano. Si contano le stringhe di almeno quattro parole: i campi con la sola cifra formattata non sono frasi.
    $trovate = [];

    // 1. vendita, successione e legato su un netto negativo.
    foreach (['vendita', 'successione', 'legato'] as $tipo) {
        $s = b51aEntrataGrande($this);
        $modulo = $tipo === 'vendita' ? fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01')
            : fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-05-01');
        if ($tipo === 'legato') {
            $modulo['sottotipo'] = Subentro::LEGATO;
            $modulo['eredi'] = [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 100]];
            $modulo['erede_di_riferimento'] = null;
        }
        $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fclAnteprima($this, $s, $modulo), "{$tipo}.anteprima")];
        if ($tipo === 'vendita') {
            $registrato = fds50Registra($this, $s, $modulo)['subentro'];
            $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fds50Storico($s, $registrato), 'vendita.storico')];
        }
    }

    // 2. il cambio d'inquilino con l'antenna, con «Scrivi» e con «Non scriverlo».
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-09-30');
    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fclAnteprima($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-10-01') + ['conguaglio' => $scelta]), "cambio.{$scelta}")];
    }
    $cambio = fclRegistra($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-10-01') + ['conguaglio' => 'scrivi']);
    $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fds50Storico($s, $cambio), 'cambio.storico')];

    // 3. la fine di una locazione, l'inizio dopo, gli avvisi del conguaglio e dell'annullamento.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fclAnteprima($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']), 'fine.anteprima')];
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')), 'inizio.anteprima')];
    $luca = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))['subentro'];
    $trovate = [...$trovate, ...b51aFrasiConMenoEuro(fds50Storico($s, $fine), 'fine.storico'), ...b51aFrasiConMenoEuro(fds50Storico($s, $luca), 'inizio.storico'),
        ...b51aFrasiConMenoEuro(['avvisi' => b51aAvvisiDelConguaglio($fine)], 'fine.avvisiDelConguaglio')];

    expect(array_values(array_unique($trovate)))->toBe([], count($trovate) . ' frasi dicono una cifra con «€ -» (' . count(array_unique($trovate)) . ' diverse).');
});

// --- Il sostantivo del passaggio nelle frasi: una funzione sola ---------------------------------------------------------------------------------------

/**
 * Il sostantivo con cui il pannello (gli avvisi di «Annulla il conguaglio» della fine, che usano `FermataDopoScrivi::passaggioInParole`) e il
 * prospetto (il testo `oppure` delle voci dei giorni senza conduttore, che usa la copia di `ProspettoOneriAccessori`) dicono il passaggio che
 * si è fermato: «la vendita», «il legato», «la fine della locazione di Luca». Le due copie devono dire le stesse parole: il prospetto
 * dice «il legato» per un legato, e conosce la fine di un conduttore.
 *
 * @return array{0: ?string, 1: ?string} [pannello, prospetto]
 */
function b51aSostantivi($test, string $tipo): array
{
    if ($tipo === 'fine_locazione') {
        $s = b51aDl7($test);
        $s['vendita'] = b51aVendi($test, $s, 'Ugo', 'Elsa', '2026-03-12');
        b51aRegistraFine($test, $s, 'Luca', '2026-04-18');
        $fine = $s['fine_ines'];
    } else {
        $s = fds50Scenario($tipo === 'estinzione' ? [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]] : [['Ugo', 'proprietario', 100]]);
        fds50Genera($s);
        fds50Emetti($test, $s, '2026-06-30');
        $fine = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
        fds50Emetti($test, $s, '2026-09-30');
        $modulo = match ($tipo) {
            'vendita' => fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'),
            'costituzione' => fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'),
            'estinzione' => fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'),
            'successione' => fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'),
            'legato' => ['tipo' => 'successione', 'sottotipo' => Subentro::LEGATO, 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'proprietario'),
                'decorrenza' => '2026-10-01', 'quota' => 100, 'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 100]], 'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO,
                'erede_di_riferimento' => null, 'copia_autentica' => false, 'estremi_titolo' => 'testamento pubblicato, rep. 55', 'pertinenze' => [], 'conguaglio' => 'scrivi'],
        };
        fds50Registra($test, $s, $modulo);
    }
    $avvisi = fds50Storico($s, $fine)['conguaglio']['avvisi'] ?? [];
    $pannello = null;
    foreach ($avvisi as $a) {
        if (preg_match('/^(.+?) del \d{1,2} \p{L}+ \d{4} si è fermat[oa]/u', $a, $m) === 1) {
            $pannello = lcfirst($m[1]);
            break;
        }
    }
    $prospetto = null;
    foreach (b51aProspetto($s)['senza_conduttore']['voci'] as $v) {
        if (preg_match('/che (.+?) del \d{2}\/\d{2}\/\d{4} non ha spostato/u', (string) ($v['oppure'] ?? ''), $m) === 1) {
            $prospetto = lcfirst($m[1]);
            break;
        }
    }

    return [$pannello, $prospetto];
}

it('sentinella — O-DL7-FRASI (SF11): pannello e prospetto dicono lo stesso passaggio per vendita, costituzione d\'usufrutto, estinzione e successione', function (string $tipo, string $atteso) {
    // Fonte: S6. Il pannello ha la copia con la data, la riserva e il legato; il prospetto una copia più povera. Per questi quattro tipi coincidono già.
    [$pannello, $prospetto] = b51aSostantivi($this, $tipo);

    expect($pannello)->toBe($atteso, "Il pannello dice «{$pannello}» per {$tipo}.")
        ->and($prospetto)->toBe($atteso, "Il prospetto dice «{$prospetto}» per {$tipo}, il pannello «{$pannello}».");
})->with([
    'vendita' => ['vendita', 'la vendita'],
    'costituzione d\'usufrutto' => ['costituzione', 'la costituzione d\'usufrutto'],
    'estinzione dell\'usufrutto' => ['estinzione', 'l\'estinzione dell\'usufrutto'],
    'successione' => ['successione', 'la successione'],
]);

it('O-DL7-FRASI (SF11) — concordanza del sostantivo: pannello e prospetto dicono lo stesso passaggio per il legato («il legato», non «la successione») e per la fine di un conduttore', function (string $tipo, string $atteso) {
    // Fonte: S6. Una funzione sola, `FermataDopoScrivi::passaggioInParole` resa pubblica: il prospetto dice «la successione» per un legato e non conosce
    // la fine di un conduttore.
    [$pannello, $prospetto] = b51aSostantivi($this, $tipo);

    expect($pannello)->toBe($atteso, "Il pannello dice «{$pannello}» per {$tipo}.")
        ->and($prospetto)->toBe($atteso, "Il prospetto dice «{$prospetto}» per {$tipo}, il pannello «{$pannello}».");
})->with([
    'legato' => ['legato', 'il legato'],
    'fine della locazione di un conduttore' => ['fine_locazione', 'la fine della locazione di Luca'],
]);
