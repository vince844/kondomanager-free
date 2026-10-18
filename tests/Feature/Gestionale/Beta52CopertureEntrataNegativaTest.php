<?php

/**
 * Una voce di entrata scritta con il meno (dato delle versioni dalla 1.7.0 alla 1.11.0-beta.51) non fa risultare scoperto un piano giusto.
 *
 * Il motore distribuisce una voce di entrata come `−abs(importo)`: con o senza il segno l'entrata accredita lo stesso. La copertura per voce
 * (`BudgetCoverageService`), da cui leggono il cruscotto, l'elenco delle voci scoperte e il selettore di un piano nuovo, leggeva invece l'importo della voce
 * e la copia che il piano ne tiene con il segno: un'entrata a −€ 240,00 risultava in deficit, il selettore la riproponeva come «Sforo da recuperare» e
 * un'integrativa fatta con quella cifra accreditava due volte. Ora le voci di entrata contano per il loro valore assoluto, in `analyze()`, nei passi di
 * `calcolaCoperturaReale()` e nel selettore (`FetchCapitoliPerGestioneController`).
 *
 * Le voci di spesa restano col loro segno: una spesa negativa il motore la addebita in positivo, e a fermarla è il fermo del piano
 * (`Beta52VoceEntrataFermoTest`); un valore assoluto anche su di loro lascerebbe passare la creazione di un piano con una spesa negativa.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spese € 1.200,00, entrata € 240,00: il piano chiede € 960,00; il fabbisogno da ripartire è il netto) e il
 * comportamento del motore (`processaConti`, `−abs` sull'entrata), che qui non cambia.
 *
 * Cosa resta scoperto: la schermata del cruscotto e del selettore (si guardano a video); i tipi di voce diversi da «spesa» ed «entrata» (la colonna
 * `conti.tipo` è un enum).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** La copertura che il cruscotto mostra, ristretta ai campi che contano. */
function be52EntCruscotto($test, array $s): array
{
    $copertura = $test->actingAs($test->user)->get(route('admin.gestionale.index', $s['c']->id))->viewData('page')['props']['copertura'];

    return collect($copertura)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all();
}

/** Il selettore di un piano nuovo: nome => [residuo in euro, disabilitata, nota]. */
function be52EntSelettore($test, array $s): array
{
    $righe = $test->actingAs($test->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id]))->json();

    return collect($righe)->mapWithKeys(fn ($c) => [$c['nome'] => [(float) $c['residuo'], $c['disabled'], $c['note']]])->all();
}

/** Una voce come la leggono i test della copertura: un oggetto semplice con il tipo, l'importo e i sottoconti, senza il database. */
function be52EntVoce(int $id, string $nome, int $importo, string $tipo = 'entrata', array $figli = []): object
{
    $v = new stdClass;
    $v->id = $id;
    $v->nome = $nome;
    $v->tipo = $tipo;
    $v->importo = $importo;
    $v->parent_id = null;
    $v->parent = null;
    $v->sottoconti = collect($figli);
    foreach ($figli as $figlio) {
        $figlio->parent_id = $id;
        $figlio->parent = $v;
    }

    return $v;
}

function be52EntCoperturaReale(array $radici, array $capitoli): array
{
    $piano = new stdClass;
    $piano->id = 1;
    $piano->nome = 'Piano';
    $piano->tipo = 'ordinario';
    $piano->attivo = true;
    $piano->capitoli = collect(array_map(function (array $c) {
        [$voce, $pivot] = $c;
        $cap = clone $voce;
        $cap->pivot = (object) ['importo' => $pivot, 'note' => null];

        return $cap;
    }, $capitoli));

    $servizio = new BudgetCoverageService;
    $metodo = new ReflectionMethod($servizio, 'calcolaCoperturaReale');

    return $metodo->invoke($servizio, collect($radici), collect([$piano]));
}

// ─── Un'entrata scritta con il meno, con la copia nel piano ──────────────────────────────────────────────────────

it('un\'entrata a −€ 240,00 con la copia −€ 240,00 nel piano: cruscotto, copertura e selettore la leggono come € 240,00 coperti', function () {
    $s = b52tScenario($this);
    // l'entrata com'era nella 1.10: −24000 sul tipo entrata (la richiesta di oggi lo rifiuta, qui si scrive a mano)
    DB::table('conti')->where('id', $s['entrata']->id)->update(['importo' => -24000]);

    $piano = b52tPiano($this, $s);
    // la copia che il piano teneva per l'entrata nella 1.10: lo stesso meno della voce
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['entrata']->id)->update(['importo' => -24000]);
    $copiaDelPiano = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['entrata']->id)->value('importo');
    $analisi = app(BudgetCoverageService::class)->analyze($s['g']->fresh());
    $voce = collect($analisi['items'])->firstWhere('id', $s['entrata']->id);

    // a mano: spese 120000 − entrata 24000 = 96000 da ripartire, e il piano copre 96000
    expect((int) $copiaDelPiano)->toBe(-24000)
        ->and(b52tQuoteDelMotore($piano))->toBe(96000)
        ->and([$voce['budget'], $voce['pianificato'], $voce['delta'], $voce['status']])->toBe([24000, 24000, 0, 'ok'])
        ->and($analisi['totali'])->toBe(['budget' => 96000, 'pianificato' => 96000])
        ->and(be52EntCruscotto($this, $s))->toMatchArray(['spese' => 120000, 'entrate' => 24000, 'da_ripartire' => 96000, 'pianificato' => 96000, 'delta' => 0])
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Proventi antenna'])->toBe([0.0, true, 'Interamente finanziato/Esaurito'])
        ->and(app(PianoRateQuoteService::class)->eDisallineato($piano))->toBeFalse();
});

it('un\'entrata a −€ 240,00 senza copia nel piano: la copertura la legge come € 240,00 e il selettore non la propone', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s, ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '1.200,00', 'note' => '']]]);
    DB::table('conti')->where('id', $s['entrata']->id)->update(['importo' => -24000]);

    $analisi = app(BudgetCoverageService::class)->analyze($s['g']->fresh());
    $voce = collect($analisi['items'])->firstWhere('id', $s['entrata']->id);

    // il piano non include l'entrata: la voce è scoperta per € 240,00 (valore assoluto), non per € −240,00
    expect($piano->capitoli()->count())->toBe(1)
        ->and([$voce['budget'], $voce['budget_teorico'], $voce['pianificato'], $voce['delta']])->toBe([24000, 24000, 0, -24000])
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Proventi antenna'])->toBe([240.0, false, 'Disp: € 240,00']);
});

// ─── Una spesa negativa portata a «Entrata» con il meno ──────────────────────────────────────────────────────────

it('una spesa a −€ 240,00 portata a «Entrata» senza toccare l\'importo: il piano è giusto e il selettore non offre uno sforo che accredita due volte', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $s['entrata']->delete();
    $antenna = b52tVoce($this, $s, 'Proventi antenna', 'spesa', '1,00', $s['box']);
    DB::table('conti')->where('id', $antenna->id)->update(['importo' => -24000]); // dato della 1.10: spesa a −240,00

    // il piano con la spesa negativa si ferma
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
    expect(DB::table('piani_rate')->count())->toBe(0);

    // l'amministratore porta la voce a «Entrata» senza toccare l'importo: il modale rimanda «-240,00»
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $antenna]), [
        'nome' => 'Proventi antenna', 'codice' => '', 'tipo' => 'entrata', 'importo' => '-240,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    expect(Conto::find($antenna->id)->tipo)->toBe('entrata');

    $piano = b52tPiano($this, $s);

    // a mano: spese 120000 − entrata 24000 = 96000, una volta sola
    expect(b52tQuoteDelMotore($piano))->toBe(96000)
        ->and(be52EntCruscotto($this, $s))->toMatchArray(['spese' => 120000, 'entrate' => 24000, 'da_ripartire' => 96000, 'pianificato' => 96000, 'delta' => 0])
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Proventi antenna'][1])->toBeTrue()
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Proventi antenna'][0])->toBe(0.0);
});

it('un provento scritto come spesa a −€ 300,00 con la copia nel piano: dopo «Entrata» e € 300,00 il ricalcolo passa, il cruscotto è a pari e il selettore non lo ripropone', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $s['entrata']->delete();
    $voce = b52tVoce($this, $s, 'Rimborso', 'spesa', '300,00', $s['box']);
    $piano = b52tPiano($this, $s);
    // com'è nel database di chi aveva scritto il meno nella 1.10: voce e copia negative
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -30000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->update(['importo' => -30000]);

    // la prima frase del fermo, alla lettera: «scegli «Entrata» e scrivi l'importo senza il segno»
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $voce]), [
        'nome' => 'Rimborso', 'codice' => '', 'tipo' => 'entrata', 'importo' => '300,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano->fresh()]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    // a mano: spese 120000 − provento 30000 = 90000, e la copia −30000 del provento copre i suoi 30000
    expect(be52Flash()['type'] ?? null)->toBe('success')
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe(90000)
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->value('importo'))->toBe(-30000)
        ->and(be52EntCruscotto($this, $s))->toMatchArray(['da_ripartire' => 90000, 'pianificato' => 90000, 'delta' => 0])
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Rimborso'][1])->toBeTrue()
        ->and(be52EntSelettore($this, $s)['[CAPITOLO] Rimborso'][0])->toBe(0.0);
});

it('la voce del provento corretto non risulta fra le voci non coperte e il totale dell\'analisi chiude a € 960,00', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this, 'spesa', -24000, -24000);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'tipo' => 'entrata', 'importo' => '240,00'])->assertSessionHasNoErrors();
    be52Ricalcola($this, $s, $piano->fresh());

    $analisi = app(BudgetCoverageService::class)->analyze($s['g']->fresh());
    $rimborso = collect($analisi['items'])->firstWhere('id', $voce->id);

    expect(be52Flash()['type'] ?? null)->toBe('success')
        ->and(be52Totale($piano->fresh()))->toBe(96000)
        ->and([$rimborso['budget'], $rimborso['pianificato'], $rimborso['delta'], $rimborso['status'], $rimborso['message']])->toBe([24000, 24000, 0, 'ok', 'Copertura perfetta.'])
        ->and($analisi['totali'])->toBe(['budget' => 96000, 'pianificato' => 96000])
        ->and(app(BudgetCoverageService::class)->getCapitoliFinanziabili($analisi))->toBe([]);
});

// ─── I passi della copertura sulle voci d'entrata ───────────────────────────────────────────────────────────────

it('la copertura di una foglia d\'entrata con la copia negativa è il valore assoluto della copia', function () {
    $entrata = be52EntVoce(1, 'Affitto antenna', -24000);

    $mappa = be52EntCoperturaReale([$entrata], [[$entrata, -24000]]);

    expect($mappa[1])->toBe(24000);
});

it('la copertura di una foglia d\'entrata senza copia è il valore assoluto del suo importo', function () {
    $entrata = be52EntVoce(1, 'Affitto antenna', -24000);

    $mappa = be52EntCoperturaReale([$entrata], [[$entrata, null]]);

    expect($mappa[1])->toBe(24000);
});

it('un capitolo d\'entrata con la copia negativa copre i suoi sottoconti d\'entrata per il valore assoluto', function () {
    $a = be52EntVoce(2, 'Antenna A', -30000);
    $b = be52EntVoce(3, 'Antenna B', -10000);
    $capitolo = be52EntVoce(1, 'Proventi', 0, 'entrata', [$a, $b]);

    $mappa = be52EntCoperturaReale([$capitolo], [[$capitolo, -40000]]);

    expect([$mappa[1], $mappa[2], $mappa[3]])->toBe([40000, 30000, 10000]);
});

it('controprova — una spesa negativa resta col suo segno: la copertura non la legge in valore assoluto', function () {
    $spesa = be52EntVoce(1, 'Rimborso', -24000, 'spesa');

    $mappa = be52EntCoperturaReale([$spesa], [[$spesa, -24000]]);

    expect($mappa[1])->toBe(-24000);
});

it('controprova — un\'entrata e una spesa positive restano quelle di sempre', function () {
    $entrata = be52EntVoce(1, 'Affitto antenna', 24000);
    $spesa = be52EntVoce(2, 'Pulizia', 120000, 'spesa');

    $mappa = be52EntCoperturaReale([$entrata, $spesa], [[$entrata, 24000], [$spesa, null]]);

    expect([$mappa[1], $mappa[2]])->toBe([24000, 120000]);
});

// ─── Il selettore: un capitolo d'entrata con i sottoconti scritti con il meno ───────────────────────────────────

it('il selettore dice l\'importo di un capitolo d\'entrata con i sottoconti negativi in valore assoluto e non lo marca come sforo', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $s['entrata']->delete();
    $padre = b52tVoce($this, $s, 'Proventi', 'entrata', '0,00', $s['box'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $a = b52tVoce($this, $s, 'Antenna A', 'entrata', '100,00', $s['box'], $padre->id, ['isSottoConto' => true]);
    $b = b52tVoce($this, $s, 'Antenna B', 'entrata', '200,00', $s['box'], $padre->id, ['isSottoConto' => true]);
    DB::table('conti')->where('id', $a->id)->update(['importo' => -10000]);
    DB::table('conti')->where('id', $b->id)->update(['importo' => -20000]);

    $selettore = be52EntSelettore($this, $s);
    $righe = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id]))->json());
    $capitolo = $righe->firstWhere('id', $padre->id);

    expect((float) $capitolo['importo_totale'])->toBe(300.0)
        ->and($capitolo['is_sforo'])->toBeFalse()
        ->and($selettore['Proventi > Antenna A'][0])->toBe(100.0)
        ->and($selettore['Proventi > Antenna B'][0])->toBe(200.0);
});
