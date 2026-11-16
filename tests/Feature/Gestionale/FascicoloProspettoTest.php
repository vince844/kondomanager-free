<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il prospetto delle rate non versate del fascicolo per il legale (`01 Rate non versate.pdf`): ogni rata scaduta e non versata della persona, con il piano, la
 * delibera del piano (data e verbale), il numero, l'unità, la scadenza, la cifra non versata, l'esercizio e la data dei sei mesi, l'accordo se c'è; il totale,
 * che con tutto scelto è tutto lo scaduto della persona (la cifra dell'elenco «Rate scadute» e della tesoreria). Nei tre casi della carta dello studio, con le
 * immagini a 70 dpi nella cartella data dall'ambiente (`FASCICOLO_IMMAGINI`), per guardarle.
 */

use App\Models\Gestionale\RendicontoVersione;
use App\Services\Gestionale\Debiti\ElencoRateScadute;

require_once __DIR__.'/FascicoloLegaleScenario.php';
require_once __DIR__.'/../Pdf/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

dataset('carte del fascicolo', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => fslCartaIntestata(),
]);

it('le rate con il piano, la delibera, la scadenza, il residuo, l\'esercizio e la data dei sei mesi, l\'accordo; il totale è tutto lo scaduto', function (\Closure $carta) {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);
    $s['c']->update(['indirizzo' => 'Via Verdi 12, 20121 Milano (MI)']);
    $s['piano']->update(['data_delibera_assemblea' => '2025-12-12', 'numero_verbale' => '4']);
    // Rossi ha versato € 40,00 della rata 2: ne restano € 60,00.
    $this->travelTo('2026-04-10 10:00:00');
    lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 2) => 4000], '2026-04-10');
    $this->travelTo('2026-05-20 10:00:00');
    fslAccordo($s, 'rossi', [3]);
    $d = fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    fslProva($d, 'cartolina.pdf', null);
    fslLettera($s, 'rossi', 'sollecito', 'stampata', '2026-04-12 09:00', [1, 2]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA, ['verbale' => null]);
    $carta();
    $nome = trim((string) preg_replace('/[^a-z0-9]+/i', '-', str_replace('dataset', '', (string) $this->dataName())), '-');

    [, $file] = fslScarica($this, $s, 'rossi');
    fslImmagini($file['01 Rate non versate.pdf'], 'prospetto-' . $nome);
    fslImmagini($file['00 Indice.pdf'], 'indice-' . $nome);
    $p = fslTesto($file['01 Rate non versate.pdf']);
    $tutto = (int) app(ElencoRateScadute::class)->righe($s['c'], app(ElencoRateScadute::class)->filtriNormalizzati([]))->firstWhere('nome', 'Rossi')['importo'];

    expect($tutto)->toBe(46000)
        ->and($p)->toContain('Rate non versate')
        ->toContain('Rossi')
        ->toContain('Preventivo 2026')
        ->toContain('delibera del 12/12/2025 (verbale n. 4)')
        ->toContain('rata 1')->toContain('rata 5')
        ->toContain('Int. A')
        ->toContain('05/11/2025')->toContain('05/05/2026')
        ->toContain('€ 60,00')
        ->toContain('Esercizio 2025')->toContain('30/06/2026')->toContain('Esercizio 2026')->toContain('30/06/2027')
        ->toContain('in un accordo di rientro del 10/01/2026: € 100,00')
        ->toContain('Totale € 460,00')
        ->toContain('Le rate scadute al 20/05/2026 e non versate');
})->with('carte del fascicolo');

it('un piano senza la data della delibera lo dice nella sua riga', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);

    [, $file] = fslScarica($this, $s, 'rossi');

    expect(fslTesto($file['01 Rate non versate.pdf']))->toContain('Preventivo 2026')->toContain('delibera: data non scritta nel piano');
});
