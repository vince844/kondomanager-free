<?php

/**
 * B2, S1 — `RisolutoreCompetenza`: la cascata di D3 come funzione pura.
 *
 * D3 riscritta il 16/09/2026 (`docs/subentro_e_competenza_temporale.md`, decisioni 11, 12, 19, 20):
 * la regola è **doppia** e si aggancia alla natura della gestione.
 *
 * - **Straordinaria:** competenza dichiarata sulla fattura o sulla copertura → data della delibera
 *   attuativa come periodo di un giorno → **stop**. Se la delibera manca il motore non scende oltre:
 *   chiede la data, non deduce (decisione 12, «non si tira a indovinare»).
 * - **Ordinaria:** competenza del capitolo (insieme di tratti, decisione 20) → periodo della gestione →
 *   periodo dell'esercizio. La delibera **non conta** (l'approvazione del preventivo non è costitutiva:
 *   Cass. 24654/2010, 24069/2022, 11199/2021), e la competenza dichiarata su una singola fattura del
 *   capitolo **non guida le rate** (decisione 19: il motore ordinario ripartisce il conto, non la fattura).
 *
 * Test puri: il risolutore riceve date e periodi, non modelli. Chi lo chiama con i modelli è S4.
 *
 * Cosa NON coprono: il pro rata nei pesi (S4), la lettura di `competenze_capitolo` (S2), l'interfaccia
 * che chiede la data della delibera (S6). Coprono l'invariante 24 del progetto e la parte «gradino» di
 * D3; l'invariante 5 (puntuale = periodo di un giorno) è qui e in `InsiemePeriodiTest`.
 */

use App\Enums\NaturaGestione;
use App\Services\Riparto\GradinoCompetenza;
use App\Services\Riparto\RisolutoreCompetenza;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;

$esercizio = fn () => new PeriodoCompetenza('2026-01-01', '2026-12-31');
$gestione = fn () => new PeriodoCompetenza('2026-01-01', '2026-12-31');

// --- Straordinaria -------------------------------------------------------------------------------

it('straordinaria: la competenza dichiarata sulla fattura è il primo gradino e prevale sulla delibera', function () {
    $esito = (new RisolutoreCompetenza())->perStraordinaria(
        competenzaDal: '2026-06-01', competenzaAl: '2026-06-30', dataDelibera: '2026-03-01',
    );

    expect($esito->risolto())->toBeTrue()
        ->and($esito->natura)->toBe(NaturaGestione::Straordinaria)
        ->and($esito->gradino)->toBe(GradinoCompetenza::Dichiarata)
        ->and($esito->periodi->toArray())->toBe([['dal' => '2026-06-01', 'al' => '2026-06-30']]);
});

it('straordinaria: senza competenza dichiarata decide la delibera, come periodo lungo un giorno (D2, scenario 1 di Leonardo)', function () {
    $esito = (new RisolutoreCompetenza())->perStraordinaria(competenzaDal: null, competenzaAl: null, dataDelibera: '2026-03-01');

    expect($esito->risolto())->toBeTrue()
        ->and($esito->gradino)->toBe(GradinoCompetenza::Delibera)
        ->and($esito->periodi->giorni())->toBe(1)
        ->and($esito->periodi->dal()->toDateString())->toBe('2026-03-01')
        // Il subentro del 1° maggio non tocca un periodo che finisce il 1° marzo: tutto al venditore.
        ->and($esito->periodi->giorniDiSovrapposizione(new PeriodoCompetenza('2026-05-01', '2026-12-31')))->toBe(0);
});

it('straordinaria: senza competenza e senza delibera il risolutore si ferma e lo dice — non scende alla gestione, che darebbe un pro rata per giorni (decisione 12)', function () {
    $esito = (new RisolutoreCompetenza())->perStraordinaria(competenzaDal: null, competenzaAl: null, dataDelibera: null);

    expect($esito->risolto())->toBeFalse()
        ->and($esito->richiedeDelibera)->toBeTrue()
        ->and($esito->periodi)->toBeNull()
        ->and($esito->gradino)->toBeNull();
});

it('straordinaria: una competenza dichiarata a metà (solo dal, o solo al) non è dichiarata: si comporta come assente', function () {
    $r = new RisolutoreCompetenza();

    expect($r->perStraordinaria('2026-06-01', null, '2026-03-01')->gradino)->toBe(GradinoCompetenza::Delibera)
        ->and($r->perStraordinaria(null, '2026-06-30', null)->richiedeDelibera)->toBeTrue();
});

// --- Ordinaria -----------------------------------------------------------------------------------

it('ordinaria: la competenza del capitolo è un insieme di tratti e prevale su gestione ed esercizio (decisione 20)', function () use ($gestione, $esercizio) {
    $stagione = new InsiemePeriodi(new PeriodoCompetenza('2026-01-01', '2026-04-15'), new PeriodoCompetenza('2026-10-15', '2026-12-31'));

    $esito = (new RisolutoreCompetenza())->perOrdinaria(capitolo: $stagione, gestione: $gestione(), esercizio: $esercizio());

    expect($esito->natura)->toBe(NaturaGestione::Ordinaria)
        ->and($esito->gradino)->toBe(GradinoCompetenza::Capitolo)
        ->and($esito->periodi->giorni())->toBe(183)
        ->and(count($esito->periodi))->toBe(2);
});

it('ordinaria: senza competenza sul capitolo vale il periodo della gestione, e senza gestione chiusa quello dell\'esercizio', function () use ($gestione, $esercizio) {
    $r = new RisolutoreCompetenza();

    $conGestione = $r->perOrdinaria(capitolo: null, gestione: new PeriodoCompetenza('2026-01-01', '2026-06-30'), esercizio: $esercizio());
    $senzaGestione = $r->perOrdinaria(capitolo: null, gestione: null, esercizio: $esercizio());

    expect($conGestione->gradino)->toBe(GradinoCompetenza::Gestione)
        ->and($conGestione->periodi->giorni())->toBe(181)
        ->and($senzaGestione->gradino)->toBe(GradinoCompetenza::Esercizio)
        ->and($senzaGestione->periodi->giorni())->toBe(365);
});

it('invariante 24: sull\'ordinaria la delibera NON è un gradino — un preventivo approvato il 15 gennaio con rogito il 30 giugno divide l\'esercizio per giorni, non lo attribuisce tutto al venditore', function () use ($gestione, $esercizio) {
    // La firma di perOrdinaria non accetta una data di delibera: è il modo più forte di dirlo.
    $esito = (new RisolutoreCompetenza())->perOrdinaria(capitolo: null, gestione: $gestione(), esercizio: $esercizio());

    $venditore = new PeriodoCompetenza('2026-01-01', '2026-06-30');
    $acquirente = new PeriodoCompetenza('2026-07-01', '2026-12-31');

    expect($esito->periodi->giorniDiSovrapposizione($venditore))->toBe(181)
        ->and($esito->periodi->giorniDiSovrapposizione($acquirente))->toBe(184)
        ->and(collect((new ReflectionMethod(RisolutoreCompetenza::class, 'perOrdinaria'))->getParameters())->map->getName()->all())
            ->not->toContain('dataDelibera');
});

it('decisione 19: la competenza dichiarata su una fattura di un capitolo ordinario si accetta e non guida le rate — il gradino resta quello del capitolo/gestione/esercizio', function () use ($gestione, $esercizio) {
    $esito = (new RisolutoreCompetenza())->perOrdinaria(
        capitolo: null, gestione: $gestione(), esercizio: $esercizio(),
        competenzaFattura: new PeriodoCompetenza('2026-03-01', '2026-03-31'),
    );

    expect($esito->gradino)->toBe(GradinoCompetenza::Gestione)
        ->and($esito->periodi->giorni())->toBe(365)
        ->and($esito->competenzaFatturaIgnorata)->toBeTrue();
});

it('la divergenza fra piani_rate.tipo e gestioni.tipo si dichiara nell\'esito, non blocca (decisione 11): fa fede la natura della gestione', function () use ($gestione, $esercizio) {
    $esito = (new RisolutoreCompetenza())->perOrdinaria(capitolo: null, gestione: $gestione(), esercizio: $esercizio(), divergenzaTipoPiano: true);

    expect($esito->risolto())->toBeTrue()
        ->and($esito->natura)->toBe(NaturaGestione::Ordinaria)
        ->and($esito->divergenzaTipoPiano)->toBeTrue();
});

it('NaturaGestione::daStringa() legge gestioni.tipo con la stessa regola di catenaSaldoSolidale(): tutto ciò che non è «straordinaria» è ordinaria', function () {
    expect(NaturaGestione::daStringa('straordinaria'))->toBe(NaturaGestione::Straordinaria)
        ->and(NaturaGestione::daStringa('ordinaria'))->toBe(NaturaGestione::Ordinaria)
        ->and(NaturaGestione::daStringa(null))->toBe(NaturaGestione::Ordinaria)
        ->and(NaturaGestione::daStringa('qualunque'))->toBe(NaturaGestione::Ordinaria);
});

it('l\'esito porta il gradino come stringa stabile, quella che finirà in righe_riparto.gradino_competenza (decisione 15)', function () {
    expect(array_map(fn ($c) => $c->value, GradinoCompetenza::cases()))
        ->toBe(['dichiarata', 'delibera', 'capitolo', 'gestione', 'esercizio']);
});
