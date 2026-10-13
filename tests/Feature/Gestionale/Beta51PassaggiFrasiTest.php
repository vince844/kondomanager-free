<?php

/**
 * 1.11.0-beta.51 — le frasi del conguaglio: la Coda 160 (il già versato della persona e dell'unità sullo stesso conto) e la Coda
 * 251-c (le frasi con un netto negativo).
 *
 * CODA 160. Dove il già versato è sia della persona sia dell'unità sullo stesso conto, il conguaglio della vendita e il piano generato
 * DOPO il passaggio danno due cifre diverse, e nessuno lo dice. La frase del conguaglio deve dire le due cifre (quella del conguaglio e quella
 * che darebbe un piano generato dopo il passaggio) e la differenza. Con una parte sola (solo la persona, solo l'unità) le due strade
 * coincidono al centesimo e la frase non c'è. Le frasi di confronto, oltre al caso base: il già versato di Ugo maggiore della sua parte lorda
 * (il conguaglio gli dà un credito e il piano dopo si ferma alla sua parte, O-160-PERSONA); la voce divisa fra proprietario e inquilino, con e
 * senza un inquilino (O-160-MISTA); il già versato dell'unità maggiore della spesa che resta, con una seconda voce che fa nascere delle rate
 * (O-160-ECCESSO). Con l'unità che copre da sola l'intera spesa non nasce nessuna rata e non c'è niente da confrontare.
 *   Scenario (misurato su TEST 591b2c11): «Spese generali» € 1.200,00 sul 100 % della tabella, tutto al proprietario; Ugo; piano generato ed
 *   emesso fino al 31/12; già versato sul conto: Ugo € 300,00 e l'unità (senza persona) € 200,00; vendita Ugo → Elsa l'1/5.
 *   Conto a mano: giorni Ugo 120 (1/1–30/4), Elsa 245 (365 in tutto): lordi 120000 × 120/365 = 39452 e 80548.
 *   - conguaglio: l'unità si toglie dal totale prima dei giorni (120000 − 20000 = 100000): Ugo 32877, Elsa 67123; poi la persona dalla parte
 *     di Ugo: 32877 − 30000 = 2877. Elsa € 671,23, Ugo € 28,77.
 *   - piano generato dopo: prima la persona sulla sua riga (Ugo 39452 − 30000 = 9452; Elsa 80548), poi l'unità in proporzione alle righe già
 *     ridotte: 20000 su [9452, 80548] (somma 90000): Ugo 2100,4 → 2100, Elsa 17899,6 → 17900 (il resto): Ugo 9452 − 2100 = 7352, Elsa
 *     80548 − 17900 = 62648. Elsa € 626,48, Ugo € 73,52. Differenza 67123 − 62648 = 4475 = € 44,75.
 *   Il programma `CalcoloQuoteService::nettingGiaVersato` fa il secondo conto (privato): la frase ne è una SECONDA FONTE dichiarata, e il
 *   test differenziale qui sotto la confronta con il piano generato davvero dopo la vendita (entro un centesimo).
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
 * una frazione del già versato, e la frase applica la stessa (O-160-ACCONTO). Se la frazione non si può dire, la frase tace.
 *
 * Che cosa NON copre: la vendita di una parte della quota, dove le due strade non sono quelle qui descritte e la frase di confronto non le riconosce;
 * la pagina Vue.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
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

function b51aEuro(int $centesimi): string
{
    return '€ ' . number_format(abs($centesimi) / 100, 2, ',', '.');
}

// --- Coda 160 ---------------------------------------------------------------------------------------------------------------------------------------

it('O-160 — con € 300,00 versati da Ugo e € 200,00 dall\'unità la frase del conguaglio dice le due cifre (Elsa € 671,23 e Ugo € 28,77; con un piano generato dopo € 626,48 e € 73,52) e la differenza (€ 44,75)', function () {
    [$s, $a] = b51aVendita160($this, 30000, 20000);

    // Il conguaglio non cambia: Elsa € 671,23 (credito a Ugo, debito a Elsa).
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]]);
    $frasi = collect($a['rate']['conguaglio']['frasi'])->filter(fn ($t) => str_contains($t, '44,75'))->values();
    expect($frasi)->toHaveCount(1, 'Nessuna frase del conguaglio dice la differenza (€ 44,75): ' . implode(' | ', $a['rate']['conguaglio']['frasi']));
    $frase = $frasi[0];
    foreach (['Spese generali', '€ 300,00', '€ 200,00', '€ 671,23', '€ 28,77', '€ 626,48', '€ 73,52', 'Ugo', 'Elsa'] as $pezzo) {
        expect(str_contains($frase, $pezzo))->toBeTrue("La frase non dice «{$pezzo}»: {$frase}");
    }
    // Comincia con due spazi come la riga del già versato: `dividiLeFrasiDelConguaglio` la tiene nel calcolo (si nasconde con «Non scriverlo»).
    expect(str_starts_with($frase, '  '))->toBeTrue('La frase non comincia con due spazi (resterebbe fra i fatti): «' . $frase . '»');
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

it('O-160 — differenziale: con la persona e l\'unità la frase dice le cifre del piano generato davvero dopo la vendita (entro un centesimo) e quella del conguaglio', function () {
    // Seconda fonte (SF6): `nettingGiaVersato` si confronta con il piano generato dopo il passaggio, non con un conto rifatto a parte.
    [, $nettiDopo, $elsaConguaglio, $frasi] = b51aConfrontoDelVersato($this, 30000, 20000);

    $frase = collect($frasi)->first(fn ($t) => str_contains($t, 'differenza'));
    expect($frase)->not->toBeNull('Nessuna frase del conguaglio dice la differenza fra conguaglio e piano generato dopo: ' . implode(' | ', $frasi));
    foreach (['Elsa', 'Ugo'] as $nome) {
        $cent = $nettiDopo[$nome] ?? 0;
        $ok = false;
        foreach ([$cent - 1, $cent, $cent + 1] as $c) {
            $ok = $ok || str_contains($frase, b51aEuro($c));
        }
        expect($ok)->toBeTrue("La frase non dice la cifra di {$nome} del piano generato dopo (" . b51aEuro($cent) . '): ' . $frase);
    }
    expect(str_contains($frase, b51aEuro($elsaConguaglio)))->toBeTrue('La frase non dice la cifra del conguaglio per Elsa: ' . $frase);
});

/** La frase del conguaglio che confronta le due strade (quella che dice «la differenza»), o null se non c'è. */
function b51aFraseDelConfronto(array $anteprima): ?string
{
    return collect($anteprima['rate']['conguaglio']['frasi'] ?? [])->first(fn ($t) => str_contains($t, 'differenza'));
}

/** La frase contiene tutti i pezzi, e nessuna cifra negativa detta con «€ -». */
function b51aDiceTutto(?string $frase, array $pezzi): void
{
    expect($frase)->not->toBeNull('La frase di confronto non c\'è.');
    foreach ($pezzi as $pezzo) {
        expect(str_contains($frase, $pezzo))->toBeTrue("La frase non dice «{$pezzo}»: {$frase}");
    }
    expect(str_contains($frase, '€ -'))->toBeFalse("La frase dice una cifra con «€ -»: {$frase}");
}

it('O-160-PERSONA — il già versato di Ugo (€ 500,00) supera la sua parte lorda (€ 394,52): il conguaglio gli dà un credito di € 171,23, il piano dopo si ferma alla sua parte e Elsa passa da € 671,23 a € 605,48', function () {
    // Conto a mano (Spese generali € 1.200,00, tutto al proprietario; vendita Ugo → Elsa l'1/5: 120 giorni a Ugo, 245 a Elsa): lordi 120000 × 120/365 =
    // 39452 e 80548. Alla generazione la persona toglie 50000 dalla riga di Ugo (120000 → 70000) e l'unità 20000: rate di 50000, «€ 500,00 su 12 quote».
    //   - conguaglio: l'unità si toglie dal totale (120000 − 20000 = 100000): Ugo 32877, Elsa 67123; poi la persona dalla parte di Ugo:
    //     32877 − 50000 = −17123, cioè un credito di € 171,23. Le due cifre sommano alle rate emesse: 67123 − 17123 = 50000.
    //   - piano dopo: la persona toglie al più la parte lorda di Ugo (39452 → 0, e 50000 − 39452 = 10548 restano suoi), l'unità 20000 va tutta sulla riga
    //     di Elsa (80548 → 60548). Elsa € 605,48 e Ugo € 0,00. Differenza per Elsa 67123 − 60548 = 6575 = € 65,75.
    [$s, $a] = b51aVendita160($this, 50000, 20000);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]]);
    b51aDiceTutto(b51aFraseDelConfronto($a), ['Spese generali', 'di Ugo (€ 500,00) e dell\'unità (€ 200,00)', 'il conguaglio dà € 671,23 a Elsa e un credito di € 171,23 a Ugo',
        'fino alla sua parte lorda di € 394,52', 'i restanti € 105,48 restano suoi', 'darebbe € 605,48 a Elsa e € 0,00 a Ugo', 'la differenza è € 65,75, e vale il conguaglio']);
    // Il piano generato davvero dopo la vendita dà a Elsa la stessa cifra (Ugo è a zero e non compare).
    expect(b51aNettiDelPianoDopo($this, 50000, 20000))->toBe(['Elsa' => 60548]);
});

it('O-160-MISTA — la voce divisa 30 % inquilino e 70 % proprietario, senza un inquilino: le due righe cadono su Ugo e poi su Elsa, e la frase dice le stesse cifre della voce tutta al proprietario (Elsa € 671,23 contro € 626,48, differenza € 44,75)', function () {
    // Conto a mano: senza un inquilino la parte del 30 % ricade sul proprietario di quei giorni (ripiego), quindi le righe di Ugo e di Elsa sommano come
    // nella voce tutta al proprietario (39452 e 80548) e il piano dopo dà Ugo € 73,52 ed Elsa € 626,48. Il conguaglio somma le due righe: Elsa
    // 20137 + 46986 = 67123, Ugo 863 + 2014 = 2877. Differenza 67123 − 62648 = 4475.
    [, $a] = b51aVendita160($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], []);

    b51aDiceTutto(b51aFraseDelConfronto($a), ['di Ugo (€ 300,00) e dell\'unità (€ 200,00)', 'il conguaglio dà € 671,23 a Elsa e € 28,77 a Ugo',
        'darebbe € 626,48 a Elsa e € 73,52 a Ugo', 'la differenza è € 44,75, e vale il conguaglio']);
    expect(b51aNettiDelPianoDopo($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], []))->toBe(['Elsa' => 62648, 'Ugo' => 7352]);
});

it('O-160-MISTA — con Ines inquilina per tutto l\'anno: il conguaglio dà a Elsa € 483,29 e a Ugo un credito di € 63,29, il piano dopo € 441,78 e € 0,00 (differenza € 41,51)', function () {
    // Conto a mano (voce 30 % inquilino = 36000 a Ines, 70 % proprietario = 84000): alla generazione la persona toglie 30000 dalla riga di Ugo (84000 →
    // 54000) e l'unità 20000 in proporzione a [Ugo 54000, Ines 36000]: Ugo 12000, Ines 8000. Il conguaglio toglie l'unità dalla riga di Ugo (84000 −
    // 12000 = 72000): Elsa 72000 × 245/365 = 48329, Ugo 23671 − 30000 = −6329, un credito di € 63,29 (48329 − 6329 = 42000, le rate emesse).
    //   Il piano dopo: Ugo 84000 × 120/365 = 27616 → la persona ne toglie 27616 (restano suoi 2384 = € 23,84), Elsa 84000 × 245/365 = 56384, Ines 36000;
    //   l'unità 20000 sulle righe [0, 56384, 36000] (somma 92384): Elsa 12206,45 → 12206, Ines 7793,55 → 7793, il resto di un centesimo a Ines (il
    //   resto maggiore): Elsa 56384 − 12206 = 44178, Ines 36000 − 7794 = 28206. Differenza per Elsa 48329 − 44178 = 4151 = € 41,51.
    [, $a] = b51aVendita160($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]]);

    b51aDiceTutto(b51aFraseDelConfronto($a), ['di Ugo (€ 300,00) e dell\'unità (€ 200,00)', 'il conguaglio dà € 483,29 a Elsa e un credito di € 63,29 a Ugo',
        'fino alla sua parte lorda di € 276,16', 'i restanti € 23,84 restano suoi', 'darebbe € 441,78 a Elsa e € 0,00 a Ugo', 'la differenza è € 41,51, e vale il conguaglio']);
    expect(b51aNettiDelPianoDopo($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]]))->toBe(['Elsa' => 44178, 'Ines' => 28206]);
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

it('O-160-ACCONTO — il piano chiede metà della voce (capitolo a € 600,00 su € 1.200,00): il già versato che applica è la metà (Ugo € 150,00, unità € 100,00), e la frase dice le cifre del piano generato dopo (Elsa € 313,24 e Ugo € 36,76) e la differenza (€ 22,38)', function () {
    // Conto a mano: la quota della chiamata è 60000/120000 = 0,5: il piano applica 15000 della persona e 10000 dell'unità. Emesso fino al 31/12: 60000 − 15000 − 10000 = 35000.
    // Vendita Ugo → Elsa l'1/5, giorni 120 e 245: lordi 60000 × 120/365 = 19726 e 40274.
    //   - conguaglio: l'unità si toglie dal totale (60000 − 10000 = 50000): Ugo 16438, Elsa 33562; poi la persona dalla parte di Ugo: 16438 − 15000 = 1438.
    //     Elsa € 335,62, Ugo € 14,38 (sommano alle rate emesse, 35000).
    //   - piano generato dopo: la persona toglie 15000 dalla riga di Ugo (19726 → 4726), poi l'unità in proporzione alle righe già ridotte: 10000 su
    //     [4726, 40274] (somma 45000): Ugo 1050,2 → 1050, Elsa 8949,8 → 8950: Ugo 4726 − 1050 = 3676, Elsa 40274 − 8950 = 31324.
    //   Differenza per Elsa 33562 − 31324 = 2238 = € 22,38. Con il già versato intero (€ 300,00 e € 200,00) la frase diceva € 202,74 e € 0,00, differenza € 132,88.
    $s = b51aVersatoAcconto();
    fclGenera($s);
    fclEmetti($this, $s, '2026-12-31');
    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));

    $frasi = $a['rate']['conguaglio']['frasi'];
    $versato = collect($frasi)->first(fn ($t) => str_contains($t, 'già versati da Ugo'));
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 33562]])
        ->and($versato)->toContain('€ 150,00 già versati da Ugo restano suoi');
    b51aDiceTutto(b51aFraseDelConfronto($a), ['Spese generali', 'di Ugo (€ 150,00) e dell\'unità (€ 100,00)', 'il conguaglio dà € 335,62 a Elsa e € 14,38 a Ugo',
        'darebbe € 313,24 a Elsa e € 36,76 a Ugo', 'la differenza è € 22,38, e vale il conguaglio']);

    // Il piano generato davvero dopo la vendita dà le stesse cifre: Elsa 31324 e Ugo 3676.
    $s2 = b51aVersatoAcconto();
    fds50Registra($this, $s2, fds50ModuloVendita($s2, 'Ugo', 'Elsa', '2026-05-01'));
    fclGenera($s2);
    expect(fclNetti($s2))->toBe(['Elsa' => 31324, 'Ugo' => 3676]);
});

it('O-160-ACCONTO — il capitolo forzato all\'importo del conto è l\'intera voce: la frase è quella di sempre, con il già versato intero (€ 626,48 e € 73,52)', function (int $capitolo) {
    $s = b51aVersatoAcconto($capitolo);
    fclGenera($s);
    fclEmetti($this, $s, '2026-12-31');
    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));

    b51aDiceTutto(b51aFraseDelConfronto($a), ['di Ugo (€ 300,00) e dell\'unità (€ 200,00)', 'darebbe € 626,48 a Elsa e € 73,52 a Ugo']);
})->with(['uguale' => [120000]]);

it('O-160-ACCONTO — la frazione della chiamata: 1 senza un importo forzato più basso, metà con il capitolo a metà, e nessuna risposta (la frase tace) dove non si può dire: piano non generato, o capitolo padre diviso fra i sottoconti in un modo che non è la loro somma', function () {
    $s = b51aVersato(30000, 20000);
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $quota = new ReflectionMethod(\App\Services\Subentro\ConguaglioPassaggio::class, 'quotaDellaChiamata');
    $quota->setAccessible(true);
    $dellaChiamata = fn (int $contoId) => $quota->invoke(new \App\Services\Subentro\ConguaglioPassaggio(), (int) $s['piano']->id, $contoId);

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

it('O-160-TERZI — Ugo e Anna al 50 %, Ugo vende la sua metà a Elsa l\'1/5 (€ 300,00 di Ugo, € 200,00 dell\'unità): la frase dice anche la quota di Anna, che nel piano di dopo cambia (€ 480,33 invece di € 466,67)', function () {
    // Conto a mano: la voce vale 120000, 60000 a testa. Il piano emesso (adesso): la persona toglie 30000 dalla riga di Ugo (60000 → 30000), poi l'unità in
    // proporzione a [Ugo 30000, Anna 60000] (somma 90000): 20000 × 30000/90000 = 6666,67, × 60000/90000 = 13333,33 → 6666 e 13333, il centesimo che avanza a Ugo
    // (,67): Ugo 30000 − 6667 = 23333, Anna 60000 − 13333 = 46667.
    // Il piano di dopo la vendita (120 giorni a Ugo, 245 a Elsa): lordi Ugo 60000 × 120/365 = 19726, Elsa 40274, Anna 60000; la persona toglie al più la parte lorda
    // di Ugo (30000 → 19726: Ugo 0, restano suoi 10274); l'unità 20000 su [0, 40274, 60000] (somma 100274): Elsa 8033,2 → 8033, Anna 11966,8 → 11966 e il centesimo
    // che avanza ad Anna (,8) → 11967: Elsa 40274 − 8033 = 32241, Anna 60000 − 11967 = 48033. Anna paga 48033 − 46667 = 1366 in più (€ 13,66).
    $mk = function (): array {
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
    $s = $mk();
    fclGenera($s);
    fclEmetti($this, $s, '2026-12-31');
    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01', 50));

    $frase = b51aFraseDelConfronto($a);
    b51aDiceTutto($frase, ['di Ugo (€ 300,00) e dell\'unità (€ 200,00)', 'darebbe € 322,41 a Elsa e € 0,00 a Ugo', 'Nello stesso piano cambia anche la quota di Anna: € 480,33 invece di € 466,67.']);

    // Il piano generato davvero dopo la vendita dà quelle cifre: Elsa 32241 e Anna 48033; il piano emesso aveva Anna a 46667.
    $s2 = $mk();
    fds50Registra($this, $s2, fds50ModuloVendita($s2, 'Ugo', 'Elsa', '2026-05-01', 50));
    fclGenera($s2);
    expect(fclNetti($s2))->toMatchArray(['Elsa' => 32241, 'Anna' => 48033]);
});

it('O-160-TERZI — con Ines inquilina per tutto l\'anno la frase dice anche la sua quota, € 282,06 invece di € 280,00; senza altri titolari, o con la quota che non cambia, non c\'è', function () {
    [, $a] = b51aVendita160($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]]);
    b51aDiceTutto(b51aFraseDelConfronto($a), ['Nello stesso piano cambia anche la quota di Ines: € 282,06 invece di € 280,00.']);

    [, $senzaAltri] = b51aVendita160($this, 30000, 20000);
    expect(b51aFraseDelConfronto($senzaAltri))->not->toContain('Nello stesso piano');
});

it('sentinella — O-160-ECCESSO: con il già versato dell\'unità (€ 1.300,00) maggiore dell\'intera spesa (€ 1.200,00) la spesa è già coperta, nessuna rata è emessa e il piano dopo non dà niente: non c\'è un conguaglio né una differenza da dire', function (?int $persona) {
    // Conto a mano: la copertura applicata è al più la spesa (120000), le righe vanno a zero e non nasce nessuna quota: né il conguaglio né il piano
    // generato dopo la vendita hanno una cifra, e le due strade coincidono. L'eccedenza (€ 100,00) è denaro dell'unità, non una differenza fra le strade.
    [, $a] = b51aVendita160($this, $persona, 130000);

    expect(fclCoppie($a))->toBe([])
        ->and(b51aFraseDelConfronto($a))->toBeNull()
        ->and(b51aNettiDelPianoDopo($this, $persona, 130000))->toBe([]);
})->with(['senza il versato della persona' => [null], 'con € 300,00 della persona' => [30000]]);

it('O-160-ECCESSO — con la spesa coperta dal già versato (Ugo € 300,00, unità € 1.300,00) e una seconda voce da € 600,00: il conguaglio dà a Elsa € 201,37 e a Ugo un credito di € 201,37, il piano dopo € 0,00 a tutti e due su quella voce (differenza € 201,37)', function () {
    // Conto a mano: alla generazione la persona toglie 30000 dalla riga di Ugo (120000 → 90000) e l'unità ne assorbe al più 90000 (l'eccedenza,
    // 130000 − 90000, resta all'unità): la voce non ha rata. Il conguaglio divide per giorni quel che l'unità ha coperto in meno della spesa:
    // 120000 − 90000 = 30000, Elsa 30000 × 245/365 = 20137, Ugo 9863 − 30000 = −20137, un credito di € 201,37 (il versato di Ugo è suo). Il piano dopo
    // toglie 30000 dalla riga di Ugo (39452 → 9452) e l'unità, che copre tutte le righe (9452 + 80548 = 90000 ≤ 130000), le azzera: Elsa € 0,00 e Ugo
    // € 0,00. Differenza 20137 − 0. La seconda voce è uguale nelle due strade (Pulizie 60000: Elsa 40274, Ugo 19726).
    [, $a] = b51aVendita160($this, 30000, 130000, conPulizie: true);

    b51aDiceTutto(b51aFraseDelConfronto($a), ['Spese generali', 'di Ugo (€ 300,00) e dell\'unità (€ 1.300,00)', 'il conguaglio dà € 201,37 a Elsa e un credito di € 201,37 a Ugo',
        'darebbe € 0,00 a Elsa e € 0,00 a Ugo', 'la differenza è € 201,37, e vale il conguaglio']);
    // Il piano generato davvero dopo la vendita: la voce coperta non dà niente a nessuno, restano le Pulizie (60000 × 245/365 = 40274 a Elsa, 19726 a Ugo).
    expect(b51aNettiDelPianoDopo($this, 30000, 130000, conPulizie: true))->toBe(['Elsa' => 40274, 'Ugo' => 19726]);
});

it('O-160-ECCESSO — il già versato di Ugo (€ 500,00) supera la sua parte lorda e quello dell\'unità (€ 1.000,00) la spesa che resta: il conguaglio dà a Elsa € 335,62 e a Ugo un credito di € 335,62, il piano dopo € 0,00 a tutti e due', function () {
    // Conto a mano: alla generazione la persona toglie 50000 dalla riga di Ugo (120000 → 70000) e l'unità assorbe 70000: la voce non ha rata. Il conguaglio:
    // 120000 − 70000 = 50000, Elsa 50000 × 245/365 = 33562, Ugo 16438 − 50000 = −33562, un credito di € 335,62. Il piano dopo: la persona toglie al più la parte
    // lorda di Ugo (39452 → 0; 10548 restano suoi) e l'unità copre la riga di Elsa (80548 ≤ 100000): Elsa € 0,00 e Ugo € 0,00. Differenza 33562.
    [, $a] = b51aVendita160($this, 50000, 100000, conPulizie: true);

    b51aDiceTutto(b51aFraseDelConfronto($a), ['di Ugo (€ 500,00) e dell\'unità (€ 1.000,00)', 'il conguaglio dà € 335,62 a Elsa e un credito di € 335,62 a Ugo',
        'fino alla sua parte lorda di € 394,52', 'i restanti € 105,48 restano suoi', 'darebbe € 0,00 a Elsa e € 0,00 a Ugo', 'la differenza è € 335,62, e vale il conguaglio']);
    expect(b51aNettiDelPianoDopo($this, 50000, 100000, conPulizie: true))->toBe(['Elsa' => 40274, 'Ugo' => 19726]);
});

it('sentinella — O-160-COMPRA-L-INQUILINO: se chi compra è già sul piano (Ines, inquilina per tutto l\'anno, compra da Ugo) le sue righe di prima e di dopo si fonderebbero in una sola, e la frase di confronto tace', function () {
    // Il piano generato dopo la vendita intesta a Ines una riga sola, somma della parte di inquilina e di quella di proprietaria, e il già versato
    // dell'unità si divide su quella: un conto che la frase non fa. Il conguaglio c'è lo stesso (Ugo → Ines, 120/365 della parte di proprietà).
    [$s, $a] = b51aVendita160($this, 30000, 20000, ['inquilino' => 30, 'proprietario' => 70], [['Ines', 100]], vende: 'Ines');

    expect(fclCoppie($a))->not->toBe([])
        ->and(b51aFraseDelConfronto($a))->toBeNull('La frase di confronto c\'è con chi compra già sul piano: ' . implode(' | ', $a['rate']['conguaglio']['frasi'] ?? []));
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
