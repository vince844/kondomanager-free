// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La conferma dell'IBAN vale per l'IBAN che l'amministratore ha visto nella finestra: se poi lo cambia, la conferma torna falsa.
 *
 * Si legge il sorgente delle due pagine (la finestra non si rende senza la pagina intera): ognuna ha un `watch` sull'IBAN del modulo
 * che azzera `iban_confermato_manualmente`, e la conferma resta nel solo `confirmIban`.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const pagine = {
    PagamentoNew: fs.readFileSync('resources/js/pages/gestionale/movimenti/pagamenti/PagamentoNew.vue', 'utf8').replace(/\s+/g, ' '),
    PagamentoEdit: fs.readFileSync('resources/js/pages/gestionale/movimenti/pagamenti/PagamentoEdit.vue', 'utf8').replace(/\s+/g, ' '),
};

describe.each(Object.entries(pagine))('%s', (_nome, sorgente) => {
    test('al cambio dell\'IBAN del modulo la conferma torna falsa', () => {
        expect(sorgente).toMatch(/watch\(\(\) => form\.iban_beneficiario, \(\) => \{ form\.iban_confermato_manualmente = false; \}\)/);
    });

    test('l\'IBAN si confronta senza gli spazi e senza i due caratteri invisibili che il server toglie', () => {
        expect(sorgente).not.toMatch(/iban[^;]*\.replace\(\/\\s\+?\/g/i);
        expect(sorgente.match(/\.replace\(\/\[\\s\\u200B\\uFEFF\]\+\/g, ''\)/g)).toHaveLength(2);
    });

    test('la conferma si dà solo dalla finestra, con confirmIban', () => {
        expect(sorgente.match(/iban_confermato_manualmente = true/g)).toHaveLength(1);
        expect(sorgente).toMatch(/const confirmIban = \(\) => \{ form\.iban_confermato_manualmente = true;/);
    });
});
