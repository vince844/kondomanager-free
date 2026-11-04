<?php

/**
 * Coda 463, decisione 188.1 — due defunti sulla stessa unità, l'erede senza righe legate: la regola della decisione 167 anche per la V5.
 *
 * Il cancello del defunto (`DefuntoNelPiano`) ha una voce per defunto e unità; i saldi non legati di una persona su quella gestione e quell'unità sono gli
 * stessi in ogni voce. La tabella della Coda 440 muoveva un erede alla volta con tutti gli altri «a posto»; qui le combinazioni fra persone: per l'erede senza
 * righe legate (Anna) i suoi saldi non legati letti come la parte in nessuna, in una o in tutte e due le voci; le quote uguali e diverse fra le due successioni;
 * l'altro erede (Bruno) con e senza la riga legata in ciascuna voce; i due eredi senza righe legate; un defunto senza la sua riga.
 *
 * La regola (decisione 188.1, la forma della decisione 167 anche per la V5):
 * - chi senza righe legate ha in una voce i saldi non legati che sono la sua parte, e in un'altra voce della stessa unità è «incerto» (il programma non sa se
 *   quei saldi comprendono la parte), lì manca: la voce chiede la sua riga legata e dice che quei saldi sono già la sua parte per l'altro defunto (N2);
 * - chi è incerto in più voci della stessa unità senza che nessuna legga quei saldi come la sua parte, nelle voci dopo la prima ha in fondo una frase che dice
 *   che sono gli stessi saldi, che comprendono la sua parte in tutte le voci solo se comprendono le parti insieme, e che per quella che non comprendono serve il
 *   saldo legato (N1).
 *
 * Fonte delle cifre, la regola, a mano (la stessa del docblock della Coda 440): «Spese generali» € 1.200,00, decesso il 1/5/2026, 120 giorni su 365.
 * - Quote uguali: Ugo e Ada al 50 %, eredi Anna 30 e Bruno 20 della quota di ognuno. La cifra di ognuno 60000 × 120/365 = 19726,03 → € 197,26; Anna 60 % =
 *   11835,6 → € 118,36, Bruno 7890,4 → € 78,90. Dopo il decesso Anna 120000 × 245/365 × 60 % = 48328,77 → 48329 (il centesimo al resto maggiore), Bruno 32219.
 * - Quote diverse: Ugo 60 %, Ada 40 %, eredi Anna 36 e Bruno 24 di Ugo, Anna 24 e Bruno 16 di Ada. Ugo 72000 × 120/365 = 23671,23 → 23671, Ada 15780,82 →
 *   15781 (resti maggiori con Anna 48328,77 → 48329 e Bruno 32219,18 → 32219). Le parti: di Ugo Anna 14202,6 → 14203 e Bruno 9468; di Ada Anna 9468,6 → 9469
 *   e Bruno 6312.
 * - Alla fine, se ognuno paga le sue parti una volta: Anna 48329 + 2 parti = 72001 (con le quote uguali 2 × 11836, con le diverse 14203 + 9469), Bruno 32219 +
 *   2 parti = 47999, i defunti 0; un riporto di € 50,00 non è la parte ed entra accanto: 77001 (o 52999 per Bruno).
 *
 * Le frasi delle varianti già decise (V1, V4, V5, la parte nella voce di un altro defunto) sono quelle del programma; la frase N1 è nuova.
 *
 * Che cosa NON copre: MySQL. Il piano creato con la richiesta è provato in `Beta1_112Giro3DefuntiStatiTest.php` («alla creazione del piano»); il defunto che è
 * anche erede dell'altro (Coda 480) nei file `Beta1_112Giro3FermataDefuntoErede*Test.php`.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\CondominioService;
use App\Services\Subentro\DefuntoNelPiano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto, dove la porta del Wallet scrive.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** Le quote di proprietà e degli eredi, con le quote uguali (U) e diverse (D) fra le due successioni. */
const G3D_QUOTE = [
    'U' => ['proprieta' => ['ugo' => 50, 'ada' => 50], 'ugo' => ['anna' => 30, 'bruno' => 20], 'ada' => ['anna' => 30, 'bruno' => 20]],
    'D' => ['proprieta' => ['ugo' => 60, 'ada' => 40], 'ugo' => ['anna' => 36, 'bruno' => 24], 'ada' => ['anna' => 24, 'bruno' => 16]],
];

/** Le parti di ogni voce, in centesimi con il segno del Wallet (positivo = debito): il defunto ha il credito della sua cifra, gli eredi le loro parti. */
const G3D_PARTE = [
    'U' => ['ugo' => ['ugo' => -19726, 'anna' => 11836, 'bruno' => 7890], 'ada' => ['ada' => -19726, 'anna' => 11836, 'bruno' => 7890]],
    'D' => ['ugo' => ['ugo' => -23671, 'anna' => 14203, 'bruno' => 9468], 'ada' => ['ada' => -15781, 'anna' => 9469, 'bruno' => 6312]],
];

/** Un riporto non legato che non è la parte: € 50,00 a debito. */
const G3D_RIPORTO = 5000;

/**
 * Unità A da 1000 millesimi, «Spese generali» € 1.200,00, i proprietari dal 2019; il 2026 e il 2027 con la gestione ordinaria agganciata a ognuno, aperto il
 * 2027; il piano del 2026, non generato; le successioni del 1/5/2026 dalla rotta, con l'arretrato agli eredi. Con `$tre` quattro proprietari al 25 % (Ugo, Ada,
 * Eva, Pia), muoiono Ugo, Ada ed Eva, eredi Anna 15 e Bruno 10 della quota di ognuno.
 *
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function g3dScenario($test, string $q = 'U', bool $applicaSaldi = true, bool $tre = false): array
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
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100]);
    $proprieta = $tre ? ['ugo' => 25, 'ada' => 25, 'eva' => 25, 'pia' => 25] : G3D_QUOTE[$q]['proprieta'];
    foreach ($proprieta as $chi => $quota) {
        $s->titolare('A', $chi, 'proprietario', $quota);
    }
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05', applicaSaldi: $applicaSaldi);
    $sub = [];
    foreach ($tre ? ['ugo', 'ada', 'eva'] : ['ugo', 'ada'] as $d) {
        $eredi = $tre ? ['anna' => 15, 'bruno' => 10] : G3D_QUOTE[$q][$d];
        $sub[$d] = $s->passaggio('successione', 'A', $d, null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    }

    return [$s, $sub];
}

/** Una riga dalla porta del Wallet, nell'esercizio aperto: legata alla successione se `$passaggio` c'è. */
function g3dWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** Una riga non legata nel 2026, l'esercizio del piano, come la scrive l'importatore (la porta del Wallet scrive solo nell'esercizio aperto). */
function g3dImportata(Beta51Scenario $s, string $chi, int $cents): void
{
    Saldo::create(['esercizio_id' => Esercizio::where('condominio_id', $s->c->id)->where('nome', 'Esercizio 2026')->value('id'), 'condominio_id' => $s->c->id,
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id,
        'saldo_iniziale' => $cents, 'origine' => 'importato', 'is_applicato' => false]);
}

/** Toglie dalla porta del Wallet la riga legata di una persona alla successione data. */
function g3dTogli($test, Beta51Scenario $s, string $chi, Subentro $sub): void
{
    $x = Saldo::where('anagrafica_id', $s->persona($chi)->id)->where('immobile_id', $s->unita['A']->id)->whereNull('subentro_id')->where('passaggio_id', $sub->id)->firstOrFail();
    $test->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $x]))->assertSessionHasNoErrors()->assertRedirect('/saldi');
}

/** La generazione dalla pagina del piano, con o senza la presa d'atto del cancello. */
function g3dGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello dei due defunti nella prova'] : []);
}

/** Le frasi delle voci dei defunti, per nome del defunto. @return array<string, string> */
function g3dFrasi(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->mapWithKeys(fn ($v) => [$v['defunto']['nome'] => $v['frase']])->all();
}

/** «€ 1.234,56», senza il verso. */
function g3dEuro(int $cents): string
{
    return '€ ' . number_format(abs($cents) / 100, 2, ',', '.');
}

/** «€ 118,36 a debito», «€ 197,26 a credito». */
function g3dVerso(int $cents): string
{
    return g3dEuro($cents) . ($cents < 0 ? ' a credito' : ' a debito');
}

/** «Anna», «Anna e Bruno», «Ugo, Anna e Bruno». @param list<string> $voci */
function g3dElenco(array $voci): string
{
    if (count($voci) <= 1) {
        return $voci[0] ?? '';
    }
    $ultima = array_pop($voci);

    return implode(', ', $voci) . ' e ' . $ultima;
}

/**
 * La fine attesa della frase della voce del defunto `$d`, dagli stati di ogni persona in ogni voce (`$stati`: per defunto la stringa «defunto Anna Bruno»):
 * L = ha la riga legata, che è la parte; S = senza righe legate, i saldi non legati sono la sua parte; M = manca, senza saldi; I = incerto, il programma non sa
 * se i saldi non legati comprendono la parte; I+ = incerto con la frase N1 (gli stessi saldi delle altre voci dove è incerto); A:ugo = manca, perché i saldi
 * non legati sono già la sua parte nella voce di Ugo. `$nonLegati` sono i saldi non legati di ognuno, per la frase che li nomina.
 *
 * @param array<string, string> $stati
 * @param array<string, int> $nonLegati
 */
function g3dAtteso(string $q, string $d, array $stati, array $nonLegati): string
{
    $parti = G3D_PARTE[$q];
    $codici = array_combine([$d, 'anna', 'bruno'], explode(' ', $stati[$d]));
    $nome = fn (string $chi) => ucfirst($chi);
    $riga = fn (string $chi) => sprintf('%s a %s di %s', g3dEuro($parti[$d][$chi]), $parti[$d][$chi] < 0 ? 'credito' : 'debito', $nome($chi));
    $con = fn (array $quali) => array_values(array_filter(array_keys($codici), fn (string $chi) => in_array(explode(':', $codici[$chi])[0], $quali, true)));
    $elenco = fn (array $chi) => g3dElenco(array_map($nome, $chi));
    $righe = fn (array $chi) => g3dElenco(array_map($riga, $chi));
    [$ok, $legati, $mancanti, $incerti] = [$con(['L', 'S']), $con(['L']), $con(['M', 'A']), $con(['I', 'I+'])];
    $succ = 'alla successione del 1 maggio 2026';
    $ciSonoGia = $ok === [] ? '' : sprintf(' %s di %s %s già.', count($ok) > 1 ? 'Quelli' : 'Quello', $elenco($ok), count($ok) > 1 ? 'ci sono' : 'c\'è');
    $cheMancano = count($mancanti) > 1 ? "i saldi legati {$succ} che mancano" : "il saldo legato {$succ} che manca";
    $tutti = ucfirst($d) . ', Anna e Bruno';

    $frase = match (true) {
        $incerti !== [] => sprintf('%s %s su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte', $elenco($incerti), count($incerti) > 1 ? 'hanno' : 'ha')
            . ($mancanti === []
                ? sprintf(': se la comprendono non serve registrare altro; altrimenti registra dal Wallet %s %s (%s), che il piano assorbe alla prossima generazione.', count($incerti) > 1 ? 'i saldi legati' : 'il saldo legato', $succ, $righe($incerti))
                : sprintf(': registra dal Wallet %s (%s) e, se quelli non legati non la comprendono, anche %s; il piano li assorbe alla prossima generazione.', $cheMancano, $righe($mancanti), $righe($incerti)))
            . $ciSonoGia,
        count($mancanti) === 3 => sprintf('Per passare la parte agli eredi registra dal Wallet i saldi legati %s, sulla stessa gestione: %s; il piano li assorbe alla prossima generazione.', $succ, $righe($mancanti)),
        $mancanti !== [] => sprintf('Per passare la parte agli eredi registra dal Wallet %s, sulla stessa gestione: %s; il piano %s assorbe alla prossima generazione.', $cheMancano, $righe($mancanti), count($mancanti) > 1 ? 'li' : 'lo') . $ciSonoGia,
        count($legati) === 3 => "I saldi ci sono già: {$tutti} hanno saldi legati {$succ} su quella gestione e quell'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.",
        default => sprintf('I saldi ci sono già: %s hanno un saldo su quella gestione e quell\'unità (%s di %s %s %s), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.',
            $tutti, count($legati) > 1 ? 'quelli' : 'quello', $elenco($legati), count($legati) > 1 ? 'legati' : 'legato', $succ),
    };

    // La parte nella voce di un altro defunto (Coda 440, e Coda 463 per chi qui era incerto): una frase per defunto che tiene la parte.
    $altrove = [];
    foreach ($codici as $chi => $codice) {
        if (str_starts_with($codice, 'A:')) {
            $altrove[substr($codice, 2)][] = $chi;
        }
    }
    foreach ($altrove as $tiene => $chi) {
        $cifre = g3dElenco(array_map(fn (string $x) => g3dVerso($nonLegati[$x]), $chi));
        $frase .= count($chi) > 1
            ? sprintf(' I saldi non legati di %s (%s) sono già le loro parti per %s: per quelle di %s servono i saldi legati.', $elenco($chi), $cifre, ucfirst($tiene), ucfirst($d))
            : sprintf(' Il saldo non legato di %s (%s) è già la sua parte per %s: per quella di %s serve il saldo legato.', $nome($chi[0]), $cifre, ucfirst($tiene), ucfirst($d));
    }

    // N1: gli stessi saldi delle altre voci dove la persona è incerta.
    foreach ($codici as $chi => $codice) {
        if ($codice !== 'I+') {
            continue;
        }
        $dove = array_values(array_filter(array_keys($stati), fn (string $x) => in_array(explode(' ', $stati[$x])[array_search($chi, ['defunto', 'anna', 'bruno'], true)], ['I', 'I+'], true)));
        $altri = array_values(array_filter($dove, fn (string $x) => $x !== $d));
        $insieme = array_sum(array_map(fn (string $x) => $parti[$x][$chi], $dove));
        $frase .= sprintf(' Il saldo non legato di %s è lo stesso anche per %s: comprende la sua parte %s solo se comprende le due parti insieme, %s; se ne comprende una sola, per l\'altra serve il saldo legato.',
            $nome($chi), $elenco($altri), g3dElenco(array_map(fn (string $x) => 'per ' . ucfirst($x), $dove)), g3dVerso($insieme));
    }

    return $frase;
}

/**
 * Le righe del Wallet di uno stato: i saldi non legati di Anna e Bruno, e per ogni voce chi ha la riga legata.
 *
 * @param array<string, int> $nonLegati
 * @param array<string, list<string>> $legate
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function g3dStato($test, string $q, array $nonLegati, array $legate): array
{
    [$s, $sub] = g3dScenario($test, $q);
    foreach ($nonLegati as $chi => $cents) {
        g3dWallet($test, $s, $chi, $cents);
    }
    foreach ($legate as $d => $chi) {
        foreach ($chi as $x) {
            g3dWallet($test, $s, $x, G3D_PARTE[$q][$d][$x], $sub[$d]);
        }
    }

    return [$s, $sub];
}

it('Coda 463 — la tabella degli stati estesa: con due defunti sulla stessa unità, per ogni combinazione fra persone ogni voce chiede ciò che manca senza fare due volte la stessa domanda sugli stessi saldi e, seguita la sua strada, ognuno paga le sue parti una volta', function (string $q, array $nonLegati, array $legate, array $prima, array $strada, array $dopo, array $fine) {
    [$s, $sub] = g3dStato($this, $q, $nonLegati, $legate);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect(array_keys($frasi))->toBe(['Ugo', 'Ada'])
        ->and($frasi['Ugo'])->toEndWith(g3dAtteso($q, 'ugo', $prima, $nonLegati))
        ->and($frasi['Ada'])->toEndWith(g3dAtteso($q, 'ada', $prima, $nonLegati));

    // La strada che le voci indicano, dalla porta del Wallet: la riga legata che manca si registra; con i saldi non legati che il programma non sa leggere,
    // la riga legata si registra per la parte che non comprendono (l'amministratore sa di che cosa sono fatti: lo dice il nome della riga della tabella).
    foreach ($strada as [$chi, $d]) {
        g3dWallet($this, $s, $chi, G3D_PARTE[$q][$d][$chi], $sub[$d]);
    }
    if ($strada !== []) {
        $frasi = g3dFrasi(g3dGenera($this, $s));
        expect($frasi['Ugo'])->toEndWith(g3dAtteso($q, 'ugo', $dopo, $nonLegati))
            ->and($frasi['Ada'])->toEndWith(g3dAtteso($q, 'ada', $dopo, $nonLegati));
    }

    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0] + $fine);
})->with(function () {
    $legate = fn (bool $brunoUgo, bool $brunoAda) => ['ugo' => array_merge(['ugo'], $brunoUgo ? ['bruno'] : []), 'ada' => array_merge(['ada'], $brunoAda ? ['bruno'] : [])];
    // [quote, saldi non legati, righe legate, stati prima, strada, stati dopo, alla fine]; per gli stati: «defunto Anna Bruno» in ogni voce.
    return [
        // Quote uguali. La riga di Anna è la sua parte (di una successione: le due parti hanno la stessa cifra).
        'U, Anna la parte, Bruno legato in tutte e due' => ['U', ['anna' => 11836], $legate(true, true), ['ugo' => 'L S L', 'ada' => 'L A:ugo L'], [['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna la parte, a Bruno manca la riga per Ugo' => ['U', ['anna' => 11836], $legate(false, true), ['ugo' => 'L I M', 'ada' => 'L A:ugo L'], [['bruno', 'ugo'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna la parte, a Bruno manca la riga per Ada' => ['U', ['anna' => 11836], $legate(true, false), ['ugo' => 'L S L', 'ada' => 'L A:ugo M'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna la parte, a Bruno mancano le due righe' => ['U', ['anna' => 11836], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L A:ugo M'], [['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        // La riga di Anna è la parte di una successione più un riporto (N1): la comprende la voce di Ugo, per Ada serve la riga legata.
        'U, Anna la parte e un riporto (N1), Bruno legato in tutte e due' => ['U', ['anna' => 16836], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [['anna', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna la parte e un riporto (N1), a Bruno manca la riga per Ugo' => ['U', ['anna' => 16836], $legate(false, true), ['ugo' => 'L I M', 'ada' => 'L I+ L'], [['bruno', 'ugo'], ['anna', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna la parte e un riporto (N1), a Bruno manca la riga per Ada' => ['U', ['anna' => 16836], $legate(true, false), ['ugo' => 'L I L', 'ada' => 'L I+ M'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna la parte e un riporto (N1), a Bruno mancano le due righe' => ['U', ['anna' => 16836], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L I+ M'], [['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        // Il solo riporto: non comprende nessuna parte.
        'U, Anna un riporto, Bruno legato in tutte e due' => ['U', ['anna' => 5000], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [['anna', 'ugo'], ['anna', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna un riporto, a Bruno manca la riga per Ugo' => ['U', ['anna' => 5000], $legate(false, true), ['ugo' => 'L I M', 'ada' => 'L I+ L'], [['bruno', 'ugo'], ['anna', 'ugo'], ['anna', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna un riporto, a Bruno manca la riga per Ada' => ['U', ['anna' => 5000], $legate(true, false), ['ugo' => 'L I L', 'ada' => 'L I+ M'], [['anna', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'U, Anna un riporto, a Bruno mancano le due righe' => ['U', ['anna' => 5000], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L I+ M'], [['bruno', 'ugo'], ['anna', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        // Una riga che è le due parti insieme (€ 236,72): le comprende tutte e due, e non serve registrare altro per Anna.
        'U, Anna le due parti in una riga, Bruno legato in tutte e due' => ['U', ['anna' => 23672], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [], [], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna le due parti in una riga, a Bruno manca la riga per Ugo' => ['U', ['anna' => 23672], $legate(false, true), ['ugo' => 'L I M', 'ada' => 'L I+ L'], [['bruno', 'ugo']], ['ugo' => 'L I L', 'ada' => 'L I+ L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna le due parti in una riga, a Bruno manca la riga per Ada' => ['U', ['anna' => 23672], $legate(true, false), ['ugo' => 'L I L', 'ada' => 'L I+ M'], [['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L I+ L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna le due parti in una riga, a Bruno mancano le due righe' => ['U', ['anna' => 23672], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L I+ M'], [['bruno', 'ugo'], ['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L I+ L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna niente, a Bruno mancano le due righe' => ['U', [], $legate(false, false), ['ugo' => 'L M M', 'ada' => 'L M M'], [['anna', 'ugo'], ['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],

        // Quote diverse. La riga di Anna è la sua parte di Ugo (N2): € 142,03, mentre per Ada la sua parte è € 94,69.
        'D, Anna la parte di Ugo (N2), Bruno legato in tutte e due' => ['D', ['anna' => 14203], $legate(true, true), ['ugo' => 'L S L', 'ada' => 'L A:ugo L'], [['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ugo (N2), a Bruno manca la riga per Ugo' => ['D', ['anna' => 14203], $legate(false, true), ['ugo' => 'L I M', 'ada' => 'L A:ugo L'], [['bruno', 'ugo'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ugo (N2), a Bruno manca la riga per Ada' => ['D', ['anna' => 14203], $legate(true, false), ['ugo' => 'L S L', 'ada' => 'L A:ugo M'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ugo (N2), a Bruno mancano le due righe' => ['D', ['anna' => 14203], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L A:ugo M'], [['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        // La riga di Anna è la sua parte di Ada: la tiene la voce di Ada, e la voce di Ugo, che viene prima, la chiede.
        'D, Anna la parte di Ada, Bruno legato in tutte e due' => ['D', ['anna' => 9469], $legate(true, true), ['ugo' => 'L A:ada L', 'ada' => 'L S L'], [['anna', 'ugo']], ['ugo' => 'L L L', 'ada' => 'L S L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ada, a Bruno manca la riga per Ugo' => ['D', ['anna' => 9469], $legate(false, true), ['ugo' => 'L A:ada M', 'ada' => 'L S L'], [['anna', 'ugo'], ['bruno', 'ugo']], ['ugo' => 'L L L', 'ada' => 'L S L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ada, a Bruno manca la riga per Ada' => ['D', ['anna' => 9469], $legate(true, false), ['ugo' => 'L A:ada L', 'ada' => 'L I M'], [['anna', 'ugo'], ['bruno', 'ada']], ['ugo' => 'L L L', 'ada' => 'L S L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ada, a Bruno mancano le due righe' => ['D', ['anna' => 9469], $legate(false, false), ['ugo' => 'L A:ada M', 'ada' => 'L I M'], [['anna', 'ugo'], ['bruno', 'ugo'], ['bruno', 'ada']], ['ugo' => 'L L L', 'ada' => 'L S L'], ['anna' => 72001, 'bruno' => 47999]],
        // La parte di Ugo più un riporto (N1 con le parti diverse): € 192,03.
        'D, Anna la parte di Ugo e un riporto (N1), Bruno legato in tutte e due' => ['D', ['anna' => 19203], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [['anna', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'D, Anna la parte di Ugo e un riporto (N1), a Bruno mancano le due righe' => ['D', ['anna' => 19203], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L I+ M'], [['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        // La parte di Ada più un riporto: € 144,69, che non torna con la parte di Ugo (€ 142,03); la comprende la voce di Ada.
        'D, Anna la parte di Ada e un riporto (N1), Bruno legato in tutte e due' => ['D', ['anna' => 14469], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [['anna', 'ugo']], ['ugo' => 'L L L', 'ada' => 'L I L'], ['anna' => 77001, 'bruno' => 47999]],
        'D, Anna un riporto, Bruno legato in tutte e due' => ['D', ['anna' => 5000], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [['anna', 'ugo'], ['anna', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
        'D, Anna le due parti in una riga, Bruno legato in tutte e due' => ['D', ['anna' => 23672], $legate(true, true), ['ugo' => 'L I L', 'ada' => 'L I+ L'], [], [], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna le due parti in una riga, a Bruno mancano le due righe' => ['D', ['anna' => 23672], $legate(false, false), ['ugo' => 'L I M', 'ada' => 'L I+ M'], [['bruno', 'ugo'], ['bruno', 'ada']], ['ugo' => 'L I L', 'ada' => 'L I+ L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna niente, Bruno legato in tutte e due' => ['D', [], $legate(true, true), ['ugo' => 'L M L', 'ada' => 'L M L'], [['anna', 'ugo'], ['anna', 'ada']], ['ugo' => 'L L L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],

        // I due eredi senza righe legate.
        'D, Anna e Bruno con la loro parte di Ugo' => ['D', ['anna' => 14203, 'bruno' => 9468], ['ugo' => ['ugo'], 'ada' => ['ada']], ['ugo' => 'L S S', 'ada' => 'L A:ugo A:ugo'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S S', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna con la parte di Ugo e Bruno con quella di Ada' => ['D', ['anna' => 14203, 'bruno' => 6312], ['ugo' => ['ugo'], 'ada' => ['ada']], ['ugo' => 'L S A:ada', 'ada' => 'L A:ugo S'], [['bruno', 'ugo'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L S'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna e Bruno con la parte e un riporto (N1 per tutti e due)' => ['U', ['anna' => 16836, 'bruno' => 12890], ['ugo' => ['ugo'], 'ada' => ['ada']], ['ugo' => 'L I I', 'ada' => 'L I+ I+'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L I I', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 52999]],
        'U, Anna con la parte e Bruno con un riporto' => ['U', ['anna' => 11836, 'bruno' => 5000], ['ugo' => ['ugo'], 'ada' => ['ada']], ['ugo' => 'L I I', 'ada' => 'L A:ugo I+'], [['bruno', 'ugo'], ['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 52999]],
        'D, Anna con la parte di Ugo (N2) e Bruno con la parte di Ugo e un riporto (N1)' => ['D', ['anna' => 14203, 'bruno' => 14468], ['ugo' => ['ugo'], 'ada' => ['ada']], ['ugo' => 'L I I', 'ada' => 'L A:ugo I+'], [['anna', 'ada'], ['bruno', 'ada']], ['ugo' => 'L I I', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 52999]],

        // Un defunto senza la sua riga legata (la riga a credito non ancora registrata).
        'U, Anna la parte, a Ugo manca la sua riga' => ['U', ['anna' => 11836], ['ugo' => ['bruno'], 'ada' => ['ada', 'bruno']], ['ugo' => 'M I L', 'ada' => 'L A:ugo L'], [['ugo', 'ugo'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'D, Anna la parte di Ugo (N2), a Ugo manca la sua riga' => ['D', ['anna' => 14203], ['ugo' => ['bruno'], 'ada' => ['ada', 'bruno']], ['ugo' => 'M I L', 'ada' => 'L A:ugo L'], [['ugo', 'ugo'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna la parte, ad Ada manca la sua riga' => ['U', ['anna' => 11836], ['ugo' => ['ugo', 'bruno'], 'ada' => ['bruno']], ['ugo' => 'L S L', 'ada' => 'M A:ugo L'], [['ada', 'ada'], ['anna', 'ada']], ['ugo' => 'L S L', 'ada' => 'L L L'], ['anna' => 72001, 'bruno' => 47999]],
        'U, Anna la parte e un riporto (N1), a Ugo manca la sua riga' => ['U', ['anna' => 16836], ['ugo' => ['bruno'], 'ada' => ['ada', 'bruno']], ['ugo' => 'M I L', 'ada' => 'L I+ L'], [['ugo', 'ugo'], ['anna', 'ada']], ['ugo' => 'L I L', 'ada' => 'L L L'], ['anna' => 77001, 'bruno' => 47999]],
    ];
});

/** La V5 della voce del defunto `$d` con Anna sola fra gli incerti e nessuno che manca: «… Quelli di {D} e Bruno ci sono già.». */
function g3dV5Anna(string $d, string $parte): string
{
    return sprintf('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: se la comprendono non serve registrare altro; altrimenti registra dal Wallet il saldo legato alla successione del 1 maggio 2026 (%s a debito di Anna), che il piano assorbe alla prossima generazione. Quelli di %s e Bruno ci sono già.', $parte, $d);
}

it('Coda 463, N1 — una riga non legata di Anna con la sua parte di una successione e un riporto (€ 168,36): la voce di Ada dice che sono gli stessi saldi della voce di Ugo e che comprendono le due parti solo se le comprendono insieme (€ 236,72); seguita, Anna € 770,01 e Bruno € 479,99', function () {
    [$s, $sub] = g3dStato($this, 'U', ['anna' => 11836 + G3D_RIPORTO], ['ugo' => ['ugo', 'bruno'], 'ada' => ['ada', 'bruno']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 118,36'))
        ->and($frasi['Ada'])->toEndWith(g3dV5Anna('Ada', '€ 118,36') . ' Il saldo non legato di Anna è lo stesso anche per Ugo: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, € 236,72 a debito; se ne comprende una sola, per l\'altra serve il saldo legato.');

    // La riga comprende la parte di una successione: «la comprendono» nella voce di Ugo, e per Ada il saldo legato. La voce di Ugo resta la sola che non sa.
    g3dWallet($this, $s, 'anna', 11836, $sub['ada']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 118,36'))
        ->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    // Conto a mano: 48329 + 11836 + 11836 + 5000 = 77001.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 77001, 'bruno' => 47999]);
});

it('Coda 463, N1 — la stessa riga con la riga legata di Anna per Ada già registrata: nessuna frase sugli stessi saldi, e Anna € 770,01', function () {
    [$s, $sub] = g3dStato($this, 'U', ['anna' => 11836 + G3D_RIPORTO], ['ugo' => ['ugo', 'bruno'], 'ada' => ['ada', 'bruno', 'anna']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 118,36'))->and(implode(' ', $frasi))->not->toContain('sono gli stessi');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 77001, 'bruno' => 47999]);
});

it('Coda 463, N2 — con le quote diverse la riga non legata di Anna (€ 142,03) è la sua parte di Ugo: la voce di Ada non dice più che il programma non sa, chiede il saldo legato (€ 94,69) e dice perché; seguita, Anna € 720,01 e Bruno € 479,99', function () {
    [$s, $sub] = g3dStato($this, 'D', ['anna' => 14203], ['ugo' => ['ugo', 'bruno'], 'ada' => ['ada', 'bruno']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 94,69 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ada e Bruno ci sono già.'
            . ' Il saldo non legato di Anna (€ 142,03 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.')
        ->and($frasi['Ada'])->not->toContain('il programma non sa');

    g3dWallet($this, $s, 'anna', 9469, $sub['ada']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    // Conto a mano: 48329 + 14203 + 9469 = 72001; 32219 + 9468 + 6312 = 47999.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 440 e 463, N3 — nella voce che tiene la parte a Bruno manca la riga: quella voce dice ancora che il programma non sa per Anna, l\'altra che la riga è già la sua parte per Ugo; seguite, in tutte e due le letture, Anna € 720,01', function (bool $comprende) {
    [$s, $sub] = g3dStato($this, 'U', ['anna' => 11836], ['ugo' => ['ugo'], 'ada' => ['ada', 'bruno']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 78,90 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 118,36 a debito di Anna; il piano li assorbe alla prossima generazione. Quello di Ugo c\'è già.')
        ->and($frasi['Ada'])->toEndWith('Il saldo non legato di Anna (€ 118,36 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.');

    // La strada: Bruno per Ugo, Anna per Ada; con «non la comprendono» anche Anna per Ugo, e la voce di Ugo dice poi la parte due volte.
    g3dWallet($this, $s, 'bruno', 7890, $sub['ugo']);
    g3dWallet($this, $s, 'anna', 11836, $sub['ada']);
    if (! $comprende) {
        g3dWallet($this, $s, 'anna', 11836, $sub['ugo']);
        $frasi = g3dFrasi(g3dGenera($this, $s));
        expect($frasi['Ugo'])->toContain('La parte di Anna entra due volte');
        g3dTogli($this, $s, 'anna', $sub['ugo']);
    }
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
})->with(['la comprendono' => [true], 'non la comprendono' => [false]]);

it('Coda 440, B2 — l\'ordine delle righe che la voce chiede è quello delle persone, il defunto e poi gli eredi: chi ha la parte nella voce di un altro defunto non va in fondo né in testa', function (array $nonLegati, string $righe) {
    [$s, $sub] = g3dStato($this, 'U', $nonLegati, isset($nonLegati['bruno']) ? ['ugo' => ['ugo'], 'ada' => ['ada']] : ['ugo' => ['ugo', 'bruno'], 'ada' => ['ada']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ada'])->toContain('Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano, sulla stessa gestione: ' . $righe . '; il piano li assorbe alla prossima generazione. Quello di Ada c\'è già.');
})->with([
    // Anna ha la parte nella voce di Ugo, a Bruno manca la riga per Ada.
    'Anna la parte altrove, Bruno niente' => [['anna' => 11836], '€ 118,36 a debito di Anna e € 78,90 a debito di Bruno'],
    // Bruno ha la parte nella voce di Ugo, Anna non ha niente in nessuna delle due.
    'Bruno la parte altrove, Anna niente' => [['bruno' => 7890], '€ 118,36 a debito di Anna e € 78,90 a debito di Bruno'],
]);

it('Coda 463 — la parte due volte in una voce e la V5 nell\'altra (quote diverse): tolta la riga legata dalla voce che dice «due volte», l\'altra voce chiede il saldo legato invece di dire di nuovo che il programma non sa; seguita, Anna € 720,01', function () {
    // Anna ha la riga legata per Ada (€ 94,69) e accanto un saldo non legato della stessa cifra (la strada a mano di prima); per Ugo niente.
    [$s, $sub] = g3dStato($this, 'D', ['anna' => 9469], ['ugo' => ['ugo', 'bruno'], 'ada' => ['ada', 'bruno', 'anna']]);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ada'])->toEndWith('La parte di Anna entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione. Le parti di Ada e Bruno entrano una volta sola.')
        ->and($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 142,03'));

    // Si toglie la serie legata (la frase lascia la scelta all'amministratore): il saldo non legato resta, ed è la parte di Anna per Ada.
    g3dTogli($this, $s, 'anna', $sub['ada']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ada'])->toEndWith('Non serve registrare altro.')
        ->and($frasi['Ugo'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 142,03 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ugo e Bruno ci sono già.'
            . ' Il saldo non legato di Anna (€ 94,69 a debito) è già la sua parte per Ada: per quella di Ugo serve il saldo legato.');

    g3dWallet($this, $s, 'anna', 14203, $sub['ugo']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

/** Tre defunti (Ugo, Ada, Eva al 25 %) con le righe legate dei defunti e di Bruno in ogni voce, e il saldo non legato di Anna. */
function g3dTre($test, int $anna): array
{
    [$s, $sub] = g3dScenario($test, tre: true);
    g3dWallet($test, $s, 'anna', $anna);
    foreach (['ugo', 'ada', 'eva'] as $d) {
        g3dWallet($test, $s, $d, -9863, $sub[$d]);
        g3dWallet($test, $s, 'bruno', 3945, $sub[$d]);
    }

    return [$s, $sub];
}

it('Coda 463, N1 con tre defunti — la parte di Anna di una successione e un riporto (€ 109,18): le voci di Ada e di Eva dicono che sono gli stessi saldi delle altre due e che comprendono le tre parti solo se le comprendono tutte insieme (€ 177,54); seguite, Anna € 590,01', function () {
    // Conto a mano (la Coda 440, tre defunti): la cifra di ognuno 30000 × 120/365 = 9863,01 → € 98,63; Anna 60 % = 5917,8 → € 59,18, Bruno € 39,45; dopo il
    // decesso Anna 36247 e Bruno 24164. La riga di Anna: 5918 + 5000 = 10918. Alla fine Anna 36247 + 3 × 5918 + 5000 = 59001, Bruno 24164 + 3 × 3945 = 35999.
    [$s, $sub] = g3dTre($this, 5918 + G3D_RIPORTO);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    $tre = fn (string $altri) => sprintf(' Il saldo non legato di Anna è lo stesso anche per %s: comprende la sua parte per Ugo, per Ada e per Eva solo se le comprende tutte insieme, € 177,54 a debito; per ogni parte che non comprende serve il saldo legato.', $altri);
    expect(array_keys($frasi))->toBe(['Ugo', 'Ada', 'Eva'])
        ->and($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 59,18'))
        ->and($frasi['Ada'])->toEndWith(g3dV5Anna('Ada', '€ 59,18') . $tre('Ugo ed Eva'))
        ->and($frasi['Eva'])->toEndWith(g3dV5Anna('Eva', '€ 59,18') . $tre('Ugo e Ada'));

    g3dWallet($this, $s, 'anna', 5918, $sub['ada']);
    g3dWallet($this, $s, 'anna', 5918, $sub['eva']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 59,18'))
        ->and($frasi['Ada'])->toEndWith('Non serve registrare altro.')->and($frasi['Eva'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'eva', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'eva' => 0, 'anna' => 59001, 'bruno' => 35999]);
});

it('Coda 463, N1 con tre defunti — una riga di Anna con due delle sue tre parti (€ 118,36): registrata la riga legata della terza, le altre due voci dicono che comprendono le due parti solo se le comprendono insieme; seguite, Anna € 540,01', function () {
    // Alla fine Anna 36247 + 11836 + 5918 = 54001 (le tre parti una volta), Bruno 35999.
    [$s, $sub] = g3dTre($this, 2 * 5918);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Eva'])->toEndWith(' Il saldo non legato di Anna è lo stesso anche per Ugo e Ada: comprende la sua parte per Ugo, per Ada e per Eva solo se le comprende tutte insieme, € 177,54 a debito; per ogni parte che non comprende serve il saldo legato.');

    // La riga comprende le parti di Ugo e di Ada: per Eva il saldo legato.
    g3dWallet($this, $s, 'anna', 5918, $sub['eva']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(g3dV5Anna('Ugo', '€ 59,18'))
        ->and($frasi['Ada'])->toEndWith(g3dV5Anna('Ada', '€ 59,18') . ' Il saldo non legato di Anna è lo stesso anche per Ugo: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, € 118,36 a debito; se ne comprende una sola, per l\'altra serve il saldo legato.')
        ->and($frasi['Eva'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'eva', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'eva' => 0, 'anna' => 54001, 'bruno' => 35999]);
});

it('Coda 440 e 463, N4 — tre defunti, Anna con la parte non legata e la riga legata per Eva soltanto: la voce di Ugo tiene la parte, quella di Ada la chiede, quella di Eva no; seguite, Anna € 540,01', function () {
    [$s, $sub] = g3dTre($this, 5918);
    g3dWallet($this, $s, 'anna', 5918, $sub['eva']);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Eva'])->toEndWith('Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('Il saldo non legato di Anna (€ 59,18 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.');
    g3dWallet($this, $s, 'anna', 5918, $sub['ada']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect(collect($frasi)->every(fn (string $f) => str_ends_with($f, 'Non serve registrare altro.')))->toBeTrue(implode("\n", $frasi));
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'eva', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'eva' => 0, 'anna' => 54001, 'bruno' => 35999]);
});

it('Coda 463 — con il piano che non applica i saldi le due voci non leggono i saldi non legati di Anna, che occupano il posto del Wallet nel 2026: la seconda dice che sono gli stessi della prima', function () {
    [$s, $sub] = g3dScenario($this, applicaSaldi: false);
    g3dImportata($s, 'anna', 11836 + G3D_RIPORTO);
    foreach (['ugo', 'ada'] as $d) {
        g3dWallet($this, $s, $d, -19726, $sub[$d]);
        g3dWallet($this, $s, 'bruno', 7890, $sub[$d]);
    }

    $frasi = g3dFrasi(g3dGenera($this, $s));
    $v2 = fn (string $d) => sprintf('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte. Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote. Le parti di %s e Bruno ci sono già, nei saldi legati alla successione. Se quelli non legati comprendono la parte, crea di nuovo il piano, che assorbe quelli ancora liberi nel Wallet, e non registrare altro; se non la comprendono, registra dal Wallet il saldo legato alla successione (€ 118,36 a debito di Anna) e crea di nuovo il piano. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.', $d);
    expect($frasi['Ugo'])->toEndWith($v2('Ugo'))
        ->and($frasi['Ada'])->toEndWith($v2('Ada') . ' Il saldo non legato di Anna è lo stesso anche per Ugo: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, € 236,72 a debito; se ne comprende una sola, per l\'altra serve il saldo legato.');
});

it('Coda 463 — alla creazione del piano (il piano nell\'esercizio aperto, la riga di Anna nel posto del Wallet): con le quote diverse la voce di Ada chiede il saldo legato di Anna e dice che la sua riga è già la parte per Ugo; registrato e rimandato il modulo, Anna € 720,01', function () {
    // Giugno 2026: il solo 2026, aperto; la porta del Wallet scrive nell'esercizio del piano. Le cifre come nella tabella, con le quote diverse.
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(10, 0));
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000]);
    $g = app(CondominioService::class)->createDefaultGestione($s->c, $s->e);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo', 'proprietario', 60)->titolare('A', 'ada', 'proprietario', 40);
    $sub = [];
    foreach (['ugo', 'ada'] as $d) {
        $sub[$d] = $s->passaggio('successione', 'A', $d, null, '2026-05-01', ['eredi' => G3D_QUOTE['D'][$d], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    }
    g3dWallet($this, $s, 'anna', 14203);
    foreach (['ugo', 'ada'] as $d) {
        g3dWallet($this, $s, $d, G3D_PARTE['D'][$d][$d], $sub[$d]);
        g3dWallet($this, $s, 'bruno', G3D_PARTE['D'][$d]['bruno'], $sub[$d]);
    }
    $crea = fn (bool $accetta) => $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $g->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ] + ($accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello dei due defunti nella prova'] : []));

    $frasi = g3dFrasi($crea(false));
    expect(\App\Models\Gestionale\PianoRate::where('condominio_id', $s->c->id)->count())->toBe(0)
        ->and($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 94,69 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ada e Bruno ci sono già.'
            . ' Il saldo non legato di Anna (€ 142,03 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.');

    g3dWallet($this, $s, 'anna', 9469, $sub['ada']);
    $frasi = g3dFrasi($crea(false));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    $crea(true)->assertSessionHasNoErrors();
    $s->piani['Preventivo 2026'] = ['p' => \App\Models\Gestionale\PianoRate::where('condominio_id', $s->c->id)->firstOrFail(), 'gestione' => 'Ordinaria', 'tipo' => 'ordinario'];
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 463 — la voce di un defunto con due successioni sull\'unità e la divisione da decidere non dice niente dei saldi: non conta fra le voci dove l\'erede è incerto, e la voce dell\'altro defunto non dice che sono gli stessi saldi', function () {
    // Ugo ha la proprietà di un quarto e la nuda proprietà di metà (usufruttuaria Elsa), Ada la proprietà di un quarto. Il dettaglio del riparto non c'è: la
    // cifra di Ugo non si divide fra le sue due successioni e la sua voce non ha parti per erede (la chiamata come quella della prova O-62-15, la divisione da
    // decidere). La riga non legata di Anna (€ 109,18) è nel 2026, l'esercizio del piano: la voce di Ugo la conta fra chi ha un saldo, ma non ne parla.
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(10, 0));
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true)
        ->titolare('A', 'ugo', 'proprietario', 25)->titolare('A', 'ugo', 'nuda_proprietario', 50)->titolare('A', 'elsa', 'usufruttuario', 50)->titolare('A', 'ada', 'proprietario', 25);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 15, 'bruno' => 10], 'arretrato' => 'eredi', 'riferimento' => 'anna', 'ruolo' => 'proprietario']);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['carla' => 50], 'arretrato' => 'eredi', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['anna' => 15, 'bruno' => 10], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 5918 + G3D_RIPORTO);
    [$ugo, $ada, $unita] = [$s->persona('ugo')->id, $s->persona('ada')->id, $s->unita['A']->id];

    $voci = collect(app(DefuntoNelPiano::class)->per($s->piano('Preventivo 2026'), [$ugo => [$unita => 29589], $ada => [$unita => 9863]], true, []))->keyBy(fn ($v) => $v['defunto']['nome']);
    expect($voci->keys()->all())->toBe(['Ugo', 'Ada'])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeTrue()
        ->and($voci['Ada']['frase'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte')
        ->and($voci['Ada']['frase'])->not->toContain('sono gli stessi');
});

it('Coda 463, N1 con tre defunti e due eredi incerti in voci diverse — le frasi sugli stessi saldi vengono nell\'ordine delle persone, ognuna con le voci e la somma delle parti di quella persona; seguite, Anna € 590,01 e Bruno € 409,99', function () {
    // Anna ha la riga legata per Ugo e una riga non legata di € 109,18 (la sua parte di una successione, € 59,18, e un riporto); Bruno nessuna riga legata e
    // una riga non legata di € 89,45 (€ 39,45 e un riporto). Anna è incerta nelle voci di Ada ed Eva, Bruno in tutte e tre. Alla fine, dalla regola: Anna
    // 36247 + 3 × 5918 + 5000 = 59001, Bruno 24164 + 3 × 3945 + 5000 = 40999.
    [$s, $sub] = g3dScenario($this, tre: true);
    g3dWallet($this, $s, 'anna', 5918 + G3D_RIPORTO);
    g3dWallet($this, $s, 'bruno', 3945 + G3D_RIPORTO);
    foreach (['ugo', 'ada', 'eva'] as $d) {
        g3dWallet($this, $s, $d, -9863, $sub[$d]);
    }
    g3dWallet($this, $s, 'anna', 5918, $sub['ugo']);

    $frasi = g3dFrasi(g3dGenera($this, $s));
    $v5 = fn (string $d) => sprintf('Anna e Bruno hanno su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: se la comprendono non serve registrare altro; altrimenti registra dal Wallet i saldi legati alla successione del 1 maggio 2026 (€ 59,18 a debito di Anna e € 39,45 a debito di Bruno), che il piano assorbe alla prossima generazione. Quello di %s c\'è già.', $d);
    $bruno = fn (string $altri) => sprintf(' Il saldo non legato di Bruno è lo stesso anche per %s: comprende la sua parte per Ugo, per Ada e per Eva solo se le comprende tutte insieme, € 118,35 a debito; per ogni parte che non comprende serve il saldo legato.', $altri);
    expect($frasi['Ugo'])->toEndWith('Bruno ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: se la comprendono non serve registrare altro; altrimenti registra dal Wallet il saldo legato alla successione del 1 maggio 2026 (€ 39,45 a debito di Bruno), che il piano assorbe alla prossima generazione. Quelli di Ugo e Anna ci sono già.')
        ->and($frasi['Ada'])->toEndWith($v5('Ada') . $bruno('Ugo ed Eva'))
        ->and($frasi['Eva'])->toEndWith($v5('Eva') . ' Il saldo non legato di Anna è lo stesso anche per Ada: comprende la sua parte per Ada e per Eva solo se comprende le due parti insieme, € 118,36 a debito; se ne comprende una sola, per l\'altra serve il saldo legato.' . $bruno('Ugo e Ada'));

    // La riga di Anna comprende la sua parte di Ada, quella di Bruno la sua parte di Ugo: le righe legate per le altre.
    g3dWallet($this, $s, 'anna', 5918, $sub['eva']);
    g3dWallet($this, $s, 'bruno', 3945, $sub['ada']);
    g3dWallet($this, $s, 'bruno', 3945, $sub['eva']);
    $frasi = g3dFrasi(g3dGenera($this, $s));
    expect($frasi['Ugo'])->not->toContain('sono gli stessi')->and($frasi['Ada'])->not->toContain('sono gli stessi')
        ->and($frasi['Eva'])->toEndWith('Non serve registrare altro.');
    g3dGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'eva', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'eva' => 0, 'anna' => 59001, 'bruno' => 40999]);
});
