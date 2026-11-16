<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Tests\TestCase;

// Funzione Helper che simula ESATTAMENTE il Trait di produzione (inclusa la lista insoluti)
function eseguiLogicaWaterfall($mockRateAggregate) {
    $creditoDisponibile = 0.0;
    $accumuloDebito = 0.0;
    $listaInsoluti = []; // <--- NUOVO: Come nel Trait
    $results = [];

    foreach ($mockRateAggregate as $rata) {
        // Simuliamo il numero rata se non c'è (per il test)
        $numeroRata = $rata['numero_rata'] ?? $rata['id']; 
        
        $netto = $rata['netto'];
        $pagato = $rata['pagato'];
        
        $snapCredito = $creditoDisponibile; 
        $residuo = 0.0;
        
        if ($netto > 0) {
            $daPagare = round(max(0, $netto - $pagato), 2);

            if (round($creditoDisponibile, 2) >= $daPagare) {
                $creditoDisponibile -= $daPagare;
                $residuo = 0.0;
            } else {
                $residuo = $daPagare - $creditoDisponibile;
                $creditoDisponibile = 0.0;
            }
        } else {
            $creditoDisponibile += abs($netto);
            $residuo = $netto; 
        }

        if($residuo > 0) $residuo = round($residuo, 2);
        $creditoDisponibile = round($creditoDisponibile, 2);

        // Snapshot della lista insoluti PRIMA di aggiungere quella corrente (per mostrare il passato)
        $stringaRiferimento = implode(', ', $listaInsoluti);

        $results[$rata['id']] = [
            'residuo' => $residuo,
            'arretrati' => $accumuloDebito,
            'snap_credito' => $snapCredito,
            'rif_arretrati' => $stringaRiferimento // <--- NUOVO
        ];
        
        if ($residuo > 0.01) {
            $accumuloDebito += $residuo;
            $accumuloDebito = round($accumuloDebito, 2);
            $listaInsoluti[] = '#' . $numeroRata; // <--- NUOVO: Aggiungiamo alla lista "cattivi"
        }
    }
    return $results;
}

// --- TEST 1 ---
it('calcola correttamente gli arretrati e genera il riferimento testuale', function () {
    $mockRateAggregate = [
        ['id' => 1, 'numero_rata' => 1, 'netto' => 100.00, 'pagato' => 80.00], 
        ['id' => 2, 'numero_rata' => 2, 'netto' => 100.00, 'pagato' => 0.00],  
    ];

    $risultati = eseguiLogicaWaterfall($mockRateAggregate);

    // Rata 1
    expect($risultati[1]['residuo'])->toEqual(20.00);

    // Rata 2
    // Deve avere arretrati (20.00) E il riferimento alla rata "#1"
    expect($risultati[2]['residuo'])->toEqual(100.00)
        ->and($risultati[2]['arretrati'])->toEqual(20.00)
        ->and($risultati[2]['rif_arretrati'])->toContain('#1'); 
});

// --- TEST 2 (Cascata standard) ---
it('gestisce correttamente il credito a cascata (Waterfall)', function () {
    $mockData = [
        ['id'=>1, 'netto'=>-100.00, 'pagato'=>0.00],
        ['id'=>2, 'netto'=>60.00,   'pagato'=>0.00],
        ['id'=>3, 'netto'=>60.00,   'pagato'=>0.00],
    ];
    $risultati = eseguiLogicaWaterfall($mockData);

    expect($risultati[1]['snap_credito'])->toEqual(0.0);
    expect($risultati[2]['snap_credito'])->toEqual(100.0)->and($risultati[2]['residuo'])->toEqual(0.0);
    expect($risultati[3]['snap_credito'])->toEqual(40.0)->and($risultati[3]['residuo'])->toEqual(20.0);
});

// --- TEST 3 (Panino) ---
it('non usa il credito futuro per sanare debiti passati', function () {
    $mockData = [
        ['id'=>1, 'netto'=>100.00, 'pagato'=>0.00], 
        ['id'=>2, 'netto'=>-50.00, 'pagato'=>0.00], 
        ['id'=>3, 'netto'=>100.00, 'pagato'=>0.00], 
    ];
    $risultati = eseguiLogicaWaterfall($mockData);

    expect($risultati[1]['residuo'])->toEqual(100.00);
    expect($risultati[2]['residuo'])->toEqual(-50.00)->and($risultati[2]['arretrati'])->toEqual(100.00);
    expect($risultati[3]['residuo'])->toEqual(50.00);
});

// --- TEST 4 (Centesimi) ---
it('gestisce correttamente la precisione dei centesimi', function () {
    $mockData = [
        ['id'=>1, 'netto'=>-33.33, 'pagato'=>0.00],
        ['id'=>2, 'netto'=>-33.33, 'pagato'=>0.00],
        ['id'=>3, 'netto'=>-33.34, 'pagato'=>0.00],
        ['id'=>4, 'netto'=>100.00, 'pagato'=>0.00],
    ];
    $risultati = eseguiLogicaWaterfall($mockData);
    
    expect($risultati[4]['snap_credito'])->toEqual(100.00)
        ->and($risultati[4]['residuo'])->toEqual(0.00);
});

// --- TEST 5 (Copertura Totale) ---
it('gestisce correttamente un credito iniziale che copre totalmente le rate successive', function () {
    $mockData = [
        ['id'=>1, 'netto'=>-50.00, 'pagato'=>0.00], 
        ['id'=>2, 'netto'=>20.00,  'pagato'=>0.00], 
        ['id'=>3, 'netto'=>40.00,  'pagato'=>0.00], 
    ];
    $risultati = eseguiLogicaWaterfall($mockData);

    expect($risultati[1]['residuo'])->toEqual(-50.00);
    expect($risultati[2]['residuo'])->toEqual(0.00)->and($risultati[2]['snap_credito'])->toEqual(50.00); 
    expect($risultati[3]['residuo'])->toEqual(10.00)->and($risultati[3]['snap_credito'])->toEqual(30.00); 
});