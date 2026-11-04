<?php

/**
 * Coda 480 (decisione 190.1) — il defunto che è anche erede di un altro defunto sulla stessa unità: la fermata del cancello del defunto.
 *
 * La voce di un defunto (`DefuntoNelPiano`) elenca la parte di ogni erede da `eredi()` della successione. Se un erede è a sua volta defunto sulla stessa
 * unità (ha una sua successione con l'arretrato agli eredi), la sua parte da erede resta nel piano a nome suo e ai suoi eredi non passa: nessuna frase lo
 * diceva, e dopo le righe legate le voci chiudevano con «Non serve registrare altro». La fermata è una voce che dice il caso, la parte da erede che il
 * programma non passa agli eredi del secondo defunto e che la divisione la decide l'amministratore: la stessa natura dello stato «la divisione la decide
 * l'amministratore», senza cifre per erede e senza la strada del Wallet, e non si chiude con le righe. La regola completa (la parte passa agli eredi per
 * quota) è un'altra Coda: qui le somme del piano non cambiano.
 *
 * Fonte delle cifre, la regola a mano. Spesa «Spese generali» € 3.650,00 (365000) sull'unità A da 1000 millesimi, 1000 centesimi al giorno per il 100 %, così
 * ogni cifra per persona è esatta e senza resti; 365 giorni nel 2026 (59 fino al 1/3, 61 fino al 1/5, 61 fino al 1/7, 184 dopo).
 * - AD1: Ugo e Ada al 50 %. Ugo muore il 1/3/2026 con eredi Ada 25 e Anna 25. Ada muore il 1/5/2026 con eredi Anna 45 e Bruno 30 (la sua quota, 75).
 *   Ugo 0,5 × 59 × 1000 = 29500, le parti Ada 14750 e Anna 14750. Ada 0,5 × 120 × 1000 + 0,25 × 61 × 1000 = 60000 + 15250 = 75250, le parti Anna 45150
 *   e Bruno 30100. Dopo i decessi Anna 0,25 × 61 × 1000 + 0,7 × 245 × 1000 = 15250 + 171500 = 186750, Bruno 0,3 × 245 × 1000 = 73500. Somma 365000.
 *   La regola completa sarebbe Ada 0, Anna 255500 e Bruno 109500; la fermata non la scrive.
 * - La catena: Ugo e Ada al 50 %. Ugo muore il 1/3 (eredi Ada 25 e Bruno 25), Ada il 1/5 (eredi Bruno 50 e Anna 25, la sua quota 75), Bruno il 1/7 (eredi
 *   Carla 50 e Anna 25, la sua quota 75). Ugo 29500 (le parti Ada 14750 e Bruno 14750). Ada 60000 + 0,25 × 61 × 1000 = 75250 (le parti Bruno 50167 e Anna
 *   25083: 75250 × 50 / 75 = 50166,67). Bruno 0,25 × 122 × 1000 + 0,5 × 61 × 1000 = 30500 + 30500 = 61000 (le parti Carla 40667 e Anna 20333). Anna 0,25 × 61 × 1000
 *   + 0,5 × 184 × 1000 = 15250 + 92000 = 107250, Carla 0,5 × 184 × 1000 = 92000. Somma 29500 + 75250 + 61000 + 107250 + 92000 = 365000.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto, dove la porta del Wallet scrive.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** Il cappello di ogni voce: chi è morto, con la cifra che il piano calcola ancora per lui. */
function g3fApertura(string $defunto, string $quando, string $cifra): string
{
    return "La successione di {$defunto} (decesso del {$quando}) ha passato l'arretrato agli eredi, ma questo piano calcola ancora per {$defunto} {$cifra} su Interno A: la competenza è anteriore al decesso e le rate nascono ora, fuori dall'arretrato.";
}

/** La chiusura delle voci della fermata: la divisione è dell'amministratore, e le righe del Wallet non chiudono la voce. */
const G3F_CHIUSURA = ' La divisione fra gli eredi la decide l\'amministratore: il programma non ne propone le parti né le righe del Wallet, e nessuna riga del Wallet chiude questa voce, che torna a ogni generazione.';

/**
 * Unità A (o A e B), «Spese generali» sulla gestione ordinaria del 2026 e del 2027 (aperto il 2027), il piano del 2026 non generato, le successioni dalla rotta.
 *
 * @param array<string, int> $unita millesimi per unità
 * @param list<array{0: string, 1: string, 2: float}> $titolari [unità, persona, quota]
 * @param list<array{0: string, 1: string, 2: string, 3: array<string, float>, 4: string, 5?: string}> $morti [unità, defunto, data, eredi, riferimento, arretrato]
 * @param int $entrata un'entrata («Affitto antenna») accanto alla spesa, in centesimi: con più della spesa ogni cifra è un credito
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function g3fScenario($test, array $unita, array $titolari, array $morti, int $spesa = 365000, bool $applicaSaldi = true, int $entrata = 0): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', $unita);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', $spesa, ['Proprietà' => 100]);
    if ($entrata > 0) {
        $conto = Conto::create(['piano_conto_id' => $s->gestioni['Ordinaria']['pc']->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $entrata]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $s->tabelle['Proprietà']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($titolari as [$u, $chi, $quota]) {
        $s->titolare($u, $chi, 'proprietario', $quota);
    }
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05', applicaSaldi: $applicaSaldi);
    $sub = [];
    foreach ($morti as $m) {
        [$u, $d, $il, $eredi, $rif] = $m;
        $sub[$d] = $s->passaggio('successione', $u, $d, null, $il, ['eredi' => $eredi, 'arretrato' => $m[5] ?? 'eredi', 'riferimento' => $rif]);
    }

    return [$s, $sub];
}

/** Il caso della Coda 480: Ugo e Ada al 50 %, Ugo morto il 1/3 con eredi Ada e Anna al 25 %, Ada morta il 1/5 con eredi Anna 45 % e Bruno 30 %. */
function g3fAd1($test, bool $applicaSaldi = true): array
{
    return g3fScenario($test, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]],
        [['A', 'ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['A', 'ada', '2026-05-01', ['anna' => 45, 'bruno' => 30], 'anna']], applicaSaldi: $applicaSaldi);
}

/** Due defunti sulla stessa unità, nessuno dei due erede dell'altro: Ugo e Ada al 50 %, eredi Anna e Bruno al 25 % di ognuno. */
function g3fDueSenzaLegame($test): array
{
    return g3fScenario($test, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]],
        [['A', 'ugo', '2026-03-01', ['anna' => 25, 'bruno' => 25], 'anna'], ['A', 'ada', '2026-05-01', ['anna' => 25, 'bruno' => 25], 'anna']]);
}

/** La catena: Ugo muore il 1/3 (eredi Ada e Bruno), Ada il 1/5 (eredi Bruno e Anna), Bruno il 1/7 (eredi Carla e Anna). */
function g3fCatena($test): array
{
    return g3fScenario($test, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]], [
        ['A', 'ugo', '2026-03-01', ['ada' => 25, 'bruno' => 25], 'ada'],
        ['A', 'ada', '2026-05-01', ['bruno' => 50, 'anna' => 25], 'bruno'],
        ['A', 'bruno', '2026-07-01', ['carla' => 50, 'anna' => 25], 'carla'],
    ]);
}

/** Una riga dalla porta del Wallet, nell'esercizio aperto: legata alla successione se `$passaggio` c'è. */
function g3fWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** La generazione dalla pagina del piano, con o senza la presa d'atto del cancello. */
function g3fGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto che è anche erede nella prova'] : []);
}

/** Le voci dei defunti del cancello, per nome del defunto. @return array<string, array<string, mixed>> */
function g3fVoci(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->keyBy(fn ($v) => $v['defunto']['nome'])->all();
}

/** Le cifre per erede di una voce, per nome (null dove il programma non le dà). @return array<string, int|null> */
function g3fParti(array $voce): array
{
    return array_column($voce['eredi'], 'cifra', 'nome');
}

/** Le sei righe che le voci di prima chiedevano per Ugo e per Ada (la strada del Wallet com'era), registrate dalla porta. */
function g3fLeSeiRighe($test, Beta51Scenario $s, array $sub): void
{
    g3fWallet($test, $s, 'ugo', -29500, $sub['ugo']);
    g3fWallet($test, $s, 'ada', 14750, $sub['ugo']);
    g3fWallet($test, $s, 'anna', 14750, $sub['ugo']);
    g3fWallet($test, $s, 'ada', -75250, $sub['ada']);
    g3fWallet($test, $s, 'anna', 45150, $sub['ada']);
    g3fWallet($test, $s, 'bruno', 30100, $sub['ada']);
}

const G3F_VOCE_DI_ADA = ' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Anna e Bruno.';
const G3F_VOCE_DI_UGO = ' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Anna e Bruno.';

it('Coda 480 — Ugo e Ada coniugi, Ugo morto il 1/3 con eredi Ada e Anna e Ada morta il 1/5: la voce di Ada dice che è anche erede di Ugo, la sua parte da erede (€ 147,50) che il programma non passa ai suoi eredi, e che la divisione la decide l\'amministratore', function () {
    [$s] = g3fAd1($this);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada']);
    // La voce di Ada: la cifra del motore resta (75250), gli eredi hanno la quota e nessuna parte, nessuna strada del Wallet.
    expect($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', '€ 752,50') . G3F_VOCE_DI_ADA . G3F_CHIUSURA)
        ->and($voci['Ada']['cifra'])->toBe(75250)
        ->and($voci['Ada']['parti_da_decidere'])->toBeTrue()
        ->and(g3fParti($voci['Ada']))->toBe(['Anna' => null, 'Bruno' => null])
        ->and($voci['Ada']['due_serie'])->toBe([])
        ->and($voci['Ada']['immobile_id'])->toBe($s->unita['A']->id)
        ->and($voci['Ada']['defunto']['id'])->toBe($s->persona('ada')->id);
    expect($voci['Ada']['frase'])->not->toContain('Non serve registrare altro')->not->toContain('registra dal Wallet')->not->toContain('2.555,00');
    // Il programma sa la parte di Ada come erede (14750 = 29500 × 25 / 50) e la dice; la divisione per erede non la dice.
    expect($voci['Ada']['frase'])->toContain('€ 147,50');
});

it('Coda 480 — la voce di Ugo nomina l\'erede che è a sua volta defunta, la sua parte (€ 147,50) che non passa ai suoi eredi, e non chiede più la riga a nome suo', function () {
    [$s] = g3fAd1($this);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 295,00') . G3F_VOCE_DI_UGO . G3F_CHIUSURA)
        ->and($voci['Ugo']['cifra'])->toBe(29500)
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()
        ->and(g3fParti($voci['Ugo']))->toBe(['Ada' => null, 'Anna' => null]);
    expect($voci['Ugo']['frase'])->not->toContain('registra dal Wallet')->not->toContain('a debito di Ada')->not->toContain('Non serve registrare altro');
});

it('Coda 480 — la voce non si chiude da sola: con le sei righe legate che le voci di prima chiedevano (Ugo, Ada, Anna, Bruno) le due voci tornano, uguali, e nessuna dice «Non serve registrare altro»', function () {
    [$s, $sub] = g3fAd1($this);
    $prima = g3fVoci(g3fGenera($this, $s));

    g3fLeSeiRighe($this, $s, $sub);
    $dopo = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($dopo))->toBe(['Ugo', 'Ada'])
        ->and($dopo['Ugo']['frase'])->toBe($prima['Ugo']['frase'])
        ->and($dopo['Ada']['frase'])->toBe($prima['Ada']['frase']);
    foreach ($dopo as $voce) {
        expect($voce['frase'])->not->toContain('Non serve registrare altro')->not->toContain('I saldi ci sono già')->and($voce['parti_da_decidere'])->toBeTrue();
    }
    // E ancora dopo che il piano è stato generato con la presa d'atto: il ricalcolo la ripropone.
    g3fGenera($this, $s, true)->assertSessionHasNoErrors();
    $ancora = g3fVoci(g3fGenera($this, $s));
    expect($ancora['Ada']['frase'])->toBe($prima['Ada']['frase']);
});

it('Coda 480 — la fermata dice, non sposta denaro: senza righe, con la presa d\'atto, le somme del piano sono quelle dei giorni (Ugo 29500, Ada 75250, Anna 186750, Bruno 73500, in tutto € 3.650,00)', function () {
    [$s] = g3fAd1($this);

    g3fGenera($this, $s, true)->assertSessionHasNoErrors();

    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 29500, 'ada' => 75250, 'anna' => 186750, 'bruno' => 73500]);
});

it('Coda 480 — con le sei righe e la presa d\'atto le somme sono quelle di prima della fermata (€ 147,50 a nome di Ada: la regola completa è un\'altra Coda); la fermata non le tocca', function () {
    [$s, $sub] = g3fAd1($this);
    g3fLeSeiRighe($this, $s, $sub);

    g3fGenera($this, $s, true)->assertSessionHasNoErrors();

    // Ada 75250 + 14750 − 75250 = 14750; Anna 186750 + 14750 + 45150 = 246650; Bruno 73500 + 30100 = 103600; Ugo 29500 − 29500 = 0.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 14750, 'anna' => 246650, 'bruno' => 103600]);
});

it('Coda 480 — il piano che non applica i saldi dice la stessa fermata: la frase non cambia', function () {
    [$s] = g3fAd1($this, applicaSaldi: false);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', '€ 752,50') . G3F_VOCE_DI_ADA . G3F_CHIUSURA)
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 295,00') . G3F_VOCE_DI_UGO . G3F_CHIUSURA)
        ->and($voci['Ada']['piano_applica_saldi'])->toBeFalse();
});

it('Coda 480 — alla creazione del piano, quando il cancello annulla la richiesta, la fermata è la stessa', function () {
    [$s] = g3fAd1($this);
    $voci = [];
    try {
        app(GeneratePianoRateAction::class)->execute($s->piani['Preventivo 2026']['p'], forzaApplicazioneSaldi: false, accettaDestinatari: false, esercizio: $s->e, allaCreazione: true);
    } catch (DestinatariCambiatiException $ex) {
        $voci = collect($ex->getCambiamenti())->where('motivo', 'defunto_nel_piano')->keyBy(fn ($v) => $v['defunto']['nome'])->all();
    }

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', '€ 752,50') . G3F_VOCE_DI_ADA . G3F_CHIUSURA)
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 295,00') . G3F_VOCE_DI_UGO . G3F_CHIUSURA);
});

it('Coda 480, controprova — due defunti sulla stessa unità che non sono uno erede dell\'altro (eredi Anna e Bruno di tutti e due): le voci chiedono le righe e, registrate, dicono «Non serve registrare altro», come prima', function () {
    [$s, $sub] = g3fDueSenzaLegame($this);

    $voci = g3fVoci(g3fGenera($this, $s));

    // Ugo 0,5 × 59 × 1000 = 29500, le parti 14750 e 14750; Ada 0,5 × 120 × 1000 = 60000, le parti 30000 e 30000.
    expect($voci['Ugo']['cifra'])->toBe(29500)->and(g3fParti($voci['Ugo']))->toBe(['Anna' => 14750, 'Bruno' => 14750])
        ->and($voci['Ada']['cifra'])->toBe(60000)->and(g3fParti($voci['Ada']))->toBe(['Anna' => 30000, 'Bruno' => 30000])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeFalse()->and($voci['Ada']['parti_da_decidere'])->toBeFalse();
    foreach ($voci as $voce) {
        expect($voce['frase'])->not->toContain('è anche erede')->not->toContain('a sua volta')->toContain('registra dal Wallet');
    }
    g3fWallet($this, $s, 'ugo', -29500, $sub['ugo']);
    g3fWallet($this, $s, 'anna', 14750, $sub['ugo']);
    g3fWallet($this, $s, 'bruno', 14750, $sub['ugo']);
    g3fWallet($this, $s, 'ada', -60000, $sub['ada']);
    g3fWallet($this, $s, 'anna', 30000, $sub['ada']);
    g3fWallet($this, $s, 'bruno', 30000, $sub['ada']);

    $frasi = array_map(fn ($v) => $v['frase'], g3fVoci(g3fGenera($this, $s)));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    g3fGenera($this, $s, true)->assertSessionHasNoErrors();
    // A mano, per Anna (e per Bruno, uguale): la parte di Ugo 14750, la parte di Ada 30000, fra i due decessi 0,25 × 61 × 1000 = 15250 e dopo 0,5 × 245 × 1000 = 122500:
    // 182500, il 50 % di 365000; Ugo e Ada 0.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 182500, 'bruno' => 182500]);
});

it('Coda 480 — con un credito (un\'entrata che supera la spesa) la cifra dei defunti è «un credito» e la parte da erede di Ada è «a credito»', function () {
    // Con l'entrata di € 7.300,00 accanto alla spesa il netto è −365000 l'anno, −1000 al giorno per il 100 %: Ugo −29500 (la parte di Ada −14750), Ada
    // 0,5 × 120 × (−1000) + 0,25 × 61 × (−1000) = −60000 − 15250 = −75250.
    [$s] = g3fScenario($this, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]],
        [['A', 'ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['A', 'ada', '2026-05-01', ['anna' => 45, 'bruno' => 30], 'anna']], entrata: 730000);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect($voci['Ugo']['cifra'])->toBe(-29500)->and($voci['Ada']['cifra'])->toBe(-75250)
        ->and($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', 'un credito di € 752,50')
            . ' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 147,50 a credito, il programma non la passa ai suoi eredi, Anna e Bruno.' . G3F_CHIUSURA)
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', 'un credito di € 295,00')
            . ' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50 a credito, il programma non la passa ai suoi eredi, Anna e Bruno.' . G3F_CHIUSURA);
});

it('Coda 480 — Ada erede di Ugo ed Eva: la voce di Ada dice le due parti (€ 118,00 e € 135,00) e quelle di Ugo e di Eva nominano Ada, ognuna con la sua parte', function () {
    // Ugo 40 %, Eva 30 %, Ada 30 %. Ugo muore il 1/3 (59 giorni, eredi Ada 20 e Anna 20), Eva il 1/4 (90 giorni, eredi Ada 15 e Anna 15), Ada il 1/5 (120 giorni, la sua quota 65,
    // eredi Bruno 50 e Anna 15). Ugo 0,4 × 59 × 1000 = 23600, la parte di Ada 11800; Eva 0,3 × 90 × 1000 = 27000, la parte di Ada 13500; Ada 0,3 × 120 × 1000 + 0,2 × 61 × 1000
    // + 0,15 × 30 × 1000 = 36000 + 12200 + 4500 = 52700.
    [$s] = g3fScenario($this, ['A' => 1000], [['A', 'ugo', 40], ['A', 'eva', 30], ['A', 'ada', 30]],
        [['A', 'ugo', '2026-03-01', ['ada' => 20, 'anna' => 20], 'ada'], ['A', 'eva', '2026-04-01', ['ada' => 15, 'anna' => 15], 'ada'], ['A', 'ada', '2026-05-01', ['bruno' => 50, 'anna' => 15], 'bruno']]);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Eva', 'Ada'])
        ->and([$voci['Ugo']['cifra'], $voci['Eva']['cifra'], $voci['Ada']['cifra']])->toBe([23600, 27000, 52700])
        ->and($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', '€ 527,00')
            . ' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 118,00, il programma non la passa ai suoi eredi, Bruno e Anna.'
            . ' Ada è anche erede di Eva su quest\'unità (decesso del 1 aprile 2026): la sua parte da erede, € 135,00, il programma non la passa ai suoi eredi, Bruno e Anna.' . G3F_CHIUSURA)
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 236,00')
            . ' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 118,00, il programma non la passa ai suoi eredi, Bruno e Anna.' . G3F_CHIUSURA)
        ->and($voci['Eva']['frase'])->toBe(g3fApertura('Eva', '1 aprile 2026', '€ 270,00')
            . ' Ada, erede di Eva, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 135,00, il programma non la passa ai suoi eredi, Bruno e Anna.' . G3F_CHIUSURA);
});

it('Coda 480 — la catena Ugo, Ada, Bruno (ognuno erede del precedente e defunto): ogni voce dice le parti dirette da erede, con la cifra dove l\'altra divisione è fatta (€ 501,67 di Bruno da Ada)', function () {
    [$s] = g3fCatena($this);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada', 'Bruno'])
        ->and([$voci['Ugo']['cifra'], $voci['Ada']['cifra'], $voci['Bruno']['cifra']])->toBe([29500, 75250, 61000])
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 295,00')
            . ' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Bruno e Anna.'
            . ' Bruno, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 luglio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Carla e Anna.' . G3F_CHIUSURA)
        ->and($voci['Ada']['frase'])->toBe(g3fApertura('Ada', '1 maggio 2026', '€ 752,50')
            . G3F_VOCE_DI_ADA_CATENA
            . ' Bruno, erede di Ada, ha a sua volta una successione su quest\'unità (decesso del 1 luglio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 501,67, il programma non la passa ai suoi eredi, Carla e Anna.' . G3F_CHIUSURA)
        ->and($voci['Bruno']['frase'])->toBe(g3fApertura('Bruno', '1 luglio 2026', '€ 610,00')
            . ' Bruno è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Carla e Anna.'
            . ' Bruno è anche erede di Ada su quest\'unità (decesso del 1 maggio 2026): la sua parte da erede, € 501,67, il programma non la passa ai suoi eredi, Carla e Anna.' . G3F_CHIUSURA);
    // Senza righe e con la presa d'atto le somme sono quelle dei giorni.
    g3fGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'bruno', 'anna', 'carla']))->toBe(['ugo' => 29500, 'ada' => 75250, 'bruno' => 61000, 'anna' => 107250, 'carla' => 92000]);
});

const G3F_VOCE_DI_ADA_CATENA = ' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Bruno e Anna.';

it('Coda 480, il piano congela le cifre di sempre: dopo la presa d\'atto `titolarita_alla` ha le due voci con la cifra del motore (Ugo 29500, Ada 75250)', function () {
    [$s] = g3fAd1($this);

    g3fGenera($this, $s, true)->assertSessionHasNoErrors();

    expect($s->titolaritaAlla('Preventivo 2026')['defunti'])->toBe([
        ['anagrafica_id' => $s->persona('ugo')->id, 'immobile_id' => $s->unita['A']->id, 'cifra' => 29500],
        ['anagrafica_id' => $s->persona('ada')->id, 'immobile_id' => $s->unita['A']->id, 'cifra' => 75250],
    ]);
});

it('Coda 480, controprova — Ada, erede di Ugo, lascia l\'arretrato a nome suo: la parte di Ada resta a nome suo per scelta, nessuna fermata, la voce di Ugo chiede la riga a nome di Ada come prima', function () {
    [$s] = g3fScenario($this, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]],
        [['A', 'ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['A', 'ada', '2026-05-01', ['anna' => 45, 'bruno' => 30], 'anna', 'defunto']]);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo'])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeFalse()
        ->and(g3fParti($voci['Ugo']))->toBe(['Ada' => 14750, 'Anna' => 14750]);
    expect($voci['Ugo']['frase'])->toContain('€ 147,50 a debito di Ada')->not->toContain('a sua volta');
});

it('Coda 480, controprova — la successione di Ada è su un\'altra unità (la B): sull\'unità A la parte di Ada non è nel caso, le voci sono quelle di prima', function () {
    // A e B da 500 millesimi: 182500 a unità. Ugo e Ada al 50 % di A, Ada al 100 % di B. Ugo muore il 1/3 su A (0,5 × 59 × 500 = 14750, la parte di Ada 7375), Ada il
    // 1/5 solo su B (120 × 500 = 60000, le parti Anna 30000 e Bruno 30000).
    [$s] = g3fScenario($this, ['A' => 500, 'B' => 500], [['A', 'ugo', 50], ['A', 'ada', 50], ['B', 'ada', 100]],
        [['A', 'ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['B', 'ada', '2026-05-01', ['anna' => 50, 'bruno' => 50], 'anna']]);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and($voci['Ugo']['cifra'])->toBe(14750)->and(g3fParti($voci['Ugo']))->toBe(['Ada' => 7375, 'Anna' => 7375])
        ->and($voci['Ada']['cifra'])->toBe(60000)->and(g3fParti($voci['Ada']))->toBe(['Anna' => 30000, 'Bruno' => 30000])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeFalse()->and($voci['Ada']['parti_da_decidere'])->toBeFalse();
    foreach ($voci as $voce) {
        expect($voce['frase'])->not->toContain('è anche erede')->not->toContain('a sua volta');
    }
});

it('Coda 480 — Ada non ha una voce sua (nessuna cifra del motore: il piano straordinario è della delibera del 1/2, prima delle due successioni): la voce di Ugo nomina Ada e la sua parte (€ 600,00)', function () {
    // Ugo è l'unico proprietario di A; la delibera dei lavori è del 1/2/2026, quando Ugo è titolare: € 1.200,00 a lui, nulla a Ada (che è erede dall'1/3 e muore l'1/5).
    // Ada eredita metà della quota di Ugo (50 su 100): la sua parte è 120000 × 50 / 100 = 60000.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Straordinaria', 'straordinaria')->voce('Straordinaria', 'Lavori', 120000, ['Proprietà' => 100])
        ->titolare('A', 'ugo', 'proprietario', 100)->pianoStraordinario('Lavori', 'Straordinaria', ['Lavori' => 120000], '2026-02-01');
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 50, 'anna' => 50], 'arretrato' => 'eredi', 'riferimento' => 'ada']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 30, 'carla' => 20], 'arretrato' => 'eredi', 'riferimento' => 'bruno']);

    $voci = g3fVoci($s->ricalcola('Lavori', false));

    expect(array_keys($voci))->toBe(['Ugo'])
        ->and($voci['Ugo']['cifra'])->toBe(120000)
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()
        ->and($voci['Ugo']['frase'])->toBe(g3fApertura('Ugo', '1 marzo 2026', '€ 1.200,00')
            . ' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 600,00, il programma non la passa ai suoi eredi, Bruno e Carla.' . G3F_CHIUSURA);
});

/**
 * Ugo è proprietario al 50 %, Ada al 25 % e nuda proprietaria al 25 % di A, con Elsa usufruttuaria del 25 %. Ugo muore il 1/3/2026 con la proprietà a Ada (30) e Anna (20);
 * Ada muore il 1/5/2026, con due successioni: la proprietà a Bruno (55) e la nuda proprietà a Carla (25).
 * Ugo 0,5 × 59 × 1000 = 29500, la parte di Ada 29500 × 30 / 50 = 17700. Ada 0,25 × 120 × 1000 + 0,3 × 61 × 1000 + 0,25 × 120 × 1000 (la nuda paga le spese con l'usufrutto,
 * come nella cifra di un defunto con la nuda proprietà) = 30000 + 18300 + 30000 = 78300.
 */
it('Coda 480 — Ada ha due successioni sull\'unità (la proprietà e la nuda proprietà): la voce di Ugo dice «più successioni» e la sua parte (€ 177,00), quella di Ada le sue due successioni e la parte da erede', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 365000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ada', 'proprietario', 25)->titolare('A', 'ada', 'nuda_proprietario', 25)->titolare('A', 'elsa', 'usufruttuario', 25);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 30, 'anna' => 20], 'arretrato' => 'eredi', 'riferimento' => 'ada', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 55], 'arretrato' => 'eredi', 'riferimento' => 'bruno', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['carla' => 25], 'arretrato' => 'eredi', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);

    $voci = g3fVoci($s->ricalcola('Preventivo 2026', false));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and([$voci['Ugo']['cifra'], $voci['Ada']['cifra']])->toBe([29500, 78300])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()->and($voci['Ada']['parti_da_decidere'])->toBeTrue();
    expect($voci['Ugo']['frase'])->toStartWith(g3fApertura('Ugo', '1 marzo 2026', '€ 295,00') . ' Ada, erede di Ugo, ha a sua volta più successioni su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 177,00, il programma non la passa ai suoi eredi, ')
        ->toEndWith(G3F_CHIUSURA)->toContain('Carla')->toContain('Bruno');
    expect($voci['Ada']['frase'])->toStartWith('Le successioni di Ada (decesso del 1 maggio 2026) hanno passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ada € 783,00 su Interno A: la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato. Ada ha su quest\'unità più di una successione (')
        ->toContain('la proprietà, con l\'erede Bruno')->toContain('la nuda proprietà, con l\'erede Carla')
        ->toContain('). Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 177,00, il programma non la passa ai suoi eredi, ')
        ->toEndWith(G3F_CHIUSURA);
});

it('Coda 480, controprova — Ugo con due successioni, e Ada erede soltanto di quella dove l\'arretrato resta a nome di Ugo: Ada non riceve niente da passare ai suoi eredi, nessuna fermata', function () {
    // Ugo 0,5 × 59 × 1000 = 29500 per la proprietà e 29500 per la nuda: 59000. Ada, nuda proprietaria dall'1/3 (0,5 × 61 × 1000 = 30500), muore l'1/5 con erede Carla.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 365000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ugo', 'nuda_proprietario', 50)->titolare('A', 'elsa', 'usufruttuario', 50);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['anna' => 30, 'bruno' => 20], 'arretrato' => 'eredi', 'riferimento' => 'anna', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 50], 'arretrato' => 'defunto', 'riferimento' => 'ada', 'ruolo' => 'nuda_proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['carla' => 50], 'arretrato' => 'eredi', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);

    $voci = g3fVoci($s->ricalcola('Preventivo 2026', false));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and([$voci['Ugo']['cifra'], $voci['Ada']['cifra']])->toBe([59000, 30500])
        ->and($voci['Ada']['parti_da_decidere'])->toBeFalse()->and(g3fParti($voci['Ada']))->toBe(['Carla' => 30500]);
    expect($voci['Ugo']['frase'])->toContain('(l\'arretrato resta a suo nome)')->not->toContain('a sua volta')->not->toContain('è anche erede');
    expect($voci['Ada']['frase'])->not->toContain('è anche erede')->toContain('registra dal Wallet');
});

it('Coda 480 — la pagina del passaggio rifiuta un defunto fra i suoi stessi eredi: il caso del defunto erede di sé stesso non arriva al cancello', function () {
    [$s] = g3fScenario($this, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]], []);

    expect(fn () => $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ugo' => 25, 'anna' => 25], 'arretrato' => 'eredi', 'riferimento' => 'anna']))
        ->toThrow(\RuntimeException::class, 'Ugo non può essere fra i suoi eredi');
});

it('Coda 480, controprova — Ada è erede di Ugo (l\'arretrato passa) e di Eva solo per la nuda proprietà (l\'arretrato resta a nome di Eva): la fermata nomina Ugo e non Eva', function () {
    // Righe che sommano 100: Ugo proprietario 50, Eva proprietaria 25 e nuda proprietaria 25, Elsa usufruttuaria 25. Muoiono Ugo (eredi Ada 50) ed Eva (la proprietà ad Anna, la nuda
    // ad Ada con l'arretrato a nome di Eva) l'1/3; Ada l'1/5, con la proprietà a Bruno (50) e la nuda a Carla (25).
    // Ugo 0,5 × 59 × 1000 = 29500, Eva (0,25 + 0,25) × 59 × 1000 = 29500, Ada (0,5 + 0,25) × 61 × 1000 = 45750; la parte di Ada come erede di Ugo è tutta la cifra di Ugo (50 su 50): 29500.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 365000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'eva', 'proprietario', 25)->titolare('A', 'eva', 'nuda_proprietario', 25)->titolare('A', 'elsa', 'usufruttuario', 25);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 50], 'arretrato' => 'eredi', 'riferimento' => 'ada', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'eva', null, '2026-03-01', ['eredi' => ['anna' => 25], 'arretrato' => 'eredi', 'riferimento' => 'anna', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'eva', null, '2026-03-01', ['eredi' => ['ada' => 25], 'arretrato' => 'defunto', 'riferimento' => 'ada', 'ruolo' => 'nuda_proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 50], 'arretrato' => 'eredi', 'riferimento' => 'bruno', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['carla' => 25], 'arretrato' => 'eredi', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);

    $voci = g3fVoci($s->ricalcola('Preventivo 2026', false));

    expect(array_keys($voci))->toBe(['Ugo', 'Eva', 'Ada'])
        ->and([$voci['Ugo']['cifra'], $voci['Eva']['cifra'], $voci['Ada']['cifra']])->toBe([29500, 29500, 45750]);
    expect($voci['Ugo']['frase'])->toContain(' Ada, erede di Ugo, ha a sua volta più successioni su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 295,00, il programma non la passa ai suoi eredi, ')
        ->and($voci['Eva']['frase'])->toContain('(l\'arretrato resta a suo nome)')->not->toContain('a sua volta')->not->toContain('è anche erede')
        ->and($voci['Ada']['frase'])->toContain(' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 295,00, il programma non la passa ai suoi eredi, ')
        ->not->toContain('erede di Eva');
});

it('Coda 480, controprova — un terzo defunto (Pia) sulla stessa unità, senza legame con gli altri due: la sua voce resta quella di sempre e, con le righe registrate, dice «Non serve registrare altro»; le voci di Ugo e di Ada restano la fermata', function () {
    // Ugo 30 %, Ada 30 %, Pia 40 %. Ugo muore l'1/3 (eredi Ada 15 e Anna 15), Ada l'1/5 (la sua quota 45, eredi Anna 30 e Bruno 15), Pia l'1/5 (eredi Anna 25 e Bruno 15).
    // Ugo 0,3 × 59 × 1000 = 17700; Ada 0,3 × 120 × 1000 + 0,15 × 61 × 1000 = 36000 + 9150 = 45150; Pia 0,4 × 120 × 1000 = 48000, le parti Anna 48000 × 25 / 40 = 30000 e Bruno 18000.
    [$s, $sub] = g3fScenario($this, ['A' => 1000], [['A', 'ugo', 30], ['A', 'ada', 30], ['A', 'pia', 40]],
        [['A', 'ugo', '2026-03-01', ['ada' => 15, 'anna' => 15], 'ada'], ['A', 'ada', '2026-05-01', ['anna' => 30, 'bruno' => 15], 'anna'], ['A', 'pia', '2026-05-01', ['anna' => 25, 'bruno' => 15], 'anna']]);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada', 'Pia'])
        ->and([$voci['Ugo']['cifra'], $voci['Ada']['cifra'], $voci['Pia']['cifra']])->toBe([17700, 45150, 48000])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()->and($voci['Ada']['parti_da_decidere'])->toBeTrue()
        ->and($voci['Pia']['parti_da_decidere'])->toBeFalse()->and(g3fParti($voci['Pia']))->toBe(['Anna' => 30000, 'Bruno' => 18000]);
    // Le righe che la voce di Pia chiede: il credito di Pia e le parti di Anna e di Bruno, legate alla sua successione.
    g3fWallet($this, $s, 'pia', -48000, $sub['pia']);
    g3fWallet($this, $s, 'anna', 30000, $sub['pia']);
    g3fWallet($this, $s, 'bruno', 18000, $sub['pia']);

    $voci = g3fVoci(g3fGenera($this, $s));

    expect($voci['Pia']['frase'])->toEndWith('Non serve registrare altro.');
    expect($voci['Ugo']['frase'])->not->toContain('Non serve registrare altro')->and($voci['Ada']['frase'])->not->toContain('Non serve registrare altro');
    g3fGenera($this, $s, true)->assertSessionHasNoErrors();
    // Pia 48000 − 48000 = 0, Anna 48000 × 25 / 40 più il resto delle sue quote: la somma di tutti è la spesa.
    expect(array_sum($s->somme('Preventivo 2026', ['ugo', 'ada', 'pia', 'anna', 'bruno'])))->toBe(365000);
});

it('Coda 480 — Ugo con due successioni, la nuda proprietà con l\'arretrato a nome suo: la divisione di Ugo non si fa, e la voce nomina Ada (erede della proprietà, defunta) con la sua parte senza la cifra', function () {
    // Ugo 0,5 × 59 × 1000 = 29500 per la proprietà e 29500 per la nuda: 59000. Ada, erede della proprietà (30 su 50) dall'1/3, muore l'1/5 con erede Bruno: 0,3 × 61 × 1000 = 18300.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 365000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ugo', 'nuda_proprietario', 50)->titolare('A', 'elsa', 'usufruttuario', 50);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 30, 'anna' => 20], 'arretrato' => 'eredi', 'riferimento' => 'ada', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['carla' => 50], 'arretrato' => 'defunto', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 30], 'arretrato' => 'eredi', 'riferimento' => 'bruno', 'ruolo' => 'proprietario']);

    $voci = g3fVoci($s->ricalcola('Preventivo 2026', false));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and([$voci['Ugo']['cifra'], $voci['Ada']['cifra']])->toBe([59000, 18300])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()->and($voci['Ada']['parti_da_decidere'])->toBeTrue();
    expect($voci['Ugo']['frase'])->toContain(' Ugo ha su quest\'unità più di una successione (la proprietà, con gli eredi Ada e Anna; la nuda proprietà, con l\'erede Carla (l\'arretrato resta a suo nome)).')
        ->toContain(' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede il programma non la passa ai suoi eredi, Bruno.')
        ->toEndWith(G3F_CHIUSURA)->not->toContain('€ 177');
    expect($voci['Ada']['frase'])->toContain(' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede il programma non la passa ai suoi eredi, Bruno.');
});
