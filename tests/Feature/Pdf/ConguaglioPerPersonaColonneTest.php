<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa «Conguaglio per persona»: le cifre di ogni riga stanno sotto la loro intestazione, dalla prima persona all'ultima e nel totale.
 *
 * La tabella è in due pezzi: le righe con l'intestazione e, in fondo, le ultime due righe con il totale, che non si spezza. Le due tabelle devono avere
 * le stesse colonne. Le intestazioni delle colonne di destra non vanno a capo, e una più larga della sua colonna la allarga solo nel pezzo con
 * l'intestazione: le cifre dell'altro pezzo finirebbero spostate.
 *
 * Si misura il PDF (la fine destra di ogni cifra), non il testo: le stesse parole possono stare in colonne diverse. Si provano otto persone in tre casi
 * della carta: senza lo studio, con lo studio e il logo, con la carta intestata (le stampe orizzontali ne hanno la testata a campi).
 *
 * Non vede: le altre stampe con lo stesso schema (il consuntivo per tabella, il foglio del conguaglio), che hanno le loro misure.
 */

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

use App\Http\Controllers\Gestionale\Movimenti\RendicontoConguaglioController;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('public');
});

function cppPdf($test, int $persone): string
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e']);
    $righe = $c->righe($s['c'], $s['e'], $letto, app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e'])['fuori_capitoli'], true);
    $modello = $righe['gestioni'][0]['righe'][0];
    $elenco = [];
    for ($i = 1; $i <= $persone; $i++) {
        $elenco[] = ['nome' => 'Persona numero '.$i, 'unita' => 'U'.$i, 'giorni' => 365, 'a' => 100000 + $i * 111, 'personali' => 0, 'b' => 90000 + $i * 7,
            'conguaglio' => 10000 + $i * 104, 'posizione' => 5000, 'fuori' => 0, 'saldo' => 15000 + $i * 104, 'non_emesse' => 0, 'b_non_emesse' => 0, 'subtotale' => false] + $modello;
    }
    $righe['gestioni'][0]['righe'] = $elenco;
    $mpdf = app(PdfService::class)->generate('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe,
        'titolo' => 'Conguaglio per persona', 'senza_firma' => true, 'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], RendicontoConguaglioController::OPZIONI_STAMPA);
    $pdf = $mpdf->Output('x.pdf', \Mpdf\Output\Destination::STRING_RETURN);
    return $pdf;
}

/** Le fini destre (mm) dei pezzi che dicono un numero col segno, raggruppate in colonne: un gruppo per ogni colonna. */
function cppColonneDelleCifre(string $pdf): array
{
    $fini = [];
    foreach (cdsRighe($pdf, 1) as $riga) {
        if (preg_match('/^€ [+-]\d[\d.]*,\d\d$/u', trim($riga[0])) && $riga[2] !== null) {
            $fini[] = $riga[2];
        }
    }
    sort($fini);
    $gruppi = [];
    foreach ($fini as $fine) {
        if ($gruppi !== [] && $fine - end($gruppi[count($gruppi) - 1]) < 1.0) {
            $gruppi[count($gruppi) - 1][] = $fine;
        } else {
            $gruppi[] = [$fine];
        }
    }

    return $gruppi;
}

/** Le fini destre dei «365» (la colonna dei giorni): una per persona. */
function cppFiniDeiGiorni(string $pdf): array
{
    $fini = [];
    foreach (cdsRighe($pdf, 1) as $riga) {
        if (trim($riga[0]) === '365' && $riga[2] !== null) {
            $fini[] = $riga[2];
        }
    }

    return $fini;
}

function cppControlla(string $pdf): void
{
    $giorni = cppFiniDeiGiorni($pdf);
    expect($giorni)->toHaveCount(8)
        ->and(max($giorni) - min($giorni))->toBeLessThan(0.5, 'i giorni: la colonna si sposta fra le righe');

    // La proposta di conguaglio e il saldo: due colonne, nelle otto righe e nel totale (nove cifre ciascuna).
    $colonne = cppColonneDelleCifre($pdf);
    expect($colonne)->toHaveCount(2)
        ->and(count($colonne[0]))->toBe(9)->and(count($colonne[1]))->toBe(9);
}

it('senza lo studio, le cifre di ogni riga e del totale stanno sotto le loro intestazioni', function () {
    cdsSenzaStudio();

    cppControlla(cppPdf($this, 8));
});

it('con lo studio e il logo, le cifre di ogni riga e del totale stanno sotto le loro intestazioni', function () {
    cdsStudioPieno(true);

    cppControlla(cppPdf($this, 8));
});

it('con la carta intestata, le cifre di ogni riga e del totale stanno sotto le loro intestazioni', function () {
    cdsStudioPieno(true);
    Storage::disk('public')->put('settings/carte/carta-delle-prove.png', cdsPng(1240, 1754));
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-delle-prove.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $impostazioni->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);

    cppControlla(cppPdf($this, 8));
});
