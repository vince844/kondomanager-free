<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Quando va versata una ritenuta, e con quali altre finisce nella stessa delega F24.
 *
 * Le regole sono tre e **dipendono dal regime**: confonderle produce versamenti tardivi
 * (sanzionabili) o anticipati. Vengono da `f24_dossier_normativo.md`, che a sua volta cita
 * la circolare 9/E del 02/05/2024:
 *
 *  - **appalti 4%**: soglia 500 €, e comunque il 16 giugno e il 16 dicembre;
 *  - **lavoro autonomo 20%**: soglia 100 €, e comunque il 16 dicembre — **una sola** data
 *    limite annuale, e un accumulatore **separato** da quello del 4%;
 *  - in entrambi i casi le ritenute di **dicembre** vanno entro il **16 gennaio**.
 *
 * Il dossier avverte anche di non implementare le date 30/6 e 20/12, che circolano in fonti
 * divulgative — nessun test qui le produce, ed è voluto.
 */

use App\Enums\Fiscale\PlafondRitenuta;
use App\Enums\Fiscale\TipoRitenuta;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PlafondRitenuteService;

function plafondService(): PlafondRitenuteService
{
    return new PlafondRitenuteService(new CalendarioFiscaleService);
}

/** Una ritenuta operata: data del pagamento, importo in centesimi, regime. */
function ritenuta(string $data, int $centesimi, TipoRitenuta $tipo = TipoRitenuta::APPALTO_4): array
{
    return ['data_pagamento' => $data, 'importo' => $centesimi, 'tipo_ritenuta' => $tipo];
}

// ════════════════════════════════════════════════════════════════════════════
// Appalti 4% — soglia 500 €
// ════════════════════════════════════════════════════════════════════════════

test('sopra i 500 € si versa il 16 del mese successivo', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 60_000),   // 600 €
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['importo'])->toEqual(60_000)
        ->and($deleghe[0]['motivo'])->toEqual('soglia_raggiunta');
});

test('più ritenute piccole si cumulano fino alla soglia', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-01-10', 20_000),
        ritenuta('2026-02-10', 20_000),
        ritenuta('2026-03-10', 20_000),   // qui il cumulo tocca 600 €
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['importo'])->toEqual(60_000)
        ->and($deleghe[0]['ritenute'])->toHaveCount(3);
});

test('sotto soglia a fine maggio si versa comunque il 16 giugno', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-02-10', 10_000),   // 100 €
        ritenuta('2026-04-10', 5_000),    //  50 € — cumulo 150 €, sotto i 500
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-06-16')
        ->and($deleghe[0]['importo'])->toEqual(15_000)
        ->and($deleghe[0]['motivo'])->toEqual('termine_di_legge');
});

test('sotto soglia nella seconda finestra si versa il 16 dicembre, non il 16 ottobre', function () {
    // È il caso che la vecchia annotazione dell'enum avrebbe sbagliato: dichiarava le
    // finestre «giu-set / ott-dic», che avrebbero chiuso qui al 16 ottobre.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-07-10', 8_000),
        ritenuta('2026-09-10', 8_000),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-12-16');
});

test('una ritenuta di marzo e una di settembre non stanno nella stessa delega', function () {
    // In mezzo c'è il 16 giugno: la prima matura lì, la seconda dopo.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 5_000),
        ritenuta('2026-09-10', 5_000),
    ]);

    expect($deleghe)->toHaveCount(2)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-06-16')
        ->and($deleghe[1]['scadenza']->format('Y-m-d'))->toEqual('2026-12-16');
});

/**
 * Il caso che il test qui sopra NON copre, e che è costato una beta.
 *
 * Là la seconda ritenuta è di settembre, cioè **dopo** il 16 giugno: il gruppo si chiudeva
 * perché la data della ritenuta successiva superava la data-limite. Ma le ritenute operate
 * fra il **1° e il 15 giugno** cadono *prima* del 16, e appartengono comunque alla finestra
 * successiva — il 16 giugno chiude ciò che è stato operato fino a maggio.
 *
 * Con il vecchio confronto, una ritenuta del 10 giugno faceva da tappo: quelle di
 * gennaio-maggio le restavano attaccate e finivano al 16 dicembre, cioè **versate in ritardo
 * di sei mesi**, con sanzione.
 */
test('una ritenuta di marzo e una del 10 giugno non stanno nella stessa delega', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 20_000),   // 200 €, sotto soglia: matura il 16 giugno
        ritenuta('2026-06-10', 20_000),   // operata PRIMA del 16, ma è già finestra nuova
    ]);

    expect($deleghe)->toHaveCount(2);

    $scadenze = array_map(fn ($d) => $d['scadenza']->format('Y-m-d'), $deleghe);
    expect($scadenze)->toEqual(['2026-06-16', '2026-12-16'])
        ->and($deleghe[0]['importo'])->toEqual(20_000)
        ->and($deleghe[1]['importo'])->toEqual(20_000);
});

/**
 * Il confine esatto. Una ritenuta operata **il 16 giugno** non si versa quel giorno stesso:
 * il suo termine è il 16 luglio, quindi appartiene alla finestra che chiude a dicembre.
 */
test('una ritenuta operata il 16 giugno appartiene già alla finestra successiva', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-04-10', 20_000),
        ritenuta('2026-06-16', 20_000),
    ]);

    $scadenze = array_map(fn ($d) => $d['scadenza']->format('Y-m-d'), $deleghe);
    expect($scadenze)->toEqual(['2026-06-16', '2026-12-16']);
});

/**
 * Le due controprove: la correzione non deve spezzare ciò che sta legittimamente insieme.
 * Due ritenute della **stessa** finestra restano in una delega sola — altrimenti avremmo
 * scambiato un versamento tardivo con una raffica di deleghe inutili.
 */
test('due ritenute della stessa finestra restano in una delega sola', function (string $primaData, string $secondaData, string $scadenzaAttesa) {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta($primaData, 10_000),
        ritenuta($secondaData, 10_000),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual($scadenzaAttesa)
        ->and($deleghe[0]['importo'])->toEqual(20_000);
})->with([
    'prima finestra: marzo e aprile' => ['2026-03-10', '2026-04-10', '2026-06-16'],
    'seconda finestra: agosto e novembre' => ['2026-08-10', '2026-11-10', '2026-12-16'],
    'a cavallo del 16 giugno, entrambe dopo' => ['2026-06-20', '2026-07-10', '2026-12-16'],
]);

// ════════════════════════════════════════════════════════════════════════════
// Dicembre — termine proprio
// ════════════════════════════════════════════════════════════════════════════

test('le ritenute di dicembre si versano entro il 16 gennaio', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-12-10', 5_000),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2027-01-18')   // 16/01/2027 è sabato
        ->and($deleghe[0]['motivo'])->toEqual('ritenute_dicembre');
});

test('dicembre non si mescola con i mesi precedenti', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-10-10', 5_000),
        ritenuta('2026-12-10', 5_000),
    ]);

    expect($deleghe)->toHaveCount(2);

    $scadenze = array_map(fn ($d) => $d['scadenza']->format('Y-m-d'), $deleghe);
    expect($scadenze)->toEqual(['2026-12-16', '2027-01-18']);
});

// ════════════════════════════════════════════════════════════════════════════
// Lavoro autonomo 20% — soglia 100 €, accumulatore separato
// ════════════════════════════════════════════════════════════════════════════

test('per il lavoro autonomo la soglia è 100 €, non 500', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 15_000, TipoRitenuta::LAVORO_AUTONOMO_20),   // 150 €
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['plafond'])->toEqual(PlafondRitenuta::SOGLIA_100_ANNUALE);
});

test('per il lavoro autonomo il 16 giugno NON è una data limite', function () {
    // Sotto i 100 €, la sola data-limite annuale è il 16 dicembre.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-02-10', 3_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-04-10', 3_000, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-12-16');
});

test('i due accumulatori non si sommano fra loro', function () {
    // 300 € di appalti + 300 € di lavoro autonomo: insieme farebbero 600 e supererebbero
    // i 500. Separati, il primo resta sotto i 500 e il secondo supera i 100.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 30_000, TipoRitenuta::APPALTO_4),
        ritenuta('2026-03-10', 30_000, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    expect($deleghe)->toHaveCount(2);

    $perPlafond = [];
    foreach ($deleghe as $d) {
        $perPlafond[$d['plafond']->value] = $d['scadenza']->format('Y-m-d');
    }

    // L'appalto resta sotto soglia → 16 giugno. Il lavoro autonomo supera i 100 → 16 aprile.
    expect($perPlafond[PlafondRitenuta::SOGLIA_500_TRE_FINESTRE->value])->toEqual('2026-06-16')
        ->and($perPlafond[PlafondRitenuta::SOGLIA_100_ANNUALE->value])->toEqual('2026-04-16');
});

// ════════════════════════════════════════════════════════════════════════════
// Lavoro dipendente — nessun rinvio
// ════════════════════════════════════════════════════════════════════════════

test('sul lavoro dipendente non esiste rinvio, anche per pochi euro', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 500, TipoRitenuta::LAVORO_DIPENDENTE),   // 5 €
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['motivo'])->toEqual('mensile_obbligatorio');
});

// ════════════════════════════════════════════════════════════════════════════
// Slittamento e casi limite
// ════════════════════════════════════════════════════════════════════════════

test('la scadenza calcolata slitta se cade in giorno non lavorativo', function () {
    // Pagamento ad aprile 2026 → 16 maggio 2026, che è sabato → lunedì 18.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-04-10', 60_000),
    ]);

    expect($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-05-18');
});

test('un elenco vuoto non produce deleghe', function () {
    expect(plafondService()->raggruppaPerScadenza([]))->toEqual([]);
});

test('le deleghe escono ordinate per scadenza', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-12-10', 5_000),
        ritenuta('2026-01-10', 60_000),
        ritenuta('2026-07-10', 60_000),
    ]);

    $scadenze = array_map(fn ($d) => $d['scadenza']->format('Y-m-d'), $deleghe);
    $ordinate = $scadenze;
    sort($ordinate);

    expect($scadenze)->toEqual($ordinate);
});

// ════════════════════════════════════════════════════════════════════════════
// Coda 717 (decisione 571; forum t=202, casi A–C di Edoardo): la soglia di ogni regime, guardata sul mese
// ════════════════════════════════════════════════════════════════════════════
//
// La legge, letta su Normattiva il 19/11/2026: per il 20 % si rinvia finché il cumulo «non supera» € 100,00 (art. 9, c. 4,
// D.Lgs. 1/2024; dal 2027 art. 32, c. 1, lett. a), D.Lgs. 33/2025); per gli appalti finché non «raggiunga» € 500,00 (art.
// 25-ter, c. 2-bis, DPR 600/1973; dal 2027 art. 40, c. 3). E in tutti e due i casi conta il cumulo delle ritenute operate
// nel mese, non il singolo pagamento: si guarda alla fine del mese.

test('Coda 717, caso A — 20 %: € 100,00 il 10/03 non superano la soglia e si rinviano, da sole, al 16/12', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 10_000, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    // € 100,00 non è «oltre € 100,00»: l'unica data-limite del 20 % è il 16 dicembre (mercoledì 16/12/2026).
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-12-16')
        ->and($deleghe[0]['importo'])->toEqual(10_000)
        ->and($deleghe[0]['motivo'])->toEqual('termine_di_legge');
});

test('Coda 717, caso A2 — 20 %: € 100,00 il 10/03 e € 50,00 il 10/05 fanno € 150,00 a maggio, da versare il 16/06', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 10_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-05-10', 5_000, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    // marzo: € 100,00, non oltre → rinvio; maggio: € 150,00 > € 100,00 → il 16 del mese dopo maggio, martedì 16/06/2026.
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-06-16')
        ->and($deleghe[0]['importo'])->toEqual(15_000)
        ->and($deleghe[0]['ritenute'])->toHaveCount(2)
        ->and($deleghe[0]['motivo'])->toEqual('soglia_raggiunta');
});

test('Coda 717, caso B — appalti: € 500,00 il 05/04 e € 40,00 il 20/04 si versano insieme, € 540,00 al 18/05', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-04-05', 50_000),
        ritenuta('2026-04-20', 4_000),
    ]);

    // Aprile: € 540,00 ≥ € 500,00 → 16/05/2026, sabato → lunedì 18/05/2026. I € 40,00 non restano indietro fino al 16 giugno.
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-05-18')
        ->and($deleghe[0]['importo'])->toEqual(54_000)
        ->and($deleghe[0]['ritenute'])->toHaveCount(2);
});

test('Coda 717, caso B2 — appalti nell\'ordine inverso: € 40,00 il 05/04 e € 500,00 il 20/04, € 540,00 al 18/05 (la controprova)', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-04-05', 4_000),
        ritenuta('2026-04-20', 50_000),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-05-18')
        ->and($deleghe[0]['importo'])->toEqual(54_000);
});

test('Coda 717, caso C — 20 %: € 120,00 il 05/04 e € 30,00 il 20/04 fanno € 150,00 ad aprile, da versare il 18/05 e non i € 30,00 a dicembre', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-04-05', 12_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-04-20', 3_000, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-05-18')
        ->and($deleghe[0]['importo'])->toEqual(15_000)
        ->and($deleghe[0]['ritenute'])->toHaveCount(2);
});

test('Coda 717 — appalti: € 500,00 esatti raggiungono la soglia, al 16 del mese dopo (il «raggiunga» della legge)', function () {
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-03-10', 50_000),
    ]);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['motivo'])->toEqual('soglia_raggiunta');
});

test('Coda 717 — un mese che passa la soglia solo all\'ultimo pagamento porta con sé tutto il mese e i rinvii di prima', function () {
    // 20 %: gennaio € 40,00 (rinvio), marzo € 30,00 + € 20,00 + € 15,00: il cumulo arriva a € 105,00 solo con l'ultimo pagamento del 28/03.
    $deleghe = plafondService()->raggruppaPerScadenza([
        ritenuta('2026-01-12', 4_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-03-02', 3_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-03-15', 2_000, TipoRitenuta::LAVORO_AUTONOMO_20),
        ritenuta('2026-03-28', 1_500, TipoRitenuta::LAVORO_AUTONOMO_20),
    ]);

    // € 105,00 > € 100,00 a marzo → giovedì 16/04/2026, tutte e quattro insieme.
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe[0]['scadenza']->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($deleghe[0]['importo'])->toEqual(10_500)
        ->and($deleghe[0]['ritenute'])->toHaveCount(4);
});

test('Coda 717 — il confronto della soglia sta sul plafond: «oltre» per i € 100,00, «almeno» per i € 500,00', function () {
    expect(PlafondRitenuta::SOGLIA_100_ANNUALE->superaLaSoglia(10_000, 10_000))->toBeFalse()
        ->and(PlafondRitenuta::SOGLIA_100_ANNUALE->superaLaSoglia(10_001, 10_000))->toBeTrue()
        ->and(PlafondRitenuta::SOGLIA_500_TRE_FINESTRE->superaLaSoglia(49_999, 50_000))->toBeFalse()
        ->and(PlafondRitenuta::SOGLIA_500_TRE_FINESTRE->superaLaSoglia(50_000, 50_000))->toBeTrue();
});
