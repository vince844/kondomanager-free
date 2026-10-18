<?php

/**
 * «Sposta spesa» confronta le voci col segno con cui il motore le distribuisce, e non sovrappone una voce a una già nel piano.
 *
 * Il totale del piano è la somma delle voci col segno con cui il motore le distribuisce (`Conto::segno()`, `PianoRateQuoteService::totaliPerSegnoCents`):
 * un capitolo di tipo «Spesa» (il tipo di default) con i sottoconti di entrata conta come un'entrata, e spostare € 100,00 da una spesa a lui porta il
 * piano di € 1.200,00 a € 1.000,00 con «Piano rate ricalcolato con successo!». Un capitolo con sottoconti di spesa e di entrata il motore lo divide fra le
 * due, e il suo segno è il tipo del capitolo: il solo confronto fra segni lo lascerebbe passare (piano da € 1.400,00 a € 1.320,00). Il server rifiuta
 * il capitolo misto e confronta il segno, sul campo della destinazione; la pagina manda a sorgenti e destinazioni il tipo calcolato dal segno
 * («spesa», «entrata» o «misto»). Lo storno resta fuori dal controllo: restituisce ciò che è già stato spostato.
 *
 * Una riga nuova nella pivot per un capitolo i cui sottoconti sono già nel piano sovrascrive le righe dei sottoconti (la quota del capitolo vince nel
 * motore): il piano perde € 1.000,00 su € 2.200,00 e «Ricalcola» risponde «con successo» senza cambiare niente. Lo stesso, a rovescio, per un sottoconto il
 * cui capitolo è già nel piano: il budget spostato non si conta. Il server rifiuta quella destinazione e la pagina non la offre; per i piani in cui il
 * movimento esiste già l'uscita è lo storno e poi «Rimuovi voce» sul capitolo.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (le cifre sono scritte accanto) e la lettura del motore (`CalcoloQuoteService::processaConti`: la
 * quota di un capitolo senza tabelle passa ai sottoconti e li segna come già processati).
 *
 * Quando una voce è sovrapposta a un capitolo del piano o a dei sottoconti del piano lo decide la regola del motore (i sottoconti nati dopo il piano, un
 * capitolo con tabelle proprie), e i piani già colpiti hanno il loro rifiuto e il loro riquadro: `Beta52SpostaSpesaRegolaDelMotoreTest.php`.
 *
 * Cosa resta scoperto: il piano straordinario (le sorgenti sono fatture e non hanno il tipo); la schermata (`ModalSpostaSpesaTipo.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BudgetMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B52S_FRASE_FINALE = 'il budget si sposta solo fra voci dello stesso tipo, altrimenti il totale del piano cambierebbe. Scegli una voce dello stesso tipo.';
const B52S_FRASE_MISTO = 'ha sottoconti di spesa e di entrata: il piano ne divide l\'importo fra le due, e spostare budget da o verso un capitolo così cambierebbe il totale del piano. Scegli una voce che sia solo una spesa o solo un\'entrata.';

function b52sSposta($test, array $s, $piano, int $da, int $a, string $euro = '100.00')
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), ['source_id' => $da, 'destination_id' => $a, 'amount' => $euro, 'reason' => 'Prova']);
}

function b52sRicalcola($test, array $s, $piano): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
}

function b52sPivot($piano): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('conto_id')->pluck('importo', 'conto_id')->map(fn ($v) => (int) $v)->all();
}

/** Le props della pagina del piano. */
function b52sPagina($test, array $s, $piano): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]))->assertOk()->viewData('page')['props'];
}

/** Il nome e il tipo di ogni voce di un elenco della pagina, in ordine di nome. */
function b52sTipi(array $voci): array
{
    return collect($voci)->mapWithKeys(fn ($v) => [$v['nome'] => $v['tipo'] ?? '(manca)'])->sortKeys()->all();
}

/** «Spese generali» da € 1.200,00 nel piano e un capitolo «Manutenzione» con Ascensore (€ 600,00) e Caldaia (€ 400,00), nati prima del piano: il piano li prende come righe. */
function b52sScenarioCapitoloConFigliNelPiano($test): array
{
    $s = b52tScenario($test, entrata: '0,00');
    $s['entrata']->delete();
    $s['padre'] = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $s['ascensore'] = b52tVoce($test, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
    $s['caldaia'] = b52tVoce($test, $s, 'Caldaia', 'spesa', '400,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
    $s['piano'] = b52tPiano($test, $s);

    return $s;
}

// ─── Il segno del capitolo ──────────────────────────────────────────────────────────────────────────────────────

it('da una spesa a un capitolo di tipo spesa con i sottoconti di entrata si rifiuta e il piano resta com\'era', function () {
    $s = b52tScenarioConPadre($this, 'entrata');
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    $pivot = b52sPivot($piano);
    expect($prima)->toBe(20000); // 1.200,00 di Pulizia meno 1.000,00 di entrate del capitolo

    b52sSposta($this, $s, $piano, $s['pulizia']->id, $s['padre']->id);
    $errori = session('errors')?->toArray() ?? [];
    b52sRicalcola($this, $s, $piano->fresh());

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('«Pulizia» è una spesa e «Manutenzione» è un capitolo i cui sottoconti sono entrate e nel piano conta come un\'entrata: '.B52S_FRASE_FINALE)
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52sPivot($piano->fresh()))->toBe($pivot)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('da un capitolo di tipo spesa con i sottoconti di entrata a una spesa si rifiuta con la stessa frase, al rovescio', function () {
    $s = b52tScenarioConPadre($this, 'entrata');
    $piano = $s['piano'];

    b52sSposta($this, $s, $piano, $s['padre']->id, $s['pulizia']->id);
    $errori = session('errors')?->toArray() ?? [];

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» è un capitolo i cui sottoconti sono entrate e nel piano conta come un\'entrata e «Pulizia» è una spesa: '.B52S_FRASE_FINALE)
        ->and(BudgetMovement::count())->toBe(0);
});

it('dalla pagina: il capitolo «Proventi» nato dopo il piano (tipo spesa, il default) con un sottoconto di entrata arriva come entrata e lo spostamento si rifiuta', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $s['entrata']->delete();
    $piano = b52tPiano($this, $s);
    $prima = b52tQuoteDelMotore($piano);
    expect($prima)->toBe(120000);
    sleep(1);
    $padre = b52tVoce($this, $s, 'Proventi', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($this, $s, 'Affitto antenna', 'entrata', '240,00', $s['box'], $padre->id, ['isSottoConto' => true]);

    $props = b52sPagina($this, $s, $piano);
    expect(b52sTipi($props['sources']))->toBe(['Spese generali' => 'spesa'])
        ->and(b52sTipi($props['destinations']))->toBe(['Affitto antenna' => 'entrata', 'Proventi' => 'entrata', 'Spese generali' => 'spesa']);

    b52sSposta($this, $s, $piano, $s['spesa']->id, $padre->id);
    $errori = session('errors')?->toArray() ?? [];
    b52sRicalcola($this, $s, $piano->fresh());

    expect($errori['destination_id'][0] ?? null)->toBe('«Spese generali» è una spesa e «Proventi» è un capitolo i cui sottoconti sono entrate e nel piano conta come un\'entrata: '.B52S_FRASE_FINALE)
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('un capitolo con un sottoconto di spesa e uno di entrata si rifiuta come destinazione: il segno del capitolo è il suo tipo e il confronto fra segni lo lascerebbe passare', function () {
    $s = b52tScenarioConPadreMisto($this);
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    $pivot = b52sPivot($piano);
    expect($s['padre']->fresh()->segno($piano->created_at))->toBe(1)
        ->and($prima)->toBe(140000); // Pulizia 1.200,00 + Manutenzione (spesa 600,00 − entrata 400,00 come il motore le divide)

    b52sSposta($this, $s, $piano, $s['pulizia']->id, $s['padre']->id);
    $errori = session('errors')?->toArray() ?? [];
    b52sRicalcola($this, $s, $piano->fresh());

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('«Manutenzione» '.B52S_FRASE_MISTO)
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52sPivot($piano->fresh()))->toBe($pivot)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('un capitolo con sottoconti di spesa e di entrata si rifiuta anche come sorgente', function () {
    $s = b52tScenarioConPadreMisto($this);
    $piano = $s['piano'];

    b52sSposta($this, $s, $piano, $s['padre']->id, $s['pulizia']->id, '50.00');
    $errori = session('errors')?->toArray() ?? [];

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» '.B52S_FRASE_MISTO)
        ->and(BudgetMovement::count())->toBe(0);
});

it('due capitoli misti: la frase li nomina tutti e due', function () {
    $s = b52tScenarioConPadreMisto($this);
    $piano = $s['piano'];
    $altro = b52tVoce($this, $s, 'Antenna', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($this, $s, 'Manutenzione antenna', 'spesa', '100,00', $s['tabella'], $altro->id, ['isSottoConto' => true]);
    b52tVoce($this, $s, 'Affitto antenna', 'entrata', '500,00', $s['box'] ?? $s['tabella'], $altro->id, ['isSottoConto' => true]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $altro->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);

    b52sSposta($this, $s, $piano, $s['padre']->id, $altro->id, '50.00');
    $errori = session('errors')?->toArray() ?? [];

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» e «Antenna» hanno sottoconti di spesa e di entrata: il piano ne divide l\'importo fra le due, e spostare budget da o verso capitoli così cambierebbe il totale del piano. Scegli voci che siano solo spese o solo entrate.')
        ->and(BudgetMovement::count())->toBe(0);
});

it('la pagina manda il tipo calcolato dal segno, e «misto» per un capitolo con spese ed entrate', function () {
    $s = b52tScenarioConPadreMisto($this);

    $props = b52sPagina($this, $s, $s['piano']);

    expect(b52sTipi($props['sources']))->toBe(['Manutenzione' => 'misto', 'Pulizia' => 'spesa'])
        ->and(b52sTipi($props['destinations']))->toBe(['Manutenzione' => 'misto', 'Pulizia' => 'spesa']);
});

it('la pagina manda come entrata un capitolo di tipo spesa con i sottoconti di entrata che è nel piano', function () {
    $s = b52tScenarioConPadre($this, 'entrata');

    $props = b52sPagina($this, $s, $s['piano']);

    expect(b52sTipi($props['sources']))->toBe(['Manutenzione' => 'entrata', 'Pulizia' => 'spesa']);
});

it('controprova — lo storno di un movimento verso un capitolo di entrata già scritto resta possibile', function () {
    $s = b52tScenarioConPadre($this, 'entrata');
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    // Il dato vecchio, scritto come lo scriveva la pagina prima del controllo del segno.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['pulizia']->id)->update(['importo' => 110000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['padre']->id)->update(['importo' => 110000]);
    $movimento = BudgetMovement::create(['piano_rate_id' => $piano->id, 'source_conto_id' => $s['pulizia']->id, 'destination_conto_id' => $s['padre']->id, 'user_id' => $this->user->id,
        'amount' => 10000, 'source_old_amount' => 120000, 'destination_old_amount' => 100000, 'reason' => 'vecchio', 'type' => 'reallocation']);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $piano->fresh(), $movimento]));
    $errori = session('errors')?->toArray();
    b52sRicalcola($this, $s, $piano->fresh());

    expect($errori)->toBeNull()
        ->and(BudgetMovement::count())->toBe(2)
        ->and(b52sPivot($piano->fresh()))->toBe([$s['pulizia']->id => 120000, $s['padre']->id => 100000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('controprova — da una spesa a un capitolo di spesa con i sottoconti di spesa, già nel piano, lo spostamento passa e il piano non cambia', function () {
    $s = b52tScenarioConPadre($this, 'spesa');
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    expect($prima)->toBe(220000);

    b52sSposta($this, $s, $piano, $s['pulizia']->id, $s['padre']->id);
    b52sRicalcola($this, $s, $piano->fresh());

    expect(session('errors')?->toArray() ?? [])->toBe([])
        ->and(BudgetMovement::count())->toBe(1)
        ->and(b52sPivot($piano->fresh()))->toBe([$s['pulizia']->id => 110000, $s['padre']->id => 110000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe(220000);
});

it('il servizio rifiuta il capitolo di entrata come destinazione di una spesa, sul campo destination_id', function () {
    $s = b52tScenarioConPadre($this, 'entrata');
    $piano = $s['piano'];
    $servizio = app(BudgetMovementService::class);

    $rifiutato = null;
    try {
        $servizio->moveBudget($piano, $s['pulizia']->id, $s['padre']->id, 5000, 'Prova', $this->user->id);
    } catch (ValidationException $e) {
        $rifiutato = array_keys($e->errors());
    }

    expect($rifiutato)->toBe(['destination_id']);
});

// ─── Una voce già nel piano per un padre o per un figlio ────────────────────────────────────────────────────────

it('verso un capitolo i cui sottoconti sono già nel piano si rifiuta, nomina i sottoconti e dice dove spostare', function () {
    $s = b52sScenarioCapitoloConFigliNelPiano($this);
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    $pivot = b52sPivot($piano);
    expect($prima)->toBe(220000)
        ->and($pivot)->toBe([$s['spesa']->id => 120000, $s['ascensore']->id => 60000, $s['caldaia']->id => 40000]);

    b52sSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id);
    $errori = session('errors')?->toArray() ?? [];
    b52sRicalcola($this, $s, $piano->fresh());

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('«Manutenzione» non è nel piano, ma lo sono i suoi sottoconti «Ascensore» e «Caldaia»: con una riga anche per il capitolo il piano conterebbe solo quella e non più le righe dei sottoconti. Sposta il budget su uno dei sottoconti.')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52sPivot($piano->fresh()))->toBe($pivot)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('verso un sottoconto il cui capitolo è già nel piano si rifiuta, nomina il capitolo e dice dove spostare', function () {
    $s = b52tScenarioConPadre($this, 'spesa');
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    $pivot = b52sPivot($piano);

    b52sSposta($this, $s, $piano, $s['pulizia']->id, $s['figlio1']->id);
    $errori = session('errors')?->toArray() ?? [];
    b52sRicalcola($this, $s, $piano->fresh());

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('«Ascensore» non è nel piano, ma lo è il suo capitolo «Manutenzione»: con una riga anche per il sottoconto il piano conterebbe solo quella del capitolo e il budget spostato non verrebbe contato. Sposta il budget sul capitolo «Manutenzione».')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(b52sPivot($piano->fresh()))->toBe($pivot)
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('un capitolo con più di tre sottoconti nel piano ne nomina tre e dice che ce n\'è un altro', function () {
    $s = b52sScenarioCapitoloConFigliNelPiano($this);
    $piano = $s['piano'];
    foreach (['Balconi', 'Cancello'] as $nome) {
        $voce = b52tVoce($this, $s, $nome, 'spesa', '100,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
        DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $voce->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    }

    b52sSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id);
    $errori = session('errors')?->toArray() ?? [];

    expect($errori['destination_id'][0] ?? null)->toStartWith('«Manutenzione» non è nel piano, ma lo sono i suoi sottoconti «Ascensore», «Balconi», «Caldaia» e un altro: ')
        ->and(BudgetMovement::count())->toBe(0);
});

it('con un solo sottoconto nel piano la frase lo dice al singolare', function () {
    $s = b52sScenarioCapitoloConFigliNelPiano($this);
    $piano = $s['piano'];
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['caldaia']->id)->delete();

    b52sSposta($this, $s, $piano, $s['spesa']->id, $s['padre']->id);
    $errori = session('errors')?->toArray() ?? [];

    expect($errori['destination_id'][0] ?? null)->toBe('«Manutenzione» non è nel piano, ma lo è il suo sottoconto «Ascensore»: con una riga anche per il capitolo il piano conterebbe solo quella e non più la riga del sottoconto. Sposta il budget sul sottoconto.')
        ->and(BudgetMovement::count())->toBe(0);
});

it('la pagina non offre come destinazione un capitolo i cui sottoconti sono nel piano, né un sottoconto il cui capitolo c\'è già', function () {
    $s = b52sScenarioCapitoloConFigliNelPiano($this);
    expect(array_keys(b52sTipi(b52sPagina($this, $s, $s['piano'])['destinations'])))->toBe(['Ascensore', 'Caldaia', 'Spese generali']);

    $t = b52tScenarioConPadre($this, 'spesa');
    expect(array_keys(b52sTipi(b52sPagina($this, $t, $t['piano'])['destinations'])))->toBe(['Manutenzione', 'Pulizia']);
});

it('uscita per un piano che ha già il movimento: lo storno passa e togliere il capitolo riporta il piano a € 2.200,00', function () {
    $s = b52sScenarioCapitoloConFigliNelPiano($this);
    $piano = $s['piano'];
    $prima = b52tQuoteDelMotore($piano);
    // Il dato vecchio: la riga del capitolo che la pagina scriveva prima del controllo.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['spesa']->id)->update(['importo' => 110000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $s['padre']->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    $movimento = BudgetMovement::create(['piano_rate_id' => $piano->id, 'source_conto_id' => $s['spesa']->id, 'destination_conto_id' => $s['padre']->id, 'user_id' => $this->user->id,
        'amount' => 10000, 'source_old_amount' => 120000, 'destination_old_amount' => 0, 'reason' => 'vecchio', 'type' => 'reallocation']);
    b52sRicalcola($this, $s, $piano->fresh());
    expect(b52tQuoteDelMotore($piano->fresh()))->toBe(120000); // la riga del capitolo ha sovrascritto i sottoconti: € 1.000,00 in meno

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $piano->fresh(), $movimento]));
    expect(session('errors')?->toArray())->toBeNull();
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano->fresh(), $s['padre']->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    expect(b52sPivot($piano->fresh()))->toBe([$s['spesa']->id => 120000, $s['ascensore']->id => 60000, $s['caldaia']->id => 40000])
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe($prima);
});

it('controprova — fra due voci del piano senza capitoli lo spostamento passa e crea la riga della destinazione', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $s['entrata']->delete();
    $piano = b52tPiano($this, $s);
    sleep(1);
    $pulizie = b52tVoce($this, $s, 'Pulizie', 'spesa', '600,00', $s['tabella']); // nata dopo il piano: nella pivot non c'è, e non ha padre né figli

    b52sSposta($this, $s, $piano, $s['spesa']->id, $pulizie->id);

    expect(session('errors')?->toArray() ?? [])->toBe([])
        ->and(b52sPivot($piano->fresh()))->toBe([$s['spesa']->id => 110000, $pulizie->id => 10000])
        ->and(array_keys(b52sTipi(b52sPagina($this, $s, $piano->fresh())['destinations'])))->toBe(['Pulizie', 'Spese generali']);
});

// ─── Le funzioni che decidono ───────────────────────────────────────────────────────────────────────────────────

it('le voci che una riga nuova sovrapporrebbe: i capitoli che la contengono, dal più vicino, e i sottoconti a ogni livello', function () {
    // 1 contiene 2 e 3; 2 contiene 4; 4 contiene 5; 6 non c'entra
    $padri = [1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 4, 6 => null];

    expect(BudgetMovementService::sovrapposteAlPiano(5, $padri, [1, 2, 6]))->toBe(['capitoli' => [2, 1], 'sottoconti' => []])
        ->and(BudgetMovementService::sovrapposteAlPiano(1, $padri, [4, 5, 6]))->toBe(['capitoli' => [], 'sottoconti' => [4, 5]])
        ->and(BudgetMovementService::sovrapposteAlPiano(2, $padri, [1, 3, 5]))->toBe(['capitoli' => [1], 'sottoconti' => [5]])
        ->and(BudgetMovementService::sovrapposteAlPiano(6, $padri, [1, 2, 3, 4, 5]))->toBe(['capitoli' => [], 'sottoconti' => []])
        ->and(BudgetMovementService::sovrapposteAlPiano(3, $padri, []))->toBe(['capitoli' => [], 'sottoconti' => []]);
});

it('un giro nei padri non fa girare la ricerca', function () {
    $padri = [1 => 2, 2 => 1];

    expect(BudgetMovementService::sovrapposteAlPiano(1, $padri, [2]))->toBe(['capitoli' => [2], 'sottoconti' => [2]]);
});

it('un capitolo è misto se i suoi sottoconti, senza tabelle proprie, non hanno tutti lo stesso segno, a ogni livello', function () {
    $s = b52tScenarioConPadreMisto($this);
    $alla = $s['piano']->created_at;
    $carica = fn (int $id) => Conto::query()->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali'])->findOrFail($id);

    expect($carica($s['pulizia']->id)->eMisto($alla))->toBeFalse() // una foglia
        ->and($carica($s['padre']->id)->eMisto($alla))->toBeTrue(); // spesa e entrata

    $s['figlio2']->update(['tipo' => 'spesa']);
    expect($carica($s['padre']->id)->eMisto($alla))->toBeFalse(); // tutti e due spesa

    // un sottocapitolo misto dentro un capitolo i cui altri sottoconti sono di spesa
    $capitolo = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => null, 'nome' => 'Esterni', 'tipo' => 'spesa', 'importo' => 0]);
    $s['padre']->update(['parent_id' => $capitolo->id]);
    $s['figlio2']->update(['tipo' => 'entrata']);
    Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $capitolo->id, 'nome' => 'Giardino', 'tipo' => 'spesa', 'importo' => 5000]);
    expect($carica($capitolo->id)->eMisto())->toBeTrue();

    // le tabelle proprie del capitolo valgono più dei sottoconti, come per il segno
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['padre']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    expect($carica($s['padre']->id)->eMisto($alla))->toBeFalse();
});
