<?php

/**
 * Coda 419 — la ricorrenza avanzata del piano scadenze («Abilita regole di ricorrenza avanzate» di `PianiRateNew.vue`).
 *
 * Cosa resta scoperto: le regole salvate da altre strade che il form (nessuna rotta ne scrive una diversa: `PianoRateCreatorService::creaRicorrenza`
 * è l'unico scrittore di `ricorrenze_rate` in `app/`); i fusi orari diversi da Europe/Rome; MySQL.
 *
 * La regola delle date: il form scrive la ricorrenza in `creaRicorrenza` — mensile senza giorni della settimana con `BYMONTHDAY` uguale al
 * «Giorno del mese» (l'ultimo del mese da 29 in su), mensile con giorni della settimana con `BYDAY` e `BYSETPOS=1`, settimanale con `BYDAY` —
 * e `GenerateDateRateAction` prende le prime `numero_rate` date della regola dalla partenza del piano (`PianoRate::dataPartenzaCalendario`:
 * la prima scadenza scelta o l'inizio della gestione). Questi test non hanno una prima scadenza scelta, e le date partono dall'inizio della
 * gestione; con una prima scadenza scelta la scelta va in testa e, nella mensile col giorno del mese, prende il posto della rata del suo mese
 * (`Beta59PianoRicorrenzaPrimaScadenzaTest.php`). Le date attese qui sotto si rifanno da quella regola e dal calendario del 2026
 * (il 01/01/2026 è un giovedì), non da ciò che il programma restituisce.
 *
 * Il ramo della ricorrenza assegnava il giorno con `$date->day = …` su `Carbon::instance(...)`, un'assegnazione che con Carbon 3 non ha
 * effetto (crea una proprietà dinamica, che PHP 8.4 segnala come deprecata): toglierla non cambia nessuna data. I test che cominciano con
 * «controprova» sono verdi prima e dopo; il loro compito è fermare una correzione che metta il giorno del mese su ogni frequenza.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59PianoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

it('Coda 419 — la ricorrenza mensile ogni 2 mesi con il giorno del mese 5, creata dalla pagina, scade il 5 di gennaio, marzo, maggio, luglio, settembre e novembre', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 6, 'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2']);

    expect(DB::table('ricorrenze_rate')->where('piano_rate_id', $piano->id)->value('rrule'))->toContain('BYMONTHDAY=5');
    expect(b59pScadenze($piano))->toBe(['2026-01-05', '2026-03-05', '2026-05-05', '2026-07-05', '2026-09-05', '2026-11-05']);
});

it('Coda 419 — la ricorrenza mensile ogni mese con il giorno del mese 5 scade il 5 di ogni mese', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    expect(b59pScadenze($piano))->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
});

it('Coda 419 — la ricorrenza mensile con il giorno del mese 31 scade l\'ultimo giorno di ogni mese, febbraio compreso', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'giorno_scadenza' => 31, 'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1']);

    // `creaRicorrenza` scrive l'ultimo del mese da 29 in su: con 31 è anche ciò che dà il ramo predefinito, min(31, giorni del mese).
    expect(b59pScadenze($piano))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('Coda 419 — controprova: la ricorrenza settimanale il giovedì cade ogni giovedì, non sul giorno del mese', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'recurrence_enabled' => true, 'recurrence_frequency' => 'WEEKLY', 'recurrence_interval' => '1', 'recurrence_by_day' => ['TH']]);

    expect(b59pScadenze($piano))->toBe(['2026-01-01', '2026-01-08', '2026-01-15', '2026-01-22']);
});

it('Coda 419 — controprova: la ricorrenza settimanale senza giorni scelti cade ogni sette giorni dalla partenza', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'recurrence_enabled' => true, 'recurrence_frequency' => 'WEEKLY', 'recurrence_interval' => '2']);

    expect(b59pScadenze($piano))->toBe(['2026-01-01', '2026-01-15', '2026-01-29']);
});

it('Coda 419 — controprova: la ricorrenza giornaliera ogni 10 giorni (la rotta la accetta, la pagina non la offre) cade ogni 10 giorni', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'recurrence_enabled' => true, 'recurrence_frequency' => 'DAILY', 'recurrence_interval' => '10']);

    expect(b59pScadenze($piano))->toBe(['2026-01-01', '2026-01-11', '2026-01-21', '2026-01-31']);
});

it('Coda 419 — controprova: la ricorrenza annuale cade nel giorno della partenza di ogni anno', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'recurrence_enabled' => true, 'recurrence_frequency' => 'YEARLY', 'recurrence_interval' => '1']);

    expect(b59pScadenze($piano))->toBe(['2026-01-01', '2027-01-01', '2028-01-01']);
});

it('Coda 419 — controprova: la ricorrenza mensile con il lunedì scelto cade il primo lunedì di ogni mese', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '1', 'recurrence_by_day' => ['MO']]);

    // il 05/01, il 02/02, il 02/03 e il 06/04/2026 sono i primi lunedì dei loro mesi
    expect(b59pScadenze($piano))->toBe(['2026-01-05', '2026-02-02', '2026-03-02', '2026-04-06']);
});

it('Coda 419 — controprova: la ricorrenza mensile ogni 2 mesi con tutti i giorni della settimana scelti cade il primo di gennaio, marzo, maggio e luglio', function () {
    $s = b59pScenarioBase($this);

    // «Forza in giorni specifici» con i sette giorni: la regola è il primo dei giorni scelti nel mese (`creaRicorrenza`, `BYSETPOS=1`), cioè il
    // primo del mese; la pagina nasconde il «Giorno del mese» quando c'è un giorno scelto, ma il form lo manda lo stesso (5).
    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 4, 'recurrence_enabled' => true, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => '2',
        'recurrence_by_day' => ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']]);

    expect(b59pScadenze($piano))->toBe(['2026-01-01', '2026-03-01', '2026-05-01', '2026-07-01']);
});

it('Coda 419 — controprova: senza la ricorrenza il ramo mensile resta com\'è, prima scadenza scelta compresa', function () {
    $s = b59pScenarioBase($this);

    $piano = b59pCreaDallaPagina($this, $s, ['numero_rate' => 3, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-01-20']);

    // la prima rata nella data scelta, con il suo giorno; dalla seconda il giorno del mese (`GenerateDateRateAction::defaultMonthly`)
    expect(b59pScadenze($piano))->toBe(['2026-01-20', '2026-02-10', '2026-03-10']);
});
