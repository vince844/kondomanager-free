<?php

/**
 * Il badge «Disallineato» con un capitolo che il motore salta: l'atteso legge il già versato sui sottoconti della riga di un capitolo senza tabelle solo
 * quando la copia del capitolo è positiva, come il motore (`CalcoloQuoteService::processaConti`, che con la copia a zero e nessun sottoconto nato prima del
 * piano salta il capitolo). Un capitolo senza sottoconti esiste quando nasce il piano «lascia vuoto», che lo scrive nella pivot con l'importo 0
 * (`PianoRateController::store`, «Padre senza figli»); dopo, l'amministratore gli aggiunge due sottoconti e registra un già versato.
 *
 * Cosa resta scoperto: la riga di un capitolo con l'importo nullo (nessun percorso di oggi la scrive) e un capitolo dentro un capitolo.
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

function b52CvRiga($piano): array
{
    $svc = app(PianoRateQuoteService::class);

    return ['generato' => $svc->totalePuroGeneratoCents($piano->fresh()), 'atteso' => $svc->totaleAttesoCents($piano->fresh()), 'disallineato' => $svc->eDisallineato($piano->fresh()),
        'netting' => (int) DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'netting')->sum('importo')];
}

function b52CvRicalcola($test, array $s, $piano): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano->fresh()]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    return session('message');
}

function b52CvScenario($test): array
{
    $s = b52tCondominio();
    $e = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    // Le voci dal modale della pagina.
    $s['pulizia'] = b52tVoce($test, $s, 'Pulizia', 'spesa', '1.200,00', $s['tabella']);
    $s['padre'] = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    DB::table('conti')->where('piano_conto_id', $pc->id)->update(['created_at' => now()->subDay()]);

    return $s;
}

it('un capitolo vuoto nel piano «lascia vuoto», con due sottoconti aggiunti dopo e € 200,00 già versati su «Ascensore»: il badge resta spento dopo «Ricalcola»', function () {
    $s = b52CvScenario($this);
    $piano = b52tPiano($this, $s);
    $pivot = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->pluck('importo', 'conto_id')->all();

    $this->travel(5)->minutes();
    $asc = b52tVoce($this, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true, 'richiedeGiaVersato' => true]);
    b52tVoce($this, $s, 'Caldaia', 'spesa', '400,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
        b52tGiaVersato($this, $s, $asc->fresh(), 'A', 20000);
        $msg = b52CvRicalcola($this, $s, $piano);
    $dopo = b52CvRiga($piano);

    // Il piano chiede la sola Pulizia (€ 1.200,00): il capitolo ha la copia a zero e nessun sottoconto nato fino al piano, il motore lo salta
    expect($pivot[$s['padre']->id] ?? 'assente')->toBe(0);
    expect($dopo['generato'])->toBe(120000);
    expect($dopo['disallineato'])->toBeFalse();
});

it('controprova — lo stesso piano senza già versato: il badge è spento', function () {
    $s = b52CvScenario($this);
    $piano = b52tPiano($this, $s);
    $this->travel(5)->minutes();
    b52tVoce($this, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true, 'richiedeGiaVersato' => true]);
    b52tVoce($this, $s, 'Caldaia', 'spesa', '400,00', $s['tabella'], $s['padre']->id, ['isSottoConto' => true]);
    $msg = b52CvRicalcola($this, $s, $piano);
    $dopo = b52CvRiga($piano);

    expect($dopo)->toBe(['generato' => 120000, 'atteso' => 120000, 'disallineato' => false, 'netting' => 0]);
});

it('la riga del capitolo a zero scritta dalla migrazione della 1.9, con i sottoconti nati prima e € 200,00 già versati su «Ascensore»: il badge resta spento', function () {
    $s = b52CvScenario($this);
    $mk = function (string $nome, int $importo) use ($s) {
        $c = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $s['padre']->id, 'nome' => $nome, 'tipo' => 'spesa', 'importo' => $importo, 'richiede_gia_versato' => true]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $c->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conti')->where('id', $c->id)->update(['created_at' => now()->subDay()]);

        return $c->fresh();
    };
    $asc = $mk('Ascensore', 60000);
    $mk('Caldaia', 40000);
    $piano = b52tPiano($this, $s);
    // La riga del capitolo a 0, come la scrive la migrazione della 1.9, al posto delle foglie.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', '!=', $s['pulizia']->id)->delete();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $s['padre']->id, 'importo' => 0, 'note' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    b52tGiaVersato($this, $s, $asc, 'A', 20000);
    $msg = b52CvRicalcola($this, $s, $piano);
    $dopo = b52CvRiga($piano);

    expect($dopo['generato'])->toBe(120000);
    expect($dopo['disallineato'])->toBeFalse();
});
