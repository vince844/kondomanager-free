// @vitest-environment jsdom

/**
 * La guida del dettaglio piano rate (`PianoRateDettaglioGuide.vue`) e le voci di entrata: «Sposta spesa», l'etichetta «Entrata» della sezione
 * «Copertura spese» e il capitolo con la copia a zero o negativa.
 *
 * «Sposta spesa». Oltre al tipo (spese con spese, entrate con entrate), un capitolo che il piano divide fra spese ed entrate non si sposta e non
 * riceve (`Conto::eMisto`; il modale spegne la sorgente e `destinazioniAmmesse` in `budgetMovements.ts` non dà destinazioni, il server la rifiuta in
 * `BudgetMovementService::controllaStessoTipo`), e il menu non offre le voci che si sovrapporrebbero a un capitolo o a dei sottoconti già nel piano
 * (`BudgetMovementService::destinazioniPerLaPagina`): di due righe sovrapposte il motore conta solo quella del capitolo (`sovrapposteAlPiano`), quindi
 * verso un sottoconto il budget spostato si perde, e verso un capitolo si perdono le righe dei suoi sottoconti. Dove la sovrapposizione c'è già (un capitolo con la sua riga accanto a quelle dei suoi
 * sottoconti, e il piano conta solo quella del capitolo) la pagina mostra un riquadro (`avvisoRigheSovrapposte`) che dice come uscirne.
 *
 * «Entrata». La sezione «Copertura spese» mette l'etichetta «Entrata» su una voce che il piano conta con il segno meno (`PianoRateResource`,
 * `'tipo' => $c->segno(...) < 0 ? 'entrata' : 'spesa'`) e ne mostra l'importo con il meno; con delle entrate la riga sotto il titolo dice
 * «da ripartire» con le spese e le entrate fra parentesi, al posto di «totale preventivo» (`PianiRateShow.vue`, `totale_entrate > 0`).
 *
 * Il capitolo. «Ricalcola» si ferma anche per un capitolo che ha nel piano una copia a zero o negativa da dividere fra i sottoconti, se uno di questi
 * ha un importo negativo, di spesa o di entrata (`SpeseNegativeNelPiano::visita`, il filtro dei `$negativi` non guarda il tipo); corretto il sottoconto
 * il ricalcolo passa, ma la copia resta, e la strada è eliminare il piano e ricrearlo (`SpeseNegativeNelPiano::frasiDiCorrezione`).
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo del primo foglio, quello aperto di default, con gli spazi normalizzati; la guida è
 * solo in italiano.
 *
 * I piani straordinari. Lì il menu della sorgente elenca le fatture (`PianoRateController`, il ramo delle sorgenti del piano straordinario), ma il
 * server legge la sorgente come una voce (`BudgetMovementController`): la guida dice di non usare «Sposta spesa» in quei piani.
 *
 * Cosa resta scoperto: il comportamento di «Sposta spesa» nei piani straordinari (aperto in roadmap); le altre schede della guida; la traduzione.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';

function testoDellaGuida(): string {
    const w = mount(PianoRateDettaglioGuide, {
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

describe('PianoRateDettaglioGuide — «Sposta spesa» e le voci di entrata', () => {
    test('nei piani straordinari la guida dice di non usare «Sposta spesa», e perché', () => {
        expect(testoDellaGuida()).toContain('Nei piani straordinari «Sposta spesa» non funziona: il menu della sorgente elenca le fatture, ma lo spostamento lavora sulle voci, e il programma lo rifiuta sempre.');
    });

    test('un capitolo che il piano divide fra spese ed entrate non si sposta e non riceve budget', () => {
        expect(testoDellaGuida()).toContain('Un capitolo che il piano divide fra spese ed entrate non si sposta e non riceve budget');
    });

    test('il menu non offre le voci che si sovrapporrebbero a un capitolo o a dei sottoconti già nel piano, e dice perché', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('il menu non offre le voci che si sovrapporrebbero a un capitolo o a dei sottoconti già nel piano, perché di due righe sovrapposte il piano conta solo quella del capitolo');
        // Verso un capitolo i cui sottoconti sono nel piano il budget spostato si conta, ma le righe dei sottoconti non più: la ragione vale per i due casi.
        expect(testo).not.toContain('perché il budget spostato non verrebbe contato');
    });

    test('dove la sovrapposizione c\'è già, un riquadro in cima alla pagina lo dice e indica come uscirne', () => {
        expect(testoDellaGuida()).toContain('Se nel piano un capitolo ha la sua riga insieme a quelle dei suoi sottoconti e il piano conta solo quella del capitolo, un riquadro in cima alla pagina lo dice e indica come uscirne.');
    });

    test('controprova — la guida dice ancora che si sposta fra voci dello stesso tipo e che il menu offre solo le voci del tipo della sorgente', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Si sposta fra voci dello stesso tipo: spese con spese, entrate con entrate');
        expect(testo).toContain('il menu della destinazione offre solo le voci del tipo della sorgente');
    });

    test('il tipo è quello con cui il piano conta la voce: un capitolo di spesa che passa l\'importo ai soli sottoconti di entrata è un\'entrata', () => {
        // `BudgetMovementService::tipoDelSegno`: `Conto::segno()` alla data del piano, non la colonna `tipo` del capitolo.
        expect(testoDellaGuida()).toContain('entrate con entrate, come il piano le conta (un capitolo di spesa che passa l\'importo ai soli sottoconti di entrata conta come un\'entrata).');
    });
});

describe('PianoRateDettaglioGuide — l\'etichetta «Entrata» nella sezione «Copertura spese»', () => {
    test('l\'etichetta si aggiunge alle altre su una voce contata con il segno meno, e l\'importo compare con il meno', () => {
        expect(testoDellaGuida()).toContain('Entrata — si aggiunge alle altre su una voce che il piano conta con il segno meno, come un affitto o un canone: l\'importo compare con il meno e si sottrae alle spese.');
    });

    test('con delle entrate la riga sotto il titolo dice quanto resta da ripartire, con le spese e le entrate fra parentesi', () => {
        expect(testoDellaGuida()).toContain('Con delle entrate la riga sotto il titolo della sezione dice quanto resta da ripartire, con le spese e le entrate fra parentesi, al posto del totale preventivo.');
    });

    test('controprova — le quattro etichette di prima ci sono ancora', () => {
        const testo = testoDellaGuida();

        for (const etichetta of ['Gruppo —', 'Integra —', 'Parziale —', 'Standard —']) {
            expect(testo).toContain(etichetta);
        }
    });
});

describe('PianoRateDettaglioGuide — il capitolo con la copia a zero o negativa', () => {
    test('«Ricalcola» si ferma anche per il capitolo, se un sottoconto fra cui divide la copia è negativo, anche di entrata', () => {
        expect(testoDellaGuida()).toContain('«Ricalcola» si ferma anche per un capitolo che nel piano ha una copia a zero o negativa da dividere fra i sottoconti, se uno di questi ha un importo negativo, anche di entrata');
    });

    test('corretto il sottoconto il ricalcolo passa, ma la copia resta: si elimina il piano e si ricrea', () => {
        expect(testoDellaGuida()).toContain('corretto il sottoconto, «Ricalcola» passa, ma la copia del capitolo resta com\'era, e per cambiarla si elimina il piano e si ricrea.');
    });

    test('controprova — la frase sulla voce di spesa negativa delle versioni precedenti c\'è ancora', () => {
        expect(testoDellaGuida()).toContain('Le versioni precedenti permettevano una voce di spesa con un importo negativo, e il calcolo delle quote la addebita in positivo.');
    });
});
