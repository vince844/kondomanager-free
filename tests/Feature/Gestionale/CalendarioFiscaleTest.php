<?php

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

test('un pagamento di luglio scade il 16 agosto, se è feriale', function () {
    // 16 agosto 2026 è una domenica → slitta a lunedì 17.
    expect(calendario()->scadenzaVersamentoRitenute(CarbonImmutable::parse('2026-07-10'))->format('Y-m-d'))
        ->toEqual('2026-08-17');
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
