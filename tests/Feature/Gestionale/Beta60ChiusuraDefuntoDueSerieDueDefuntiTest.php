<?php

/**
 * Coda 332, decisione 164.1 — la parte due volte con due defunti sulla stessa unità: i saldi non legati di un erede sono gli stessi in ogni voce.
 *
 * Il cancello confronta, voce per voce, le righe legate alla successione di quella voce e i saldi non legati della persona su quella gestione e
 * quell'unità (`DefuntoNelPiano::saldiCheEntrano`): questi non hanno una successione, e con due defunti sulla stessa unità sono gli stessi nelle due
 * voci. Una riga non legata può essere la seconda serie in una voce sola; e se in una voce è la parte di chi non ha righe legate, in nessun'altra è la
 * seconda serie, anche quando a un altro erede manca ancora la sua riga (la parte non legata si legge persona per persona). Altrimenti le due voci
 * dicono tutte e due «la parte entra due volte» per la stessa riga, e la strada di ognuna («togline una») toglie una parte che serve.
 *
 * Fonte dell'atteso: la decisione 164.1 («il cancello vede tutte e due le serie e si ferma, con i nomi e le cifre di ognuna, finché
 * l'amministratore non toglie quella in più»: la serie in più è una) e la decisione 170 (la voce dice i nomi e la strada in una frase, e le due serie
 * in una tabella, la chiave `due_serie`: una riga per persona, solo nella voce che le dice). Le cifre: Ugo e Ada proprietari di A al 50 %, decesso di tutti e due il
 * 1/5/2026, eredi Anna e Bruno per 30 e 20 della quota di ognuno; la cifra di ognuno 120 giorni su 365 di € 600,00 = € 197,26; Anna 60 % =
 * € 118,36 (11835,6), Bruno 40 % = € 78,90 (7890,4); dopo il decesso Anna (120000 − 39452) × 60 % = 48329, Bruno 32219 (O-62-2, per persona).
 *
 * Che cosa NON copre: MySQL; due righe non legate della stessa persona che sommano due parti (si confrontano sommate: limite dichiarato del
 * secondo giro); una riga non legata che è la parte in due voci, senza righe legate (preesistente); tre defunti sulla stessa unità, o un defunto che
 * è anche erede dell'altro.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** Le parti di ogni successione, in centesimi con il segno del Wallet (positivo = debito). */
const B60DD_PARTE = ['defunto' => -19726, 'anna' => 11836, 'bruno' => 7890];

/** L'apertura comune delle due voci, dopo il nome del defunto. */
const B60DD_EREDI = 'Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 118,36 e Bruno € 78,90.';

/**
 * Unità A da 1000 millesimi, Ugo e Ada proprietari al 50 % dal 2019, «Spese generali» € 1.200,00; il 2026 e il 2027 con la gestione ordinaria
 * agganciata a ognuno come la aggancia l'importatore, aperto il 2027; il piano del 2026 (non generato); le due successioni dalla rotta.
 *
 * @return array{0: Beta51Scenario, 1: Subentro, 2: Subentro}
 */
function b60ddScenario($test, array $erediAda = ['anna' => 30, 'bruno' => 20]): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ada', 'proprietario', 50);
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $subU = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 30, 'bruno' => 20], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    $subA = $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => $erediAda, 'arretrato' => 'eredi', 'riferimento' => 'anna']);

    return [$s, $subU, $subA];
}

/** Una riga dalla porta del Wallet, nell'esercizio aperto: legata alla successione se `$passaggio` c'è. */
function b60ddWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** Le righe legate di una successione: il defunto e gli eredi dati. */
function b60ddLegate($test, Beta51Scenario $s, Subentro $sub, string $defunto, array $eredi = ['anna', 'bruno']): void
{
    b60ddWallet($test, $s, $defunto, B60DD_PARTE['defunto'], $sub);
    foreach ($eredi as $chi) {
        b60ddWallet($test, $s, $chi, B60DD_PARTE[$chi], $sub);
    }
}

/** La generazione dalla pagina del piano, con o senza la presa d'atto del cancello. */
function b60ddGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello dei due defunti nella prova'] : []);
}

/** Le frasi delle voci dei defunti, per nome del defunto. @return array<string, string> */
function b60ddFrasi(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->mapWithKeys(fn ($v) => [$v['defunto']['nome'] => $v['frase']])->all();
}

/** Le tabelle delle due serie delle voci dei defunti, per nome del defunto. @return array<string, list<array<string, mixed>>> */
function b60ddTabelle(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->mapWithKeys(fn ($v) => [$v['defunto']['nome'] => $v['due_serie']])->all();
}

/** La riga di Anna nella tabella: tutte e due le serie della cifra detta, nel 2027 aperto e quindi nel Wallet. */
function b60ddRigaDiAnna(Beta51Scenario $s, string $detta): array
{
    $serie = ['cifra_formattata' => $detta, 'esercizi' => ['Esercizio 2027'], 'nel_wallet' => true];

    return ['id' => $s->persona('anna')->id, 'nome' => 'Anna', 'legata' => $serie, 'non_legata' => $serie, 'esercizio' => 'Esercizio 2027'];
}

/** Toglie dalla porta del Wallet la riga di una persona: legata alla successione data, o non legata se `$sub` è null. */
function b60ddTogli($test, Beta51Scenario $s, string $chi, ?Subentro $sub): void
{
    $x = Saldo::where('anagrafica_id', $s->persona($chi)->id)->where('immobile_id', $s->unita['A']->id)->whereNull('subentro_id')
        ->when($sub !== null, fn ($q) => $q->where('passaggio_id', $sub->id), fn ($q) => $q->whereNull('passaggio_id'))->firstOrFail();
    $test->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $x]))->assertSessionHasNoErrors()->assertRedirect('/saldi');
}

it('Coda 332 — due defunti sulla stessa unità, una riga non legata di Anna accanto alle legate delle due successioni: la parte in più è una, e la dice una voce sola', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    b60ddLegate($this, $s, $subU, 'ugo');
    b60ddLegate($this, $s, $subA, 'ada');

    $r = b60ddGenera($this, $s);
    $frasi = b60ddFrasi($r);
    expect($frasi['Ugo'])->toEndWith(B60DD_EREDI.' La parte di Anna entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Le parti di Ugo e Bruno entrano una volta sola.')
        ->and($frasi['Ada'])->toEndWith(B60DD_EREDI.' I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        // La tabella sta nella sola voce che dice la parte due volte: Anna, € 118,36 a debito in tutte e due le serie.
        ->and(b60ddTabelle($r))->toBe(['Ugo' => [b60ddRigaDiAnna($s, '€ 118,36 a debito')], 'Ada' => []]);
});

it('Coda 332 — due defunti sulla stessa unità: seguendo la voce che dice la parte due volte (tolta la riga legata di Anna), le due voci dicono che i saldi ci sono già e Anna paga tutte e due le parti una volta', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    b60ddLegate($this, $s, $subU, 'ugo');
    b60ddLegate($this, $s, $subA, 'ada');
    b60ddTogli($this, $s, 'anna', $subU);

    $frasi = b60ddFrasi(b60ddGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.');

    b60ddGenera($this, $s, true)->assertSessionHasNoErrors();
    // Conto a mano: Ugo 19726 − 19726 = 0, Ada lo stesso; Anna 48329 + 11836 + 11836 = 72001; Bruno 32219 + 7890 + 7890 = 47999.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 332 — due defunti sulla stessa unità: seguendo la stessa voce dall\'altra parte (tolta la riga non legata di Anna), le due voci dicono che i saldi ci sono già', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    b60ddLegate($this, $s, $subU, 'ugo');
    b60ddLegate($this, $s, $subA, 'ada');
    b60ddTogli($this, $s, 'anna', null);

    $frasi = b60ddFrasi(b60ddGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.');
    b60ddGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 332 — due defunti sulla stessa unità, la riga non legata di Anna è la sua parte di Ugo (nessuna riga legata per Ugo) e la legata è per Ada: nessuna voce dice la parte due volte, e le cifre sono giuste', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    b60ddLegate($this, $s, $subU, 'ugo', ['bruno']);
    b60ddLegate($this, $s, $subA, 'ada');

    $r = b60ddGenera($this, $s);
    $frasi = b60ddFrasi($r);
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and(implode(' ', $frasi))->not->toContain('due volte')
        ->and(b60ddTabelle($r))->toBe(['Ugo' => [], 'Ada' => []]);

    b60ddGenera($this, $s, true)->assertSessionHasNoErrors();
    // Conto a mano, come sopra: le tre righe di Anna sono le sue due parti una volta.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('controprova — due defunti sulla stessa unità, una riga non legata di Anna per ognuno e nessuna riga legata di Anna: le voci dicono che i saldi ci sono già, come prima', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    // La porta del Wallet tiene una riga non legata per persona nell'esercizio: la seconda come la scrive l'importatore, nel 2026.
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    Saldo::create(['esercizio_id' => Esercizio::where('condominio_id', $s->c->id)->where('nome', 'Esercizio 2026')->value('id'), 'condominio_id' => $s->c->id,
        'anagrafica_id' => $s->persona('anna')->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id,
        'saldo_iniziale' => B60DD_PARTE['anna'], 'origine' => 'importato', 'is_applicato' => false]);
    b60ddLegate($this, $s, $subU, 'ugo', ['bruno']);
    b60ddLegate($this, $s, $subA, 'ada', ['bruno']);

    // Anna non ha righe legate: le sue due righe non legate (sommate € 236,72) non sono la parte di nessuna delle due voci, e il cancello non sa se la
    // comprendono (V5), come prima della correzione.
    $frasi = b60ddFrasi(b60ddGenera($this, $s));
    expect($frasi['Ugo'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte')
        ->and($frasi['Ada'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte');
});

it('Coda 332 — due defunti sulla stessa unità, la riga non legata di Anna è la sua parte di Ugo e a Bruno manca ancora la sua: le righe di Anna sono quante le sue parti, e nessuna voce dice la parte due volte', function () {
    [$s, $subU, $subA] = b60ddScenario($this);
    b60ddWallet($this, $s, 'anna', B60DD_PARTE['anna']);
    b60ddLegate($this, $s, $subU, 'ugo', []);
    b60ddLegate($this, $s, $subA, 'ada');

    // Anna ha due righe per due parti (la non legata e la legata di Ada): nella voce di Ugo la non legata è la sua parte, anche se Bruno non ha ancora la
    // sua, e in quella di Ada non è una seconda serie. La voce di Ugo non sa se la non legata comprende la parte (V5) e chiede la riga che manca di Bruno.
    $r = b60ddGenera($this, $s);
    $frasi = b60ddFrasi($r);
    expect($frasi['Ada'])->toEndWith(B60DD_EREDI.' I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ugo'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 78,90 a debito di Bruno)')
        ->and(implode(' ', $frasi))->not->toContain('due volte')
        ->and(b60ddTabelle($r))->toBe(['Ugo' => [], 'Ada' => []]);

    // La strada: la riga di Bruno; la non legata di Anna è la sua parte di Ugo, e resta.
    b60ddWallet($this, $s, 'bruno', B60DD_PARTE['bruno'], $subU);
    $frasi = b60ddFrasi(b60ddGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('I saldi ci sono già: Ada, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.');

    b60ddGenera($this, $s, true)->assertSessionHasNoErrors();
    // Conto a mano: Ugo 19726 − 19726 = 0, Ada lo stesso; Anna 48329 + 11836 + 11836 = 72001; Bruno 32219 + 7890 + 7890 = 47999.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('controprova — due defunti sulla stessa unità con quote diverse fra le due successioni: la riga non legata di Anna è della cifra della sua parte di Ada, non di quella di Ugo, e nella voce di Ada è la seconda serie', function () {
    // Ada lascia ad Anna 10 e a Bruno 40 della sua quota: la parte di Anna è 19726 × 20 % = 3945,2 → € 39,45 (Bruno 15781, il centesimo al resto maggiore).
    [$s, $subU, $subA] = b60ddScenario($this, ['anna' => 10, 'bruno' => 40]);
    b60ddWallet($this, $s, 'anna', 3945);
    b60ddWallet($this, $s, 'ugo', B60DD_PARTE['defunto'], $subU);
    b60ddWallet($this, $s, 'ada', B60DD_PARTE['defunto'], $subA);
    b60ddWallet($this, $s, 'anna', 3945, $subA);
    b60ddWallet($this, $s, 'bruno', 15781, $subA);

    // Nella voce di Ugo la riga non legata di Anna (€ 39,45) non è la sua parte (€ 118,36): Anna è incerta, e la riga resta la seconda serie di Ada.
    $r = b60ddGenera($this, $s);
    $frasi = b60ddFrasi($r);
    expect($frasi['Ada'])->toContain('La parte di Anna entra due volte')
        ->and($frasi['Ugo'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte')
        ->and($frasi['Ugo'])->not->toContain('due volte')
        // La tabella della voce di Ada ha la cifra della parte di Anna in quella successione, € 39,45, non quella della successione di Ugo.
        ->and(b60ddTabelle($r))->toBe(['Ugo' => [], 'Ada' => [b60ddRigaDiAnna($s, '€ 39,45 a debito')]]);
});
