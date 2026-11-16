// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il widget «Tesoreria a 30gg»: i testi sui fondi vincolati dicono quello che il calcolo fa.
 *
 * Si legge il sorgente, come nei test delle guide: conta la frase, non il disegno. Il servizio toglie
 * dal disponibile i fondi che non si possono usare (`DisponibilitaDiOggi`, con la regola di `TipoDelFondo`), e
 * «Da dove viene» li mostra come righe tolte; l'avviso compare quando il condominio ha un fondo ma nessun fondo
 * ha denaro. Non deve dire che il calcolo «non distingue ancora» i fondi, né rimandare a una funzione («Voci
 * Accantonamento») che nel programma non esiste.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const sorgente = fs.readFileSync('resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue', 'utf8').replace(/\s+/g, ' ');

describe('TreasuryGuardianWidget — l\'avviso sul fondo riserva senza somme accantonate', () => {
    test('non dice che il calcolo non distingue ancora i fondi vincolati', () => {
        expect(sorgente).not.toContain('non distingue ancora');
        expect(sorgente).toContain('Il fondo riserva non ha somme accantonate');
    });

    test('non rimanda a una funzione che non esiste', () => {
        expect(sorgente).not.toContain('Voci Accantonamento');
        expect(sorgente).not.toContain('sarà disponibile');
    });
});
