// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le altre schede della guida; la traduzione (la guida è solo in italiano).
 *
 * Coda 438, decisione 192.1: sulla gestione usata in più esercizi «Ricalcola» e il cestino delle voci rifiutano il piano senza il suo esercizio
 * salvato, e «Ricalcola» il piano ordinario senza voci associate quando la gestione ha un piano in un altro esercizio (`PianoRate::fraseDaRifare`). Il
 * pulsante non è disabilitato: la guida, che dice «È bloccato in tre casi» per il pulsante disabilitato, dice anche questo rifiuto e la strada del
 * messaggio (eliminare il piano e crearlo di nuovo dalla pagina del suo esercizio, `Beta1_112GiaVersatoPianiDiPrimaTest`). Il cestino non è
 * disabilitato nemmeno lui, e la frase che elenca quando lo è non basta più a dire quando si rifiuta.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';

const STUBS = {
    Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
    SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
};

const testo = () => mount(PianoRateDettaglioGuide, { props: { open: true }, global: { stubs: STUBS } }).text().replace(/\s+/g, ' ');

describe('PianoRateDettaglioGuide — i piani nati con le versioni più vecchie sulla gestione usata in più esercizi (Coda 438)', () => {
    test('«Ricalcola» si rifiuta per le due forme, con il perché e la strada del messaggio', () => {
        const t = testo();

        expect(t).toContain('Sulla gestione usata in più esercizi il pulsante non è bloccato, ma si rifiuta per due forme di piano che vengono di solito dalle versioni più vecchie: '
            + 'il piano senza il suo esercizio salvato (anche dal cestino delle voci), e il piano ordinario senza voci associate quando la gestione ha un piano in un altro esercizio.');
        expect(t).toContain('Il programma non sa a quale esercizio appartiene, o quali voci gli spettano, e ricalcolandolo potrebbe spostare il già versato da un esercizio '
            + 'all\'altro o chiedere due volte le stesse voci.');
        expect(t).toContain('dice la strada: eliminarlo dall\'elenco dei piani rate (un piano approvato si riporta prima in bozza) e crearlo di nuovo dalla pagina dei piani '
            + 'rate del suo esercizio, scegliendo le voci.');
        expect(t).toContain('È la strada anche quando un altro messaggio chiede di ricalcolare quel piano, per esempio prima di emetterlo dopo una vendita.');
    });

    test('il cestino non è disabilitato sul piano senza esercizio: si rifiuta con lo stesso messaggio', () => {
        expect(testo()).toContain('Sul piano senza il suo esercizio salvato, che «Ricalcola» rifiuta sulla gestione usata in più esercizi (vedi sopra), il cestino non è '
            + 'disabilitato ma si rifiuta, con lo stesso fatto e la stessa strada.');
    });

    test('il cestino non toglie l\'ultima voce del piano, e la guida dice perché e che cosa fare', () => {
        expect(testo()).toContain('L\'ultima voce di un piano ordinario non si toglie: un piano senza voci, al ricalcolo, prende tutte le voci della gestione che nessun altro '
            + 'piano del suo esercizio ha già, quindi anche quella appena tolta se nessun altro piano l\'ha. Se il piano deve chiedere un\'altra voce, si aggiunge prima '
            + 'con «Sincronizza», se è libera, e poi si toglie questa; se non serve più, si elimina dall\'elenco dei piani rate.');
    });

    test('controprova — il pulsante resta bloccato nei tre casi di prima, che la pagina vede da sola', () => {
        expect(testo()).toContain('È bloccato in tre casi: se qualche rata è già a giornale;');
    });
});
