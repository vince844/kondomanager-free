<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (Coda 679, R5 del progetto della tappa 2; art. 1137 c.c., legge §9): l'impugnazione è un segno e non ferma niente. Lettura del codice:
 * nessun file di `app/` fuori dalle assemblee e dal registro legge `impugnata_il`, quindi l'emissione, il pagamento e il conguaglio non lo vedono.
 *
 * Che cosa non copre: una lettura costruita senza il nome della colonna (per esempio da una variabile); le pagine (`resources/js`), che lo mostrano.
 */

it('solo le assemblee e il registro nominano impugnata_il', function () {
    $fuori = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $percorso = str_replace(base_path() . '/', '', $file->getPathname());
        if (str_starts_with($percorso, 'app/Services/Assemblee/') || str_starts_with($percorso, 'app/Models/Assemblee/')
            || str_starts_with($percorso, 'app/Services/RegistroModifiche/')) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), 'impugnata_il')) {
            $fuori[] = $percorso;
        }
    }

    expect($fuori)->toBe([]);
});
