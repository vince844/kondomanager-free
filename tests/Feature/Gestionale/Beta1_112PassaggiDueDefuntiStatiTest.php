<?php

/**
 * Coda 440, decisione 167 — due defunti sulla stessa unità: una riga non legata dell'erede è la sua parte in una voce sola del cancello.
 *
 * Il cancello del defunto (`DefuntoNelPiano`) ha una voce per defunto e unità, e confronta voce per voce le righe legate alla successione di quella voce e i
 * saldi non legati della persona su quella gestione e quell'unità. I saldi non legati non hanno una successione: con due defunti sulla stessa unità sono gli
 * stessi in tutte e due le voci. Una riga non legata può essere la parte di una voce sola: nelle altre l'erede manca, e la voce chiede la sua riga legata
 * (V1). Prima, se l'erede non aveva righe legate in nessuna delle due voci, tutte e due dicevano «I saldi ci sono già … Non serve registrare altro» e la
 * generazione assorbiva la riga una volta: Anna € 601,65 invece di € 720,01.
 *
 * La tabella degli stati, per ogni erede (Anna e Bruno; l'altro erede e i due defunti hanno le loro righe legate in tutte e due le voci): la riga legata nella
 * voce di Ugo (l1), quella nella voce di Ada (l2), la riga non legata (u: nessuna; la parte, dal Wallet nel 2027 aperto; la parte, importata nel 2026, che è
 * l'esercizio del piano e occupa il posto del Wallet; un riporto che non è la parte; due righe che sommano due parti). Per ogni stato: che cosa dice ogni voce,
 * la strada che la voce indica, seguita dalle rotte vere, e le somme del piano alla fine. Il controllo che tiene ferma la famiglia: quando nessuna voce chiede
 * più niente, ognuno paga le sue parti una volta.
 *
 * Fonte dell'atteso: le decisioni 86.4 (V4), 164.1 e 170 (la parte due volte, una voce sola) e 167 (la riga non legata è la parte in una voce sola, nell'altra
 * l'erede manca e la frase chiede la sua riga legata). Le cifre, dalla regola: Ugo e Ada proprietari di A al 50 %, morti tutti e due il 1/5/2026, eredi Anna e
 * Bruno per 30 e 20 della quota di ognuno; la cifra di ognuno 120 giorni su 365 di € 600,00 = 19726,03 → € 197,26; Anna 60 % = 11835,6 → € 118,36, Bruno
 * 40 % = 7890,4 → € 78,90. Dopo il decesso Anna (120000 − 2 × 19726,03) × 60 % = 48328,8 → 48329, Bruno 32219,2 → 32219 (per persona). Alla fine Anna
 * 48329 + 2 × 11836 = 72001, Bruno 32219 + 2 × 7890 = 47999, i defunti 19726 − 19726 = 0. Le frasi delle varianti già decise sono quelle del programma.
 *
 * Coda 463 (decisione 188.1): la stessa regola anche per la V5. Con i saldi non legati che il programma non sa leggere in tutte e due le voci (il riporto, le due
 * righe), la voce di Ada dice in fondo che sono gli stessi saldi della voce di Ugo (I+); la riga non legata della parte di una voce e non dell'altra, con le
 * quote diverse, nell'altra voce non è più incerta ma manca (la prova sotto). Le combinazioni fra persone sono in `Beta1_112Giro3DefuntiStatiTest.php`.
 *
 * Che cosa NON copre: MySQL. Il piano creato con la richiesta è provato in `Beta1_112Giro3DefuntiStatiTest.php` («alla creazione del piano»); il defunto che è
 * anche erede dell'altro (Coda 480) nei file `Beta1_112Giro3FermataDefuntoErede*Test.php`.
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
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto (la prova con il piano nell'esercizio aperto si sposta da sé al 2026).
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** Le parti di ogni successione, in centesimi con il segno del Wallet (positivo = debito). */
const DDT_PARTE = ['defunto' => -19726, 'anna' => 11836, 'bruno' => 7890];

/** Le quote di ogni erede dopo il decesso, dalle due successioni (per persona). */
const DDT_DOPO = ['anna' => 48329, 'bruno' => 32219];

/** Un riporto non legato che non è la parte: € 50,00 a debito. */
const DDT_RIPORTO = 5000;

/** La frase che chiude la voce quando il saldo di chi ha la parte sta nel 2026, che il Wallet non mostra. */
const DDT_FUORI = ' Il saldo dell\'esercizio «Esercizio 2026» non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.';

/**
 * Unità A da 1000 millesimi, Ugo e Ada proprietari al 50 % dal 2019, «Spese generali» € 1.200,00; il 2026 e il 2027 con la gestione ordinaria agganciata a
 * ognuno, aperto il 2027 (con `$nellAperto` il solo 2026, aperto: il piano sta nell'esercizio che il Wallet mostra); il piano del 2026, non generato; le
 * successioni del 1/5/2026 dalla rotta. Con `$eva` anche Eva e Pia: quattro proprietari al 25 %, e muoiono Ugo, Ada ed Eva.
 *
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function ddtScenario($test, bool $nellAperto = false, array $erediAda = ['anna' => 30, 'bruno' => 20], bool $applicaSaldi = true, bool $eva = false): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ($nellAperto ? [2026] : [2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $quota = $eva ? 25 : 50;
    $s->voce('Ordinaria', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo', 'proprietario', $quota)->titolare('A', 'ada', 'proprietario', $quota);
    if ($eva) {
        $s->titolare('A', 'eva', 'proprietario', 25)->titolare('A', 'pia', 'proprietario', 25);
    }
    if (! $nellAperto) {
        $es[2026]->update(['stato' => 'chiuso']);
    }
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05', applicaSaldi: $applicaSaldi);
    $eredi = $eva ? ['anna' => 15, 'bruno' => 10] : ['anna' => 30, 'bruno' => 20];
    $sub = [
        'ugo' => $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => 'anna']),
        'ada' => $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => $eva ? $eredi : $erediAda, 'arretrato' => 'eredi', 'riferimento' => 'anna']),
    ];
    if ($eva) {
        $sub['eva'] = $s->passaggio('successione', 'A', 'eva', null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    }

    return [$s, $sub];
}

/** Una riga dalla porta del Wallet, nell'esercizio aperto: legata alla successione se `$passaggio` c'è. */
function ddtWallet($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : []))->assertSessionHasNoErrors();
}

/** Una riga non legata nel 2026, l'esercizio del piano, come la scrive l'importatore (la porta del Wallet scrive solo nell'esercizio aperto). */
function ddtImportata(Beta51Scenario $s, string $chi, int $cents): void
{
    Saldo::create(['esercizio_id' => Esercizio::where('condominio_id', $s->c->id)->where('nome', 'Esercizio 2026')->value('id'), 'condominio_id' => $s->c->id,
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id,
        'saldo_iniziale' => $cents, 'origine' => 'importato', 'is_applicato' => false]);
}

/** Toglie dalla porta del Wallet la riga legata di una persona alla successione data. */
function ddtTogli($test, Beta51Scenario $s, string $chi, Subentro $sub): void
{
    $x = Saldo::where('anagrafica_id', $s->persona($chi)->id)->where('immobile_id', $s->unita['A']->id)->whereNull('subentro_id')->where('passaggio_id', $sub->id)->firstOrFail();
    $test->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $x]))->assertSessionHasNoErrors()->assertRedirect('/saldi');
}

/** La generazione dalla pagina del piano, con o senza la presa d'atto del cancello. */
function ddtGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello dei due defunti nella prova'] : []);
}

/** Le frasi delle voci dei defunti, per nome del defunto. @return array<string, string> */
function ddtFrasi(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->mapWithKeys(fn ($v) => [$v['defunto']['nome'] => $v['frase']])->all();
}

/**
 * Lo stato della tabella per l'erede `$chi`: la sua riga non legata (`$u`), poi le righe legate dei due defunti e dell'altro erede in tutte e due le voci, e
 * quelle di `$chi` dove `$l1` (voce di Ugo) e `$l2` (voce di Ada) lo dicono.
 */
function ddtStato($test, Beta51Scenario $s, array $sub, string $chi, bool $l1, bool $l2, string $u): void
{
    $altro = $chi === 'anna' ? 'bruno' : 'anna';
    match ($u) {
        'parte' => ddtWallet($test, $s, $chi, DDT_PARTE[$chi]),
        'parte_2026' => ddtImportata($s, $chi, DDT_PARTE[$chi]),
        'riporto' => ddtWallet($test, $s, $chi, DDT_RIPORTO),
        'due_righe' => [ddtWallet($test, $s, $chi, DDT_PARTE[$chi]), ddtImportata($s, $chi, DDT_PARTE[$chi])],
        'nessuna' => null,
    };
    foreach (['ugo' => $l1, 'ada' => $l2] as $defunto => $legata) {
        ddtWallet($test, $s, $defunto, DDT_PARTE['defunto'], $sub[$defunto]);
        ddtWallet($test, $s, $altro, DDT_PARTE[$altro], $sub[$defunto]);
        if ($legata) {
            ddtWallet($test, $s, $chi, DDT_PARTE[$chi], $sub[$defunto]);
        }
    }
}

/** «€ 118,36» per Anna, «€ 78,90» per Bruno. */
function ddtEuro(string $chi): string
{
    return $chi === 'anna' ? '€ 118,36' : '€ 78,90';
}

/**
 * La fine attesa della frase della voce del defunto `$d` per lo stato dell'erede `$chi`:
 * L = V4 con le righe legate di tutti; M = V4 con le righe miste (la riga non legata di `$chi` è la sua parte); C = V1, chiede la riga legata di `$chi`;
 * C+ = V1 e dice perché la riga non legata di `$chi` non basta (è la parte nella voce dell'altro defunto); D = la parte di `$chi` due volte, con le due serie
 * nel Wallet; D1 = la stessa con la serie non legata nel 2026, fuori dal Wallet; I = V5, il programma non sa se i saldi non legati di `$chi` comprendono la parte;
 * I+ = la V5 e, dopo, che sono gli stessi saldi della voce di Ugo (Coda 463). Con la riga non legata nel 2026 la voce che la legge come parte (M) o come
 * incerta (I, I+) lo dice in fondo.
 */
function ddtAtteso(string $d, string $codice, string $chi, string $u): string
{
    $D = ucfirst($d);
    $Chi = ucfirst($chi);
    $Altro = $chi === 'anna' ? 'Bruno' : 'Anna';
    $O = $d === 'ugo' ? 'Ada' : 'Ugo';
    $fuori = in_array($u, ['parte_2026', 'due_righe'], true) ? DDT_FUORI : '';
    $ok = $chi === 'anna' ? "{$D} e Bruno" : "{$D} e Anna";
    $v5 = sprintf('%s ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte: se la comprendono non serve registrare altro; altrimenti registra dal Wallet il saldo legato alla successione del 1 maggio 2026 (%s a debito di %s), che il piano assorbe alla prossima generazione. Quelli di %s ci sono già.', $Chi, ddtEuro($chi), $Chi, $ok);
    $chiede = sprintf('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: %s a debito di %s; il piano lo assorbe alla prossima generazione. Quelli di %s ci sono già.', ddtEuro($chi), $Chi, $ok);

    return match ($codice) {
        'L' => "I saldi ci sono già: {$D}, Anna e Bruno hanno saldi legati alla successione del 1 maggio 2026 su quella gestione e quell'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.",
        'M' => "I saldi ci sono già: {$D}, Anna e Bruno hanno un saldo su quella gestione e quell'unità (quelli di {$D} e {$Altro} legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro." . $fuori,
        'C' => $chiede,
        'C+' => $chiede . sprintf(' Il saldo non legato di %s (%s a debito) è già la sua parte per %s: per quella di %s serve il saldo legato.', $Chi, ddtEuro($chi), $O, $D),
        'D' => "La parte di {$Chi} entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione. Le parti di {$ok} entrano una volta sola.",
        'D1' => "La parte di {$Chi} entra due volte. Togline una dal Wallet: il piano assorbe quella che resta alla prossima generazione. Il Wallet mostra un solo esercizio aperto: per {$Chi} ce n'è solo una, ed è quella da togliere. Le parti di {$ok} entrano una volta sola.",
        'I' => $v5 . $fuori,
        // Coda 463: le due parti di `$chi` insieme, 2 × € 118,36 = € 236,72 per Anna e 2 × € 78,90 = € 157,80 per Bruno. Con una riga sola la frase è al singolare, con
        // le due righe dello stato «due_righe» al plurale.
        'I+' => $v5 . ($u === 'due_righe'
            ? sprintf(' I saldi non legati di %s sono gli stessi anche per %s: comprendono la sua parte per Ugo e per Ada solo se comprendono le due parti insieme, %s a debito; se ne comprendono una sola, per l\'altra serve il saldo legato.', $Chi, $O, $chi === 'anna' ? '€ 236,72' : '€ 157,80')
            : sprintf(' Il saldo non legato di %s è lo stesso anche per %s: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, %s a debito; se ne comprende una sola, per l\'altra serve il saldo legato.', $Chi, $O, $chi === 'anna' ? '€ 236,72' : '€ 157,80')) . $fuori,
    };
}

it('Coda 440 — la tabella degli stati: per ogni erede, con le righe legate e non legate nelle due voci, ogni voce dice il vero e, seguita la sua strada, ognuno paga le sue parti una volta', function (string $chi, bool $l1, bool $l2, string $u, string $voceUgo, string $voceAda, array $strada) {
    [$s, $sub] = ddtScenario($this);
    ddtStato($this, $s, $sub, $chi, $l1, $l2, $u);

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect(array_keys($frasi))->toBe(['Ugo', 'Ada'])
        ->and($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', $voceUgo, $chi, $u))
        ->and($frasi['Ada'])->toEndWith(ddtAtteso('ada', $voceAda, $chi, $u));

    // La strada che la voce indica, dalle rotte vere: la riga legata che manca si registra, la serie in più si toglie; con i saldi non legati che il programma non
    // sa leggere, la riga legata si registra se non sono la parte (il riporto), e niente se lo sono (le due righe).
    $legate = ['ugo' => $l1, 'ada' => $l2];
    foreach ($strada as [$azione, $defunto]) {
        $azione === 'registra' ? ddtWallet($this, $s, $chi, DDT_PARTE[$chi], $sub[$defunto]) : ddtTogli($this, $s, $chi, $sub[$defunto]);
        $legate[$defunto] = $azione === 'registra';
    }
    if ($strada !== []) {
        // Nessuna voce chiede più niente. La riga non legata nel 2026 è la parte nella voce dove la riga legata non c'è, che lo dice in fondo.
        $frasi = ddtFrasi(ddtGenera($this, $s));
        expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.' . ($u === 'parte_2026' && ! $legate['ugo'] ? DDT_FUORI : ''))
            ->and($frasi['Ada'])->toEndWith('Non serve registrare altro.' . ($u === 'parte_2026' && ! $legate['ada'] ? DDT_FUORI : ''));
    }

    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    $altro = $chi === 'anna' ? 'bruno' : 'anna';
    // Conto a mano: le quote dopo il decesso più le due parti, una volta; il riporto, che non è la parte, entra accanto.
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', $chi, $altro]))->toBe(['ugo' => 0, 'ada' => 0,
        $chi => DDT_DOPO[$chi] + 2 * DDT_PARTE[$chi] + ($u === 'riporto' ? DDT_RIPORTO : 0), $altro => DDT_DOPO[$altro] + 2 * DDT_PARTE[$altro]]);
})->with(function () {
    // [l1, l2, u, voce di Ugo, voce di Ada, la strada]
    $stati = [
        'legate in tutte e due, niente altro' => [true, true, 'nessuna', 'L', 'L', []],
        'legate in tutte e due e la parte non legata nel Wallet' => [true, true, 'parte', 'D', 'L', [['togli', 'ugo']]],
        'legate in tutte e due e la parte non legata nel 2026' => [true, true, 'parte_2026', 'D1', 'L', [['togli', 'ugo']]],
        'legate in tutte e due e un riporto' => [true, true, 'riporto', 'L', 'L', []],
        'legata per Ugo, niente per Ada' => [true, false, 'nessuna', 'L', 'C', [['registra', 'ada']]],
        'legata per Ugo e la parte non legata nel Wallet' => [true, false, 'parte', 'L', 'M', []],
        'legata per Ugo e la parte non legata nel 2026' => [true, false, 'parte_2026', 'L', 'M', []],
        'legata per Ugo e un riporto' => [true, false, 'riporto', 'L', 'I', [['registra', 'ada']]],
        'legata per Ada, niente per Ugo' => [false, true, 'nessuna', 'C', 'L', [['registra', 'ugo']]],
        'legata per Ada e la parte non legata nel Wallet' => [false, true, 'parte', 'M', 'L', []],
        'legata per Ada e la parte non legata nel 2026' => [false, true, 'parte_2026', 'M', 'L', []],
        'legata per Ada e un riporto' => [false, true, 'riporto', 'I', 'L', [['registra', 'ugo']]],
        'nessuna riga' => [false, false, 'nessuna', 'C', 'C', [['registra', 'ugo'], ['registra', 'ada']]],
        'nessuna legata e la parte non legata nel Wallet (la Coda)' => [false, false, 'parte', 'M', 'C+', [['registra', 'ada']]],
        'nessuna legata e la parte non legata nel 2026, al posto del Wallet (la Coda)' => [false, false, 'parte_2026', 'M', 'C+', [['registra', 'ada']]],
        // Coda 463: la voce di Ada dice che sono gli stessi saldi della voce di Ugo (I+).
        'nessuna legata e un riporto' => [false, false, 'riporto', 'I', 'I+', [['registra', 'ugo'], ['registra', 'ada']]],
        'nessuna legata e due righe non legate che sommano le due parti' => [false, false, 'due_righe', 'I', 'I+', []],
    ];
    $righe = [];
    foreach (['anna', 'bruno'] as $chi) {
        foreach ($stati as $nome => $stato) {
            $righe[ucfirst($chi) . ' — ' . $nome] = [$chi, ...$stato];
        }
    }

    return $righe;
});

it('Coda 440 — Anna e Bruno senza righe legate, con la parte non legata di tutti e due: la voce di Ugo la legge come le loro parti, quella di Ada chiede le due righe legate e dice perché', function () {
    [$s, $sub] = ddtScenario($this);
    ddtWallet($this, $s, 'anna', DDT_PARTE['anna']);
    ddtWallet($this, $s, 'bruno', DDT_PARTE['bruno']);
    ddtWallet($this, $s, 'ugo', DDT_PARTE['defunto'], $sub['ugo']);
    ddtWallet($this, $s, 'ada', DDT_PARTE['defunto'], $sub['ada']);

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quello di Ugo legato alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano, sulla stessa gestione: € 118,36 a debito di Anna e € 78,90 a debito di Bruno; il piano li assorbe alla prossima generazione. Quello di Ada c\'è già.'
            . ' I saldi non legati di Anna e Bruno (€ 118,36 a debito e € 78,90 a debito) sono già le loro parti per Ugo: per quelle di Ada servono i saldi legati.');

    ddtWallet($this, $s, 'anna', DDT_PARTE['anna'], $sub['ada']);
    ddtWallet($this, $s, 'bruno', DDT_PARTE['bruno'], $sub['ada']);
    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 440 — Anna senza righe legate e con due righe non legate che sommano la sua parte (€ 50,00 nel Wallet e € 68,36 nel 2026): la voce di Ugo le legge come la sua parte, quella di Ada chiede la riga legata e dice che quelle righe sono già la parte per Ugo', function () {
    [$s, $sub] = ddtScenario($this);
    ddtWallet($this, $s, 'anna', 5000);
    ddtImportata($s, 'anna', DDT_PARTE['anna'] - 5000);
    ddtStato($this, $s, $sub, 'anna', false, false, 'nessuna');

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', 'M', 'anna', 'parte_2026'))
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 118,36 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ada e Bruno ci sono già.'
            . ' I saldi non legati di Anna (€ 118,36 a debito in tutto) sono già la sua parte per Ugo: per quella di Ada serve il saldo legato.');

    ddtWallet($this, $s, 'anna', DDT_PARTE['anna'], $sub['ada']);
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 440 — nella voce dove Anna manca, Ada senza righe legate e con la sua parte nei saldi non legati (la strada a mano di prima) resta fra chi ha già la sua parte: Anna non la trascina fra gli incerti', function () {
    [$s, $sub] = ddtScenario($this);
    ddtWallet($this, $s, 'anna', DDT_PARTE['anna']);
    ddtWallet($this, $s, 'ada', DDT_PARTE['defunto']);
    ddtWallet($this, $s, 'ugo', DDT_PARTE['defunto'], $sub['ugo']);
    ddtWallet($this, $s, 'bruno', DDT_PARTE['bruno'], $sub['ugo']);
    ddtWallet($this, $s, 'bruno', DDT_PARTE['bruno'], $sub['ada']);

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', 'M', 'anna', 'parte'))
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 118,36 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ada e Bruno ci sono già.'
            . ' Il saldo non legato di Anna (€ 118,36 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.')
        ->and($frasi['Ada'])->not->toContain('il programma non sa');

    ddtWallet($this, $s, 'anna', DDT_PARTE['anna'], $sub['ada']);
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 440 — con il piano nell\'esercizio aperto (il Wallet scrive dove il piano legge, e la riga di Anna occupa il posto del Wallet): la voce di Ada chiede la riga legata di Anna, non dice che il programma non sa', function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(10, 0));
    [$s, $sub] = ddtScenario($this, nellAperto: true);
    ddtStato($this, $s, $sub, 'anna', false, false, 'parte');

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', 'M', 'anna', 'parte'))
        ->and($frasi['Ada'])->toEndWith(ddtAtteso('ada', 'C+', 'anna', 'parte'));

    ddtWallet($this, $s, 'anna', DDT_PARTE['anna'], $sub['ada']);
    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', 'M', 'anna', 'parte'))->and($frasi['Ada'])->toEndWith(ddtAtteso('ada', 'L', 'anna', 'parte'));
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 72001, 'bruno' => 47999]);
});

it('Coda 440 — tre defunti sulla stessa unità e la parte non legata di Anna: la tiene la prima voce, le altre due la chiedono; seguite, Anna paga le tre parti una volta', function () {
    // Quattro proprietari al 25 %; muoiono Ugo, Ada ed Eva il 1/5/2026, eredi Anna 15 e Bruno 10 della quota di ognuno. La cifra di ognuno: 30000 × 120/365 =
    // 9863,01 → € 98,63; Anna 60 % = 5917,8 → € 59,18, Bruno 3945,2 → € 39,45. Dopo il decesso Anna 3 × (30000 − 9863,01) × 60 % = 36246,58 e Bruno
    // 24164,38, per persona; con Pia 30000 e i tre defunti 3 × 9863,01 la somma delle parti intere è 119999, e il centesimo va al resto maggiore, Anna: 36247. Alla fine Anna 36247 + 3 × 5918 = 54001, Bruno 24164 + 3 × 3945 = 35999.
    [$s, $sub] = ddtScenario($this, eva: true);
    ddtWallet($this, $s, 'anna', 5918);
    foreach (['ugo', 'ada', 'eva'] as $defunto) {
        ddtWallet($this, $s, $defunto, -9863, $sub[$defunto]);
        ddtWallet($this, $s, 'bruno', 3945, $sub[$defunto]);
    }

    $frasi = ddtFrasi(ddtGenera($this, $s));
    $chiede = fn (string $d) => sprintf('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 59,18 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di %s e Bruno ci sono già. Il saldo non legato di Anna (€ 59,18 a debito) è già la sua parte per Ugo: per quella di %s serve il saldo legato.', $d, $d);
    expect(array_keys($frasi))->toBe(['Ugo', 'Ada', 'Eva'])
        ->and($frasi['Ugo'])->toEndWith('I saldi ci sono già: Ugo, Anna e Bruno hanno un saldo su quella gestione e quell\'unità (quelli di Ugo e Bruno legati alla successione del 1 maggio 2026), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.')
        ->and($frasi['Ada'])->toEndWith($chiede('Ada'))
        ->and($frasi['Eva'])->toEndWith($chiede('Eva'));

    ddtWallet($this, $s, 'anna', 5918, $sub['ada']);
    ddtWallet($this, $s, 'anna', 5918, $sub['eva']);
    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect(collect($frasi)->every(fn (string $f) => str_ends_with($f, 'Non serve registrare altro.')))->toBeTrue(implode("\n", $frasi));
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'eva', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'eva' => 0, 'anna' => 54001, 'bruno' => 35999]);
});

it('Coda 440, controprova — con il piano che non applica i saldi le due voci non leggono la riga non legata di Anna: tutte e due chiedono le sue righe legate, e nessuna dice che i saldi ci sono già', function () {
    [$s, $sub] = ddtScenario($this, applicaSaldi: false);
    ddtStato($this, $s, $sub, 'anna', false, false, 'parte');

    // La riga di Anna sta nel 2027, fuori dal posto del Wallet del piano del 2026: per tutte e due le voci Anna non ha niente. Il piano non assorbe saldi: la strada
    // è registrarli e creare di nuovo il piano, e quel piano passa dal cancello che li legge.
    $frasi = ddtFrasi(ddtGenera($this, $s));
    foreach (['Ugo' => 'Ada', 'Ada' => 'Ugo'] as $defunto => $altro) {
        expect($frasi[$defunto])->toContain(sprintf('Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote. Le parti di %s e Bruno ci sono già, nei saldi legati alla successione. Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 118,36 a debito di Anna) e crea di nuovo il piano', $defunto))
            ->and($frasi[$defunto])->not->toContain('I saldi ci sono già');
    }
});

it('Coda 440 e 463 — due defunti con quote diverse fra gli eredi e la riga non legata di Anna della sua parte di Ugo: la voce di Ugo la legge come parte, quella di Ada chiede il saldo legato e dice che quella riga è già la parte per Ugo; seguita, Anna paga le due parti una volta', function () {
    // Coda 463 (decisione 188.1): prima la voce di Ada diceva che il programma non sa se la riga comprende la parte (V5), e con «la comprende» Anna perdeva la
    // parte di Ada. Ada lascia ad Anna 10 e a Bruno 40 della sua quota: la parte di Anna è 19726 × 20 % = 3945,2 → € 39,45, Bruno 15781. Dopo il decesso Anna
    // ha il 30 + 10 = 40 % dell'unità, 80547,95 × 40 % = 32219,18 → 32219, Bruno il 60 %, 48328,77 → 48329 (il centesimo al resto maggiore). Alla fine Anna
    // 32219 + 11836 + 3945 = 48000 (il 40 % di 120000), Bruno 48329 + 7890 + 15781 = 72000.
    [$s, $sub] = ddtScenario($this, erediAda: ['anna' => 10, 'bruno' => 40]);
    ddtWallet($this, $s, 'anna', DDT_PARTE['anna']);
    ddtWallet($this, $s, 'ugo', DDT_PARTE['defunto'], $sub['ugo']);
    ddtWallet($this, $s, 'bruno', DDT_PARTE['bruno'], $sub['ugo']);
    ddtWallet($this, $s, 'ada', DDT_PARTE['defunto'], $sub['ada']);
    ddtWallet($this, $s, 'bruno', 15781, $sub['ada']);

    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith(ddtAtteso('ugo', 'M', 'anna', 'parte'))
        ->and($frasi['Ada'])->toEndWith('Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca, sulla stessa gestione: € 39,45 a debito di Anna; il piano lo assorbe alla prossima generazione. Quelli di Ada e Bruno ci sono già.'
            . ' Il saldo non legato di Anna (€ 118,36 a debito) è già la sua parte per Ugo: per quella di Ada serve il saldo legato.')
        ->and($frasi['Ada'])->not->toContain('il programma non sa');

    ddtWallet($this, $s, 'anna', 3945, $sub['ada']);
    $frasi = ddtFrasi(ddtGenera($this, $s));
    expect($frasi['Ugo'])->toEndWith('Non serve registrare altro.')->and($frasi['Ada'])->toEndWith('Non serve registrare altro.');
    ddtGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 48000, 'bruno' => 72000]);
});
