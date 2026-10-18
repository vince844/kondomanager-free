<?php

/**
 * Cosa resta scoperto: MySQL (sqlite in memoria, con JSON_UNQUOTE registrata a mano); le voci `uscita` (la colonna `conti.tipo` è un enum
 * `spesa`/`entrata`, qui non si scrive: le prova il vitest di `totaliPerTipo`).
 *
 * Il badge «Disallineato: ricalcola!» e l'atteso del piano con le voci di entrata.
 *
 * Il motore dà il segno dal tipo della voce (`CalcoloQuoteService.php:1500-1502` e `:1586-1589`: una voce che non è di spesa si
 * distribuisce con il segno meno), ma la pivot `piano_rate_capitoli` tiene l'importo positivo, come lo scrive la pagina. Chi confronta
 * la pivot con le quote generate deve rifare il segno: `PianoRateQuoteService::totaleAttesoCents` non lo fa, e ogni piano con
 * un'entrata nasce «Disallineato» con un delta di due volte l'entrata, che «Ricalcola» non spegne.
 *
 * Gli scenari: spese € 1.200,00 sulla tabella «Proprietà» (A di Ugo e C di Bice, 500 millesimi ciascuna) ed entrata € 240,00 sulla
 * tabella «Box» (solo il box D di Bice). Il motore dà Ugo 60000 e Bice 36000 (60000 − 24000): 96000 in tutto.
 *
 * L'atteso segue lo stesso ordine del motore per i capitoli padre: con tabelle proprie conta il tipo del padre, senza tabelle contano i
 * sottoconti nati fino alla data del piano e, se non ce n'è nessuno (sottoconti nati dopo il piano), tutti i sottoconti attuali. I due
 * rami hanno ciascuno il suo test: «padre con figli di entrata» nato dopo il piano (T0) e nato prima, con la pivot spostata sul padre (T0b).
 * Un padre senza tabelle i cui sottoconti sono di tipo diverso divide la pivot fra spese ed entrate come la divide il motore (in proporzione
 * all'importo dei sottoconti, l'ultimo prende il resto): l'atteso è la somma delle quote, anche quando la divisione arrotonda (T0c).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Atteso e generato di un piano riletto dal database. */
function b52tMisura(PianoRate $piano): array
{
    $svc = app(PianoRateQuoteService::class);
    $piano = PianoRate::find($piano->id);

    return ['atteso' => $svc->totaleAttesoCents($piano), 'generato' => $svc->totalePuroGeneratoCents($piano), 'disallineato' => $svc->eDisallineato($piano)];
}

it('T1.1 un piano giusto con un\'entrata non è disallineato: atteso e generato dicono 96000', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);

    expect(b52tSommeQuote($piano))->toBe(['Bice' => 36000, 'Ugo' => 60000]); // il motore è giusto già oggi
    expect(b52tMisura($piano))->toBe(['atteso' => 96000, 'generato' => 96000, 'disallineato' => false]);
});

it('T1.1b «Ricalcola» su un piano giusto non cambia le quote e il badge resta spento', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [])->assertSessionHasNoErrors();

    expect(b52tSommeQuote($piano))->toBe(['Bice' => 36000, 'Ugo' => 60000]);
    expect(b52tMisura($piano)['disallineato'])->toBeFalse();
});

it('T1.2 l\'acconto: spesa € 600,00 ed entrata € 240,00 scelte dalla pagina, atteso 36000 uguale al generato', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s, ['capitoli_config' => [
        ['id' => $s['spesa']->id, 'importo' => '600,00', 'note' => ''],
        ['id' => $s['entrata']->id, 'importo' => '240,00', 'note' => ''],
    ]]);

    expect(b52tSommeQuote($piano))->toBe(['Bice' => 6000, 'Ugo' => 30000]);
    expect(b52tMisura($piano))->toBe(['atteso' => 36000, 'generato' => 36000, 'disallineato' => false]);
});

it('T1.3 la sola entrata: atteso e generato dicono −24000 e il badge è spento (il max(0, …) sta sulle sole spese)', function () {
    $s = b52tScenario($this, soloEntrata: true);
    $piano = b52tPiano($this, $s);

    expect(b52tSommeQuote($piano))->toBe(['Bice' => -24000]);
    expect(b52tMisura($piano))->toBe(['atteso' => -24000, 'generato' => -24000, 'disallineato' => false]);
});

it('T1.4 il già versato di € 200,00 sulla spesa: atteso 76000 uguale al generato', function () {
    $s = b52tScenario($this);
    b52tGiaVersato($this, $s, $s['spesa'], 'A', 20_000);
    $piano = b52tPiano($this, $s);

    expect(b52tSommeQuote($piano))->toBe(['Bice' => 36000, 'Ugo' => 40000]);
    expect(b52tMisura($piano))->toBe(['atteso' => 76000, 'generato' => 76000, 'disallineato' => false]);
});

it('T1.5d il già versato si legge sulle sole voci di spesa: una riga di già versato su una voce di entrata (costruita a mano) non cambia l\'atteso', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    // la pagina «Già versato» offre solo voci di spesa; la riga sull'entrata si costruisce a mano, come la lascerebbe un dato di un'altra origine
    DB::table('contributi_versati')->insert([
        'condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['entrata']->id, 'immobile_id' => $s['unita']['D']->id,
        'anagrafica_id' => $s['persone']['bice']->id, 'importo_cents' => 10_000, 'natura' => 'avanzo', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(b52tMisura($piano)['atteso'])->toBe(96000); // 120000 − 24000: il già versato dell'entrata non si toglie dalle spese
});

it('T1.5a controprova: un piano di sole spese resta com\'è (120000, spento), con e senza il già versato', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPiano($this, $s);
    expect(b52tMisura($piano))->toBe(['atteso' => 120000, 'generato' => 120000, 'disallineato' => false]);

    $s2 = b52tScenario($this, entrata: '0,00');
    b52tGiaVersato($this, $s2, $s2['spesa'], 'A', 20_000);
    $piano2 = b52tPiano($this, $s2, [], 'Piano con già versato');
    expect(b52tMisura($piano2))->toBe(['atteso' => 100000, 'generato' => 100000, 'disallineato' => false]);
});

it('T1.5c controprova: un piano generato senza righe nella pivot (come i piani di prima della 1.9) ha atteso 0 e resta disallineato, come oggi', function () {
    $s = b52tScenario($this, soloEntrata: true);
    $piano = b52tPiano($this, $s);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->delete();

    expect(b52tMisura($piano))->toBe(['atteso' => 0, 'generato' => -24000, 'disallineato' => true]);
});

it('T1.5b un disallineamento vero con un\'entrata accende il badge con lo scarto vero, e «Ricalcola» lo spegne', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    // prima: spento (oggi acceso per colpa del segno)
    expect(b52tMisura($piano)['disallineato'])->toBeFalse();

    // l'amministratore cambia il tipo della voce già nel piano: da entrata a spesa. Le quote generate restano quelle di prima (96000).
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['entrata']]), [
        'nome' => 'Proventi antenna', 'codice' => '', 'tipo' => 'spesa', 'importo' => '240,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    expect(Conto::find($s['entrata']->id)->tipo)->toBe('spesa');

    // lo scarto vero è il doppio dell'importo: da −24000 a +24000
    expect(b52tMisura($piano))->toBe(['atteso' => 144000, 'generato' => 96000, 'disallineato' => true]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [])->assertSessionHasNoErrors();
    expect(b52tMisura($piano))->toBe(['atteso' => 144000, 'generato' => 144000, 'disallineato' => false]);
});

it('T0 l\'atteso concorda con il motore su ogni forma di voce: spesa, entrata, padre di sole spese, padre con figli di entrata nati dopo il piano', function () {
    // La sentinella contro una futura divergenza fra il segno dei totali e quello del motore: per ognuna delle forme, la somma delle
    // quote generate (il motore) è l'atteso. Un padre in pivot nasce da «Ricalcola con voci orfane»; i suoi figli nascono dopo il piano,
    // quindi lo snapshot è vuoto e il motore ripiega su tutti i sottoconti attuali.
    $casi = [
        'foglie spesa ed entrata' => fn () => b52tPiano($this, b52tScenario($this)),
        'padre con figli di spesa' => fn () => b52tScenarioConPadre($this, 'spesa')['piano'],
        'padre con figli di entrata' => fn () => b52tScenarioConPadre($this, 'entrata')['piano'],
    ];
    $esiti = [];
    foreach ($casi as $nome => $costruisci) {
        $piano = $costruisci();
        $esiti[$nome] = ['atteso' => b52tMisura($piano)['atteso'], 'quote' => b52tQuoteDelMotore($piano)];
    }

    expect($esiti)->toBe([
        'foglie spesa ed entrata' => ['atteso' => 96000, 'quote' => 96000],
        'padre con figli di spesa' => ['atteso' => 220000, 'quote' => 220000],   // 120000 + 100000
        'padre con figli di entrata' => ['atteso' => 20000, 'quote' => 20000],   // 120000 − 100000
    ]);
});

it('T0b l\'atteso concorda con il motore anche sul padre in pivot con i figli nati prima del piano (la forma dei piani di prima della 1.9)', function () {
    // Qui lo snapshot dei sottoconti è pieno: il segno del padre è quello dei figli, come per il motore.
    $esiti = [];
    foreach (['spesa', 'entrata'] as $tipoFigli) {
        $s = b52tScenarioConPadreLegacy($this, $tipoFigli);
        $esiti["figli di {$tipoFigli}"] = ['atteso' => b52tMisura($s['piano'])['atteso'], 'quote' => b52tQuoteDelMotore($s['piano'])];
    }

    expect($esiti)->toBe([
        'figli di spesa' => ['atteso' => 220000, 'quote' => 220000],
        'figli di entrata' => ['atteso' => 20000, 'quote' => 20000],
    ]);
});

it('T0c l\'atteso concorda con il motore su un padre in pivot con sottoconti di tipo diverso: il netto è la somma delle quote, e il badge resta spento', function () {
    // Un padre nella pivot (la forma dei piani di prima della 1.9, o «Ricalcola con voci orfane») i cui figli sono una spesa e un'entrata: il motore
    // divide la pivot fra i figli in proporzione al loro importo e dà a ciascuno il suo segno. Con il tipo del padre per tutta la pivot l'atteso
    // sbagliava del doppio della parte di entrata (€ 800,00 su € 1.000,00), e «Ricalcola» non lo spegneva.
    $casi = [
        'spesa 600, entrata 400, pivot 1.000' => fn () => b52tScenarioConPadreMisto($this)['piano'],
        'acconto di 333,33 sulla stessa coppia' => fn () => b52tScenarioConPadreMisto($this, pivot: 33333)['piano'],
        // metà e metà con una pivot dispari: l'arrotondamento dà il centesimo in più al primo figlio (la spesa), e il segno dell'ultimo cambia il netto
        'spesa 500, entrata 500, pivot 1.000,01' => fn () => b52tScenarioConPadreMisto($this, 50000, 50000, 100001)['piano'],
    ];
    $esiti = [];
    foreach ($casi as $nome => $costruisci) {
        $piano = $costruisci();
        $misura = b52tMisura($piano);
        $esiti[$nome] = [$misura['atteso'], b52tQuoteDelMotore($piano), $misura['disallineato']];
    }

    expect($esiti)->toBe([
        'spesa 600, entrata 400, pivot 1.000' => [140000, 140000, false],           // Pulizia 120000 + (60000 − 40000)
        'acconto di 333,33 sulla stessa coppia' => [126667, 126667, false],        // Pulizia 120000 + (20000 − 13333)
        'spesa 500, entrata 500, pivot 1.000,01' => [120001, 120001, false],       // Pulizia 120000 + (50001 − 50000)
    ]);
});

it('T1.5e una voce con la pivot scritta negativa (dato di una versione vecchia): il motore la distribuisce in valore assoluto col segno del tipo, e l\'atteso e i totali dicono lo stesso numero', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);
    expect(b52tSommeQuote($piano))->toBe(['Bice' => 36000, 'Ugo' => 60000]);

    // la sola spesa negativa
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['spesa']->id)->update(['importo' => -120000]);
    $totali = app(PianoRateQuoteService::class)->totaliPerSegnoCents(PianoRate::find($piano->id));
    expect([$totali['spese'], $totali['entrate']])->toBe([120000, 24000]);
    expect(b52tMisura($piano))->toBe(['atteso' => 96000, 'generato' => 96000, 'disallineato' => false]);

    // anche l\'entrata negativa: il motore la accredita comunque per 240,00
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['entrata']->id)->update(['importo' => -24000]);
    $totali = app(PianoRateQuoteService::class)->totaliPerSegnoCents(PianoRate::find($piano->id));
    expect([$totali['spese'], $totali['entrate']])->toBe([120000, 24000]);
    expect(b52tMisura($piano))->toBe(['atteso' => 96000, 'generato' => 96000, 'disallineato' => false]);
});
