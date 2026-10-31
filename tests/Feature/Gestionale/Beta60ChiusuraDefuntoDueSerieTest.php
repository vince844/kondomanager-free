<?php

/**
 * Coda 332, decisione 164.1 — la parte del defunto in due serie: il cancello le vede tutte e due e si ferma.
 *
 * Una persona (il defunto o un erede) può avere la sua parte due volte nei saldi che la generazione assorbe: le righe legate alla successione
 * e, accanto, saldi non legati della stessa cifra (scritti a mano, o importati). La generazione le prende tutte e due (`GenerateSaldiAction`,
 * per gestione: libere o già di questo piano), e la parte entra due volte. Il cancello (`DefuntoNelPiano::chiHaLaParte`) confronta chi ha righe
 * legate con quelle, e un riporto accanto non conta; ma un saldo non legato della stessa cifra della parte è la seconda serie. La voce lo dice
 * con una frase, i nomi e la strada (quale si toglie dal Wallet, che mostra un solo esercizio aperto: `FermataDopoScrivi::fuoriDalWallet`), e con
 * una tabella, la chiave `due_serie`: per persona la serie legata e quella non legata, ognuna con la cifra, gli esercizi e se il Wallet la mostra.
 * Quale togliere lo decide l'amministratore.
 *
 * Fonte dell'atteso: la decisione 164.1 («il cancello vede tutte e due le serie e si ferma, con i nomi e le cifre di ognuna, finché
 * l'amministratore non toglie quella in più») e la decisione 170 (una frase sola sopra e una tabella: persona, la serie legata alla successione,
 * quella non legata, l'esercizio, se si toglie dal Wallet; la strada resta «togline una per ognuno dal Wallet», il piano assorbe quella che resta;
 * nessuna cifra cambia). Le cifre sono il conto a mano di `Beta51PianoRateDefuntoNelPianoTest` (O-62-2): Ugo 1/1–30/4 =
 * 120 giorni su 365 di € 1.200,00 = € 394,52; Anna 60 % = € 236,71, Bruno 40 % = € 157,81; le quote del motore dopo il decesso Anna
 * (120000 − 39452) × 60 % = 48329, Bruno 32219. Nella tabella ogni serie ha la cifra della parte con il segno del Wallet (positivo = debito):
 * Ugo € 394,52 a credito, Anna € 236,71 a debito, Bruno € 157,81 a debito.
 *
 * Lo scenario è quello della gestione ordinaria riusata (`CondominioService::createDefaultGestione` su ogni esercizio, come l'importatore): il 2026
 * chiuso, il 2027 aperto, il piano del 2026.
 *
 * Con il piano che non applica i saldi le due serie non si leggono (questo piano non le assorbe): la frase della V2 resta com'era, e le due serie le
 * dice il cancello del piano creato di nuovo.
 *
 * Che cosa NON copre: MySQL; la parte doppia nascosta da un riporto nella stessa somma (i saldi non legati di una persona si confrontano sommati);
 * come il pannello mette la tabella sotto la frase (`DestinatariWarningDefunto.test.ts`).
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
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

/** Le parti, in centesimi con il segno del Wallet (positivo = debito): Ugo ha il credito, gli eredi il debito. */
const B60S_PARTE = ['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781];

/**
 * Unità A da 1000 millesimi, Ugo proprietario dal 2019, «Spese generali» € 1.200,00; il 2025, il 2026 e il 2027 con la gestione ordinaria
 * agganciata a ognuno come la aggancia l'importatore. Resta aperto solo il 2027.
 *
 * @return array{0: Beta51Scenario, 1: array<int, Esercizio>}
 */
function b60sScenario($test): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2025, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo');

    return [$s, $es];
}

/** Lascia aperto solo l'esercizio dell'anno dato. @param array<int, Esercizio> $es */
function b60sApertoSolo(array $es, int $anno): void
{
    foreach ($es as $a => $e) {
        $e->update(['stato' => $a === $anno ? 'aperto' : 'chiuso']);
    }
}

/** La successione di Ugo (decesso 1/5/2026) con l'arretrato agli eredi, Anna 60 % e Bruno 40 %, dalla rotta. */
function b60sSuccessione(Beta51Scenario $s): Subentro
{
    return $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
}

/** La parte di una persona dalla porta del Wallet, che scrive nell'esercizio aperto: legata alla successione se `$passaggio` c'è. */
function b60sWallet($test, Beta51Scenario $s, string $chi, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => B60S_PARTE[$chi],
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** La parte di una persona (o la cifra data), non legata, in un esercizio dato, come la scrive l'importatore dei saldi (nell'esercizio del file). */
function b60sImportato(Beta51Scenario $s, Esercizio $e, string $chi, ?int $cents = null): void
{
    Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id,
        'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents ?? B60S_PARTE[$chi], 'origine' => 'importato', 'is_applicato' => false]);
}

/** Il piano ordinario del 2026 sulla gestione riusata, che applica i saldi (non ancora generato). */
function b60sPiano(Beta51Scenario $s, Esercizio $e2026): string
{
    $s->e = $e2026;
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');

    return 'Preventivo 2026';
}

/** La generazione del piano dalla rotta della pagina del piano («Genera», la stessa del ricalcolo), con o senza la presa d'atto del cancello. */
function b60sGenera($test, Beta51Scenario $s, string $piano, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano($piano)]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto nella prova'] : []);
}

/** La voce del defunto nel flash del cancello. */
function b60sVoce(TestResponse $r): ?array
{
    return Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
}

/** Toglie dalla porta del Wallet le righe di una persona, legate alla successione o no. */
function b60sTogli($test, Beta51Scenario $s, string $chi, bool $legate): void
{
    foreach (Saldo::where('anagrafica_id', $s->persona($chi)->id)->where('immobile_id', $s->unita['A']->id)->whereNull('subentro_id')
        ->when($legate, fn ($q) => $q->whereNotNull('passaggio_id'), fn ($q) => $q->whereNull('passaggio_id'))->get() as $x) {
        $test->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $x]))->assertSessionHasNoErrors()->assertRedirect('/saldi');
    }
}

/**
 * Lo scenario della prova: il piano del 2026 creato quando il 2027 è aperto, la successione, la parte non legata dal Wallet (nel 2027) e poi le
 * righe legate dal Wallet (nel 2027), come chiedeva il cancello che quella parte non la vedeva.
 *
 * @return array{0: Beta51Scenario, 1: array<int, Esercizio>, 2: string, 3: Subentro}
 */
function b60sDueSerieNel2027($test): array
{
    [$s, $es] = b60sScenario($test);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($test, $s, $chi);
    }
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($test, $s, $chi, $sub);
    }
    expect(Saldo::where('esercizio_id', $es[2027]->id)->whereNull('subentro_id')->count())->toBe(6);

    return [$s, $es, $piano, $sub];
}

/**
 * Le due serie di tutti e tre nell'esercizio aperto: la frase dice i nomi e la strada, le cifre e l'esercizio stanno nella tabella (decisione 170,
 * la forma scelta da Vincenzo).
 */
const B60S_TUTTI_NEL_2027 = 'Le parti di Ugo, Anna e Bruno entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.';

/** Le parti della regola come le dice la tabella, con il verso del Wallet: il credito di Ugo, il debito degli eredi. */
const B60S_DETTA = ['ugo' => '€ 394,52 a credito', 'anna' => '€ 236,71 a debito', 'bruno' => '€ 157,81 a debito'];

/**
 * Una serie nella tabella `due_serie`: la cifra già detta con il verso (decisione 171: la cifra intera non viaggia, la pagina legge il testo), gli
 * esercizi in ordine di data e se il Wallet la mostra.
 */
function b60sSerie(string $detta, array $esercizi, bool $nelWallet): array
{
    return ['cifra_formattata' => $detta, 'esercizi' => $esercizi, 'nel_wallet' => $nelWallet];
}

/** Una serie con la parte della regola di una persona. */
function b60sParte(string $chi, array $esercizi, bool $nelWallet): array
{
    return b60sSerie(B60S_DETTA[$chi], $esercizi, $nelWallet);
}

/** La riga di una persona nella tabella: le due serie, e `esercizio` quando tutte e due stanno in un solo esercizio, lo stesso (altrimenti null). */
function b60sRiga(Beta51Scenario $s, string $chi, array $legata, array $nonLegata, ?string $esercizio): array
{
    return ['id' => $s->persona($chi)->id, 'nome' => ucfirst($chi), 'legata' => $legata, 'non_legata' => $nonLegata, 'esercizio' => $esercizio];
}

/** La tabella delle due serie nel 2027, tutte nel Wallet, per le persone date nell'ordine della frase. */
function b60sTabellaNel2027(Beta51Scenario $s, array $chi = ['ugo', 'anna', 'bruno']): array
{
    return array_map(fn (string $c) => b60sRiga($s, $c, b60sParte($c, ['Esercizio 2027'], true), b60sParte($c, ['Esercizio 2027'], true), 'Esercizio 2027'), $chi);
}

// --- Il cancello vede le due serie ---------------------------------------------------------------------------------------------

it('Coda 332 — la parte di tutti e tre in due serie nell\'esercizio aperto: la generazione dalla pagina del piano si ferma, la frase dice i nomi e la strada e la tabella le due serie di ognuno, e non che i saldi ci sono già', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);

    $r = b60sGenera($this, $s, $piano);
    $voce = b60sVoce($r);
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)
        ->and(collect($voce['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => $e['cifra']])->all())->toBe(['Anna' => 23671, 'Bruno' => 15781])
        // Decisione 170: la frase viene subito dopo la parte degli eredi, e le cifre sono nella tabella.
        ->and($voce['frase'])->toEndWith('Anna € 236,71 e Bruno € 157,81. '.B60S_TUTTI_NEL_2027)
        ->and($voce['due_serie'])->toBe(b60sTabellaNel2027($s))
        ->and($voce['frase'])->not->toContain('Non serve registrare altro')
        ->and($voce['frase'])->not->toContain('I saldi ci sono già')
        // Il cancello si è fermato: nessuna rata.
        ->and($s->piano($piano)->rate()->count())->toBe(0);
});

it('Coda 332 — ogni serie della tabella porta la cifra già detta con il verso, gli esercizi e se il Wallet la mostra, e nient\'altro: la cifra intera non viaggia', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['due_serie'])->toHaveCount(3);
    foreach ($voce['due_serie'] as $riga) {
        expect(array_keys($riga))->toBe(['id', 'nome', 'legata', 'non_legata', 'esercizio']);
        foreach (['legata', 'non_legata'] as $serie) {
            expect(array_keys($riga[$serie]))->toBe(['cifra_formattata', 'esercizi', 'nel_wallet']);
        }
    }
});

it('Coda 332 — la rotta di creazione con «genera subito» si ferma con la stessa frase e la stessa tabella delle due serie, e il piano non nasce', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi);
        b60sWallet($this, $s, $chi, $sub);
    }

    $r = $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $es[2026]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'nome' => 'Consuntivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ]);
    $voce = b60sVoce($r);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeTrue()
        ->and($voce['frase'])->toEndWith(' '.B60S_TUTTI_NEL_2027)
        ->and($voce['due_serie'])->toBe(b60sTabellaNel2027($s))
        ->and(PianoRate::where('nome', 'Consuntivo 2026')->count())->toBe(0);
});

it('Coda 332 — la rotta del ricalcolo, con le due serie già nel piano, dice le stesse due serie: le righe del piano si tolgono ancora dal Wallet', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);
    b60sGenera($this, $s, $piano, accetta: true)->assertSessionHasNoErrors();
    expect(Saldo::where('piano_rate_id', $s->piano($piano)->id)->whereNull('subentro_id')->count())->toBe(6);

    $voce = b60sVoce($s->ricalcola($piano, accetta: false));
    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toEndWith(' '.B60S_TUTTI_NEL_2027)
        ->and($voce['due_serie'])->toBe(b60sTabellaNel2027($s));
});

// --- La strada che la frase indica, dalla pagina ---------------------------------------------------------------------------------

it('Coda 332 — la frase dice il vero: accettato il cancello con le due serie, la parte entra due volte', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);
    b60sGenera($this, $s, $piano, accetta: true)->assertSessionHasNoErrors();

    // Conto a mano: Ugo 39452 − 39452 − 39452 = −39452; Anna 48329 + 23671 + 23671 = 95671; Bruno 32219 + 15781 + 15781 = 63781.
    expect($s->somme($piano, ['ugo', 'anna', 'bruno']))->toBe(['ugo' => -39452, 'anna' => 95671, 'bruno' => 63781]);
});

it('Coda 332 — seguendo la frase, tolta dal Wallet una serie per ognuno (per Ugo quella legata, per Anna e Bruno quella non legata), il cancello dice che i saldi ci sono già e la parte entra una volta', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);
    b60sTogli($this, $s, 'ugo', legate: true);
    b60sTogli($this, $s, 'anna', legate: false);
    b60sTogli($this, $s, 'bruno', legate: false);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Anna e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        // Nessuno ha più la parte due volte: la tabella è vuota.
        ->and($voce['due_serie'])->toBe([]);

    b60sGenera($this, $s, $piano, accetta: true)->assertSessionHasNoErrors();
    // Conto a mano: Ugo 39452 − 39452 = 0; Anna 48329 + 23671 = 72000; Bruno 32219 + 15781 = 48000.
    expect($s->somme($piano, ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

it('Coda 332 — dopo la generazione con le due serie, tolta dal Wallet quella non legata di ognuno, il ricalcolo dice che i saldi ci sono già e la parte entra una volta', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);
    b60sGenera($this, $s, $piano, accetta: true)->assertSessionHasNoErrors();
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sTogli($this, $s, $chi, legate: false);
    }

    $voce = b60sVoce($s->ricalcola($piano, accetta: false));
    expect($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($voce['due_serie'])->toBe([]);
    $s->ricalcola($piano)->assertSessionHasNoErrors();
    expect($s->somme($piano, ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

// --- Le forme: chi ha due serie accanto a chi ne ha una, e dove stanno ---------------------------------------------------------

it('Coda 332 — Anna e Bruno con due serie e Ugo con una sola: la voce dice le due serie di Anna e di Bruno, e che la parte di Ugo entra una volta sola', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi);
    }
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // Decisione 170: «quella di Ugo» dopo la strada si leggerebbe riferito alla serie che resta: la frase dice «la parte».
    expect($voce['frase'])->toEndWith(' Le parti di Anna e Bruno entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' La parte di Ugo entra una volta sola.')
        ->and($voce['frase'])->not->toContain('Non serve registrare altro')
        ->and($voce['due_serie'])->toBe(b60sTabellaNel2027($s, ['anna', 'bruno']));
});

it('Coda 332 — la serie non legata di Ugo importata nel 2026 chiuso: il Wallet mostra solo quella legata, e la frase chiede di togliere quella che c\'è; per Anna tutte e due sono nel Wallet; Bruno ne ha una', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    b60sImportato($s, $es[2026], 'ugo');
    b60sWallet($this, $s, 'anna');
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // Tutti e due ne hanno una nel Wallet: la strada vale per ognuno, e per Ugo è quella che il Wallet mostra (la legata, del 2027).
    expect($voce['frase'])->toEndWith(' Le parti di Ugo e Anna entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Il Wallet mostra un solo esercizio aperto: per Ugo ce n\'è solo una, ed è quella da togliere. La parte di Bruno entra una volta sola.')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2027'], true), b60sParte('ugo', ['Esercizio 2026'], false), null),
            b60sRiga($s, 'anna', b60sParte('anna', ['Esercizio 2027'], true), b60sParte('anna', ['Esercizio 2027'], true), 'Esercizio 2027'),
        ]);

    // La strada passa: tolta dal Wallet la riga legata di Ugo e la non legata di Anna, il cancello dice che i saldi ci sono già.
    b60sTogli($this, $s, 'ugo', legate: true);
    b60sTogli($this, $s, 'anna', legate: false);
    $dopo = b60sVoce(b60sGenera($this, $s, $piano));
    expect($dopo['frase'])->toContain('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Anna e Bruno legati alla successione del 1 maggio 2026)')
        ->and($dopo['frase'])->toEndWith('Non serve registrare altro. Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.')
        ->and($dopo['due_serie'])->toBe([]);
});

it('Coda 332 — le righe legate scritte nel 2026 quando era aperto: per Ugo, con quella non legata importata nel 2026, nessuna delle due è nel Wallet e la frase non gli indica il Wallet; per Anna c\'è solo quella non legata, del 2027', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2026);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }
    b60sImportato($s, $es[2026], 'ugo');
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60sApertoSolo($es, 2027);
    b60sWallet($this, $s, 'anna');

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // La strada, e quella che resta, solo per Anna: per Ugo le serie restano due, e la frase dice che il Wallet non ne mostra nessuna.
    expect($voce['frase'])->toEndWith(' Le parti di Ugo e Anna entrano due volte. Per Anna togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Il Wallet mostra un solo esercizio aperto: per Anna ce n\'è solo una, ed è quella da togliere; per Ugo non ce n\'è nessuna. La parte di Bruno entra una volta sola.'
        // Bruno ha la sua riga legata nel 2026: la frase della Coda 332 lo dice, come prima.
        .' Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.')
        ->and($voce['frase'])->not->toContain('Togline una per ognuno')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2026'], false), b60sParte('ugo', ['Esercizio 2026'], false), 'Esercizio 2026'),
            b60sRiga($s, 'anna', b60sParte('anna', ['Esercizio 2026'], false), b60sParte('anna', ['Esercizio 2027'], true), null),
        ]);
});

it('Coda 332 — le sei serie nello stesso esercizio: la frase non nomina l\'esercizio né le cifre, che stanno nella tabella, una riga per persona', function () {
    [$s, , $piano] = b60sDueSerieNel2027($this);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // Decisione 170: sei serie (due per ognuno dei tre), tutte nel 2027. La frase dice i nomi e la strada; l'esercizio è la colonna della tabella.
    expect(substr_count($voce['frase'], 'Esercizio 2027'))->toBe(0)
        ->and($voce['frase'])->not->toContain('quella legata è di')
        ->and(array_column($voce['due_serie'], 'esercizio'))->toBe(['Esercizio 2027', 'Esercizio 2027', 'Esercizio 2027']);
});

it('Coda 332 — le serie di Ugo tutte e due nel 2026 chiuso e quelle di Anna tutte e due nel 2027 aperto: l\'esercizio sta su ogni riga della tabella; il resto lo toglie il Wallet solo per Anna', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2026);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }
    b60sImportato($s, $es[2026], 'ugo');
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60sApertoSolo($es, 2027);
    b60sWallet($this, $s, 'anna', $sub);
    b60sWallet($this, $s, 'anna');

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // Ognuna delle due persone ha le sue serie in un esercizio solo, ma non è lo stesso: la tabella lo dice su ogni riga. Tolta una serie, quella che
    // resta c'è per Anna; per Ugo le serie restano due.
    expect($voce['frase'])->toContain(' Le parti di Ugo e Anna entrano due volte. Per Anna togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Il Wallet mostra un solo esercizio aperto: per Ugo non ce n\'è nessuna. La parte di Bruno entra una volta sola.')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2026'], false), b60sParte('ugo', ['Esercizio 2026'], false), 'Esercizio 2026'),
            b60sRiga($s, 'anna', b60sParte('anna', ['Esercizio 2027'], true), b60sParte('anna', ['Esercizio 2027'], true), 'Esercizio 2027'),
        ]);
});

it('Coda 332 — Ugo con due serie, Anna con la sua riga legata, Bruno senza niente: la frase chiede la riga di Bruno e dice le due serie di Ugo', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    b60sWallet($this, $s, 'ugo');
    b60sWallet($this, $s, 'ugo', $sub);
    b60sWallet($this, $s, 'anna', $sub);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toContain('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 157,81 a debito di Bruno; il piano lo assorbe alla prossima generazione. Quello di Anna c\'è già.')
        ->and($voce['frase'])->toEndWith(' La parte di Ugo entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.')
        ->and($voce['frase'])->not->toContain('Quello di Ugo')
        ->and($voce['due_serie'])->toBe(b60sTabellaNel2027($s, ['ugo']));
});

it('Coda 332 — la serie non legata di Ugo divisa fra il 2025 e il 2026, quella di Anna nel 2026 con un centesimo in più: per tutti e due nel Wallet ci sono solo quelle legate', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    b60sImportato($s, $es[2025], 'ugo', -20000);
    b60sImportato($s, $es[2026], 'ugo', -19452);
    // € 236,72 invece di € 236,71: il centesimo di resto non distingue la parte (`chiHaLaParte`, a meno di un centesimo).
    b60sImportato($s, $es[2026], 'anna', 23672);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith(' Le parti di Ugo e Anna entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Il Wallet mostra un solo esercizio aperto: per Ugo e Anna ce n\'è solo una per ognuno, ed è quella da togliere. La parte di Bruno entra una volta sola.')
        // La serie non legata di Ugo: € 200,00 + € 194,52 = € 394,52 a credito, in due esercizi in ordine di data; quella di Anna con il centesimo in più.
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2027'], true), b60sSerie('€ 394,52 a credito', ['Esercizio 2025', 'Esercizio 2026'], false), null),
            b60sRiga($s, 'anna', b60sParte('anna', ['Esercizio 2027'], true), b60sSerie('€ 236,72 a debito', ['Esercizio 2026'], false), null),
        ]);
});

it('Coda 332 — le righe di una serie scritte fuori dall\'ordine di data: gli esercizi della tabella restano in ordine di data, e la cifra è la somma di tutte', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    // I nomi degli esercizi sono liberi: il 2025 si chiama «Gestione 2025», che in ordine alfabetico viene dopo «Esercizio 2026».
    $es[2025]->update(['nome' => 'Gestione 2025']);
    // Il credito di Ugo (€ 394,52 = 120 giorni su 365 di € 1.200,00) come serie non legata in due righe: prima quella del 2026 (€ 194,52), poi quella
    // del 2025 (€ 200,00). La riga del 2026 ha l'id più basso, e il 2026 è stato creato prima del 2025: né l'ordine di scrittura né quello degli id
    // o dei nomi degli esercizi è quello delle date, che è l'unico che la tabella deve dire.
    b60sImportato($s, $es[2026], 'ugo', -19452);
    b60sImportato($s, $es[2025], 'ugo', -20000);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    $ugo = collect($voce['due_serie'])->firstWhere('nome', 'Ugo');
    expect($ugo['non_legata'])->toBe(b60sSerie('€ 394,52 a credito', ['Gestione 2025', 'Esercizio 2026'], false))
        ->and($ugo['legata'])->toBe(b60sParte('ugo', ['Esercizio 2027'], true))
        ->and($ugo['esercizio'])->toBeNull();
});

it('Coda 332 — le due serie di Ugo divise tutte e due fra il 2026 e il 2027: la tabella dice gli esercizi su ogni serie e non in una colonna sola; il Wallet non ne mostra nessuna intera', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2026);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    // Il credito di Ugo in due righe per serie: € 200,00 nel 2026 e € 194,52 nel 2027, legate dalla porta del Wallet e non legate (la prima importata).
    $scrivi = fn (int $cents, ?Subentro $passaggio) => $this->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona('ugo')->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
    $scrivi(-20000, $sub);
    b60sWallet($this, $s, 'anna', $sub);
    b60sWallet($this, $s, 'bruno', $sub);
    b60sImportato($s, $es[2026], 'ugo', -20000);
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60sApertoSolo($es, 2027);
    $scrivi(-19452, $sub);
    $scrivi(-19452, null);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    // Ogni serie ha una riga nel 2026, che il Wallet non mostra: nessuna delle due si toglie intera dal Wallet, e la frase non gli indica il Wallet.
    expect($voce['frase'])->toContain(' La parte di Ugo entra due volte. Il Wallet mostra un solo esercizio aperto: per Ugo non ce n\'è nessuna.')
        ->and($voce['frase'])->not->toContain('togli')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2026', 'Esercizio 2027'], false), b60sParte('ugo', ['Esercizio 2026', 'Esercizio 2027'], false), null),
        ]);
});

it('Coda 332 — le righe legate di tutti nel 2026 chiuso, e quelle non legate di Anna e Bruno nel 2027: per tutti e due nel Wallet ci sono solo quelle non legate', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2026);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60sApertoSolo($es, 2027);
    b60sWallet($this, $s, 'anna');
    b60sWallet($this, $s, 'bruno');

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith(' Le parti di Anna e Bruno entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.'
        .' Il Wallet mostra un solo esercizio aperto: per Anna e Bruno ce n\'è solo una per ognuno, ed è quella da togliere. La parte di Ugo entra una volta sola.'
        .' Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'anna', b60sParte('anna', ['Esercizio 2026'], false), b60sParte('anna', ['Esercizio 2027'], true), null),
            b60sRiga($s, 'bruno', b60sParte('bruno', ['Esercizio 2026'], false), b60sParte('bruno', ['Esercizio 2027'], true), null),
        ]);
});

it('Coda 332 — le due serie di Ugo tutte e due nel 2026 chiuso: la voce le dice e non chiede niente al Wallet, che non le mostra', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2026);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }
    b60sImportato($s, $es[2026], 'ugo');
    $this->travelTo(now()->setDate(2027, 6, 15));
    b60sApertoSolo($es, 2027);

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith(' La parte di Ugo entra due volte. Il Wallet mostra un solo esercizio aperto: per Ugo non ce n\'è nessuna. Le parti di Anna e Bruno entrano una volta sola.'
        .' I saldi dell\'esercizio «Esercizio 2026» non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.')
        ->and($voce['frase'])->not->toContain('quella che resta')
        ->and($voce['frase'])->not->toContain('togli')
        ->and($voce['due_serie'])->toBe([
            b60sRiga($s, 'ugo', b60sParte('ugo', ['Esercizio 2026'], false), b60sParte('ugo', ['Esercizio 2026'], false), 'Esercizio 2026'),
        ]);
});

it('Coda 332 — il piano che non applica i saldi, con le due serie: la frase dice di crearlo di nuovo; eliminato e creato di nuovo dalle rotte, il cancello dice le due serie', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $s->e = $es[2026];
    // In bozza: un piano approvato si riporta in bozza prima di eliminarlo (`PianoRateController::destroy`).
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05', bozza: true, applicaSaldi: false);
    $sub = b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi);
        b60sWallet($this, $s, $chi, $sub);
    }

    // Questo piano non assorbe niente: la frase della V2 con le righe legate già registrate, come prima.
    $voce = b60sVoce(b60sGenera($this, $s, 'Preventivo 2026'));
    expect($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['frase'])->toEndWith('Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote. Le parti di Ugo, Anna e Bruno ci sono già, nei saldi legati alla successione. Crea di nuovo il piano, che assorbe i saldi legati alla successione, e non registrare altro. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.')
        ->and($voce['due_serie'])->toBe([]);

    // Seguendo la frase: il piano si elimina dalla sua pagina e si crea di nuovo; il piano nuovo applica i saldi, e il suo cancello vede le due serie.
    $this->actingAs($s->admin)->from('/piani')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $es[2026], $s->piano('Preventivo 2026')]))->assertSessionHasNoErrors();
    expect(PianoRate::where('nome', 'Preventivo 2026')->count())->toBe(0);
    $r = $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $es[2026]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ]);
    $nuova = b60sVoce($r);
    expect($nuova['piano_applica_saldi'])->toBeTrue()
        ->and($nuova['frase'])->toEndWith(' '.B60S_TUTTI_NEL_2027)
        ->and($nuova['due_serie'])->toBe(b60sTabellaNel2027($s));
});

// --- Controprove: un riporto accanto alle righe legate non conta ----------------------------------------------------------------

it('controprova — Ugo con le righe legate e accanto un riporto non legato che non è la sua parte: il cancello dice che i saldi ci sono già, come prima', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    $sub = b60sSuccessione($s);
    // Un riporto di € 100,00 a debito di Ugo: non è la sua parte (€ 394,52 a credito), e non conta.
    $this->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona('ugo')->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => 10000,
    ])->assertSessionHasNoErrors();
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi, $sub);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($voce['frase'])->not->toContain('due volte')
        ->and($voce['due_serie'])->toBe([]);
});

it('controprova — la parte non legata di tutti e tre senza righe legate: una serie sola, il cancello dice che i saldi ci sono già, come prima', function () {
    [$s, $es] = b60sScenario($this);
    b60sApertoSolo($es, 2027);
    $piano = b60sPiano($s, $es[2026]);
    b60sSuccessione($s);
    foreach (['ugo', 'anna', 'bruno'] as $chi) {
        b60sWallet($this, $s, $chi);
    }

    $voce = b60sVoce(b60sGenera($this, $s, $piano));
    expect($voce['frase'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($voce['due_serie'])->toBe([]);
});
