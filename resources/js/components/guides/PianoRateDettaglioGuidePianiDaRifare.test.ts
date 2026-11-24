// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
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
 * Coda 489 (decisione 685 (3)): sulla gestione con un esercizio solo il ricalcolo del piano senza voci associate gli scrive le voci che chiede, un piano
 * nuovo senza voci scelte si ferma finché ce n'è uno così in tutta la gestione (con la strada di eliminarlo e crearlo di nuovo dove il ricalcolo si
 * rifiuta: rilievi 1 e 10 dello scettico della tappa 1), e anche con le voci scelte finché quello si ricalcola (decisione 685 (3), «qualsiasi piano
 * nuovo»); l'ultima voce di un piano non si elimina dal piano dei conti (`Beta14GiaVersatoGuardiaTest`).
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
            + 'il piano senza il suo esercizio salvato (anche dal cestino delle voci), e il piano ordinario senza voci associate quando la gestione ha, in un altro '
            + 'esercizio, un piano che prende le voci del preventivo: uno straordinario o il piano degli addebiti non contano, perché non prendono voci.');
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

    test('Coda 489 — sulla gestione con un esercizio solo il piano senza voci associate si ricalcola, e il ricalcolo gli scrive le voci', () => {
        const t = testo();

        expect(t).toContain('Sulla gestione con un esercizio solo il piano ordinario senza voci associate si ricalcola:');
        // Rilievo 4 del secondo scettico: «con le stesse cifre» non è vero quando il ricalcolo dà il pregresso dell'unità a un'altra persona.
        expect(t).toContain('il ricalcolo gli scrive le voci che chiede, senza importo e con gli stessi importi, e da lì gli altri piani le vedono; applica le regole '
            + 'di oggi, anche a chi va il pregresso dell\'unità, e se lo dà a un\'altra persona l\'esito del ricalcolo lo dice;');
        expect(t).not.toContain('con le stesse cifre');
        expect(t).toContain('finché un piano così c\'è nella gestione, in qualunque esercizio, un piano nuovo creato senza scegliere le voci si ferma: il messaggio '
            + 'dice di ricalcolarlo prima o, dove il suo ricalcolo si rifiuta (vedi sopra), di eliminarlo e crearlo di nuovo scegliendo le voci;');
        expect(t).toContain('finché un piano così si ricalcola, si ferma anche il piano nuovo con le voci scelte: il messaggio dice di ricalcolarlo prima, perché '
            + 'si scriva le voci con le sue cifre;');
        expect(t).toContain('non lo ferma un piano così di un altro esercizio con quote a giornale, un incasso o il conguaglio di un passaggio: non si ricalcola né '
            + 'si elimina, e il piano nuovo non prende le sue voci.');
        expect(t).not.toContain('finché un piano così è nel suo esercizio');
    });

    test('Coda 489 — l\'ultima voce di un piano, anche in bozza, non si elimina dal piano dei conti', () => {
        expect(testo()).toContain('Per la stessa ragione l\'ultima voce di un piano, anche in bozza, non si elimina dal piano dei conti.');
    });

    test('controprova — il pulsante resta bloccato nei tre casi di prima, che la pagina vede da sola', () => {
        expect(testo()).toContain('È bloccato in tre casi: se qualche rata è già a giornale;');
    });
});
