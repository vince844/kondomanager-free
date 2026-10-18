<?php

/**
 * «Sposta spesa» decide se una voce è sovrapposta a un'altra del piano con la regola del motore, e nei piani che hanno già righe sovrapposte non le
 * offre né le accetta e dice l'uscita.
 *
 * Il motore passa la quota di un capitolo che ha la sua riga nel piano ai suoi sottoconti, e li salta: la loro riga, o quella nuova che si scrivesse
 * per loro, non conta. Ma la passa solo se il capitolo non ha tabelle millesimali proprie, e solo ai sottoconti nati fino alla data del piano (se
 * nessuno è nato fino ad allora, a tutti); a ogni livello, con la stessa regola (`CalcoloQuoteService::processaConti`, `Conto::sottocontiDelPiano`). Una
 * voce è sovrapposta a un capitolo del piano, o a dei sottoconti del piano, solo lungo quella catena: un sottoconto nato dopo il piano sotto un
 * capitolo che ha la sua riga prende una riga sua e il piano resta com'era (€ 2.200,00), un capitolo con tabelle proprie non copre i suoi sottoconti.
 * Il server rifiuta con la stessa regola e il menu non offre le voci che il server rifiuterebbe.
 *
 * Un piano che ha già la riga di un capitolo insieme a quelle dei suoi sottoconti (la scriveva «Sposta spesa» nelle versioni che non controllavano la
 * sovrapposizione) chiede € 2.200,00 e ne ripartisce € 1.200,00, e «Ricalcola» risponde «con successo» senza cambiare niente. Lì le voci sovrapposte, anche
 * se hanno già la loro riga, non si offrono come destinazione e il server le rifiuta con la via d'uscita: togliere il capitolo con «Rimuovi voce e
 * ricalcola», dopo aver stornato lo spostamento che gli ha dato budget. La pagina del piano lo dice in un riquadro (`avviso_righe_sovrapposte`) finché le
 * righe sono sovrapposte. Lo storno non passa dal controllo: restituisce ciò che è già stato spostato.
 *
 * Se la voce da nominare come strada è un capitolo con spese ed entrate insieme, il rifiuto dice del misto e non manda a un capitolo che il server
 * rifiuterebbe a sua volta.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (le cifre sono scritte accanto) e la lettura del motore.
 *
 * Cosa resta scoperto: la sorgente sovrapposta (resta accettata: sposta budget da una riga che il motore non legge); la riga di un capitolo con
 * importo vuoto («tutto il residuo») o a zero, che il motore tratta in modo diverso e qui conta come riga che copre i sottoconti; il piano
 * straordinario; la schermata del riquadro (`PianiRateShowAvvisoRigheSovrapposte.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che non deve cambiare: sono verdi prima e dopo.
 */

use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BudgetMovementService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B52M_MISTO = '«Manutenzione» ha sottoconti di spesa e di entrata: il piano ne divide l\'importo fra le due, e spostare budget da o verso un capitolo così cambierebbe il totale del piano. Scegli una voce che sia solo una spesa o solo un\'entrata.';
const B52M_USCITA = 'togli il capitolo «Manutenzione» dal piano con «Rimuovi voce e ricalcola»; se il capitolo ha ricevuto budget con «Sposta spesa», storna prima lo spostamento dallo storico (icona dell\'orologio, accanto all\'importo).';

/** Gli errori della richiesta di spostamento, per campo (vuoto se è passata). */
function b52mSposta($test, array $s, $piano, int $da, int $a, string $euro = '100.00'): array
{
    session()->forget('errors');
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), ['source_id' => $da, 'destination_id' => $a, 'amount' => $euro, 'reason' => 'Prova']);

    return session('errors')?->toArray() ?? [];
}

function b52mRicalcola($test, array $s, $piano): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
}

function b52mPivot($piano): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('conto_id')->pluck('importo', 'conto_id')->map(fn ($v) => (int) $v)->all();
}

function b52mPagina($test, array $s, $piano): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]))->assertOk()->viewData('page')['props'];
}

/** Le destinazioni offerte dalla pagina, come «nome:tipo» in ordine alfabetico. */
function b52mDestinazioni($test, array $s, $piano): array
{
    return collect(b52mPagina($test, $s, $piano)['destinations'])->map(fn ($d) => $d['nome'].':'.$d['tipo'])->sort()->values()->all();
}

function b52mDisallineato($piano): bool
{
    return app(PianoRateQuoteService::class)->eDisallineato($piano);
}

/** Un sottoconto con la tabella e il 100 % al proprietario, nato adesso. */
function b52mSottoconto($test, array $s, string $nome, int $padreId, int $importo): Conto
{
    $voce = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $padreId, 'nome' => $nome, 'tipo' => 'spesa', 'importo' => $importo]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    return $voce;
}

/** Le tabelle proprie di una voce, con il 100 % al proprietario. */
function b52mTabelleProprie(array $s, Conto $voce): void
{
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/** «Spese generali» da € 1.200,00 e il capitolo «Manutenzione» con Ascensore (€ 600,00) e Caldaia (€ 400,00), nati prima del piano: nella pivot ci sono le tre righe, il capitolo no. */
function b52mScenarioCapitoloConFigli($test): array
{
    $s = b52tScenario($test, entrata: '0,00');
    $s['entrata']->delete();
    $s['padre'] = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $s['ascensore'] = b52tVoce($test, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
    $s['caldaia'] = b52tVoce($test, $s, 'Caldaia', 'spesa', '400,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
    $s['piano'] = b52tPiano($test, $s);

    return $s;
}

/**
 * Il piano già colpito: la riga del capitolo che «Sposta spesa» scriveva (€ 100,00 tolti a «Spese generali», con il movimento) insieme alle righe dei
 * sottoconti. Il piano chiede € 2.200,00 e ripartisce € 1.200,00.
 */
function b52mPianoGiaColpito($test): array
{
    $s = b52mScenarioCapitoloConFigli($test);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['spesa']->id)->update(['importo' => 110000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['padre']->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    $s['vecchio'] = BudgetMovement::create(['piano_rate_id' => $s['piano']->id, 'source_conto_id' => $s['spesa']->id, 'destination_conto_id' => $s['padre']->id, 'user_id' => $test->user->id,
        'amount' => 10000, 'source_old_amount' => 120000, 'destination_old_amount' => 0, 'reason' => 'vecchio', 'type' => 'reallocation']);
    b52mRicalcola($test, $s, $s['piano']->fresh());

    return $s;
}

// ─── La regola del motore: un sottoconto nato dopo il piano, un capitolo con tabelle proprie ────────────────────

it('un sottoconto nato dopo il piano sotto un capitolo che ha la sua riga si sposta: il motore passa la quota ai sottoconti nati prima, e il piano resta € 2.200,00', function () {
    $s = b52tScenarioConPadreLegacy($this, 'spesa');
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    expect($prima)->toBe(220000) // Pulizia 1.200,00 + Manutenzione 1.000,00 (60 % Ascensore, 40 % Caldaia)
        ->and(b52mPivot($piano))->toBe([$s['pulizia']->id => 120000, $s['padre']->id => 100000]);
    sleep(1); // il sottoconto nasce dopo il piano: lo snapshot del motore non lo comprende
    $cancello = b52mSottoconto($this, $s, 'Manutenzione cancello', $s['padre']->id, 20000);

    expect(b52mDestinazioni($this, $s, $piano->fresh()))->toBe(['Manutenzione cancello:spesa', 'Manutenzione:spesa', 'Pulizia:spesa']);

    $errori = b52mSposta($this, $s, $piano->fresh(), $s['pulizia']->id, $cancello->id);
    b52mRicalcola($this, $s, $piano->fresh());

    expect($errori)->toBe([])
        ->and(BudgetMovement::count())->toBe(1)
        ->and(b52mPivot($piano->fresh()))->toBe([$s['pulizia']->id => 110000, $s['padre']->id => 100000, $cancello->id => 10000]) // € 1.100,00 + 1.000,00 + 100,00
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima)
        ->and(b52mDisallineato($piano->fresh()))->toBeFalse()
        ->and(b52mPagina($this, $s, $piano->fresh())['avviso_righe_sovrapposte'])->toBeNull();
});

it('un capitolo con tabelle proprie nel piano non passa la quota ai sottoconti: verso un suo sottoconto si sposta e il piano resta € 2.200,00', function () {
    $s = b52tScenarioConPadreLegacy($this, 'spesa');
    $piano = $s['piano'];
    b52mTabelleProprie($s, $s['padre']);
    b52mRicalcola($this, $s, $piano->fresh());
    $prima = b52tQuoteDelMotore($piano->fresh());
    expect($prima)->toBe(220000); // il capitolo ripartisce i suoi € 1.000,00 sulle sue tabelle

    expect(b52mDestinazioni($this, $s, $piano->fresh()))->toBe(['Ascensore:spesa', 'Caldaia:spesa', 'Manutenzione:spesa', 'Pulizia:spesa']);

    $errori = b52mSposta($this, $s, $piano->fresh(), $s['pulizia']->id, $s['figlio1']->id);
    b52mRicalcola($this, $s, $piano->fresh());

    expect($errori)->toBe([])
        ->and(b52mPivot($piano->fresh()))->toBe([$s['pulizia']->id => 110000, $s['padre']->id => 100000, $s['figlio1']->id => 10000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima)
        ->and(b52mDisallineato($piano->fresh()))->toBeFalse();
});

it('verso un capitolo i cui sottoconti nel piano non sono fra quelli a cui il motore passa la quota si sposta, e il piano resta € 1.400,00', function () {
    $s = b52tScenarioConPadreLegacy($this, 'spesa');
    $piano = $s['piano'];
    sleep(1);
    $cancello = b52mSottoconto($this, $s, 'Manutenzione cancello', $s['padre']->id, 20000);
    // La riga del capitolo esce dal piano, quella del cancello (nato dopo) entra: il capitolo passerebbe la quota ad Ascensore e Caldaia, non al cancello.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['padre']->id)->delete();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $cancello->id, 'importo' => 20000, 'created_at' => now(), 'updated_at' => now()]);
    b52mRicalcola($this, $s, $piano->fresh());
    $prima = b52tQuoteDelMotore($piano->fresh());
    expect($prima)->toBe(140000); // Pulizia 1.200,00 + cancello 200,00

    $errori = b52mSposta($this, $s, $piano->fresh(), $s['pulizia']->id, $s['padre']->id);
    b52mRicalcola($this, $s, $piano->fresh());

    expect($errori)->toBe([])
        ->and(b52mPivot($piano->fresh()))->toBe([$s['pulizia']->id => 110000, $s['padre']->id => 10000, $cancello->id => 20000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima) // Pulizia 1.100,00 + capitolo 100,00 + cancello 200,00
        ->and(b52mDisallineato($piano->fresh()))->toBeFalse();
});

it('controprova — se nessun sottoconto è nato fino alla data del piano il motore li prende tutti: verso un sottoconto il cui capitolo è nel piano si rifiuta ancora', function () {
    $s = b52tScenarioConPadre($this, 'spesa'); // i sottoconti nascono dopo il piano e il capitolo entra come voce orfana
    $piano = $s['piano'];
    $pivot = b52mPivot($piano);

    $errori = b52mSposta($this, $s, $piano, $s['pulizia']->id, $s['figlio1']->id);

    expect($errori['destination_id'][0] ?? null)->toBe('«Ascensore» non è nel piano, ma lo è il suo capitolo «Manutenzione»: con una riga anche per il sottoconto il piano conterebbe solo quella del capitolo e il budget spostato non verrebbe contato. Sposta il budget sul capitolo «Manutenzione».')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52mPivot($piano->fresh()))->toBe($pivot)
        ->and(b52mDestinazioni($this, $s, $piano->fresh()))->toBe(['Manutenzione:spesa', 'Pulizia:spesa']);
});

it('controprova — i sottoconti nati prima del piano sotto un capitolo che ha la sua riga restano sovrapposti: il server li rifiuta e il menu non li offre', function () {
    $s = b52tScenarioConPadreLegacy($this, 'spesa');
    $piano = $s['piano'];

    $errori = b52mSposta($this, $s, $piano, $s['pulizia']->id, $s['figlio1']->id);

    expect($errori['destination_id'][0] ?? null)->toStartWith('«Ascensore» non è nel piano, ma lo è il suo capitolo «Manutenzione»: ')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52mDestinazioni($this, $s, $piano->fresh()))->toBe(['Manutenzione:spesa', 'Pulizia:spesa']);
});

it('controprova — verso un capitolo i cui sottoconti, nati prima del piano, hanno la loro riga si rifiuta, nomina i sottoconti e il menu non lo offre', function () {
    $s = b52mScenarioCapitoloConFigli($this);
    $piano = $s['piano'];

    $errori = b52mSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id);

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» non è nel piano, ma lo sono i suoi sottoconti «Ascensore» e «Caldaia»: con una riga anche per il capitolo il piano conterebbe solo quella e non più le righe dei sottoconti. Sposta il budget su uno dei sottoconti.')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52mDestinazioni($this, $s, $piano))->toBe(['Ascensore:spesa', 'Caldaia:spesa', 'Spese generali:spesa'])
        ->and(b52mPagina($this, $s, $piano)['avviso_righe_sovrapposte'])->toBeNull(); // le righe dei sottoconti ci sono, quella del capitolo no: niente è sovrapposto
});

// ─── Un capitolo misto: il rifiuto non manda a un capitolo che si rifiuta ───────────────────────────────────────

it('verso un sottoconto di un capitolo misto che ha la sua riga il rifiuto dice del misto e non manda al capitolo', function () {
    $s = b52tScenarioConPadreMisto($this);
    $piano = $s['piano'];
    $pivot = b52mPivot($piano);

    $errori = b52mSposta($this, $s, $piano, $s['pulizia']->id, $s['figlio1']->id);
    // la strada, seguita alla lettera, non porta a un rifiuto diverso
    $eCapitolo = b52mSposta($this, $s, $piano->fresh(), $s['pulizia']->id, $s['padre']->id);

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe(B52M_MISTO)
        ->and($errori['destination_id'][0])->not->toContain('Sposta il budget sul capitolo')
        ->and($eCapitolo['destination_id'][0] ?? null)->toBe(B52M_MISTO)
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52mPivot($piano->fresh()))->toBe($pivot);
});

// ─── Il piano già colpito: né offerte né accettate, e l'uscita detta ────────────────────────────────────────────

it('nel piano già colpito il menu offre solo le voci che non si sovrappongono a nessuna riga', function () {
    $s = b52mPianoGiaColpito($this);

    expect(b52mPivot($s['piano']->fresh()))->toBe([$s['spesa']->id => 110000, $s['padre']->id => 10000, $s['ascensore']->id => 60000, $s['caldaia']->id => 40000])
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000) // la riga del capitolo vince: € 1.000,00 in meno dei € 2.200,00 che il piano chiede
        ->and(b52mDestinazioni($this, $s, $s['piano']->fresh()))->toBe(['Spese generali:spesa']);
});

it('nel piano già colpito verso un sottoconto che ha già la sua riga si rifiuta, dice perché e dice l\'uscita', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();
    $pivot = b52mPivot($piano);
    $quote = b52tQuoteDelMotore($piano);

    $errori = b52mSposta($this, $s, $piano, $s['spesa']->id, $s['ascensore']->id, '50.00');

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('«Ascensore» ha già la sua riga nel piano, ma anche il suo capitolo «Manutenzione» ha la sua: il piano conta solo quella del capitolo, e il budget spostato su «Ascensore» non verrebbe contato. Per uscirne '.B52M_USCITA)
        ->and(BudgetMovement::count())->toBe(1)
        ->and(b52mPivot($piano->fresh()))->toBe($pivot)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($quote);
});

it('nel piano già colpito verso il capitolo che ha già la sua riga e quelle dei sottoconti si rifiuta, nomina i sottoconti e dice l\'uscita', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();
    $pivot = b52mPivot($piano);

    $errori = b52mSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id, '50.00');

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» ha già la sua riga nel piano, ma anche i suoi sottoconti «Ascensore» e «Caldaia» hanno la loro: il piano conta solo quella del capitolo e non più quelle dei sottoconti, e finché restano così «Sposta spesa» non lavora su queste voci. Per uscirne '.B52M_USCITA)
        ->and(BudgetMovement::count())->toBe(1)
        ->and(b52mPivot($piano->fresh()))->toBe($pivot);
});

it('nel piano già colpito l\'uscita indicata funziona: lo storno passa, togliere il capitolo riporta il piano a € 2.200,00, e le voci tornano nel menu', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $piano, $s['vecchio']]));
    expect(session('errors')?->toArray())->toBeNull();
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano->fresh(), $s['padre']->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    expect(b52mPivot($piano->fresh()))->toBe([$s['spesa']->id => 120000, $s['ascensore']->id => 60000, $s['caldaia']->id => 40000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe(220000)
        ->and(b52mDisallineato($piano->fresh()))->toBeFalse()
        ->and(b52mDestinazioni($this, $s, $piano->fresh()))->toBe(['Ascensore:spesa', 'Caldaia:spesa', 'Spese generali:spesa'])
        ->and(b52mPagina($this, $s, $piano->fresh())['avviso_righe_sovrapposte'])->toBeNull();
});

it('nel piano già colpito con un solo sottoconto che ha la sua riga le frasi dicono il singolare', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['caldaia']->id)->delete();

    $errori = b52mSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id, '50.00');
    $avviso = b52mPagina($this, $s, $piano->fresh())['avviso_righe_sovrapposte'];

    expect($errori['destination_id'][0] ?? null)->toStartWith('«Manutenzione» ha già la sua riga nel piano, ma anche il suo sottoconto «Ascensore» ha la sua: il piano conta solo quella del capitolo e non più quella del sottoconto, ')
        ->and($avviso['voci'])->toBe(['Il capitolo «Manutenzione» ha la sua riga e anche il suo sottoconto «Ascensore» ha la sua: il piano conta solo quella del capitolo.']);
});

it('con due capitoli colpiti il riquadro li elenca tutti e due, con la loro uscita, nell\'ordine del piano', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();
    $impianti = b52tVoce($this, $s, 'Impianti', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $antenna = b52tVoce($this, $s, 'Antenna', 'spesa', '100,00', $s['tabella'], $impianti->id, ['isSottoConto' => true]);
    foreach ([$impianti->id => 5000, $antenna->id => 5000] as $contoId => $importo) {
        DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $contoId, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
    }

    $avviso = b52mPagina($this, $s, $piano->fresh())['avviso_righe_sovrapposte'];

    expect($avviso['titolo'])->toBe('Nel piano 2 capitoli hanno la loro riga insieme a quelle dei loro sottoconti')
        ->and($avviso['voci'])->toBe([
            'Il capitolo «Manutenzione» ha la sua riga e anche i suoi sottoconti «Ascensore» e «Caldaia» hanno la loro: il piano conta solo quella del capitolo.',
            'Il capitolo «Impianti» ha la sua riga e anche il suo sottoconto «Antenna» ha la sua: il piano conta solo quella del capitolo.',
        ])
        ->and($avviso['strade'])->toBe([ucfirst(B52M_USCITA), ucfirst(str_replace('«Manutenzione»', '«Impianti»', B52M_USCITA))]);
});

it('controprova — nel piano già colpito lo storno del movimento vecchio non passa dal controllo della destinazione', function () {
    $s = b52mPianoGiaColpito($this);
    $piano = $s['piano']->fresh();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $piano, $s['vecchio']]));

    expect(session('errors')?->toArray())->toBeNull()
        ->and(BudgetMovement::count())->toBe(2)
        ->and(b52mPivot($piano->fresh())[$s['padre']->id])->toBe(0);
});

it('la pagina del piano già colpito manda il riquadro con il capitolo, i sottoconti e l\'uscita', function () {
    $s = b52mPianoGiaColpito($this);

    $avviso = b52mPagina($this, $s, $s['piano']->fresh())['avviso_righe_sovrapposte'];

    expect($avviso)->toBe([
        'titolo' => 'Nel piano un capitolo ha la sua riga insieme a quelle dei suoi sottoconti',
        'voci' => ['Il capitolo «Manutenzione» ha la sua riga e anche i suoi sottoconti «Ascensore» e «Caldaia» hanno la loro: il piano conta solo quella del capitolo.'],
        'situazione' => 'La somma delle righe non è quella che il piano ripartisce: «Ricalcola» non lo corregge, e «Sposta spesa» non offre queste voci come destinazione.',
        'strade' => [ucfirst(B52M_USCITA)],
    ]);
});

// ─── La funzione che decide ─────────────────────────────────────────────────────────────────────────────────────

it('con l\'elenco dei figli a cui il motore passa la quota, una voce è sovrapposta solo lungo quella catena, a ogni livello', function () {
    // 1 contiene 2 e 3; 2 contiene 4; 4 contiene 5. Il motore passa la quota di 1 a 2 e 3 e quella di 2 a 4; 4 ha tabelle proprie, e non la passa a 5.
    $padri = [1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 4];
    $raggiunti = [1 => [2, 3], 2 => [4], 3 => [], 4 => [], 5 => []];

    expect(BudgetMovementService::sovrapposteAlPiano(5, $padri, [1, 2, 4], $raggiunti))->toBe(['capitoli' => [], 'sottoconti' => []]) // 4 non passa a 5: nessuno più in alto arriva a 5
        ->and(BudgetMovementService::sovrapposteAlPiano(4, $padri, [1, 2], $raggiunti))->toBe(['capitoli' => [2, 1], 'sottoconti' => []])
        ->and(BudgetMovementService::sovrapposteAlPiano(4, $padri, [1], $raggiunti))->toBe(['capitoli' => [1], 'sottoconti' => []]) // 2 non è nel piano ma passa la quota che 1 gli dà
        ->and(BudgetMovementService::sovrapposteAlPiano(1, $padri, [4, 5], $raggiunti))->toBe(['capitoli' => [], 'sottoconti' => [4]]) // 5 non è raggiunto
        ->and(BudgetMovementService::sovrapposteAlPiano(3, $padri, [1, 2], $raggiunti))->toBe(['capitoli' => [1], 'sottoconti' => []]);
});

it('un capitolo senza figli raggiunti non copre niente, e un elenco che non nomina una voce non la raggiunge da nessuno', function () {
    $padri = [1 => null, 2 => 1, 3 => 2];

    expect(BudgetMovementService::sovrapposteAlPiano(2, $padri, [1, 3], [1 => [], 2 => [3], 3 => []]))->toBe(['capitoli' => [], 'sottoconti' => [3]])
        ->and(BudgetMovementService::sovrapposteAlPiano(3, $padri, [1, 2], []))->toBe(['capitoli' => [], 'sottoconti' => []]);
});
