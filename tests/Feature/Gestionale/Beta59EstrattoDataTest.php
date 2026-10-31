<?php

/**
 * 1.11.0-beta.59, Coda 317 (2) — la data dell'estratto conto per persona è la data di competenza della scrittura.
 *
 * La colonna «Data» della pagina e del PDF mostrava la data di registrazione (il giorno in cui l'amministratore ha inserito il movimento) e le
 * righe seguivano l'ordine delle scritture: un incasso del 10/07 inserito il 18/10 compariva «18/10», dopo tutto ciò che era già inserito, e il
 * saldo progressivo delle righe in mezzo era sbagliato. La Prima nota (il Registro di contabilità) usa la competenza dalla 1.11.0-beta.24.
 *
 * La regola (decisione 153.6; D6 e D21.4 di `registri_contabili.md`): la colonna «Data» è la data di competenza, in pagina e nel PDF; l'ordine
 * è per data di competenza, poi nello stesso giorno il dare prima dell'avere, poi la scrittura; lo storno sta alla sua data; la data di
 * registrazione compare solo in pagina, quando è diversa, non nel PDF (D6: «all'amministratore, non ai condòmini»). Totali e saldo finale non
 * cambiano per l'ordine.
 *
 * Due precisazioni della stessa regola. Lo storno (la rettifica) fatto lo stesso giorno della scrittura che annulla sta dopo di lei (tra le due
 * possono esserci solo le righe in avere delle scritture inserite prima di lei): prima di lei annullerebbe un movimento che nell'elenco non c'è
 * ancora; una compensazione col credito, figlia di un incasso che resta valido, non è uno storno e segue il dare prima dell'avere. E la data di
 * registrazione è il giorno in cui l'amministratore ha inserito il movimento nel suo calendario (Europe/Rome, `config('app.user_timezone')`),
 * non quello di Greenwich con cui il programma scrive la colonna.
 *
 * Fonte dell'atteso: la regola qui sopra, applicata a mano in ogni test (date, ordine, saldo progressivo riga per riga). Le operazioni passano
 * dalle rotte delle loro pagine; il giorno in cui l'amministratore le inserisce è il giorno del test (`travelTo`).
 *
 * Che cosa NON copre: il filtro per mastro (`Beta59EstrattoMastriTest.php`); la riga «Registrata il» disegnata dalla pagina
 * (`EstrattoContoAnagrafica.test.ts`); MySQL.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** Le righe dell'estratto come [data, data di registrazione se diversa, dare, avere, saldo progressivo]. */
function b59eRighe(array $timeline): array
{
    return array_map(fn ($r) => [$r['data'], $r['data_registrazione'] ?? null, (int) $r['dare'], (int) $r['avere'], (int) $r['saldo']], $timeline);
}

it('Coda 317 — un incasso del 10/07 inserito il 18/10 compare al 10/07, fra la rata del 05/01 e quella del 05/08, con la registrazione in pagina e non nel PDF', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-08-05 10:00:00');
    b59eEmetti($s, [2], '2026-08-05');
    $this->travelTo('2026-10-18 10:00:00');
    b59eIncassa($s, 1, '2026-07-10', '500.00');
    $this->travelTo('2026-10-30 09:00:00');

    $pagina = b59ePagina($s);
    $pdf = b59ePdf($s);

    // A mano, per data di competenza: rata 1 (05/01) +50000 → 50000; incasso (10/07) −50000 → 0; rata 2 (05/08) +50000 → 50000.
    // Solo l'incasso ha la registrazione diversa dalla competenza: 18/10/2026. Totali: addebiti 100000, versato 50000, saldo 50000, come prima.
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['10/07/2026', '18/10/2026', 0, 50000, 0],
        ['05/08/2026', null, 50000, 0, 50000],
    ])
        ->and(b59eCifre($pagina))->toBe(['€ 500,00', '€ 1.000,00', '€ 500,00'])
        ->and(array_map(fn ($r) => [$r['data'], (int) $r['saldo']], $pdf['timeline']))->toBe([['05/01/2026', 50000], ['10/07/2026', 0], ['05/08/2026', 50000]])
        ->and(b59eCifre($pdf))->toBe(['€ 500,00', '€ 1.000,00', '€ 500,00'])
        ->and($pdf['testo'])->toContain('10/07/2026')
        ->and($pdf['testo'])->not->toContain('18/10/2026')
        ->and($pdf['testo'])->not->toContain('Registrata il');
});

it('Coda 317 — nello stesso giorno il dare prima dell\'avere: la rata emessa il 05/03 viene prima dell\'incasso del 05/03 inserito prima di lei', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-03-05 10:00:00');
    $incasso = b59eIncassa($s, 1, '2026-03-05', '500.00');
    $this->travelTo('2026-03-05 11:00:00');
    b59eEmetti($s, [2], '2026-03-05');
    $this->travelTo('2026-10-30 09:00:00');

    $emissione2 = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->max('id');
    expect($incasso)->toBeLessThan($emissione2);

    // A mano: rata 1 (05/01) → 50000; il 05/03 prima il dare della rata 2 → 100000, poi l'avere dell'incasso → 50000. Mai sotto il vero.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['05/03/2026', null, 50000, 0, 100000],
        ['05/03/2026', null, 0, 50000, 50000],
    ])
        ->and(b59eCifre($pagina))->toBe(['€ 500,00', '€ 1.000,00', '€ 500,00'])
        ->and(array_map(fn ($r) => (int) $r['saldo'], b59ePdf($s)['timeline']))->toBe([50000, 100000, 50000]);
});

it('Coda 317 — lo storno sta alla sua data: l\'incasso del 10/03 stornato il 20/04 viene dopo la rata del 05/04 inserita il 25/04', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = b59eIncassa($s, 1, '2026-03-10', '500.00');
    $this->travelTo('2026-04-20 10:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    $this->travelTo('2026-04-25 10:00:00');
    b59eEmetti($s, [2], '2026-04-05');
    $this->travelTo('2026-10-30 09:00:00');

    // A mano: rata 1 (05/01) → 50000; incasso (10/03) → 0; rata 2 (05/04, inserita il 25/04) → 50000; lo storno (20/04, rettifica in dare) → 100000.
    // La coppia incasso-storno resta nell'elenco e fuori dai totali (decisione 75.5): addebiti 100000, versato 0, saldo 100000.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['10/03/2026', null, 0, 50000, 0],
        ['05/04/2026', '25/04/2026', 50000, 0, 50000],
        ['20/04/2026', null, 50000, 0, 100000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'incasso_rata', 'emissione_rata', 'rettifica'])
        ->and(b59eCifre($pagina))->toBe(['€ 1.000,00', '€ 1.000,00', '€ 0,00']);
});

it('Coda 317 — lo storno fatto lo stesso giorno dell\'incasso sta dopo l\'incasso che annulla, in pagina e nel PDF, e i totali non cambiano', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-03-15 10:00:00');
    $incasso = b59eIncassa($s, 1, '2026-03-15', '500.00');
    $this->travelTo('2026-03-15 11:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    $this->travelTo('2026-10-30 09:00:00');

    // A mano: rata (05/01) +50000 → 50000. Il 15/03 l'incasso −50000 → 0 e, dopo di lui, lo storno che lo annulla (la rettifica in dare) +50000 → 50000.
    // Con il dare prima dell'avere anche fra scritture diverse (decisione 153.6) lo storno stava prima dell'incasso: 100000, poi 50000. La coppia
    // incasso-storno resta nell'elenco e fuori dai totali (decisione 75.5): addebiti 50000, versato 0, saldo 50000.
    $pagina = b59ePagina($s);
    $pdf = b59ePdf($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['15/03/2026', null, 0, 50000, 0],
        ['15/03/2026', null, 50000, 0, 50000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'incasso_rata', 'rettifica'])
        ->and(b59eCifre($pagina))->toBe(['€ 500,00', '€ 500,00', '€ 0,00'])
        ->and(array_map(fn ($r) => (int) $r['saldo'], $pdf['timeline']))->toBe([50000, 0, 50000])
        ->and(array_column($pdf['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'incasso_rata', 'rettifica'])
        ->and(b59eCifre($pdf))->toBe(['€ 500,00', '€ 500,00', '€ 0,00']);
});

it('Coda 317 — lo storno sta subito dopo l\'incasso che annulla, non dopo un altro incasso dello stesso giorno inserito dopo di lui', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1, 2], '2026-01-05');
    $this->travelTo('2026-03-15 10:00:00');
    $primo = b59eIncassa($s, 1, '2026-03-15', '500.00');
    $this->travelTo('2026-03-15 11:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $primo]))->assertSessionHasNoErrors();
    $this->travelTo('2026-03-15 12:00:00');
    b59eIncassa($s, 2, '2026-03-15', '500.00');
    $this->travelTo('2026-10-30 09:00:00');

    // A mano: le due rate del 05/01 +50000 +50000 → 100000. Il 15/03 il primo incasso −50000 → 50000, lo storno che lo annulla subito dopo +50000 → 100000,
    // poi il secondo incasso −50000 → 50000. Addebiti 100000 (le due rate), versato 50000 (il secondo incasso), saldo 50000.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['05/01/2026', null, 50000, 0, 100000],
        ['15/03/2026', null, 0, 50000, 50000],
        ['15/03/2026', null, 50000, 0, 100000],
        ['15/03/2026', null, 0, 50000, 50000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'emissione_rata', 'incasso_rata', 'rettifica', 'incasso_rata'])
        ->and(b59eCifre($pagina))->toBe(['€ 500,00', '€ 1.000,00', '€ 500,00']);
});

it('Coda 317 — lo storno di un incasso di un altro giorno resta nel giorno dello storno, con il dare prima dell\'avere, anche se quell\'incasso è stato inserito dopo un incasso del giorno dello storno', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1, 2], '2026-01-05');
    $this->travelTo('2026-04-20 10:00:00');
    b59eIncassa($s, 2, '2026-04-20', '500.00');
    $this->travelTo('2026-04-20 11:00:00');
    $primo = b59eIncassa($s, 1, '2026-03-10', '500.00');
    $this->travelTo('2026-04-20 12:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $primo]))->assertSessionHasNoErrors();
    $this->travelTo('2026-10-30 09:00:00');

    // A mano: le due rate del 05/01 → 100000. Il 10/03 il primo incasso (inserito il 20/04, dopo il secondo) −50000 → 50000. Il 20/04 lo storno è del 20/04 e
    // il suo incasso è di un altro giorno: il dare prima dell'avere, quindi lo storno +50000 → 100000 e poi il secondo incasso −50000 → 50000.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['05/01/2026', null, 50000, 0, 100000],
        ['10/03/2026', '20/04/2026', 0, 50000, 50000],
        ['20/04/2026', null, 50000, 0, 100000],
        ['20/04/2026', null, 0, 50000, 50000],
    ])
        ->and(b59eCifre($pagina))->toBe(['€ 500,00', '€ 1.000,00', '€ 500,00']);
});

it('Coda 317 — l\'incasso pagato anche con il credito e stornato lo stesso giorno: ogni storno sta dopo la scrittura che annulla, e nello storno il dare viene prima dell\'avere', function (bool $righeInvertite) {
    $s = b59eScenario($this, -60000, 'rata_zero');

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1, 2], '2026-01-05');
    $this->travelTo('2026-03-10 09:00:00');
    // € 100,00 in contanti e € 600,00 del credito riportato per la rata 1 (€ 500,00) e per € 200,00 della rata 2.
    b59eCompensa($s, '2026-03-10', [1 => '500.00', 2 => '200.00', 0 => '-600.00'], '100.00');
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    $this->travelTo('2026-03-10 10:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    if ($righeInvertite) {
        // L'ordine in cui lo storno scrive le righe segue quello in cui il database restituisce le righe della scrittura che annulla, e non è un
        // contratto: qui le righe in avere di ogni storno sono scritte prima di quelle in dare, e dentro ogni lato dall'importo più piccolo al più
        // grande (il dare di € 200,00 è scritto prima di quello di € 400,00).
        foreach (DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rettifica')->pluck('id') as $id) {
            $righe = DB::table('righe_scritture')->where('scrittura_id', $id)->orderBy('id')->get();
            DB::table('righe_scritture')->where('scrittura_id', $id)->delete();
            foreach ($righe->sortBy(fn ($r) => [$r->tipo_riga === 'avere' ? 0 : 1, (int) $r->importo])->values() as $r) {
                DB::table('righe_scritture')->insert(collect((array) $r)->except('id')->all());
            }
        }
    }
    $this->travelTo('2026-10-30 09:00:00');

    // La compensazione è figlia dell'incasso (che resta la sua madre, non la sua scrittura inversa): non è uno storno e segue la regola di tutte
    // le scritture, il dare prima dell'avere. Lo storno dell'incasso e quello della compensazione hanno la data del 10/03 come le loro madri.
    $storni = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->whereIn('tipo_movimento', ['storno_credito', 'rettifica'])
        ->orderBy('id')->get(['tipo_movimento', 'scrittura_padre_id'])->map(fn ($r) => [$r->tipo_movimento, (int) $r->scrittura_padre_id === $incasso])->all();
    expect($storni)->toBe([['storno_credito', true], ['rettifica', true], ['rettifica', false]]);

    // A mano. Saldo iniziale −60000 (il credito riportato). Il 05/01 le due rate +50000 +50000 → −10000, poi 40000. Il 10/03 prima i dare delle
    // scritture del giorno: il prelievo dal credito della compensazione +60000 → 100000. Poi gli avere: l'incasso −10000 → 90000, e subito dopo
    // il suo storno +10000 → 100000; gli avere della compensazione, l'importo più alto per primo, −40000 → 60000 e −20000 → 40000, e dopo di lei il
    // suo storno: prima i due dare, +40000 → 80000 e +20000 → 100000, poi l'avere (il credito che torna) −60000 → 40000. La famiglia è stornata:
    // saldo finale 100000 di rate − 60000 di credito = 40000; sta nell'elenco e fuori dai totali (decisione 75.5): addebiti 100000, versato 0.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, -10000],
        ['05/01/2026', null, 50000, 0, 40000],
        ['10/03/2026', null, 60000, 0, 100000],
        ['10/03/2026', null, 0, 10000, 90000],
        ['10/03/2026', null, 10000, 0, 100000],
        ['10/03/2026', null, 0, 40000, 60000],
        ['10/03/2026', null, 0, 20000, 40000],
        ['10/03/2026', null, 40000, 0, 80000],
        ['10/03/2026', null, 20000, 0, 100000],
        ['10/03/2026', null, 0, 60000, 40000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'emissione_rata', 'storno_credito', 'incasso_rata', 'rettifica', 'storno_credito', 'storno_credito', 'rettifica', 'rettifica', 'rettifica'])
        ->and(b59eCifre($pagina))->toBe(['€ 400,00', '€ 1.000,00', '€ 0,00'])
        ->and(array_map(fn ($r) => (int) $r['saldo'], b59ePdf($s)['timeline']))->toBe([-10000, 40000, 100000, 90000, 100000, 60000, 40000, 80000, 100000, 40000]);
})->with([
    'righe dello storno come le scrive il programma' => [false],
    'righe dello storno scritte prima l\'avere e poi il dare, ogni lato dall\'importo più piccolo' => [true],
]);

it('Coda 317 — due storni della stessa scrittura (archivio di prima della 1.11.0-beta.49): le righe di ciascuno stanno insieme, per scrittura, e non si alternano per importo', function () {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1, 2], '2026-01-05');
    $this->travelTo('2026-03-15 10:00:00');
    // Un incasso di € 500,00 sulle due rate: € 300,00 sulla prima e € 200,00 sulla seconda.
    b59eCompensa($s, '2026-03-15', [1 => '300.00', 2 => '200.00'], '500.00');
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    $this->travelTo('2026-03-15 11:00:00');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    $primo = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rettifica')->max('id');

    // Il doppio storno concorrente dello stesso incasso è bloccato dalla rotta; prima della 1.11.0-beta.49 si poteva fare, e l'archivio può averlo.
    // Il secondo storno è scritto nel database: la scrittura del primo con una riga sola, in dare, di € 250,00 sulla prima rata.
    $this->travelTo('2026-03-15 12:00:00');
    $scrittura = (array) DB::table('scritture_contabili')->where('id', $primo)->first();
    unset($scrittura['id']);
    $scrittura['numero_protocollo'] = 'DOPPIO-'.$primo;
    $scrittura['created_at'] = $scrittura['updated_at'] = now();
    $secondo = DB::table('scritture_contabili')->insertGetId($scrittura);
    $riga = (array) DB::table('righe_scritture')->where('scrittura_id', $primo)->where('tipo_riga', 'dare')->orderBy('id')->first();
    unset($riga['id']);
    $riga['scrittura_id'] = $secondo;
    $riga['importo'] = 25000;
    $riga['created_at'] = $riga['updated_at'] = now();
    DB::table('righe_scritture')->insert($riga);
    $this->travelTo('2026-10-30 09:00:00');

    expect($secondo)->toBeGreaterThan($primo)
        ->and(DB::table('righe_scritture')->where('scrittura_id', $primo)->where('tipo_riga', 'dare')->pluck('importo')->map(fn ($i) => (int) $i)->sort()->values()->all())->toBe([20000, 30000]);

    // A mano. Il 05/01 le due rate +50000 +50000 → 100000. Il 15/03 l'incasso, l'importo più alto per primo: −30000 → 70000, −20000 → 50000. Dopo di lui i suoi
    // due storni, uno dopo l'altro: il primo ha due righe in dare, +30000 → 80000 e +20000 → 100000; il secondo, scritto dopo, ha la sua +25000 → 125000.
    // Se le righe si alternassero per importo, la riga di € 250,00 del secondo storno verrebbe prima di quella di € 200,00 del primo (80000, 105000, 125000).
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        ['05/01/2026', null, 50000, 0, 100000],
        ['15/03/2026', null, 0, 30000, 70000],
        ['15/03/2026', null, 0, 20000, 50000],
        ['15/03/2026', null, 30000, 0, 80000],
        ['15/03/2026', null, 20000, 0, 100000],
        ['15/03/2026', null, 25000, 0, 125000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'emissione_rata', 'incasso_rata', 'incasso_rata', 'rettifica', 'rettifica', 'rettifica']);
});

it('Coda 317 — la registrazione è il giorno dell\'amministratore, non quello di Greenwich: un movimento inserito poco dopo la mezzanotte di Roma', function (string $istante, string $dataMovimento, ?string $registrata, bool $soloGiorno) {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo($istante);
    b59eIncassa($s, 1, $dataMovimento, '500.00');
    if ($soloGiorno) {
        // Una colonna DATE di MySQL tiene il solo giorno: l'ora, e con lei il giorno di Roma, non si ricava più dalla registrazione.
        DB::table('scritture_contabili')->update(['data_registrazione' => DB::raw('substr(data_registrazione, 1, 10)')]);
    }
    $this->travelTo('2026-10-30 09:00:00');

    // Il presupposto: il programma scrive la registrazione con il giorno di Greenwich (`now()`, fuso UTC), non quello di Roma.
    $scritta = (string) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->value('data_registrazione');
    expect(substr($scritta, 0, 10))->toBe(substr($istante, 0, 10));

    // A mano (D6): la data di registrazione è il giorno in cui il movimento è stato inserito, nel calendario dell'amministratore (Europe/Rome:
    // UTC+1 in inverno, UTC+2 con l'ora legale); la pagina la scrive solo quando è diversa dalla data del movimento.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, 50000],
        [\Illuminate\Support\Carbon::parse($dataMovimento)->format('d/m/Y'), $registrata, 0, 50000, 0],
    ])
        ->and(b59eCifre($pagina))->toBe(['€ 0,00', '€ 500,00', '€ 500,00'])
        ->and(b59ePdf($s)['testo'])->not->toContain('Registrata il');
})->with(function () {
    $casi = [
        'alle 00:30 di Roma del 16/03 (inverno), con la data di oggi' => ['2026-03-15 23:30:00', '2026-03-16', null],
        'alle 00:30 di Roma del 16/03 (inverno), con la data del giorno prima' => ['2026-03-15 23:30:00', '2026-03-15', '16/03/2026'],
        'alle 00:30 di Roma del 01/07 (ora legale), con la data di oggi' => ['2026-06-30 22:30:00', '2026-07-01', null],
        'alle 00:30 di Roma del 01/07 (ora legale), con la data del giorno prima' => ['2026-06-30 22:30:00', '2026-06-30', '01/07/2026'],
        'alle 23:30 di Roma del 15/03 (inverno), con la data di oggi' => ['2026-03-15 22:30:00', '2026-03-15', null],
        'alle 23:30 di Roma del 30/06 (ora legale), con la data di oggi' => ['2026-06-30 21:30:00', '2026-06-30', null],
    ];
    foreach ([false, true] as $soloGiorno) {
        foreach ($casi as $nome => [$istante, $dataMovimento, $registrata]) {
            yield ($soloGiorno ? 'registrazione di solo giorno, ' : 'registrazione come la scrive il programma, ').$nome => [$istante, $dataMovimento, $registrata, $soloGiorno];
        }
    }
});

it('Coda 317 — una registrazione scritta con un giorno diverso da quello dell\'inserimento si mostra com\'è scritta', function (string $scritta, string $attesa, bool $senzaIstante) {
    $s = b59eScenario($this);

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-03-15 23:30:00');
    $incasso = b59eIncassa($s, 1, '2026-03-15', '500.00');
    DB::table('scritture_contabili')->where('id', $incasso)->update(['data_registrazione' => $scritta] + ($senzaIstante ? ['created_at' => null] : []));
    $this->travelTo('2026-10-30 09:00:00');

    // A mano: la registrazione scritta è un fatto della scrittura (il giorno di Roma di chi la scrive già in quel calendario, o un giorno scelto da chi
    // la registra); la pagina non la sposta e la scrive perché è diversa dalla data del movimento (15/03).
    expect(b59eRighe(b59ePagina($s)['timeline'])[1])->toBe(['15/03/2026', $attesa, 0, 50000, 0]);
})->with([
    'il giorno di Roma, già scritto' => ['2026-03-16', '16/03/2026', false],
    'un giorno dopo' => ['2026-03-20', '20/03/2026', false],
    'un giorno prima' => ['2026-03-10', '10/03/2026', false],
    'senza l\'istante dell\'inserimento' => ['2026-03-20', '20/03/2026', true],
]);

it('Coda 317 — a parità di data le righe di una scrittura seguono l\'importo decrescente: la compensazione chiude prima la quota più alta', function () {
    $s = b59eScenario($this, -60000, 'rata_zero');
    $fornitore = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    $this->travelTo('2026-01-05 10:00:00');
    b59eEmetti($s, [1], '2026-01-05');
    $this->travelTo('2026-03-10 09:00:00');
    b59eIncassa($s, 1, '2026-03-10', '300.00');
    $this->travelTo('2026-03-10 10:00:00');
    b59eEmetti($s, [2], '2026-03-10');
    $this->travelTo('2026-03-10 11:00:00');
    $compensazione = b59eCompensa($s, '2026-03-10', [1 => '200.00', 2 => '400.00', 0 => '-600.00']);
    $this->travelTo('2026-03-10 12:00:00');
    b59eRegolazione($s, $fornitore, '2026-03-10', 16.68);
    $this->travelTo('2026-03-10 13:00:00');
    b59eFattura($s, $fornitore, 'FT-ORDINE', '2026-03-10', 1000.00);
    $this->travelTo('2026-10-30 09:00:00');

    // La compensazione scrive le due quote in avere nell'ordine delle rate (€ 200,00 della rata 1, poi € 400,00 della rata 2): è l'ordine in cui
    // l'amministratore le ha inserite, e non quello che deve uscire.
    expect(DB::table('righe_scritture')->where('scrittura_id', $compensazione)->where('tipo_riga', 'avere')->orderBy('id')->pluck('importo')->map(fn ($i) => (int) $i)->all())->toBe([20000, 40000]);

    // A mano. Saldo iniziale −60000 (il credito riportato). Il 05/01 la rata 1 +50000 → −10000. Il 10/03 prima i dare, per scrittura: la rata 2 +50000
    // → 40000, il prelievo dal credito della compensazione +60000 → 100000. Poi gli avere, per scrittura: l'incasso 30000 → 70000; della compensazione,
    // a parità di scrittura e di lato l'importo decrescente (la regola dell'ordine per scrittura del 20/02/2026, commit `84adbe6a`): 40000 → 30000,
    // poi 20000 → 10000. La regolazione e la fattura del fornitore non entrano. Saldo finale: 50000 + 50000 − 30000 − 60000 + 60000 = 10000.
    $pagina = b59ePagina($s);
    expect(b59eRighe($pagina['timeline']))->toBe([
        ['05/01/2026', null, 50000, 0, -10000],
        ['10/03/2026', null, 50000, 0, 40000],
        ['10/03/2026', null, 60000, 0, 100000],
        ['10/03/2026', null, 0, 30000, 70000],
        ['10/03/2026', null, 0, 40000, 30000],
        ['10/03/2026', null, 0, 20000, 10000],
    ])
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata', 'emissione_rata', 'storno_credito', 'incasso_rata', 'storno_credito', 'storno_credito'])
        ->and($pagina['saldo_finale'])->toBe('€ 100,00');
    $pdf = b59ePdf($s);
    expect(array_map(fn ($r) => (int) $r['saldo'], $pdf['timeline']))->toBe([-10000, 40000, 100000, 70000, 30000, 10000])
        ->and($pdf['saldo_finale'])->toBe('€ 100,00')
        ->and($pdf['testo'])->not->toContain('FT-ORDINE')
        ->and($pdf['testo'])->not->toContain('Rossi Impianti');
});
