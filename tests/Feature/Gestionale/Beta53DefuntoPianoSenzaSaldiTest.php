<?php

/**
 * 1.11.0-beta.53 (Coda 244) — il cancello del defunto con un piano che non applica i saldi del Wallet.
 *
 * Il caso di `Beta51PianoRateDefuntoNelPianoTest` (O-62-2, O-62-13): la successione di Ugo il 1/5/2026, eredi Anna 60 % e Bruno 40 %, arretrato
 * agli eredi; il «Preventivo 2026» (€ 1.200,00) creato con il Wallet vuoto, quindi con `applica_saldi` falso, calcola ancora per Ugo € 394,52, da
 * passare agli eredi: Anna € 236,71, Bruno € 157,81.
 *
 * Con il piano che non applica i saldi il cancello non legge i saldi già registrati: chi ne ha uno non legato alla successione è «incerto» (il
 * programma non sa se comprende la parte). La frase chiede senza condizione solo le righe di chi non ha niente; per gli incerti dice le due strade:
 * se i saldi non legati comprendono la parte basta creare di nuovo il piano, che li assorbe; se non la comprendono, le righe legate, poi il piano
 * creato di nuovo. Seguita la frase fino al piano creato di nuovo, la parte entra una volta sola: Ugo € 0,00, Anna € 720,00, Bruno € 480,00.
 *
 * Le righe legate alla successione già registrate si leggono anche con questo piano: chi le ha non se le vede chiedere di nuovo (la porta del
 * Wallet le rifiuta come doppione), una che non è la parte si corregge, e il piano creato di nuovo dalla rotta le assorbe (nasce applicando i
 * saldi, perché nel Wallet ci sono righe libere): misurato, Ugo € 0,00, Anna € 720,00, Bruno € 480,00, le righe legate nel piano nuovo. Se lo
 * studio sceglie invece di regolare la parte fra le parti, le righe legate ancora libere restano nel Wallet e il prossimo piano della gestione che
 * applica i saldi le assorbe (misurato): la frase dice di toglierle.
 *
 * Le righe legate alla successione già assorbite da un altro piano della stessa gestione che si riscrive ancora («Conguaglio 2026», in bozza, con
 * una voce sua): la porta del Wallet rifiuta una seconda riga per le stesse persone, e la frase non le chiede di nuovo. Dice in quale piano sono e
 * le due strade, ognuna seguita dalle rotte fino alle quote: eliminare quel piano (le righe tornano libere e questo piano le assorbe), oppure
 * approvarlo ed emetterlo e poi registrare quelle di questo piano. Anche con il piano che applica i saldi e alla creazione.
 *
 * Fonte dell'atteso: decisione 86.4 (la parte non si regola due volte) e il conto a mano scritto in ogni test.
 *
 * Che cosa NON copre: il piano che applica i saldi senza righe in un altro piano (`Beta53DefuntoLegataTest`, `Beta53DefuntoLegataCasiInPiuTest`);
 * più successioni dello stesso defunto sulla stessa unità; righe in più piani diversi; MySQL.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

const SL53Q_RIGHE = '€ 394,52 a credito di Ugo, € 236,71 a debito di Anna e € 157,81 a debito di Bruno';
const SL53Q_NON_APPLICA = 'Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote.';
/** La forma senza condizione, che chiede le righe legate a tutti. */
const SL53Q_SENZA_CONDIZIONE = 'Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione (' . SL53Q_RIGHE . ') e crea di nuovo il piano';

/** La creazione del piano dalla rotta vera, con o senza la presa d'atto del cancello. */
function sl53qCrea($test, Beta51Scenario $s, bool $accetta): TestResponse
{
    return $test->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ] + ($accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto nella prova'] : []));
}

/** Un saldo dalla porta del Wallet, legato alla successione se `$passaggio` c'è; restituisce gli errori. */
function sl53qSaldo($test, Beta51Scenario $s, string $chi, int $cents, ?Subentro $passaggio = null): array
{
    return sl53Errori($test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
        'saldo_iniziale' => $cents,
    ] + ($passaggio !== null ? ['passaggio_id' => $passaggio->id] : [])));
}

/**
 * La successione di Ugo, poi il «Preventivo 2026» creato dalla rotta con il Wallet vuoto (nasce con `applica_saldi` falso), poi i saldi non legati
 * dati dalla porta del Wallet. Restituisce lo scenario, la successione e la frase del cancello al «Ricalcola» senza la presa d'atto.
 *
 * @param array<string, int> $saldi chi => centesimi (positivo = debito)
 * @return array{0: Beta51Scenario, 1: Subentro, 2: string}
 */
function sl53qFinoAlCancello($test, array $saldi): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);

    sl53qCrea($test, $s, true)->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    expect($piano->applica_saldi)->toBeFalse();
    $s->piani['Preventivo 2026'] = ['p' => $piano, 'gestione' => 'Ordinaria 2026', 'tipo' => 'ordinario'];

    foreach ($saldi as $chi => $cents) {
        expect(sl53qSaldo($test, $s, $chi, $cents))->toBe([], "Il saldo di {$chi} è rifiutato.");
    }
    $voce = Beta51Scenario::voceDelDefunto($s->ricalcola('Preventivo 2026', accetta: false)->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeFalse()->and($voce['cifra'])->toBe(39452);

    return [$s, $succ, (string) $voce['frase']];
}

/** «Crea di nuovo il piano»: elimina dalla rotta il piano in bozza e lo crea di nuovo con la presa d'atto. Restituisce le somme del piano nuovo. */
function sl53qCreaDiNuovo($test, Beta51Scenario $s): array
{
    $test->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $s->piani['Preventivo 2026']['p']]));
    expect(PianoRate::whereKey($s->piani['Preventivo 2026']['p']->id)->exists())->toBeFalse();
    sl53qCrea($test, $s, true)->assertSessionHasNoErrors();
    $s->piani['Preventivo 2026']['p'] = PianoRate::where('nome', 'Preventivo 2026')->latest('id')->firstOrFail();

    return $s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']);
}

it('i saldi non legati che sono la parte: la frase chiede le righe legate solo se quei saldi non la comprendono, e seguita fino al piano creato di nuovo la parte entra una volta sola', function () {
    [$s, $succ, $frase] = sl53qFinoAlCancello($this, ['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781]);

    expect($frase)->toContain('Ugo, Anna e Bruno hanno su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte. ' . SL53Q_NON_APPLICA)
        ->toContain(SL53Q_NON_APPLICA . ' Se quelli non legati comprendono la parte, crea di nuovo il piano, che assorbe quelli ancora liberi nel Wallet, e non registrare altro; se non la comprendono, registra dal Wallet i saldi legati alla successione (' . SL53Q_RIGHE . ') e crea di nuovo il piano. Senza un piano nuovo la parte si regola fra le parti.')
        ->not->toContain(SL53Q_SENZA_CONDIZIONE);

    // La frase seguita: se chiede le righe legate senza condizione si registrano (la porta le accetta); qui i saldi non legati sono la parte, e
    // la frase dice di creare di nuovo il piano senza registrare altro.
    if (str_contains($frase, SL53Q_SENZA_CONDIZIONE)) {
        foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
            expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
        }
    }

    // Conto a mano: le quote del piano nuovo (Ugo 39452, Anna 48329, Bruno 32219) più i saldi che assorbe (−39452, +23671, +15781).
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000])
        ->and(Saldo::whereNotNull('passaggio_id')->count())->toBe(0);
});

it('un riporto di Ugo (€ 50,00) e Anna e Bruno senza niente: la frase chiede senza condizione le righe di Anna e Bruno e, al condizionale, quella di Ugo; seguita, la parte entra una volta sola', function () {
    [$s, $succ, $frase] = sl53qFinoAlCancello($this, ['ugo' => 5000]);

    expect($frase)->toContain('Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte. ' . SL53Q_NON_APPLICA
        . ' Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano (€ 236,71 a debito di Anna e € 157,81 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; poi crea di nuovo il piano. Senza un piano nuovo la parte si regola fra le parti.')
        ->not->toContain(SL53Q_SENZA_CONDIZIONE);

    // Il riporto non è la parte: si registrano le tre righe, poi il piano creato di nuovo. Conto a mano: Ugo 39452 + 5000 − 39452 = 5000 (il
    // riporto resta suo), Anna 48329 + 23671, Bruno 32219 + 15781.
    foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
    }
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 5000, 'anna' => 72000, 'bruno' => 48000]);
});

it('alla creazione, con i saldi non legati già assorbiti da un altro piano: la frase dice che con la parte non serve registrare altro, e chiede le righe legate solo se non la comprendono', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: false);
    $s->titolare('A', 'ugo');
    $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        $s->saldo($chi, 'A', 'Ordinaria 2026', $cents, applicato: true);
    }

    try {
        app(GeneratePianoRateAction::class)->execute($s->piani['Preventivo 2026']['p'], forzaApplicazioneSaldi: false, accettaDestinatari: false, esercizio: $s->e, allaCreazione: true);
        $voci = [];
    } catch (DestinatariCambiatiException $ex) {
        $voci = $ex->getCambiamenti();
    }
    $frase = (string) (Beta51Scenario::voceDelDefunto($voci)['frase'] ?? '');

    expect($frase)->toContain(SL53Q_NON_APPLICA . ' Il piano non è ancora stato creato: se quelli non legati comprendono la parte non serve registrare altro; se non la comprendono, registra adesso dal Wallet i saldi legati alla successione (' . SL53Q_RIGHE . ') e rimanda il modulo (il piano li assorbe), oppure la parte si regola fra le parti.')
        ->not->toContain('se registri adesso dal Wallet i saldi legati alla successione (' . SL53Q_RIGHE . ') e rimandi il modulo');
});

it('alla creazione, con il riporto di Ugo già assorbito da un altro piano e Anna e Bruno senza niente: la frase chiede senza condizione le righe di Anna e Bruno e, al condizionale, quella di Ugo', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: false);
    $s->titolare('A', 'ugo');
    $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000, applicato: true);

    try {
        app(GeneratePianoRateAction::class)->execute($s->piani['Preventivo 2026']['p'], forzaApplicazioneSaldi: false, accettaDestinatari: false, esercizio: $s->e, allaCreazione: true);
        $voci = [];
    } catch (DestinatariCambiatiException $ex) {
        $voci = $ex->getCambiamenti();
    }

    expect((string) (Beta51Scenario::voceDelDefunto($voci)['frase'] ?? ''))->toContain('Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte. ' . SL53Q_NON_APPLICA
        . ' Il piano non è ancora stato creato: registra adesso dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano (€ 236,71 a debito di Anna e € 157,81 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; poi rimanda il modulo (il piano li assorbe), oppure la parte si regola fra le parti.');
});

// --- Le righe legate alla successione già registrate, con il piano che non applica i saldi ----------------------------------------

/** La frase del cancello al «Ricalcola» senza la presa d'atto: il piano resta quello che non applica i saldi. */
function sl53qFrase(Beta51Scenario $s): string
{
    $voce = Beta51Scenario::voceDelDefunto($s->ricalcola('Preventivo 2026', accetta: false)->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeFalse();

    return (string) $voce['frase'];
}

/** Le righe legate alla successione, per persona: [nome => [centesimi, applicata, id del piano]]. */
function sl53qLegate(Beta51Scenario $s, Subentro $succ): array
{
    $nomi = collect(['ugo', 'anna', 'bruno'])->mapWithKeys(fn (string $chi) => [(int) $s->persona($chi)->id => $chi])->all();

    return Saldo::where('passaggio_id', $succ->id)->orderBy('id')->get()
        ->mapWithKeys(fn (Saldo $x) => [$nomi[(int) $x->anagrafica_id] => [(int) $x->saldo_iniziale, (bool) $x->is_applicato, $x->piano_rate_id === null ? null : (int) $x->piano_rate_id]])->all();
}

it('le righe legate alla successione già registrate, seguendo la frase: il cancello non le chiede di nuovo, dice di creare di nuovo il piano, e seguito fino in fondo la porta non rifiuta niente e la parte entra una volta sola', function () {
    [$s, $succ, $frase] = sl53qFinoAlCancello($this, []);
    // La prima frase, con il Wallet vuoto: le tre righe legate e il piano creato di nuovo.
    expect($frase)->toContain(SL53Q_SENZA_CONDIZIONE);
    foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
    }

    // Registrate le righe, il «Ricalcola» ripete il cancello: il piano non applica ancora i saldi.
    $frase = sl53qFrase($s);
    expect($frase)->toContain(SL53Q_NON_APPLICA . ' Le parti di Ugo, Anna e Bruno ci sono già, nei saldi legati alla successione. Crea di nuovo il piano, che assorbe i saldi legati alla successione, e non registrare altro. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.')
        ->not->toContain('registra dal Wallet');

    // La frase seguita fino in fondo: se chiede ancora le righe, la porta deve accettarle.
    if (str_contains($frase, SL53Q_SENZA_CONDIZIONE)) {
        foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
            expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([], "La porta rifiuta la riga di {$chi} che la frase chiede.");
        }
    }
    // Conto a mano: le quote del piano nuovo (Ugo 39452, Anna 48329, Bruno 32219) più le righe legate che assorbe (−39452, +23671, +15781).
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
    $nuovo = (int) $s->piani['Preventivo 2026']['p']->id;
    expect($s->piani['Preventivo 2026']['p']->fresh()->applica_saldi)->toBeTrue()
        ->and(sl53qLegate($s, $succ))->toBe(['ugo' => [-39452, true, $nuovo], 'anna' => [23671, true, $nuovo], 'bruno' => [15781, true, $nuovo]]);
});

it('una riga legata sola già registrata (Anna): la frase chiede solo quelle che mancano, dice che quella di Anna c\'è già, e seguita la parte entra una volta sola', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    expect(sl53qSaldo($this, $s, 'anna', 23671, $succ))->toBe([]);

    $frase = sl53qFrase($s);
    expect($frase)->toContain(SL53Q_NON_APPLICA . ' La parte di Anna c\'è già, nei saldi legati alla successione. Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026 che mancano (€ 394,52 a credito di Ugo e € 157,81 a debito di Bruno) e crea di nuovo il piano, che assorbe anche i saldi legati già registrati. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.')
        ->not->toContain('€ 236,71 a debito di Anna');

    // La frase seguita: le due righe che mancano.
    foreach (['ugo' => -39452, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([], "La porta rifiuta la riga di {$chi} che la frase chiede.");
    }
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000])
        ->and(Saldo::where('passaggio_id', $succ->id)->count())->toBe(3);
});

it('un riporto di Ugo (€ 50,00), la riga legata di Anna e Bruno senza niente: la frase chiede la riga di Bruno e, al condizionale, quella di Ugo, non quella di Anna; seguita, la parte entra una volta sola', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, ['ugo' => 5000]);
    expect(sl53qSaldo($this, $s, 'anna', 23671, $succ))->toBe([]);

    $frase = sl53qFrase($s);
    expect($frase)->toContain('Ugo ha su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte. ' . SL53Q_NON_APPLICA
        . ' La parte di Anna c\'è già, nei saldi legati alla successione. Per passare la parte agli eredi registra dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 157,81 a debito di Bruno) e, se quelli non legati non la comprendono, anche € 394,52 a credito di Ugo; poi crea di nuovo il piano. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.')
        ->not->toContain('€ 236,71 a debito di Anna');

    // Il riporto non è la parte: si registrano le righe di Bruno e di Ugo. Conto a mano: Ugo 39452 + 5000 − 39452 = 5000, Anna 48329 + 23671, Bruno 32219 + 15781.
    foreach (['bruno' => 15781, 'ugo' => -39452] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([], "La porta rifiuta la riga di {$chi} che la frase chiede.");
    }
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 5000, 'anna' => 72000, 'bruno' => 48000]);
});

it('una riga legata che non è la parte (Anna € 100,00 invece di € 236,71): la frase chiede di correggerla, non di registrarla di nuovo; corretta dalla porta e creato di nuovo il piano, la parte entra una volta sola', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    foreach (['ugo' => -39452, 'anna' => 10000, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
    }

    $frase = sl53qFrase($s);
    expect($frase)->toContain(SL53Q_NON_APPLICA . ' Le parti di Ugo e Bruno ci sono già, nei saldi legati alla successione. Per Anna il saldo legato alla successione è di € 100,00 a debito invece di € 236,71 a debito: correggilo dal Wallet. Poi crea di nuovo il piano, che assorbe i saldi legati alla successione, e non registrare altro. Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.')
        ->not->toContain('registra dal Wallet');

    // La correzione dalla porta della modifica, poi il piano creato di nuovo.
    $riga = Saldo::where('passaggio_id', $succ->id)->where('anagrafica_id', $s->persona('anna')->id)->firstOrFail();
    $r = $this->actingAs($s->admin)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s->c, $riga]), ['saldo_iniziale' => 23671, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id]);
    expect(sl53Errori($r))->toBe([]);
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

it('alla creazione, con le righe legate di Ugo e Anna già nel Wallet e il piano che non applica i saldi (dall\'azione, che la rotta non raggiunge: con righe libere il piano nasce applicandole): la frase chiede solo quella di Bruno', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: false);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    foreach (['ugo' => -39452, 'anna' => 23671] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
    }

    try {
        app(GeneratePianoRateAction::class)->execute($s->piani['Preventivo 2026']['p'], forzaApplicazioneSaldi: false, accettaDestinatari: false, esercizio: $s->e, allaCreazione: true);
        $voci = [];
    } catch (DestinatariCambiatiException $ex) {
        $voci = $ex->getCambiamenti();
    }

    expect((string) (Beta51Scenario::voceDelDefunto($voci)['frase'] ?? ''))->toContain(SL53Q_NON_APPLICA . ' Le parti di Ugo e Anna ci sono già, nei saldi legati alla successione. Il piano non è ancora stato creato: registra adesso dal Wallet il saldo legato alla successione del 1 maggio 2026 che manca (€ 157,81 a debito di Bruno) e rimanda il modulo (il piano li assorbe con quelli già registrati), oppure la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.');
});

// --- Le righe legate alla successione già in un altro piano della stessa gestione, non ancora emesso ------------------------------------

const SL53Q_ALTRO = 'I saldi legati alla successione di Ugo, Anna e Bruno sono già nel piano «Conguaglio 2026», che non è ancora emesso: finché sono lì, il Wallet non ne accetta altri per le stesse persone.';

/**
 * Un altro piano della stessa gestione, «Conguaglio 2026», in bozza e con i saldi applicati, con una voce sua («Pulizie», € 1.200,00 sulla stessa
 * tabella: per Ugo la stessa cifra, € 394,52). Il suo cancello dice che i saldi legati alla successione ci sono già; generato, li assorbe.
 */
function sl53qAltroPiano($test, Beta51Scenario $s, Subentro $succ): PianoRate
{
    $s->voce('Ordinaria 2026', 'Pulizie', 120000, ['Proprietà' => 100]);
    $p = PianoRate::create(['gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Conguaglio 2026',
        'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    $p->capitoli()->attach($s->voci['Pulizie']['conto']->id, ['importo' => 120000, 'note' => 'La voce del conguaglio']);
    $s->piani['Conguaglio 2026'] = ['p' => $p, 'gestione' => 'Ordinaria 2026', 'tipo' => 'ordinario'];
    expect((string) (Beta51Scenario::voceDelDefunto($s->cancello('Conguaglio 2026'))['frase'] ?? ''))->toContain('I saldi ci sono già');
    $s->genera('Conguaglio 2026', forza: null);
    $id = (int) $p->id;
    expect(sl53qLegate($s, $succ))->toBe(['ugo' => [-39452, true, $id], 'anna' => [23671, true, $id], 'bruno' => [15781, true, $id]]);

    return $p;
}

/** Le tre righe legate alla successione dalla porta del Wallet; restituisce gli errori di ognuna. @return array<string, array> */
function sl53qLeTre($test, Beta51Scenario $s, Subentro $succ): array
{
    $errori = [];
    foreach (['ugo' => -39452, 'anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        $errori[$chi] = sl53qSaldo($test, $s, $chi, $cents, $succ);
    }

    return $errori;
}

it('le righe legate alla successione già nel piano in bozza «Conguaglio 2026»: la frase non le chiede di nuovo, dice dove sono e le due strade; eliminato quel piano, il piano creato di nuovo le assorbe', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    expect(sl53qLeTre($this, $s, $succ))->toBe(['ugo' => [], 'anna' => [], 'bruno' => []]);
    $altro = sl53qAltroPiano($this, $s, $succ);

    $frase = sl53qFrase($s);
    expect($frase)->toContain(SL53Q_NON_APPLICA . ' ' . SL53Q_ALTRO . ' Se sono di questo piano, elimina «Conguaglio 2026» dalla sua pagina, così tornano liberi nel Wallet, e crea di nuovo questo piano, che li assorbe. Se sono di «Conguaglio 2026», registra dal Wallet quelli di questo piano (' . SL53Q_RIGHE . ') dopo aver approvato ed emesso «Conguaglio 2026», e crea di nuovo questo piano. Senza un piano nuovo la parte si regola fra le parti.')
        ->not->toContain(SL53Q_SENZA_CONDIZIONE);
    // La strada di prima, dalla porta: le righe che la frase chiedeva si rifiutano come doppione.
    expect(sl53qLeTre($this, $s, $succ)['ugo']['anagrafica_id'][0] ?? '')->toStartWith('Questa persona ha già un saldo legato a questo passaggio');

    // La prima strada, dalle rotte: «Conguaglio 2026» in bozza si elimina, le righe tornano libere, il piano creato di nuovo (con la sua voce) le assorbe.
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $altro]));
    expect(PianoRate::find($altro->id))->toBeNull()
        ->and(collect(sl53qLegate($s, $succ))->every(fn (array $x) => $x[1] === false))->toBeTrue();
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $s->piani['Preventivo 2026']['p']]));
    $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true, 'capitoli_ids' => [$s->voci['Spese generali']['conto']->id],
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto nella prova',
    ])->assertSessionHasNoErrors();
    $s->piani['Preventivo 2026']['p'] = PianoRate::where('nome', 'Preventivo 2026')->latest('id')->firstOrFail();
    $nuovo = (int) $s->piani['Preventivo 2026']['p']->id;
    // Conto a mano: le quote del piano nuovo (Ugo 39452, Anna 48329, Bruno 32219) più le righe legate che assorbe (−39452, +23671, +15781).
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000])
        ->and(sl53qLegate($s, $succ))->toBe(['ugo' => [-39452, true, $nuovo], 'anna' => [23671, true, $nuovo], 'bruno' => [15781, true, $nuovo]]);
});

it('le righe legate alla successione già nel piano in bozza «Conguaglio 2026», l\'altra strada: approvato ed emesso quel piano, la porta accetta le righe di questo, e il piano creato di nuovo le assorbe', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    sl53qLeTre($this, $s, $succ);
    $altro = sl53qAltroPiano($this, $s, $succ);
    expect(sl53qFrase($s))->toContain(SL53Q_ALTRO);

    // Dalle rotte: «Conguaglio 2026» si approva e si emette; le sue righe restano sue, ferme.
    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $altro]), ['approvato' => true, 'data_delibera_assemblea' => '2026-10-15'])
        ->assertSessionHasNoErrors();
    $s->emetti('Conguaglio 2026', '2026-12-31')->assertSessionHasNoErrors();
    expect($altro->fresh()->eImmutabile())->toBeTrue();
    // Le righe di questo piano, dalla porta: nessun rifiuto. Poi il piano creato di nuovo.
    expect(sl53qLeTre($this, $s, $succ))->toBe(['ugo' => [], 'anna' => [], 'bruno' => []]);
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
    $nuovo = (int) $s->piani['Preventivo 2026']['p']->id;
    expect(Saldo::where('passaggio_id', $succ->id)->orderBy('id')->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $altro->id, (int) $altro->id, (int) $altro->id, $nuovo, $nuovo, $nuovo]);
});

it('con il piano che applica i saldi e le righe legate già nel piano in bozza «Conguaglio 2026»: la frase dice dove sono e le due strade; eliminato quel piano, la generazione le assorbe', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true, bozza: true, applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    expect((string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? ''))->toContain('Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione');
    sl53qLeTre($this, $s, $succ);
    $altro = sl53qAltroPiano($this, $s, $succ);

    $frase = (string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? '');
    expect($frase)->toContain(SL53Q_ALTRO . ' Se sono di questo piano, elimina «Conguaglio 2026» dalla sua pagina: tornano liberi nel Wallet, e questo piano li assorbe alla prossima generazione. Se sono di «Conguaglio 2026», registra dal Wallet quelli di questo piano (' . SL53Q_RIGHE . ') dopo aver approvato ed emesso «Conguaglio 2026»: questo piano li assorbe alla prossima generazione.')
        ->not->toContain('Per passare la parte agli eredi registra dal Wallet');

    // La prima strada, dalle rotte: «Conguaglio 2026» si elimina; il cancello dice che i saldi ci sono già, e la generazione li assorbe.
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $altro]));
    expect(PianoRate::find($altro->id))->toBeNull();
    expect((string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? ''))->toContain('I saldi ci sono già');
    $s->genera('Preventivo 2026', forza: null);
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

it('alla creazione, con le righe legate già nel piano in bozza «Conguaglio 2026» (dall\'azione): la frase dice dove sono e le due strade, con «rimanda il modulo»', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    sl53qLeTre($this, $s, $succ);
    sl53qAltroPiano($this, $s, $succ);
    $s->piani['Preventivo 2026']['p']->update(['applica_saldi' => false]);

    try {
        app(GeneratePianoRateAction::class)->execute($s->piani['Preventivo 2026']['p']->fresh(), forzaApplicazioneSaldi: false, accettaDestinatari: false, esercizio: $s->e, allaCreazione: true);
        $voci = [];
    } catch (DestinatariCambiatiException $ex) {
        $voci = $ex->getCambiamenti();
    }

    expect((string) (Beta51Scenario::voceDelDefunto($voci)['frase'] ?? ''))->toContain(SL53Q_NON_APPLICA . ' ' . SL53Q_ALTRO . ' Il piano non è ancora stato creato. Se sono di questo piano, elimina «Conguaglio 2026» dalla sua pagina, così tornano liberi nel Wallet, e rimanda il modulo (il piano li assorbe). Se sono di «Conguaglio 2026», registra dal Wallet quelli di questo piano (' . SL53Q_RIGHE . ') dopo aver approvato ed emesso «Conguaglio 2026», e rimanda il modulo. Senza il piano la parte si regola fra le parti.');
});

/** Un altro piano della stessa gestione con una voce sua (€ 1.200,00 sulla stessa tabella), nello stato dato, generato: assorbe le righe libere. */
function sl53qUnAltroPiano(Beta51Scenario $s, string $nome, string $voce, string $stato): PianoRate
{
    $s->voce('Ordinaria 2026', $voce, 120000, ['Proprietà' => 100]);
    $p = PianoRate::create(['gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => $nome,
        'stato' => $stato, 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        'data_delibera_assemblea' => $stato === 'approvato' ? '2026-10-15' : null]);
    $p->capitoli()->attach($s->voci[$voce]['conto']->id, ['importo' => 120000, 'note' => 'La voce sua']);
    $s->piani[$nome] = ['p' => $p, 'gestione' => 'Ordinaria 2026', 'tipo' => 'ordinario'];
    $s->genera($nome, forza: null);

    return $p;
}

it('le righe legate alla successione già nel piano approvato «Conguaglio 2026»: la frase dice di emettere quel piano, per nome; seguita, la porta accetta le righe di questo, e il piano creato di nuovo le assorbe', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    sl53qLeTre($this, $s, $succ);
    $altro = sl53qUnAltroPiano($s, 'Conguaglio 2026', 'Pulizie', 'approvato');
    expect(collect(sl53qLegate($s, $succ))->every(fn (array $x) => $x[2] === (int) $altro->id))->toBeTrue();

    expect(sl53qFrase($s))->toContain(SL53Q_NON_APPLICA . ' ' . SL53Q_ALTRO . ' Se sono di questo piano, riporta «Conguaglio 2026» in bozza ed eliminalo dalla sua pagina, così tornano liberi nel Wallet, e crea di nuovo questo piano, che li assorbe. Se sono di «Conguaglio 2026», registra dal Wallet quelli di questo piano (' . SL53Q_RIGHE . ') dopo aver emesso «Conguaglio 2026», e crea di nuovo questo piano.');

    // La strada detta, dalle rotte: «Conguaglio 2026» si emette, la porta accetta le tre righe, il piano creato di nuovo le assorbe.
    $s->emetti('Conguaglio 2026', '2026-12-31')->assertSessionHasNoErrors();
    expect($altro->fresh()->eImmutabile())->toBeTrue();
    expect(sl53qLeTre($this, $s, $succ))->toBe(['ugo' => [], 'anna' => [], 'bruno' => []]);
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

it('le righe legate alla successione in due altri piani, uno in bozza e uno approvato: la frase dice di emettere quei piani, approvando prima quello in bozza; seguita, la porta accetta le righe di questo, e il piano creato di nuovo le assorbe', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    expect(sl53qSaldo($this, $s, 'ugo', -39452, $succ))->toBe([]);
    $conguaglio = sl53qUnAltroPiano($s, 'Conguaglio 2026', 'Pulizie', 'bozza');
    foreach (['anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents, $succ))->toBe([]);
    }
    $extra = sl53qUnAltroPiano($s, 'Rate extra', 'Ascensore', 'approvato');
    expect(sl53qLegate($s, $succ))->toBe(['ugo' => [-39452, true, (int) $conguaglio->id], 'anna' => [23671, true, (int) $extra->id], 'bruno' => [15781, true, (int) $extra->id]]);

    expect(sl53qFrase($s))->toContain('Se sono di quei piani, registra dal Wallet quelli di questo piano (' . SL53Q_RIGHE . ') dopo aver emesso quei piani (approvando prima quelli in bozza), e crea di nuovo questo piano.');

    // La strada detta, dalle rotte: «Conguaglio 2026» si approva, poi i due piani si emettono; la porta accetta le tre righe, il piano creato di nuovo le assorbe.
    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $conguaglio]), ['approvato' => true, 'data_delibera_assemblea' => '2026-10-15'])
        ->assertSessionHasNoErrors();
    $s->emetti('Conguaglio 2026', '2026-12-31')->assertSessionHasNoErrors();
    $s->emetti('Rate extra', '2026-12-31')->assertSessionHasNoErrors();
    expect($conguaglio->fresh()->eImmutabile() && $extra->fresh()->eImmutabile())->toBeTrue();
    expect(sl53qLeTre($this, $s, $succ))->toBe(['ugo' => [], 'anna' => [], 'bruno' => []]);
    expect(sl53qCreaDiNuovo($this, $s))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

it('le righe legate registrate e la scelta «la parte si regola fra le parti»: la frase dice di togliere quelle ancora libere; senza toglierle, il piano dopo della stessa gestione le assorbe', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    sl53qLeTre($this, $s, $succ);
    expect(sl53qFrase($s))->toEndWith('Senza un piano nuovo la parte si regola fra le parti, e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi.');
    // La presa d'atto sul piano che non applica i saldi: la parte si regola fra le parti.
    expect(sl53Errori($s->ricalcola('Preventivo 2026', accetta: true)))->toBe([]);

    // Senza toglierle: un altro piano della stessa gestione che applica i saldi le assorbe (la parte entrerebbe due volte).
    $altro = sl53qAltroPiano($this, $s, $succ);
    expect(Saldo::where('passaggio_id', $succ->id)->where('piano_rate_id', $altro->id)->count())->toBe(3);
});

it('controprova — le righe legate tolte dal Wallet dopo la scelta «fra le parti»: la porta non rifiuta niente, e il piano dopo della stessa gestione non le trova', function () {
    [$s, $succ] = sl53qFinoAlCancello($this, []);
    sl53qLeTre($this, $s, $succ);
    expect(sl53Errori($s->ricalcola('Preventivo 2026', accetta: true)))->toBe([]);

    foreach (Saldo::where('passaggio_id', $succ->id)->get() as $x) {
        expect(sl53Errori($this->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $x]))))->toBe([]);
    }
    expect(Saldo::where('passaggio_id', $succ->id)->count())->toBe(0);
    $s->voce('Ordinaria 2026', 'Pulizie', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Conguaglio 2026', 'Ordinaria 2026', numeroRate: 2, primaScadenza: '2026-11-05', bozza: true, applicaSaldi: true);
    $s->piani['Conguaglio 2026']['p']->capitoli()->attach($s->voci['Pulizie']['conto']->id, ['importo' => 120000, 'note' => 'La voce del conguaglio']);
    expect((string) (Beta51Scenario::voceDelDefunto($s->cancello('Conguaglio 2026'))['frase'] ?? ''))
        ->toContain('Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026, sulla stessa gestione: ' . SL53Q_RIGHE);
});

it('con il piano che applica i saldi, la riga legata di Ugo già nel piano in bozza «Conguaglio 2026» e i saldi non legati di Anna e Bruno che sono la loro parte: la frase dice che quelli ci sono già e chiede solo la strada per Ugo; eliminato quel piano, la generazione assorbe tutto', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true, bozza: true, applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    expect(sl53qSaldo($this, $s, 'ugo', -39452, $succ))->toBe([]);
    // «Conguaglio 2026», con la sua voce, assorbe la riga di Ugo (l'unica libera); poi Anna e Bruno hanno i loro saldi non legati.
    $s->voce('Ordinaria 2026', 'Pulizie', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Conguaglio 2026', 'Ordinaria 2026', numeroRate: 2, primaScadenza: '2026-11-05', bozza: true, applicaSaldi: true);
    $altro = $s->piani['Conguaglio 2026']['p'];
    $altro->capitoli()->attach($s->voci['Pulizie']['conto']->id, ['importo' => 120000, 'note' => 'La voce del conguaglio']);
    $s->genera('Conguaglio 2026', forza: null);
    expect(sl53qLegate($s, $succ))->toBe(['ugo' => [-39452, true, (int) $altro->id]]);
    foreach (['anna' => 23671, 'bruno' => 15781] as $chi => $cents) {
        expect(sl53qSaldo($this, $s, $chi, $cents))->toBe([]);
    }

    $frase = (string) (Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'] ?? '');
    expect($frase)->toContain('Quelli di Anna e Bruno ci sono già. Il saldo legato alla successione di Ugo è già nel piano «Conguaglio 2026», che non è ancora emesso: finché è lì, il Wallet non ne accetta un altro per la stessa persona. Se è di questo piano, elimina «Conguaglio 2026» dalla sua pagina: torna libero nel Wallet, e questo piano lo assorbe alla prossima generazione. Se è di «Conguaglio 2026», registra dal Wallet quello di questo piano (€ 394,52 a credito di Ugo) dopo aver approvato ed emesso «Conguaglio 2026»: questo piano lo assorbe alla prossima generazione.')
        ->not->toContain('€ 236,71 a debito di Anna');

    // La prima strada, dalle rotte; poi la generazione con la presa d'atto. Conto a mano: Ugo 39452 − 39452, Anna 48329 + 23671, Bruno 32219 + 15781.
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $altro]));
    expect(PianoRate::find($altro->id))->toBeNull();
    $s->genera('Preventivo 2026', forza: null);
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});
