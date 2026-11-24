<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 493, decisioni 686 e 687) — la nota di licenza dell'installatore ha le parole e l'ordine della finestra «Note legali»
 * delle pagine: «© … · Licenza GNU AGPL-3.0 · Note legali · Codice sorgente», con «Note legali» fra la licenza e il codice sorgente, e il testo
 * «secondo i termini della GNU Affero General Public License» e «senza alcuna garanzia, nemmeno di commerciabilità…».
 */
$vista = fn () => file_get_contents(resource_path('views/installer/installer-wizard.blade.php'));

it('la riga dell\'installatore mette «Note legali» fra la licenza e il codice sorgente', function () use ($vista) {
    $v = $vista();
    $licenza = strpos($v, "installer.wizard.footer_license");
    $note = strpos($v, "installer.wizard.footer_notes'");
    $codice = strpos($v, "installer.wizard.footer_source");

    expect($licenza)->not->toBeFalse()->and($note)->not->toBeFalse()->and($codice)->not->toBeFalse()
        ->and($licenza < $note)->toBeTrue('la licenza prima di «Note legali»')
        ->and($note < $codice)->toBeTrue('«Note legali» prima del codice sorgente');
});

it('il testo delle note dice «secondo i termini della» e «nemmeno di commerciabilità», in italiano e in inglese', function () {
    $it = require lang_path('it/installer.php');
    $en = require lang_path('en/installer.php');

    expect($it['wizard']['footer_notes_text'])
        ->toContain('secondo i termini della GNU Affero General Public License, versione 3')
        ->toContain('È fornito senza alcuna garanzia, nemmeno di commerciabilità o di idoneità a uno scopo particolare.')
        ->and($en['wizard']['footer_notes_text'])
        ->toContain('under the terms of the GNU Affero General Public License, version 3')
        ->toContain('without any warranty, not even of merchantability or fitness for a particular purpose');
});
