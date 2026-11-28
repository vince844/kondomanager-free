// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della creazione del piano rate (`PianoRateGuide.vue`), scheda del budget: il tetto sulle voci.
 *
 * Ogni voce ha un tetto, il maggiore fra il preventivo e lo speso meno quello che ne chiedono gli altri piani attivi dello stesso esercizio
 * (`TettoSulleVoci::primoSforo`), alla creazione, a «Ricalcola» con le voci da aggiungere e a «Esercizio · Cambia»; l'importo vuoto e la selezione rapida
 * prendono il residuo (`PianoRateController::conIlResiduoSulleVoci`), una cifra oltre il residuo si rifiuta. Le sottovoci che il piano chiede con la riga di
 * un capitolo hanno lo stesso tetto, e un capitolo con un importo suo lo ha sulla sua parte. «Sposta spesa» è l'eccezione voluta.
 *
 * Cosa resta scoperto: le altre schede.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateGuide from './PianoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };

function guida() {
    return mount(PianoRateGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
            },
        },
    });
}

function sezione(): string {
    return guida().get('[data-testid="guida-tetto-voce"]').text().replace(/\s+/g, ' ');
}

describe('PianoRateGuide — una voce già chiesta da un altro piano', () => {
    test('la voce chiesta per intero non si chiede di nuovo, e il tetto è il maggiore fra preventivo e speso meno gli altri piani', () => {
        const testo = sezione();

        expect(testo).toContain('Una voce che i piani dell\'esercizio chiedono già per intero non si chiede di nuovo');
        expect(testo).toContain('il maggiore fra il preventivo e lo speso, meno quello che ne chiedono gli altri piani.');
    });

    test('l\'importo vuoto prende il residuo, una cifra oltre ferma la creazione, e vale per le sottovoci di un capitolo', () => {
        const testo = sezione();

        expect(testo).toContain('Con l\'importo vuoto, o con la selezione rapida, il piano prende quello che resta.');
        expect(testo).toContain('Una cifra oltre quello che resta ferma la creazione');
        expect(testo).toContain('il capitolo passa a ognuna la sua parte, e ognuna ha il suo tetto.');
    });

    test('lo sforo vero si chiede dopo la fattura, con un piano integrativo', () => {
        expect(sezione()).toContain('registra prima la fattura: lo speso alza il tetto, e un piano integrativo chiede la differenza.');
    });

    test('il tetto vale alla creazione, al ricalcolo con le voci da aggiungere e al cambio di esercizio; «Sposta spesa» è l\'eccezione voluta', () => {
        const testo = sezione();

        expect(testo).toContain('Il tetto vale quando il piano nasce, quando «Ricalcola» gli aggiunge delle voci e quando «Cambia» lo porta in un altro esercizio');
        expect(testo).toContain('«Sposta spesa» è l\'eccezione voluta: sposta quanto il piano finanzia fra le sue voci, non il preventivo, e la parte ceduta si chiede dopo.');
        expect(testo).toContain('Un capitolo con un importo suo ha il tetto anche sulla sua parte.');
    });

    test('il riquadro: una voce già finanziata non è riselezionabile finché non ha un residuo', () => {
        const testo = guida().text().replace(/\s+/g, ' ');

        expect(testo).toContain('Una voce già finanziata da un altro piano attivo non è riselezionabile finché non ha un residuo');
        expect(testo).not.toContain('non è riselezionabile, e vale');
    });
});
