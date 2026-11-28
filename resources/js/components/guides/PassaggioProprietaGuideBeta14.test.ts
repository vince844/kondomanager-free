// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 2 — le frasi nuove delle guide in pagina «Registrare un passaggio» e «Piano rate»:
 * - Coda 708: «Quando l'annullamento non è consentito» ha un punto in più, un erede ha già versato sul debito ereditato (l'annullamento si ferma e dice quale incasso stornare);
 * - Coda 709: la scelta sugli interessi del defunto dice che con l'arretrato a nome del defunto, dopo il decesso, nessuno conta interessi su quel debito;
 * - Coda 484: il defunto che è anche erede di un altro defunto vale anche con la successione registrata su un'altra unità.
 *
 * Si montano le guide con i segnaposto del foglio e del cassetto e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';
import PianoRateGuide from './PianoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };
const stubs = {
    Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
    Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
    Accordion: passa, AccordionItem: passa, AccordionTrigger: passa, AccordionContent: passa,
};
const testo = (guida: unknown) => mount(guida as never, { props: { open: true }, global: { stubs } }).text().replace(/\s+/g, ' ');

describe('le guide del passaggio e del piano rate, tappa 2 della beta.14', () => {
    test('708 — fra i motivi per cui l\'annullamento non è consentito c\'è il versamento di un erede sul debito ereditato', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('nella successione, un erede ha già versato sul debito ereditato: si storna prima il suo incasso, dall\'elenco degli incassi;');
    });

    test('709 — con l\'arretrato a nome del defunto, dopo il decesso nessuno conta interessi su quel debito', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('conta i giorni fino al giorno prima del decesso: dopo il decesso nessuno conta interessi su questo debito');
    });

    test('484 — il defunto che è anche erede di un altro defunto, anche con la successione registrata su un\'altra unità, in tutte e due le guide', () => {
        // Con la regola completa (`DefuntoNelPiano::sostituisci`) la successione sull'altra unità è il caso che resta nella fermata: le due guide lo dicono.
        const frase = 'Con la sua successione su un\'altra unità, o con più successioni sull\'unità e quote diverse, il cancello lo dice';

        expect(testo(PassaggioProprietaGuide)).toContain(frase);
        expect(testo(PianoRateGuide)).toContain(frase);
        expect(testo(PassaggioProprietaGuide)).not.toContain('erede di un altro defunto della stessa unità');
    });
});
