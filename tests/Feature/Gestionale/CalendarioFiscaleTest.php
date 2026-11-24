<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le date delle scadenze F24, e il loro slittamento.
 *
 * Difetto §8 punto 5 del design: il calcolo dentro `SyncF24WithPagamento` gestiva solo
 * `isWeekend()`, quindi proponeva come valida una scadenza che cade in giorno festivo.
 *
 * La regola sta nella circolare 9/E del 02/05/2024, ripresa in `f24_dossier_normativo.md`:
 * se il termine del 16 cade di sabato o in giorno festivo, slitta al **primo giorno
 * lavorativo successivo**.
 *
 * Le date di questi test sono verificabili su un calendario: sono scelte apposta perché un
 * errore nell'algoritmo di Pasqua o nello slittamento le faccia cadere.
 */

use App\Services\Gestionale\CalendarioFiscaleService;
use Carbon\CarbonImmutable;

function calendario(): CalendarioFiscaleService
{
    return new CalendarioFiscaleService;
}

// ════════════════════════════════════════════════════════════════════════════
// Pasqua — l'unica festività mobile che tocca queste scadenze
// ════════════════════════════════════════════════════════════════════════════

test('la domenica di Pasqua è calcolata correttamente', function (int $anno, string $atteso) {
    expect(calendario()->pasqua($anno)->format('Y-m-d'))->toEqual($atteso);
})->with([
    [2024, '2024-03-31'],
    [2025, '2025-04-20'],
    [2026, '2026-04-05'],
    [2027, '2027-03-28'],
    [2038, '2038-04-25'],   // la Pasqua più tarda del secolo
]);

test('il lunedì dell Angelo è il giorno dopo Pasqua', function () {
    expect(calendario()->lunediDellAngelo(2025)->format('Y-m-d'))->toEqual('2025-04-21');
});

// ════════════════════════════════════════════════════════════════════════════
// Giorni lavorativi
// ════════════════════════════════════════════════════════════════════════════

test('Ferragosto non è un giorno lavorativo', function () {
    expect(calendario()->isGiornoLavorativo(CarbonImmutable::parse('2025-08-15')))->toBeFalse();
});

test('il lunedì dell Angelo non è un giorno lavorativo', function () {
    // 21 aprile 2025 è un lunedì: senza il calendario passerebbe per feriale.
    $giorno = CarbonImmutable::parse('2025-04-21');

    expect($giorno->isWeekend())->toBeFalse()
        ->and(calendario()->isGiornoLavorativo($giorno))->toBeFalse();
});

test('un martedì qualunque è lavorativo', function () {
    expect(calendario()->isGiornoLavorativo(CarbonImmutable::parse('2026-08-04')))->toBeTrue();
});

// ════════════════════════════════════════════════════════════════════════════
// Scadenza del versamento
// ════════════════════════════════════════════════════════════════════════════

test('Coda 717 — un pagamento di luglio scade il 20 agosto: le scadenze dal 1° al 20 agosto valgono fino al 20', function () {
    // Art. 37, c. 11-bis, D.L. 223/2006 (dal 2027 art. 11 D.Lgs. 33/2025). Il 16/08/2026 è domenica: prima del 20 agosto il
    // programma diceva lunedì 17/08/2026, e dal 18 la delega diceva «Scaduta da 1 giorno». Il 20/08/2026 è giovedì.
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-07-10'))->format('Y-m-d'))
        ->toEqual('2026-08-20');
});

test('il 16 che cade di sabato slitta al lunedì', function () {
    // 16 maggio 2026 è un sabato → lunedì 18.
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-04-20'))->format('Y-m-d'))
        ->toEqual('2026-05-18');
});

test('il 16 feriale non si muove', function () {
    // 16 giugno 2026 è un martedì.
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-05-12'))->format('Y-m-d'))
        ->toEqual('2026-06-16');
});

/**
 * Il caso che il vecchio codice sbagliava in pieno: il 16 cade di domenica e il lunedì
 * successivo è il lunedì dell'Angelo. `next('Monday')` si fermava lì e proponeva una
 * scadenza in giorno festivo.
 */
test('se il lunedì su cui si slitta è il lunedì dell Angelo, si slitta ancora', function () {
    // 2038: Pasqua il 25 aprile, quindi lunedì dell'Angelo il 26.
    // Ma serve un anno in cui il 16 cada di domenica e il lunedì sia festivo.
    // Costruiamo il caso direttamente sul metodo di slittamento.
    $domenicaDiPasqua = calendario()->pasqua(2025);           // 2025-04-20, domenica
    $slittata = calendario()->primoGiornoLavorativo($domenicaDiPasqua);

    // Domenica 20 → lunedì 21 è il lunedì dell'Angelo → martedì 22.
    expect($slittata->format('Y-m-d'))->toEqual('2025-04-22');
});

test('un 16 che è esso stesso il lunedì dell Angelo slitta al martedì', function () {
    // Pasqua 2049 cade il 18 aprile? Verifichiamo invece un caso costruito:
    // cerchiamo un anno in cui il lunedì dell'Angelo cada il 16.
    $anno = collect(range(2024, 2100))->first(
        fn ($a) => calendario()->lunediDellAngelo($a)->day === 16
    );

    expect($anno)->not->toBeNull('Nessun anno con lunedì dell Angelo il 16: rivedere il test.');

    $sedici = calendario()->lunediDellAngelo($anno);
    $slittata = calendario()->primoGiornoLavorativo($sedici);

    expect($slittata->format('Y-m-d'))->toEqual($sedici->addDay()->format('Y-m-d'));
});

test('il primo giorno lavorativo di una data già lavorativa è la data stessa', function () {
    $giorno = CarbonImmutable::parse('2026-06-16');

    expect(calendario()->primoGiornoLavorativo($giorno)->format('Y-m-d'))->toEqual('2026-06-16');
});

test('una scadenza a gennaio non salta l anno nel calcolo delle festività', function () {
    // Pagamento a dicembre → scadenza 16 gennaio dell'anno dopo.
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-12-20'))->format('Y-m-d'))
        ->toEqual('2027-01-18');   // 16 gennaio 2027 è sabato → lunedì 18
});

test('il 31 gennaio non scavalca febbraio', function () {
    // addMonth() puro porterebbe al 3 marzo prima di applicare day(16).
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-01-31'))->format('Y-m'))
        ->toEqual('2026-02');
});

// ════════════════════════════════════════════════════════════════════════════
// Coda 717 (decisione 571; forum t=202, casi D ed E di Edoardo): il 20 agosto
// ════════════════════════════════════════════════════════════════════════════

/** Le deleghe di un elenco di ritenute, con le regole di `PlafondRitenuteService`: data, importo in centesimi, regime. */
function calendarioDeleghe(array $righe): array
{
    $servizio = new \App\Services\Gestionale\PlafondRitenuteService(new CalendarioFiscaleService);

    return $servizio->raggruppaPerScadenza(array_map(fn ($r) => ['data_pagamento' => $r[0], 'importo' => $r[1], 'tipo_ritenuta' => $r[2]], $righe));
}

test('Coda 717, caso D — 20 %: € 200,00 il 10/07 superano la soglia a luglio e si versano il 20/08/2026, non il 17/08', function () {
    $deleghe = calendarioDeleghe([['2026-07-10', 20_000, \App\Enums\Fiscale\TipoRitenuta::LAVORO_AUTONOMO_20]]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-08-20')
        ->and($deleghe[0]['importo'])->toEqual(20_000);
});

test('Coda 717, caso E — appalti: € 600,00 il 10/07 si versano il 20/08/2026', function () {
    $deleghe = calendarioDeleghe([['2026-07-10', 60_000, \App\Enums\Fiscale\TipoRitenuta::APPALTO_4]]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-08-20');
});

test('Coda 717 — il 20/08/2028 è domenica: la scadenza di luglio 2028 slitta a lunedì 21/08/2028', function () {
    expect(CarbonImmutable::parse('2028-08-20')->isSunday())->toBeTrue()
        ->and(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2028-07-10'))->format('Y-m-d'))->toEqual('2028-08-21')
        ->and(calendarioDeleghe([['2028-07-10', 60_000, \App\Enums\Fiscale\TipoRitenuta::APPALTO_4]])[0]['scadenza']->format('Y-m-d'))->toEqual('2028-08-21');
});

test('Coda 717 — la regola di agosto vale dal 1° al 20: il 1° agosto diventa il 20, il 21 resta il 21, le altre date non si muovono', function () {
    expect(calendario()->scadenzaFiscale(CarbonImmutable::parse('2026-08-01'))->format('Y-m-d'))->toEqual('2026-08-20')
        ->and(calendario()->scadenzaFiscale(CarbonImmutable::parse('2026-08-20'))->format('Y-m-d'))->toEqual('2026-08-20')
        ->and(calendario()->scadenzaFiscale(CarbonImmutable::parse('2026-08-21'))->format('Y-m-d'))->toEqual('2026-08-21')
        // fuori da agosto solo lo slittamento: il 16/05/2026 è sabato → lunedì 18/05/2026
        ->and(calendario()->scadenzaFiscale(CarbonImmutable::parse('2026-05-16'))->format('Y-m-d'))->toEqual('2026-05-18')
        ->and(calendario()->scadenzaFiscale(CarbonImmutable::parse('2026-06-16'))->format('Y-m-d'))->toEqual('2026-06-16');
});

test('Coda 717 — il 20 agosto è solo delle scadenze fiscali: il primo giorno lavorativo delle assemblee resta quello del calendario', function () {
    // `primoGiornoLavorativo()` serve anche i termini delle assemblee (convocazione, impugnazione): lì agosto non sposta niente.
    expect(calendario()->primoGiornoLavorativo(CarbonImmutable::parse('2026-08-16'))->format('Y-m-d'))->toEqual('2026-08-17')
        ->and(calendario()->primoGiornoLavorativo(CarbonImmutable::parse('2026-08-04'))->format('Y-m-d'))->toEqual('2026-08-04');
});
