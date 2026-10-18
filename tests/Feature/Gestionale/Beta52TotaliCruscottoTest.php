<?php

/**
 * Cosa resta scoperto: la pagina `Dashboard.vue` montata (la sua etichetta e le sue percentuali hanno il vitest `DashboardBeta52.test.ts`); gli
 * scoperti per voce, che restano sul valore assoluto (gli scoperti di un'entrata detti insieme a quelli di spesa sono della 1.11.1); lo stato
 * della copertura (`aligned`, `deficit`, `surplus`), che si decide nella pagina.
 *
 * Il cruscotto, il riquadro «da ripartire» e `BudgetCoverageService` con le voci di entrata.
 *
 * Il cruscotto legge due cose: i piani disallineati (`PianoRateQuoteService`, che carica i capitoli con una `select` propria) e la
 * copertura (`BudgetCoverageService::analyze`, i totali per gestione). Sulle voci di entrata sommano tutte e due come spese.
 *
 * Tre chiavi nuove nella copertura invece del vecchio `preventivo`, che con un'entrata dice un numero che il piano non chiede:
 * `spese` (il fabbisogno delle voci di spesa), `entrate` (quello delle voci di entrata, positivo) e `da_ripartire` (spese meno entrate).
 *
 * La `select` del cruscotto porta `conti.tipo`: senza, il segno di ogni voce sarebbe nullo e ogni piano risulterebbe disallineato.
 *
 * Un piano a voci scelte che lascia fuori una voce di entrata chiede alle persone più del fabbisogno netto: la copertura lo dice con i
 * totali col segno (spese € 1.200,00, entrate € 240,00, da ripartire € 960,00, pianificato € 1.200,00, delta −€ 240,00, 125 %). L'elenco
 * delle voci non coperte (`orfani`) nomina ancora l'entrata, con il valore assoluto: è il limite dichiarato, e il test lo fissa com'è.
 *
 * Lo sforo si confronta con il preventivo al netto delle entrate: una fattura di € 1.100,00 su una spesa di € 1.000,00 con un'entrata di
 * € 240,00 in piano dà un fabbisogno di € 860,00 contro un preventivo di € 760,00 (T1.6g). Sommare le entrate al preventivo invece di sottrarle
 * (€ 1.240,00) nasconderebbe lo sforo.
 *
 * I test che cominciano con «controprova» o che dicono «resta com'è» sono verdi prima e dopo la correzione.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta52TotaliScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

function b52tCruscotto($test, array $s): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.index', $s['c']->id));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

it('T1.6 il cruscotto di un piano giusto con un\'entrata: nessun piano disallineato, da ripartire 96000', function () {
    $s = b52tScenario($this);
    b52tPiano($this, $s);

    $p = b52tCruscotto($this, $s);

    expect($p['pianiDisallineati'])->toBe([]);
    expect(collect($p['copertura'])->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all())->toEqual([
        'spese' => 120000, 'entrate' => 24000, 'da_ripartire' => 96000, 'pianificato' => 96000, 'delta' => 0, 'percentuale' => 100,
    ]);
    expect($p['copertura'])->not->toHaveKey('preventivo'); // la chiave vecchia non resta con un altro significato
});

it('T1.6b il cruscotto di un piano di sole spese: gli stessi numeri di prima, con le chiavi nuove', function () {
    $s = b52tScenario($this, entrata: '0,00');
    b52tPiano($this, $s);

    $p = b52tCruscotto($this, $s);

    expect($p['pianiDisallineati'])->toBe([]);
    expect(collect($p['copertura'])->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta'])->all())->toEqual([
        'spese' => 120000, 'entrate' => 0, 'da_ripartire' => 120000, 'pianificato' => 120000, 'delta' => 0,
    ]);
});

it('T1.6c un disallineamento vero con un\'entrata resta nel cruscotto, con lo scarto vero (48000, non maggiorato)', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    expect(b52tCruscotto($this, $s)['pianiDisallineati'])->toBe([]);

    // cambio di tipo della voce già nel piano: lo scarto vero è il doppio dell'importo (da −24000 a +24000)
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['entrata']]), [
        'nome' => 'Proventi antenna', 'codice' => '', 'tipo' => 'spesa', 'importo' => '240,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    $disallineati = b52tCruscotto($this, $s)['pianiDisallineati'];
    expect(collect($disallineati)->map(fn ($d) => [$d['id'], $d['delta']])->all())->toBe([[$piano->id, 48000]]);
});

it('T1.6d la select del cruscotto porta il tipo: senza, il segno non si sa e il servizio lo dice invece di tirare a indovinare', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);

    // come li carica `DashboardController` prima della correzione: i capitoli senza `conti.tipo`
    $caricato = PianoRate::where('id', $piano->id)->with(['capitoli' => fn ($q) => $q->select('conti.id', 'conti.importo'), 'rate.rateQuote'])->first();
    expect($caricato->capitoli->every(fn ($c) => $c->tipo === null))->toBeTrue();

    // con il tipo nullo, ogni voce risulterebbe un'entrata e ogni piano del cruscotto disallineato: meglio un errore chiaro
    // (`BadMethodCallException` è un `LogicException`: un metodo che non esiste non vale come rifiuto)
    $errore = null;
    try {
        app(PianoRateQuoteService::class)->totaleAttesoCents($caricato);
    } catch (Throwable $e) {
        $errore = $e;
    }
    expect($errore)->toBeInstanceOf(LogicException::class)->not->toBeInstanceOf(BadMethodCallException::class);
});

it('T1.6e un piano a voci scelte che lascia fuori l\'entrata: la copertura dice spese 1.200,00, entrate 240,00, da ripartire 960,00 e il piano che ne chiede 1.200,00 (delta −240,00)', function () {
    $s = b52tScenario($this);
    b52tPiano($this, $s, ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '1.200,00', 'note' => '']]]);

    $c = b52tCruscotto($this, $s)['copertura'];

    expect(collect($c)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all())->toEqual([
        'spese' => 120000, 'entrate' => 24000, 'da_ripartire' => 96000, 'pianificato' => 120000, 'delta' => -24000, 'percentuale' => 125,
    ]);
    expect($c)->not->toHaveKey('preventivo');
});

it('T1.6f limite dichiarato, resta com\'è: con l\'entrata fuori dal piano l\'elenco delle voci non coperte la nomina ancora, con il valore assoluto', function () {
    $s = b52tScenario($this);
    b52tPiano($this, $s, ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '1.200,00', 'note' => '']]]);

    $c = b52tCruscotto($this, $s)['copertura'];

    expect(collect($c['orfani'])->map(fn ($o) => [$o['nome'], $o['importo']])->all())->toBe([['Proventi antenna', 24000]]);
    expect($c['scoperto_count'])->toBe(1);
});

it('T1.10 BudgetCoverageService::analyze: i totali col segno, le righe per voce come prima, ogni voce porta il suo tipo', function () {
    $s = b52tScenario($this);
    b52tPiano($this, $s);

    $r = app(BudgetCoverageService::class)->analyze($s['g']->fresh(), [], []);

    expect($r['totali'])->toBe(['budget' => 96000, 'pianificato' => 96000]);
    $righe = collect($r['items'])->mapWithKeys(fn ($i) => [$i['nome'] => [$i['tipo'] ?? null, $i['budget'], $i['pianificato'], $i['delta'], $i['status']]])->all();
    expect($righe)->toBe([
        'Spese generali' => ['spesa', 120000, 120000, 0, 'ok'],
        'Proventi antenna' => ['entrata', 24000, 24000, 0, 'ok'],
    ]);
});

it('T1.6g uno sforo di € 100,00 su una spesa, con un\'entrata nel piano dei conti: il cruscotto dice che c\'è uno sforo (fabbisogno 860,00 contro preventivo 760,00)', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $spesa] = setupContabile();
    $spesa->update(['importo' => 100000]);
    Conto::create(['piano_conto_id' => $spesa->piano_conto_id, 'nome' => 'Proventi antenna', 'tipo' => 'entrata', 'importo' => 24000]);
    (new FatturaPassivaService())->registraFattura(datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [['descrizione' => 'Spesa fuori preventivo', 'importo_imponibile' => 1100, 'aliquota_iva' => 0, 'conto_id' => $spesa->id, 'is_sopravvenienza' => false]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['strategia_rientro' => 'rata_integrativa', 'motivazione' => 'Urgente']],
    ]), $condominio->id);

    $copertura = $this->actingAs($this->user)->get(route('admin.gestionale.index', $condominio->id))->viewData('page')['props']['copertura'];

    expect(collect($copertura)->only(['spese', 'entrate', 'da_ripartire'])->all())->toEqual(['spese' => 110000, 'entrate' => 24000, 'da_ripartire' => 86000]);
    expect($copertura['has_sforo'])->toBeTrue();
});

it('T1.6h controprova: la stessa spesa e la stessa entrata senza sforo non accendono lo sforo', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $spesa] = setupContabile();
    $spesa->update(['importo' => 100000]);
    Conto::create(['piano_conto_id' => $spesa->piano_conto_id, 'nome' => 'Proventi antenna', 'tipo' => 'entrata', 'importo' => 24000]);
    (new FatturaPassivaService())->registraFattura(datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [['descrizione' => 'Spesa nel preventivo', 'importo_imponibile' => 900, 'aliquota_iva' => 0, 'conto_id' => $spesa->id, 'is_sopravvenienza' => false]],
    ]), $condominio->id);

    $copertura = $this->actingAs($this->user)->get(route('admin.gestionale.index', $condominio->id))->viewData('page')['props']['copertura'];

    expect(collect($copertura)->only(['spese', 'entrate', 'da_ripartire'])->all())->toEqual(['spese' => 100000, 'entrate' => 24000, 'da_ripartire' => 76000]);
    expect($copertura['has_sforo'])->toBeFalse();
});

it('T1.6i la copertura virtuale di una voce di entrata conta col segno meno, come il resto del netto: una fattura vecchia con rientro a conguaglio su un\'entrata dà −300,00', function () {
    // Una fattura registrata su una voce che poi è diventata un\'entrata (oggi la richiesta non lo permette più): è l\'unico modo di avere una copertura
    // virtuale su una voce di entrata. Il fabbisogno e il già pianificato contano le entrate col meno, e la copertura virtuale segue la stessa regola.
    [$condominio, $esercizio, $gestione, $fornitore, $spesa] = setupContabile();
    $spesa->update(['importo' => 100000]);
    $entrata = Conto::create(['piano_conto_id' => $spesa->piano_conto_id, 'nome' => 'Proventi antenna', 'tipo' => 'entrata', 'importo' => 24000, 'conto_contabile_id' => $spesa->conto_contabile_id]);
    (new FatturaPassivaService())->registraFattura(datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [['descrizione' => 'Riga su una voce di entrata', 'importo_imponibile' => 300, 'aliquota_iva' => 0, 'conto_id' => $entrata->id, 'is_sopravvenienza' => false]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['strategia_rientro' => 'conguaglio_fine_anno', 'motivazione' => 'Registrata prima del perimetro']],
    ]), $condominio->id);

    $copertura = $this->actingAs($this->user)->get(route('admin.gestionale.index', $condominio->id))->viewData('page')['props']['copertura'];

    expect(collect($copertura)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'virtuale', 'delta'])->all())->toEqual([
        'spese' => 100000, 'entrate' => 30000, 'da_ripartire' => 70000, 'pianificato' => 0, 'virtuale' => -30000, 'delta' => 100000,
    ]);
});
