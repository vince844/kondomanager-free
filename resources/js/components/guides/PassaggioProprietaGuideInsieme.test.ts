// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — la guida «Registrare un passaggio» ha il cassetto «Più persone che escono insieme», nella scheda della vendita,
 * a schema (una voce per domanda, niente paragrafi lunghi): quando (la vendita e la riserva d'usufrutto), che cosa scrive il programma (un passaggio per
 * persona, la stessa data, tutti o nessuno), lo stesso ruolo, chi entra (una persona sola), il pannello, l'annullamento insieme, il conguaglio di ciascuna.
 * Il riquadro «In breve» della vendita rimanda al cassetto.
 *
 * Si monta la guida con i segnaposto del foglio e del cassetto e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';

const passa = { template: '<div><slot /></div>' };
const stubs = {
    Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
    Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
    Accordion: passa, AccordionItem: passa, AccordionTrigger: passa, AccordionContent: passa,
};
const testo = () => mount(PassaggioProprietaGuide, { props: { open: true, tipo: 'vendita' }, global: { stubs } }).text().replace(/\s+/g, ' ');

describe('la guida del passaggio, tappa 7 della beta.14 — più persone che escono insieme', () => {
    test('c\'è il cassetto «Più persone che escono insieme»', () => {
        expect(testo()).toContain('Più persone che escono insieme');
    });

    test('quando: nella vendita o donazione, anche con la riserva d\'usufrutto, si spuntano tutte le persone che vendono con lo stesso atto', () => {
        expect(testo()).toContain('Quando: nella vendita o donazione, anche con la riserva d\'usufrutto, spunti in «Chi esce» tutte le persone che vendono o donano con lo stesso atto.');
    });

    test('che cosa scrive il programma: un passaggio per persona, la stessa data, tutti o nessuno', () => {
        expect(testo()).toContain('Che cosa scrive il programma: un passaggio per ciascuna persona, con la stessa data e lo stesso atto, tutti insieme o nessuno. Se uno non va, non si registra niente e il messaggio dice di chi è.');
    });

    test('lo stesso ruolo, e chi entra una persona sola', () => {
        const t = testo();

        expect(t).toContain('Lo stesso ruolo: tutte con la proprietà piena, o tutte con la nuda proprietà. Con ruoli diversi si registrano passaggi separati, uno per persona.');
        expect(t).toContain('Chi entra: una persona sola, che riceve la somma delle quote. Se ognuna vende a una persona diversa, si registrano passaggi separati. '
            + 'Se una persona vende a più persone (per esempio due coniugi che comprano insieme), il programma non lo registra ancora: si fa a mano, come la vendita di una parte della quota.');
        // Rilievi 4 e 7 dello scettico della tappa 7: la guida non promette i passaggi separati per più compratori da un venditore solo, e non dice «uno per ciascuna».
        expect(t).not.toContain('Se a comprare sono più persone, per ora si registrano passaggi separati.');
        expect(t).not.toContain('uno per ciascuna');
    });

    test('il pannello, l\'annullamento insieme e il conguaglio di ciascuna', () => {
        const t = testo();

        expect(t).toContain('Il pannello: una parte per ciascuna persona che esce, con le sue rate e il suo conguaglio, e le cifre sono quelle che si scrivono. Chi resta obbligato e le teste in assemblea si dicono una volta sola.');
        expect(t).toContain('Annullare: i passaggi si annullano tutti insieme, l\'ultimo registrato per primo, con un motivo solo. Se uno non si può annullare, non se ne annulla nessuno.');
        expect(t).toContain('Il conguaglio: ciascuna persona ha il suo, e si annulla anche da solo, dallo storico.');
    });

    test('il riquadro «In breve» della vendita rimanda al cassetto', () => {
        expect(testo()).toContain('Se escono più persone con lo stesso atto, in «Chi esce» le spunti tutte: un passaggio per ciascuna, tutti o nessuno (cassetto «Più persone che escono insieme»).');
    });

    test('niente numeri di Coda nel cassetto: le parole sono dell\'amministratore', () => {
        const dentro = testo().split('Più persone che escono insieme').slice(1).join(' ');

        expect(dentro).not.toMatch(/Coda \d+/);
    });
});
