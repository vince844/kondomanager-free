<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il peso del fascicolo per il legale (progetto D2 § 5): una persona con 30 lettere, ognuna con il PDF conservato e una prova, e il rendiconto approvato di 22
 * pagine (un PDF di 2 MB) con il verbale. L'archivio si scrive a pezzi: la memoria tiene un documento alla volta. La soglia: 10 s e 64 MB in più del picco, per
 * la richiesta intera (la lettura, i PDF del programma, l'archivio, il registro).
 */

use App\Models\Gestionale\RendicontoVersione;

require_once __DIR__.'/FascicoloLegaleScenario.php';

const FSM_SECONDI = 10.0;
const FSM_MEGA = 64.0;

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('una persona con 30 lettere con le prove e il rendiconto di 22 pagine: sotto 10 s e 64 MB in più', function () {
    $s = fslScenario($this);
    foreach (range(1, 30) as $n) {
        $giorno = \Carbon\CarbonImmutable::parse('2025-12-01')->addDays($n * 5);
        $riga = fslLettera($s, 'rossi', $n % 3 === 0 ? 'diffida' : 'sollecito', 'spedita', $giorno->format('Y-m-d') . ' 10:' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            [1, 2, 3, 4, 5], ['pdf' => fslPdf("lettera {$n}") . str_repeat("% riempimento\n", 6000)]);
        fslProva($riga, "ricevuta-{$n}.pdf", str_repeat('p', 200_000), $giorno->addDay()->format('Y-m-d') . ' 09:00:00');
    }
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA, ['pdf' => fslPdf('rendiconto') . str_repeat("% pagina\n", 230_000)]);
    $anteprima = fslAnteprima($this, $s, 'rossi');

    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $r = $this->actingAs($this->user)->post(fslRotta($s, 'rossi', 'anagrafiche.fascicolo-legale.prepara'),
        ['rate' => collect($anteprima['esercizi'])->flatMap(fn ($e) => array_column($e['rate'], 'chiave'))->all(), 'parti' => $anteprima['parti']]);
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    fwrite(STDERR, sprintf("\n[misura] fascicolo, 30 lettere con le prove e il rendiconto di 2 MB: %.2f s, %.1f MB in più\n", $secondi, $mega));

    expect($r->getStatusCode())->toBe(200)
        ->and($secondi)->toBeLessThan(FSM_SECONDI)
        ->and($mega)->toBeLessThan(FSM_MEGA);
    $r->streamedContent();
});
