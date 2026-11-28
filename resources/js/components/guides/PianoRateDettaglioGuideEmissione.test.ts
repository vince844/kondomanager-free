// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina del piano (`PianoRateDettaglioGuide.vue`), scheda «Emissione», a schema: i tre passi per emettere, la tabella di che cosa va a
 * giornale, la rata emessa che ferma il ricalcolo e le quote dei piani di prima della 1.8.0 senza il dettaglio del calcolo. Una prova per ogni cosa che la
 * scheda dice; le scritture le provano i test dell'emissione (`CreditiRevisioneEmissioneTest`, `CreditiEmissioneEstrattoContoTest`).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';

const passa = { template: '<div><slot /></div>' };
const STUBS = { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa, Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa };
const guida = () => mount(PianoRateDettaglioGuide, { props: { open: true }, global: { stubs: STUBS } });
const testo = () => guida().text().replace(/\s+/g, ' ');

describe('PianoRateDettaglioGuide — la scheda «Emissione»', () => {
    test('emettere in tre passi: il piano approvato, le rate non ancora emesse, il modulo con la causale facoltativa', () => {
        const t = testo();

        expect(t).toContain('Il piano dev\'essere «Approvato».');
        expect(t).toContain('Spunta le rate (le caselle in testa a ogni colonna) o usa «Seleziona tutte»: si scelgono solo quelle non ancora emesse.');
        expect(t).toContain('Premi «Emetti» e scegli la data di registrazione, la causale (facoltativa: senza, il programma usa una dicitura standard) e se mostrarle subito ai condòmini.');
    });

    test('che cosa va a giornale e che cosa no; fuori dal giornale solo i tre casi con «No», e l\'esito dice perché', () => {
        const t = testo();

        expect(t).toContain('Rata di soli creditiPer ogni quota a credito: la spesa della rata in dare e il credito riportato che la paga in avere, a nome del condòmino.');
        expect(t).toContain('Quota a zero (spesa e credito uguali)Con tutte e due le righe.');
        expect(t).toContain('Rata vuota, o con il credito già usato o rimborsato per interoNon ha niente da portare.');
        expect(t).toContain('Solo quote a credito o a zero senza il dettaglio del calcoloPiani di prima della 1.8.0: vedi sotto.');
        expect(t).toContain('Rata «emessa» senza scrittureL\'ha presa così un passaggio registrato con una versione di prima.');
        expect(t).toContain('Non vanno a giornale solo le rate dei tre casi con «No», e l\'esito dell\'emissione dice perché.');
    });

    test('una rata emessa, anche di soli crediti, ferma il ricalcolo: si storna e si annulla l\'emissione', () => {
        const t = testo();

        expect(t).toContain('Una rata emessa, anche di soli crediti, ferma il ricalcolo del piano (vedi «Ricalcola»).');
        expect(t).toContain('Per ricalcolare: storna gli incassi, le compensazioni o i rimborsi che la toccano, poi annulla l\'emissione.');
    });

    test('le quote dei piani di prima della 1.8.0: che cosa resta fuori, che cosa mostrano le altre pagine, come portarle a giornale', () => {
        const t = testo();

        expect(t).toContain('Piani di prima della 1.8.0: quote senza il dettaglio del calcolo');
        expect(t).toContain('Una quota a credito, o a zero, senza il dettaglio resta fuori da sola: il programma non separa la spesa dal credito riportato. L\'esito nomina la persona e la rata.');
        expect(t).toContain('A credito: il condòmino tiene il credito, da usare o rimborsare.');
        expect(t).toContain('A zero: la spesa della rata e il credito riportato che la paga si pareggiano e restano fuori tutti e due; non c\'è niente da usare né da rimborsare, anche se l\'estratto conto può mostrare a credito il saldo pregresso.');
        expect(t).toContain('Se le altre quote della rata vanno a giornale, la card «Crediti da riallineare» degli incassi tiene la quota ferma fra le voci che restano come sono, anche dopo lo storno di un incasso o di un rimborso che ne ha usato il credito.');
        expect(t).toContain('Una rata fatta solo di quote ferme resta in bozza, e così la mostra la pagina del piano; il perché lo dice l\'esito dell\'emissione.');
        expect(t).toContain('Per portare la quota a giornale:');
        const passi = [...guida().element.querySelectorAll('details ol li')].map((li) => (li.textContent ?? '').replace(/\s+/g, ' ').trim());
        expect(passi).toEqual([
            'controlla il saldo pregresso della persona (prima della 1.8.0 si sottraeva invece di sommare);',
            'storna incassi, compensazioni e rimborsi;',
            'annulla le emissioni;',
            'ricalcola il piano.',
        ]);
    });
});
