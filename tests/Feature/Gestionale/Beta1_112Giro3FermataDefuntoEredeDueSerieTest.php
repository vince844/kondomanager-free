<?php

/**
 * Coda 480 (decisione 190.1): la voce del defunto che è anche erede legge i saldi come prima.
 *
 * La fermata mette le voci del caso nello stato della divisione da decidere (eredi con la sola quota, nessuna strada del Wallet). Ma il codice della fermata,
 * con la divisione, spegneva anche la lettura dei saldi: la voce non diceva più la parte due volte (decisioni 164.1 e 170), che prima della fermata diceva, e
 * le altre voci della stessa unità non trovavano più i saldi non legati di una persona come parte di una voce sola (decisione 167). La fermata dice, non sposta:
 * i saldi si leggono con la divisione del motore, la voce si mostra da decidere.
 *
 * Fonte delle cifre, la regola a mano. Spesa «Spese generali» € 3.650,00 (365000) sull'unità A da 1000 millesimi, 1000 centesimi al giorno per il 100 %; 365 giorni
 * nel 2026 (59 fino al 1/3, 120 fino al 1/5; dal 1/3 al 30/4 61 giorni, dopo il 1/5 245).
 * - AD1: Ugo e Ada al 50 %, Ugo muore il 1/3 (eredi Ada 25, Anna 25), Ada il 1/5 (eredi Anna 45, Bruno 30). Ugo 29500 (le parti Ada 14750, Anna 14750); Ada
 *   75250 (Anna 45150, Bruno 30100); Anna dei giorni 186750, Bruno 73500. Le sei righe legate e una riga non legata di Anna di € 147,50: la generazione le assorbe
 *   tutte e due, Anna 186750 + 14750 + 45150 + 14750 = 261400 (la parte di Ugo due volte). Con la riga non legata di Ugo di −€ 295,00 accanto alla legata: Ugo
 *   29500 − 29500 − 29500 = −29500.
 * - Il terzo defunto: Ugo 20, Pia 20, Ada 60. Ugo e Pia muoiono il 1/3 (Ugo: Ada 10 e Anna 10; Pia: Anna 10 e Bruno 10), Ada (70) il 1/5 (Anna 40, Bruno 30). Ugo
 *   0,2 × 59 × 1000 = 11800 (Ada 5900, Anna 5900); Pia 11800 (Anna 5900, Bruno 5900); Ada 0,6 × 120 × 1000 + 0,1 × 61 × 1000 = 78100 (Anna 78100 × 40 / 70 =
 *   44628,57 → 44629, Bruno 33471). Le parti di Anna da Ugo e da Pia sono uguali al centesimo: una sola riga non legata di € 59,00 è la parte di una voce sola,
 *   la prima (Ugo). Con un riporto di € 50,00 (€ 109,00) Anna è incerta in tutte e tre: le sue parti insieme 5900 + 5900 + 44629 = 56429.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

function g3fdsScenario($test, array $unita, array $titolari, array $morti, int $spesa = 365000, array $prima = []): array
{
    $s = Beta51Scenario::nuovo($test);
    foreach ($prima as [$box, $di]) {
        $s->pertinenza($box, $di);
    }
    $s->tabella('Proprietà', $unita);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', $spesa, ['Proprietà' => 100]);
    foreach ($titolari as [$u, $chi, $quota]) {
        $s->titolare($u, $chi, 'proprietario', $quota);
    }
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $sub = [];
    foreach ($morti as $m) {
        [$u, $d, $il, $eredi, $rif] = $m;
        $sub[$d . ':' . $u] = $s->passaggio('successione', $u, $d, null, $il, ['eredi' => $eredi, 'arretrato' => $m[5] ?? 'eredi', 'riferimento' => $rif] + ($m[6] ?? []));
    }

    return [$s, $sub];
}

function g3fdsWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null, string $unita = 'A'): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita[$unita]->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

function g3fdsGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto nella prova'] : []);
}

/** Le voci, per «nome del defunto» o, con più unità, per «nome:unità». */
function g3fdsVoci(TestResponse $r, bool $perUnita = false): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')
        ->keyBy(fn ($v) => $perUnita ? $v['defunto']['nome'] . ':' . $v['immobile_nome'] : $v['defunto']['nome'])->all();
}

function g3fdsAd1($test): array
{
    return g3fdsScenario($test, ['A' => 1000], [['A', 'ugo', 50], ['A', 'ada', 50]],
        [['A', 'ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['A', 'ada', '2026-05-01', ['anna' => 45, 'bruno' => 30], 'anna']]);
}

it('Coda 480 — la parte due volte di Anna nella voce di Ugo (le sei righe legate e una riga non legata di Anna di € 147,50): la voce della fermata la dice, con la tabella, e le somme non cambiano', function () {
    [$s, $sub] = g3fdsAd1($this);
    g3fdsWallet($this, $s, 'ugo', -29500, $sub['ugo:A']);
    g3fdsWallet($this, $s, 'ada', 14750, $sub['ugo:A']);
    g3fdsWallet($this, $s, 'anna', 14750, $sub['ugo:A']);
    g3fdsWallet($this, $s, 'ada', -75250, $sub['ada:A']);
    g3fdsWallet($this, $s, 'anna', 45150, $sub['ada:A']);
    g3fdsWallet($this, $s, 'bruno', 30100, $sub['ada:A']);
    g3fdsWallet($this, $s, 'anna', 14750);

    $voci = g3fdsVoci(g3fdsGenera($this, $s));

    g3fdsGenera($this, $s, true)->assertSessionHasNoErrors();
    $somme = $s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']);
    // Dalla regola a mano: Anna 186750 + 14750 + 45150 + 14750 = 261400 (la parte di Ugo due volte); Ugo 0, Ada 14750, Bruno 103600.
    expect($somme)->toBe(['ugo' => 0, 'ada' => 14750, 'anna' => 261400, 'bruno' => 103600]);
    // Prima della fermata la voce di Ugo lo diceva («La parte di Anna entra due volte», con la tabella): la fermata non lo deve far tacere.
    expect($voci['Ugo']['frase'])->toEndWith(G3FDS_CHIUSURA . ' La parte di Anna entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.')
        ->toContain(' Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50, il programma non la passa ai suoi eredi, Anna e Bruno.')
        ->not->toContain('Non serve registrare altro')->not->toContain('registra dal Wallet')
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()
        ->and(array_column($voci['Ugo']['eredi'], 'cifra', 'nome'))->toBe(['Ada' => null, 'Anna' => null])
        ->and(array_column($voci['Ugo']['due_serie'], 'nome'))->toBe(['Anna'])
        ->and($voci['Ugo']['due_serie'][0]['legata']['cifra_formattata'])->toBe('€ 147,50 a debito')
        ->and($voci['Ugo']['due_serie'][0]['non_legata']['cifra_formattata'])->toBe('€ 147,50 a debito');
    // La voce di Ada non ha due serie (la riga non legata di Anna non è la sua parte di Ada, € 451,50): la fermata com'è.
    expect($voci['Ada']['frase'])->toEndWith(G3FDS_CHIUSURA)->and($voci['Ada']['due_serie'])->toBe([]);
    // Tolta la serie non legata, la voce torna la fermata senza le due serie, e le somme sono quelle delle sei righe.
    \App\Models\Saldo::whereNull('passaggio_id')->where('anagrafica_id', $s->persona('anna')->id)->whereNull('subentro_id')->delete();
    $dopo = g3fdsVoci(g3fdsGenera($this, $s));
    expect($dopo['Ugo']['frase'])->toEndWith(G3FDS_CHIUSURA)->and($dopo['Ugo']['due_serie'])->toBe([]);
});

const G3FDS_CHIUSURA = ' La divisione fra gli eredi la decide l\'amministratore: il programma non ne propone le parti né le righe del Wallet, e nessuna riga del Wallet chiude questa voce, che torna a ogni generazione.';


it('Coda 480 — la parte due volte del defunto stesso (Ugo, la riga legata e una non legata di −€ 295,00): la voce della fermata la dice', function () {
    [$s, $sub] = g3fdsAd1($this);
    g3fdsWallet($this, $s, 'ugo', -29500, $sub['ugo:A']);
    g3fdsWallet($this, $s, 'ugo', -29500);
    g3fdsWallet($this, $s, 'anna', 14750, $sub['ugo:A']);
    g3fdsWallet($this, $s, 'ada', 14750, $sub['ugo:A']);

    $voci = g3fdsVoci(g3fdsGenera($this, $s));

    g3fdsGenera($this, $s, true)->assertSessionHasNoErrors();
    $somme = $s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']);
    // Ugo 29500 − 29500 − 29500 = −29500 (un credito a nome di un defunto); Ada 75250 + 14750 = 90000; Anna 186750 + 14750 = 201500; Bruno 73500.
    expect($somme)->toBe(['ugo' => -29500, 'ada' => 90000, 'anna' => 201500, 'bruno' => 73500]);
    expect($voci['Ugo']['frase'])->toEndWith(G3FDS_CHIUSURA . ' La parte di Ugo entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione.')
        ->and(array_column($voci['Ugo']['due_serie'], 'nome'))->toBe(['Ugo']);
});


it('Coda 480 — un terzo defunto (Pia) e una sola riga non legata di Anna uguale alla sua parte di Ugo e a quella di Pia: è la parte di una voce sola (Ugo), e la voce di Pia chiede il saldo legato come prima della fermata', function () {
    [$s, $sub] = g3fdsScenario($this, ['A' => 1000], [['A', 'ugo', 20], ['A', 'pia', 20], ['A', 'ada', 60]], [
        ['A', 'ugo', '2026-03-01', ['ada' => 10, 'anna' => 10], 'ada'], ['A', 'pia', '2026-03-01', ['anna' => 10, 'bruno' => 10], 'anna'],
        ['A', 'ada', '2026-05-01', ['anna' => 40, 'bruno' => 30], 'anna'],
    ]);
    g3fdsWallet($this, $s, 'anna', 5900);
    g3fdsWallet($this, $s, 'pia', -11800, $sub['pia:A']);
    g3fdsWallet($this, $s, 'bruno', 5900, $sub['pia:A']);

    $voci = g3fdsVoci(g3fdsGenera($this, $s));
    expect([$voci['Ugo']['cifra'], $voci['Pia']['cifra'], $voci['Ada']['cifra']])->toBe([11800, 11800, 78100]);
    // Come prima della fermata (decisione 167): la riga di Anna è la sua parte di una voce sola, la prima (Ugo); per Pia serve il saldo legato.
    expect($voci['Pia']['frase'])->toEndWith('Il saldo non legato di Anna (€ 59,00 a debito) è già la sua parte per Ugo: per quella di Pia serve il saldo legato.')
        ->not->toContain('Non serve registrare altro');
    expect($voci['Ugo']['frase'])->toEndWith(G3FDS_CHIUSURA)->and($voci['Ada']['frase'])->toEndWith(G3FDS_CHIUSURA);
    // Seguita la voce di Pia (la riga legata di Anna), e decisa la divisione di Ugo come la regola (art. 754 c.c.) senza toccare la riga non legata: le somme.
    g3fdsWallet($this, $s, 'anna', 5900, $sub['pia:A']);
    $voci = g3fdsVoci(g3fdsGenera($this, $s));
    expect($voci['Pia']['frase'])->toEndWith('Non serve registrare altro.')->and($voci['Pia']['due_serie'])->toBe([]);
});


it('Coda 480 — il terzo defunto (Pia) e la riga di Anna incerta in tutte e tre le voci (€ 109,00: la parte di € 59,00 e un riporto di € 50,00): la voce di Pia dice gli stessi saldi come prima della fermata', function () {
    // Le parti di Anna dalla divisione del motore: Ugo 5900, Pia 5900, Ada 44629; insieme 56429. La riga 10900 non è nessuna di loro: Anna è incerta in tutte e tre.
    [$s, $sub] = g3fdsScenario($this, ['A' => 1000], [['A', 'ugo', 20], ['A', 'pia', 20], ['A', 'ada', 60]], [
        ['A', 'ugo', '2026-03-01', ['ada' => 10, 'anna' => 10], 'ada'], ['A', 'pia', '2026-03-01', ['anna' => 10, 'bruno' => 10], 'anna'],
        ['A', 'ada', '2026-05-01', ['anna' => 40, 'bruno' => 30], 'anna'],
    ]);
    g3fdsWallet($this, $s, 'anna', 10900);
    g3fdsWallet($this, $s, 'pia', -11800, $sub['pia:A']);
    g3fdsWallet($this, $s, 'bruno', 5900, $sub['pia:A']);

    $voci = g3fdsVoci(g3fdsGenera($this, $s));
    expect($voci['Pia']['frase'])->toEndWith('Il saldo non legato di Anna è lo stesso anche per Ugo e Ada: comprende la sua parte per Ugo, per Pia e per Ada solo se le comprende tutte insieme, € 564,29 a debito; per ogni parte che non comprende serve il saldo legato.');
    expect($voci['Ugo']['frase'])->toEndWith(G3FDS_CHIUSURA)->and($voci['Ada']['frase'])->toEndWith(G3FDS_CHIUSURA);
});
