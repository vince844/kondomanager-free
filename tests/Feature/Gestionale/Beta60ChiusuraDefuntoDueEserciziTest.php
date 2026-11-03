<?php

/**
 * Coda 332 — su una gestione agganciata a più esercizi, il cancello del defunto conta i saldi che la generazione assorbe davvero.
 *
 * La generazione prende i saldi per gestione, senza l'esercizio (`GenerateSaldiAction::execute`: liberi nel Wallet o già di questo piano): con la
 * gestione ordinaria riusata ogni anno (`CondominioService::createDefaultGestione`, l'importatore) un piano assorbe anche i saldi di un altro esercizio.
 * Il cancello del defunto (`DefuntoNelPiano::saldiCheEntrano`) li conta come la generazione, senza il filtro d'esercizio; chi occupa il posto del
 * Wallet (`FermataDopoScrivi::conUnSaldo`) resta per esercizio. Nessuna cifra cambia: la generazione è quella di prima, cambia ciò che il cancello dice.
 * Quando i saldi che il piano assorbe stanno in un esercizio diverso da quello aperto, la frase lo dice: il Wallet mostra un solo esercizio aperto.
 *
 * Fonte dell'atteso: la decisione 163.2 (D4, la Y: il cancello del defunto segue la generazione, nessuna cifra cambia) e la decisione 86.4 (V4: il
 * defunto e gli eredi hanno saldi che la generazione assorbe e quei saldi sono la parte). Le cifre sono quelle del conto a mano di
 * `Beta51PianoRateDefuntoNelPianoTest` (O-62-2): Ugo 1/1–30/4 = 120 giorni su 365 di € 1.200,00 = € 394,52; Anna 60 % = € 236,71, Bruno 40 % = € 157,81.
 *
 * Lo scenario lo costruisce come l'importatore: due esercizi, e la stessa gestione ordinaria agganciata a tutti e due con `createDefaultGestione`; poi il
 * 2026 si chiude e il 2027 resta l'esercizio aperto, quello che il Wallet mostra.
 *
 * Che cosa NON copre: MySQL; la parte in due serie (`Beta60ChiusuraDefuntoDueSerieTest`).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/**
 * Le rate del piano del 2026 assorbita la parte del 2027, come le scriveva la generazione sul codice di partenza (cifre lette dal programma: è il
 * confronto «prima e dopo»). La prima rata ha i saldi (`prima_rata`): Ugo 3288 − 39452 = −36164, Anna 4028 + 23671 = 27699, Bruno 2685 + 15781 = 18466;
 * le somme fanno 0, 72000 e 48000, il conto a mano del test.
 */
const B60D_RATE_DI_PRIMA = [
    1 => ['ugo' => -36164, 'anna' => 27699, 'bruno' => 18466],
    2 => ['ugo' => 3288, 'anna' => 4028, 'bruno' => 2685],
    3 => ['ugo' => 3288, 'anna' => 4028, 'bruno' => 2685],
    4 => ['ugo' => 3288, 'anna' => 4028, 'bruno' => 2685],
    5 => ['ugo' => 3288, 'anna' => 4028, 'bruno' => 2685],
    6 => ['ugo' => 3288, 'anna' => 4027, 'bruno' => 2685],
    7 => ['ugo' => 3288, 'anna' => 4027, 'bruno' => 2685],
    8 => ['ugo' => 3288, 'anna' => 4027, 'bruno' => 2685],
    9 => ['ugo' => 3287, 'anna' => 4027, 'bruno' => 2685],
    10 => ['ugo' => 3287, 'anna' => 4027, 'bruno' => 2685],
    11 => ['ugo' => 3287, 'anna' => 4027, 'bruno' => 2685],
    12 => ['ugo' => 3287, 'anna' => 4027, 'bruno' => 2684],
];

const B60D_RIGHE = '€ 394,52 a credito di Ugo, € 236,71 a debito di Anna e € 157,81 a debito di Bruno';

/**
 * Unità A da 1000 millesimi, Ugo proprietario dal 2019, «Spese generali» € 1.200,00; gli esercizi `$esercizi` (il primo è quello che `nuovo()` crea, il
 * 2026) con la gestione ordinaria agganciata a ognuno come la aggancia l'importatore. Alla fine resta aperto solo il 2027.
 *
 * @param list<string> $esercizi gli anni, nell'ordine d'importazione
 * @return array{0: Beta51Scenario, 1: array<string, Esercizio>}
 */
function b60dScenario($test, array $esercizi = ['2026', '2027']): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = ['2026' => $s->e];
    $gestioni = [];
    foreach ($esercizi as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo');

    return [$s, $es];
}

/** Chiude tutti gli esercizi tranne il 2027, come a gennaio dopo la chiusura del 2026. */
function b60dSoloIl2027Aperto(array $es): void
{
    foreach ($es as $anno => $e) {
        // Le chiavi «2026», «2027» per PHP sono numeri: il confronto è sul numero.
        $e->update(['stato' => (int) $anno === 2027 ? 'aperto' : 'chiuso']);
    }
}

/** Un saldo dalla porta del Wallet (positivo = debito), legato alla successione se `$passaggio` c'è: il Wallet scrive nell'esercizio aperto. */
function b60dWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** La parte di Ugo, Anna e Bruno dalla porta del Wallet (Ugo a credito, gli eredi a debito). */
function b60dParte($test, Beta51Scenario $s, ?Subentro $passaggio = null): void
{
    b60dWallet($test, $s, 'ugo', -39452, $passaggio);
    b60dWallet($test, $s, 'anna', 23671, $passaggio);
    b60dWallet($test, $s, 'bruno', 15781, $passaggio);
}

/** Un saldo libero, non legato, scritto in un esercizio dato come lo scrive l'importatore dei saldi (`LivelloSaldi`, nell'esercizio del file). */
function b60dSaldoImportato(Beta51Scenario $s, Esercizio $e, string $chi, int $cents): Saldo
{
    return Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id,
        'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'importato', 'is_applicato' => false]);
}

/** La successione di Ugo con l'arretrato agli eredi (Anna 60 %, Bruno 40 %), dalla rotta. */
function b60dSuccessione(Beta51Scenario $s, string $il): Subentro
{
    return $s->passaggio('successione', 'A', 'ugo', null, $il, ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
}

/** Il piano ordinario dell'anno sulla gestione riusata, che applica i saldi se non si dice altro (non ancora generato): `$s->e` diventa il suo esercizio. */
function b60dPiano(Beta51Scenario $s, Esercizio $e, string $anno, bool $applicaSaldi = true): string
{
    $s->e = $e;
    $s->pianoOrdinario("Preventivo {$anno}", 'Ordinaria', primaScadenza: "{$anno}-01-05", applicaSaldi: $applicaSaldi);

    return "Preventivo {$anno}";
}

/** Le quote di ogni rata, per persona: [numero_rata => [nome => centesimi]]. */
function b60dRate(Beta51Scenario $s, string $piano): array
{
    $nomi = collect($s->persone)->mapWithKeys(fn ($a, $chi) => [$a->id => $chi])->all();

    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s->piano($piano)->id)
        ->selectRaw('rate.numero_rata as n, rate_quote.anagrafica_id as a, SUM(rate_quote.importo) as t')->groupBy('rate.numero_rata', 'rate_quote.anagrafica_id')
        ->orderBy('rate.numero_rata')->orderBy('rate_quote.anagrafica_id')->get()
        ->groupBy('n')->map(fn ($g) => $g->mapWithKeys(fn ($r) => [$nomi[$r->a] ?? (string) $r->a => (int) $r->t])->all())->all();
}

// --- Il cancello vede i saldi che la generazione assorbe da un altro esercizio -------------------------------------------------

/**
 * Giugno 2027, il 2026 è chiuso: si crea il consuntivo del 2026 (il piano del 2026) sulla gestione riusata, si registra la successione di Ugo
 * (decesso 1/5/2026) e la parte dalla porta del Wallet, che la scrive nel 2027. La generazione del piano del 2026 assorbe quei saldi (per gestione).
 */
function b60dConsuntivoConLaParteNel2027($test): array
{
    [$s, $es] = b60dScenario($test);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    b60dParte($test, $s);
    expect(Saldo::where('esercizio_id', $es['2027']->id)->whereNull('subentro_id')->count())->toBe(3);

    return [$s, $es, $piano];
}

it('Coda 332 — il piano del 2026 creato quando il 2027 è l\'esercizio aperto: la parte registrata dal Wallet nel 2027, che la generazione assorbe, il cancello la vede e non la chiede di nuovo', function () {
    [$s, , $piano] = b60dConsuntivoConLaParteNel2027($this);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)
        ->and(collect($voce['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => $e['cifra']])->all())->toBe(['Anna' => 23671, 'Bruno' => 15781])
        // Il posto del Wallet è per esercizio: nel 2026 nessuno ha un saldo.
        ->and($voce['con_un_saldo'])->toBe([])
        ->and($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        // Il 2027 è l'esercizio aperto: il Wallet li mostra, e la frase non dice altro.
        ->and($voce['frase'])->not->toContain('non compaiono nel Wallet')
        ->and($voce['frase'])->not->toContain('registra dal Wallet');
});

it('Coda 332 — la parte nel 2027 con tutti e due gli esercizi aperti: il Wallet mostra un solo esercizio aperto, il 2026, e la frase non dice che mostra «l\'esercizio aperto»', function () {
    [$s, $es, $piano] = b60dConsuntivoConLaParteNel2027($this);
    // L'importatore lascia aperti gli esercizi che crea: il Wallet ne mostra uno, il primo con lo stato «aperto» (`HasEsercizio::getEsercizioCorrente`), il 2026.
    $es['2026']->update(['stato' => 'aperto']);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toEndWith(' I saldi dell\'esercizio «Esercizio 2027» non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.')
        ->and($voce['frase'])->not->toContain('mostra solo l\'esercizio aperto');
});

it('Coda 332 — la rotta del ricalcolo porta la stessa voce, con i saldi del 2027 già nel piano del 2026', function () {
    [$s, , $piano] = b60dConsuntivoConLaParteNel2027($this);
    $s->genera($piano, null);

    $r = $s->ricalcola($piano, accetta: false);
    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.');
});

it('Coda 332 — la parte registrata nel 2026 quando era l\'esercizio aperto, poi chiuso: il cancello la vede come prima e dice che il Wallet non la mostra', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60dScenario($this);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    b60dParte($this, $s);
    expect(Saldo::where('esercizio_id', $es['2026']->id)->whereNull('subentro_id')->count())->toBe(3);
    // Gennaio 2027: il 2026 si chiude, il 2027 è l'esercizio aperto.
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60dSoloIl2027Aperto($es);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toContain('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($voce['frase'])->toEndWith(' I saldi dell\'esercizio «Esercizio 2026» non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('Coda 332 — le righe legate alla successione scritte nel 2026, poi chiuso: il cancello le legge come prima e dice che il Wallet non le mostra', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60dScenario($this);
    $piano = b60dPiano($s, $es['2026'], '2026');
    $sub = b60dSuccessione($s, '2026-05-01');
    b60dParte($this, $s, $sub);
    expect(Saldo::where('esercizio_id', $es['2026']->id)->where('passaggio_id', $sub->id)->count())->toBe(3);
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60dSoloIl2027Aperto($es);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toContain('I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($voce['frase'])->toEndWith(' I saldi dell\'esercizio «Esercizio 2026» non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('Coda 332 — la parte divisa fra due esercizi che non sono quello aperto: la frase li nomina tutti e due, e non nomina quello aperto', function () {
    [$s, $es] = b60dScenario($this, ['2026', '2025', '2027']);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    // Il credito di Ugo diviso fra un saldo importato nel 2025 e uno nel 2026 (l'importatore scrive nell'esercizio del file); i due eredi dal Wallet, nel 2027.
    b60dSaldoImportato($s, $es['2025'], 'ugo', -20000);
    b60dSaldoImportato($s, $es['2026'], 'ugo', -19452);
    b60dWallet($this, $s, 'anna', 23671);
    b60dWallet($this, $s, 'bruno', 15781);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toContain('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo')
        ->and($voce['frase'])->toEndWith(' I saldi degli esercizi «Esercizio 2025» e «Esercizio 2026» non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('Coda 332 — un saldo solo della parte fuori dall\'esercizio aperto: la frase lo dice al singolare', function () {
    [$s, $es] = b60dScenario($this);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    // Il credito di Ugo importato nel 2026; i due eredi dal Wallet, nel 2027, che il Wallet mostra.
    b60dSaldoImportato($s, $es['2026'], 'ugo', -39452);
    b60dWallet($this, $s, 'anna', 23671);
    b60dWallet($this, $s, 'bruno', 15781);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toEndWith('Non serve registrare altro. Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('Coda 332 — solo Ugo ha la sua riga legata, nel 2026 chiuso: il cancello chiede quelle che mancano, dice che la sua c\'è già e che il Wallet non la mostra', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60dScenario($this);
    $piano = b60dPiano($s, $es['2026'], '2026');
    $sub = b60dSuccessione($s, '2026-05-01');
    b60dWallet($this, $s, 'ugo', -39452, $sub);
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60dSoloIl2027Aperto($es);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    // Chi cerca nel Wallet la riga di Ugo non la trova, e la porta ne accetta una nuova nell'esercizio aperto: la frase lo dice.
    expect($voce['frase'])->toContain('registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano, sulla stessa gestione: € 236,71 a debito di Anna e € 157,81 a debito di Bruno')
        ->and($voce['frase'])->toEndWith('Quello di Ugo c\'è già. Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('controprova — il piano che non applica i saldi non assorbe le righe legate del 2026: la frase non dice che le assorbe', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60dScenario($this);
    $piano = b60dPiano($s, $es['2026'], '2026', applicaSaldi: false);
    $sub = b60dSuccessione($s, '2026-05-01');
    b60dParte($this, $s, $sub);
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60dSoloIl2027Aperto($es);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['frase'])->toContain('Questo piano non applica i saldi del Wallet')
        ->and($voce['frase'])->not->toContain('non compaiono nel Wallet');
});

// --- Nessuna cifra cambia ---------------------------------------------------------------------------------------------------

it('Coda 332 — nessuna cifra cambia: accettato il cancello, il piano del 2026 assorbe la parte del 2027 e le rate sono quelle della generazione di prima', function () {
    [$s, $es, $piano] = b60dConsuntivoConLaParteNel2027($this);
    $s->genera($piano, null);

    // Conto a mano: Ugo 39452 − 39452 = 0; Anna (120000 − 39452) × 60 % = 48329 (O-62-2) + 23671 = 72000; Bruno (120000 − 39452) × 40 % = 32219 + 15781 = 48000.
    expect($s->somme($piano, ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000])
        // I tre saldi del 2027 sono ora del piano del 2026.
        ->and(Saldo::where('esercizio_id', $es['2027']->id)->whereNull('subentro_id')->where('piano_rate_id', $s->piano($piano)->id)->count())->toBe(3)
        // Rata per rata, come la generazione le scriveva prima di questa versione (cifre lette dal programma sul codice di partenza:
        // il cancello non scrive niente, e la generazione non cambia).
        ->and(b60dRate($s, $piano))->toBe(B60D_RATE_DI_PRIMA);
});

// --- Un saldo non legato di un altro esercizio che la generazione assorbe: incerto -------------------------------------------

/**
 * La V5 con un riporto non legato di Ugo (€ 100,00 a debito, non la sua parte): il programma non sa se comprende la parte, chiede le righe di Anna e
 * Bruno e, al condizionale, quella di Ugo.
 */
const B60D_V5_UGO = 'Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano (€ 236,71 a debito di Anna e € 157,81 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; il piano li assorbe alla prossima generazione.';

it('Coda 332 — un riporto non legato nel 2027 che non è la parte, e che la generazione del piano del 2026 assorbe: il cancello dice che non sa se comprende la parte; il posto del Wallet resta per esercizio', function () {
    [$s, $es] = b60dScenario($this);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    b60dWallet($this, $s, 'ugo', 10000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    // Decisione 164.3: un saldo non legato di un altro esercizio, che la generazione assorbe, fa «incerto» (V5) e non «manca» (V1). Il posto del
    // Wallet (`con_un_saldo`, `wallet`) resta per esercizio: nel 2026 Ugo non ha saldi.
    expect($voce['con_un_saldo'])->toBe([])
        ->and($voce['wallet'])->toBeTrue()
        ->and($voce['frase'])->toEndWith(' '.B60D_V5_UGO)
        ->and($voce['frase'])->not->toContain('non compare nel Wallet');
});

it('Coda 332 — un riporto non legato importato nel 2025 chiuso: il cancello dice che non sa se comprende la parte, e che il Wallet non lo mostra', function () {
    [$s, $es] = b60dScenario($this, ['2026', '2025', '2027']);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026');
    b60dSuccessione($s, '2026-05-01');
    b60dSaldoImportato($s, $es['2025'], 'ugo', 10000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['con_un_saldo'])->toBe([])
        ->and($voce['frase'])->toEndWith(' '.B60D_V5_UGO.' Il saldo dell\'esercizio «Esercizio 2025» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.');
});

it('controprova — il piano che non applica i saldi non assorbe il riporto del 2027: il cancello chiede i saldi legati di tutti, come prima', function () {
    [$s, $es] = b60dScenario($this);
    b60dSoloIl2027Aperto($es);
    $piano = b60dPiano($s, $es['2026'], '2026', applicaSaldi: false);
    b60dSuccessione($s, '2026-05-01');
    b60dWallet($this, $s, 'ugo', 10000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello($piano));
    expect($voce['frase'])->toContain('Questo piano non applica i saldi del Wallet: un saldo registrato adesso non entra qui. Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione ('.B60D_RIGHE.') e crea di nuovo il piano')
        ->and($voce['frase'])->not->toContain('non sa se');
});
