<?php

/**
 * Il badge «Disallineato» di un piano con la riga di un capitolo senza tabelle e del già versato su un suo sottoconto.
 *
 * Il già versato sta sul sottoconto (la pagina «Già versato» lo scrive sulla voce con le tabelle), e il motore (`CalcoloQuoteService::processaConti`) ne toglie
 * dalle quote dei sottoconti che guarda sotto la riga del capitolo con la copia positiva: quelli nati fino alla creazione del piano o, se nessuno lo è, tutti quelli
 * di adesso (con la copia a zero salta il capitolo: `Beta52TotaliCapitoloVuotoTest`). L'atteso
 * di `PianoRateQuoteService::totaleAttesoCents` leggeva il già versato solo sulle voci della pivot, cioè sul capitolo: il piano giusto risultava «Disallineato»
 * e «Ricalcola» non lo spegneva. Le righe di capitolo le scrivono «Sincronizza» di un capitolo e la migrazione della 1.9, quindi i piani vecchi.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (Pulizia € 1.200,00, il capitolo «Manutenzione» con «Ascensore» € 600,00 e «Caldaia» € 400,00, € 200,00 già
 * versati da Ugo su «Ascensore») e il netting che il motore registra in `righe_riparto`, che qui non cambia.
 *
 * Cosa resta scoperto: la riga del capitolo diversa dalla somma dei sottoconti (la quota della chiamata non si può dire: si toglie tutto il già versato); la schermata del badge.
 *
 * I test che cominciano con «controprova» proteggono ciò che la lettura non deve toccare: il già versato di un sottoconto che il motore non guarda non si toglie.
 */

use App\Models\Gestionale\Conto;
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

/** Le tre misure che il badge confronta (le quote generate, l'atteso, il verdetto) e il netting che il motore ha registrato sul piano. */
function be52GvRiga($piano): array
{
    $svc = app(PianoRateQuoteService::class);

    return [
        'generato' => $svc->totalePuroGeneratoCents($piano->fresh()),
        'atteso' => $svc->totaleAttesoCents($piano->fresh()),
        'disallineato' => $svc->eDisallineato($piano->fresh()),
        'netting' => (int) DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'netting')->sum('importo'),
    ];
}

function be52GvRicalcola($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
}

/** Un sottoconto con le tabelle, nato prima della creazione del piano o dopo: `$s['piano']` esiste già. */
function be52GvFiglio($test, array $s, string $nome, int $padre, int $importo): Conto
{
    $voce = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $padre, 'nome' => $nome, 'tipo' => 'spesa', 'importo' => $importo, 'richiede_gia_versato' => true]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    return $voce;
}

it('la riga del capitolo nel piano e € 200,00 già versati su «Ascensore»: dopo «Ricalcola» il piano è giusto e il badge è spento', function () {
    $s = b52tScenarioConPadre($this);
    DB::table('conti')->where('id', $s['figlio1']->id)->update(['richiede_gia_versato' => true]);
    b52tGiaVersato($this, $s, $s['figlio1']->fresh(), 'A', 20000);

    be52GvRicalcola($this, $s);

    // a mano: € 1.200,00 + € 1.000,00 − € 200,00 già versati = € 2.000,00, e il motore ne ha registrato il netting
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['padre']->id)->value('importo'))->toBe(100000)
        ->and(be52GvRiga($s['piano']))->toBe(['generato' => 200000, 'atteso' => 200000, 'disallineato' => false, 'netting' => -20000]);
});

it('il già versato è sul sottoconto di un capitolo con un altro livello in mezzo: si toglie anche lì', function () {
    $s = b52tScenarioConPadreLegacy($this);
    $impianti = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $s['padre']->id, 'nome' => 'Impianti', 'tipo' => 'spesa', 'importo' => 0]);
    DB::table('conti')->where('id', $impianti->id)->update(['created_at' => now()->subDay()]);
    DB::table('conti')->whereIn('id', [$s['figlio1']->id, $s['figlio2']->id])->update(['parent_id' => $impianti->id]);
    DB::table('conti')->where('id', $s['figlio1']->id)->update(['richiede_gia_versato' => true]);
    b52tGiaVersato($this, $s, $s['figlio1']->fresh(), 'A', 20000);

    be52GvRicalcola($this, $s);

    expect(be52GvRiga($s['piano']))->toBe(['generato' => 200000, 'atteso' => 200000, 'disallineato' => false, 'netting' => -20000]);
});

it('controprova — il già versato di un sottoconto nato dopo il piano, quando un altro è nato prima, il motore non lo guarda e l\'atteso non lo toglie', function () {
    $s = b52tScenarioConPadreLegacy($this);
    DB::table('conti')->where('id', $s['figlio1']->id)->update(['richiede_gia_versato' => true]);
    b52tGiaVersato($this, $s, $s['figlio1']->fresh(), 'A', 20000);
    sleep(1);
    $portineria = be52GvFiglio($this, $s, 'Portineria', $s['padre']->id, 30000);
    b52tGiaVersato($this, $s, $portineria, 'A', 10000);

    be52GvRicalcola($this, $s);

    // a mano: il piano chiede € 1.200,00 + € 1.000,00 (la copia del capitolo) − € 200,00 già versati su «Ascensore»; «Portineria» è nata dopo il piano e non entra
    expect(be52GvRiga($s['piano']))->toBe(['generato' => 200000, 'atteso' => 200000, 'disallineato' => false, 'netting' => -20000]);
});

it('controprova — senza già versato la riga del capitolo resta allineata', function () {
    $s = b52tScenarioConPadre($this);

    be52GvRicalcola($this, $s);

    expect(be52GvRiga($s['piano']))->toBe(['generato' => 220000, 'atteso' => 220000, 'disallineato' => false, 'netting' => 0]);
});
