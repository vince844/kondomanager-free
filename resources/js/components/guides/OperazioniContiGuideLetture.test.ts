// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida del piano dei conti (`OperazioniContiGuide.vue`) e le letture di fine anno dei sottocontatori di una voce (`LettureDiFineAnno.vue`,
 * `Beta15LettureDiFineAnnoTest`): dove si scrivono, che cosa, l'esempio, che cosa cambia, con il piano approvato e nell'esercizio chiuso.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: la traduzione; il tema scuro.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import OperazioniContiGuide from './OperazioniContiGuide.vue';

function testoDellaGuida(): string {
    const w = mount(OperazioniContiGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('OperazioniContiGuide — le letture di fine anno', () => {
    test('a schema: dove, che cosa si scrive, l\'esempio, che cosa cambia, il piano approvato e l\'esercizio chiuso, dove si vede', () => {
        const t = testoDellaGuida();
        expect(t).toContain('Le letture di fine anno');
        expect(t).toContain('Dove: nel dettaglio della voce, il riquadro «Le letture di fine anno».');
        expect(t).toContain('Che cosa si scrive: il contatore generale e, per ogni tabella, la lettura o la percentuale; al più una tabella «per differenza».');
        expect(t).toContain('Esempio: generale 10.000 kWh, ascensore 5.000, impianto termico 2.000, luci per differenza: 50 %, 20 %, 30 %.');
        expect(t).toContain('Che cosa cambia: il conguaglio divide la spesa con queste percentuali; le rate del preventivo restano.');
        expect(t).toContain('Con il piano approvato: si scrivono lo stesso. In un esercizio chiuso no.');
    });
});
