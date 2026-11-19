<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.12 (H5, decisione 483 (3)): nella pagina «Commenti» la conferma di «Elimina» dice che l'autore riceve un'email, e quando non la riceve:
 * dalla pagina «Commenti» parte un'email per commento eliminato. Nelle quattro lingue, anche per i commenti selezionati.
 *
 * Che cosa non copre: l'email stessa (`tests/Feature/Commenti/AvvisoCommentoEliminatoTest.php`); la finestra di conferma (vitest della pagina).
 */

it('la conferma di «Elimina» dice l\'avviso all\'autore, in italiano come «Nascondi», e nelle altre tre lingue', function () {
    $it = fn (string $chiave) => __($chiave, ['autore' => 'Lucia Verdi'], 'it');
    // Con il testo di prima: «Vuoi eliminare il commento di Lucia Verdi? L'operazione non si può annullare.» Poi (H5) «riceve l'avviso che avete previsto per i
    // commenti eliminati»; dal giro B delle correzioni della Fase 1-bis la frase dice che è un'email, e quando non parte (decisione 483 (3); la regola è
    // quella di `CommentoService::elimina`: l'avviso «Commento eliminato» spento nelle preferenze di chi ha scritto).
    expect($it('commenti_pagina.confirm_delete.description'))->toBe('Vuoi eliminare il commento di Lucia Verdi? Chi lo ha scritto riceve un\'email che il suo commento '
        . 'è stato eliminato, salvo che abbia spento l\'avviso «Commento eliminato». L\'operazione non si può annullare.')
        ->and($it('commenti_pagina.confirm_delete.bulk_description'))->toContain('Chi li ha scritti riceve un\'email per ogni commento eliminato, salvo chi ha spento '
            . 'l\'avviso «Commento eliminato».');
    foreach (['en' => 'Comment deleted', 'es' => 'Comentario eliminado', 'pt' => 'Comentário eliminado'] as $lingua => $parole) {
        expect(__('commenti_pagina.confirm_delete.description', ['autore' => 'X'], $lingua))->toContain($parole)
            ->and(__('commenti_pagina.confirm_delete.bulk_description', [], $lingua))->toContain($parole);
    }
});
