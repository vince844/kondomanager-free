<?php

/**
 * Coda 419 — la prima scadenza scelta con la ricorrenza avanzata del piano scadenze («Abilita regole di ricorrenza avanzate» di
 * `PianiRateNew.vue`, con la data della prima scadenza compilata).
 *
 * La regola (decisione 153, scelta 6): con una prima scadenza scelta la prima rata è quella data, come nel ramo mensile
 * (`GenerateDateRateAction::defaultMonthly`); le successive sono le date della regola dopo di lei, fino a `numero_rate` rate in tutto. Se
 * la data scelta è anche una data della regola, la rata non si raddoppia. Con la mensile nel giorno del mese la data scelta prende il posto
 * della rata del suo mese (decisione 159, scelta 3): scelta 03/01 con il giorno 5 → 03/01, 05/02, 05/03, una rata per mese come nel ramo
 * mensile. Senza una data scelta le date restano quelle della regola dalla data d'inizio della gestione (`Beta59PianoRicorrenzaTest`).
 *
 * Le date attese si rifanno dalla regola che `PianoRateCreatorService::creaRicorrenza` scrive (mensile con `BYMONTHDAY` uguale al «Giorno del
 * mese», mensile con giorni scelti con `BYDAY` e `BYSETPOS=1`, settimanale con `BYDAY`) e dal calendario del 2026 (il 01/01/2026 è un
 * giovedì, il 20/01/2026 un martedì, il 22/01/2026 un giovedì): la libreria delle ricorrenze non emette le date che precedono la partenza.
 * I test che cominciano con «controprova» sono verdi anche senza la regola nuova: fermano un raddoppio della data scelta.
 *
 * Cosa resta scoperto: con la settimanale nei giorni scelti e con la mensile nei giorni della settimana scelti la data scelta non prende il
 * posto di niente (settimanale il giovedì, scelta martedì 20/01: 20/01, 22/01, 29/01; primo lunedì, scelta venerdì 02/01: 02/01, 05/01,
 * 02/02), e non è deciso se debba; il giorno del mese 29 e 30, che la regola porta all'ultimo del mese; `recurrence_until`; le regole salvate
 * da strade diverse dal form; i fusi orari diversi da Europe/Rome; MySQL.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59PianoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

it('Coda 419 — con la prima scadenza scelta il 20/01 la ricorrenza ogni 2 mesi con il giorno del mese 5 scade il 20/01, il 05/03, il 05/05 e il 05/07', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    // il 05/01 precede la data scelta e la regola non lo emette; la prima rata è la data scelta, le altre tre sono le date della regola dopo di lei
    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-03-05', '2026-05-05', '2026-07-05']);
});

it('Coda 419 — con la prima scadenza scelta il 20/01 la ricorrenza ogni mese con il giorno del mese 5 scade il 20/01, il 05/02, il 05/03 e il 05/04', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-02-05', '2026-03-05', '2026-04-05']);
});

it('Coda 419 — con una sola rata e la prima scadenza scelta la rata è quella data', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 1, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    expect(b59pScadenze($piano))->toBe(['2026-01-20']);
});

it('Coda 419 — controprova: se la prima scadenza scelta è una data della regola (il 05/01 con il giorno del mese 5) la rata non si raddoppia', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-05',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    expect(b59pScadenze($piano))->toBe(['2026-01-05', '2026-03-05', '2026-05-05', '2026-07-05']);
});

it('Coda 419 — il ricalcolo di un piano generato con le date di prima le rifà dalla data scelta, e le quote portano la scadenza della loro rata', function () {
    $s = b59pScenarioBase($this);
    $attese = ['2026-01-20', '2026-03-05', '2026-05-05', '2026-07-05'];

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    // Un piano generato prima di questa correzione ha scritto sulle rate le date della regola dopo la data scelta (la prima rata il 05/03).
    $prima = ['2026-03-05', '2026-05-05', '2026-07-05', '2026-09-05'];
    foreach ($prima as $i => $data) {
        $rata = DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', $i + 1)->value('id');
        DB::table('rate')->where('id', $rata)->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => $data]);
    }
    expect(b59pScadenze($piano))->toBe($prima);

    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors();

    expect(b59pScadenze($piano))->toBe($attese);

    // `rate_quote.data_scadenza` è quella che la schermata di incasso ordina e mostra: la stessa della rata, per ogni rata
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate.numero_rata', '>', 0)
        ->orderBy('rate.numero_rata')->select('rate.numero_rata', 'rate_quote.data_scadenza')->get()
        ->groupBy('numero_rata')->map(fn ($righe) => $righe->pluck('data_scadenza')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());
    expect($quote->values()->all())->toBe(array_map(fn ($d) => [$d], $attese));
});

it('Coda 419 — controprova: la ricorrenza settimanale il giovedì con la prima scadenza scelta in un giovedì (il 22/01) non raddoppia la data', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-22',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'WEEKLY', 'recurrence_interval' => '1', 'recurrence_by_day' => ['TH']]);

    expect(b59pScadenze($piano))->toBe(['2026-01-22', '2026-01-29', '2026-02-05', '2026-02-12']);
});

it('Coda 419 — la ricorrenza settimanale il giovedì con la prima scadenza scelta in un martedì (il 20/01) scade il 20/01 e poi ogni giovedì', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'WEEKLY', 'recurrence_interval' => '1', 'recurrence_by_day' => ['TH']]);

    // la prima rata è la data scelta (un martedì); la regola dà i giovedì dopo di lei: 22/01, 29/01, 05/02
    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-01-22', '2026-01-29', '2026-02-05']);
});

it('Coda 419 — controprova: la ricorrenza settimanale senza giorni scelti con la prima scadenza scelta il 20/01 parte dal 20/01 senza raddoppiarlo', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'WEEKLY', 'recurrence_interval' => '2']);

    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-02-03', '2026-02-17']);
});

it('Coda 419 — controprova: la ricorrenza giornaliera ogni 10 giorni con la prima scadenza scelta il 20/01 parte dal 20/01 senza raddoppiarlo', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'DAILY', 'recurrence_interval' => '10']);

    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-01-30', '2026-02-09', '2026-02-19']);
});

it('Coda 419 — la ricorrenza mensile con il lunedì scelto e la prima scadenza scelta il 20/01 scade il 20/01 e poi il primo lunedì di ogni mese', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1', 'recurrence_by_day' => ['MO']]);

    // il primo lunedì di gennaio (05/01) precede la data scelta; il 02/02, il 02/03 e il 06/04/2026 sono i primi lunedì dei loro mesi
    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-02-02', '2026-03-02', '2026-04-06']);
});

it('Coda 419 — controprova: senza la prima scadenza scelta la ricorrenza parte dall\'inizio della gestione e la prima rata non è la data d\'inizio', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'data_prima_scadenza' => null,
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    // la gestione comincia il 01/01/2026: nessuna data scelta, nessuna rata aggiunta in testa
    expect(b59pScadenze($piano))->toBe(['2026-01-05', '2026-03-05', '2026-05-05']);
});

// ── La data scelta prende il posto della rata del suo mese (decisione 159, scelta 3: la 153.6 «come nel ramo mensile») ─────────────────
//
// Con la frequenza mensile nel giorno del mese il ramo senza ricorrenza (`defaultMonthly`) dà una rata per mese: la data scelta e poi il giorno
// del mese dal mese dopo. La ricorrenza fa lo stesso: la data della regola nello stesso mese della scelta non si aggiunge, la scelta la sostituisce.

it('Coda 419 — con la prima scadenza scelta il 03/01 la ricorrenza ogni mese con il giorno del mese 5 scade il 03/01, il 05/02, il 05/03 e il 05/04', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-03',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    // il 05/01 della regola è nello stesso mese della data scelta: la scelta ne prende il posto, non si aggiunge
    expect(b59pScadenze($piano))->toBe(['2026-01-03', '2026-02-05', '2026-03-05', '2026-04-05']);
});

it('Coda 419 — con la prima scadenza scelta il 03/01 la ricorrenza ogni mese dà le stesse date del piano senza ricorrenza', function () {
    // due condomini uguali: lo stesso piano con la ricorrenza nel primo e senza nel secondo
    $s = b59pScenarioBase($this);
    $s2 = b59pScenarioBase($this);

    $conRegola = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-03',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);
    $senza = b59pCreaDallaPagina($this, $s2, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-03']);

    // il ramo senza ricorrenza: la data scelta, poi il 5 dal mese dopo (`GenerateDateRateAction::defaultMonthly`)
    expect(b59pScadenze($senza))->toBe(['2026-01-03', '2026-02-05', '2026-03-05', '2026-04-05']);
    expect(b59pScadenze($conRegola))->toBe(b59pScadenze($senza));
});

it('Coda 419 — con la prima scadenza scelta il 03/01 la ricorrenza ogni 2 mesi con il giorno del mese 5 scade il 03/01, il 05/03, il 05/05 e il 05/07', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-03',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    // la regola conta i mesi dalla data scelta (gennaio, marzo, maggio, luglio); il 05/01 lo sostituisce la scelta
    expect(b59pScadenze($piano))->toBe(['2026-01-03', '2026-03-05', '2026-05-05', '2026-07-05']);
});

it('Coda 419 — con la prima scadenza scelta il 03/01 la ricorrenza ogni 12 mesi toglie la sola rata di gennaio 2026, non quelle di gennaio degli anni dopo', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'data_prima_scadenza' => '2026-01-03',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '12']);

    expect(b59pScadenze($piano))->toBe(['2026-01-03', '2027-01-05', '2028-01-05']);
});

it('Coda 419 — con la prima scadenza scelta il 20/01 e il giorno del mese 31 la ricorrenza mensile scade il 20/01, il 28/02, il 31/03 e il 30/04', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2026-01-20',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    // il 31/01 della regola è nello stesso mese della data scelta; poi l'ultimo del mese, come min(31, giorni del mese) del ramo senza ricorrenza
    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('Coda 419 — con la prima scadenza scelta il 10/02 e il giorno del mese 31 la ricorrenza mensile scade il 10/02, il 31/03 e il 30/04', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2026-02-10',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    // il 28/02 (l'ultimo di febbraio 2026) è nello stesso mese della data scelta: la scelta lo sostituisce
    expect(b59pScadenze($piano))->toBe(['2026-02-10', '2026-03-31', '2026-04-30']);
});

it('Coda 419 — il ricalcolo di un piano con la prima scadenza scelta il 03/01, generato con le date di prima, dà una rata per mese', function () {
    $s = b59pScenarioBase($this);
    $attese = ['2026-01-03', '2026-02-05', '2026-03-05', '2026-04-05'];

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'data_prima_scadenza' => '2026-01-03',
        'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    // Un piano generato prima della 1.11.0-beta.59 ha scritto sulle rate le date della regola dalla data scelta, senza la data scelta.
    $prima = ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'];
    foreach ($prima as $i => $data) {
        $rata = DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', $i + 1)->value('id');
        DB::table('rate')->where('id', $rata)->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => $data]);
    }
    expect(b59pScadenze($piano))->toBe($prima);

    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors();

    expect(b59pScadenze($piano))->toBe($attese);

    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate.numero_rata', '>', 0)
        ->orderBy('rate.numero_rata')->select('rate.numero_rata', 'rate_quote.data_scadenza')->get()
        ->groupBy('numero_rata')->map(fn ($righe) => $righe->pluck('data_scadenza')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());
    expect($quote->values()->all())->toBe(array_map(fn ($d) => [$d], $attese));
});
