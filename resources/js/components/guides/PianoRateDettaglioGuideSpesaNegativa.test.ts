// @vitest-environment jsdom

/**
 * La guida del dettaglio piano rate spiega la voce di spesa negativa con il soggetto giusto, la condizione giusta e il costo della via d'uscita.
 *
 * La guida (`PianoRateDettaglioGuide.vue`) dice che cosa fa il cestino e che cosa succede a una voce di spesa con l'importo negativo (le versioni precedenti lo
 * permettevano): il calcolo delle quote la addebita in positivo, «Ricalcola» si ferma dove succede, e la strada passa dal cestino e da «Sincronizza». Il testo deve
 * dire che la copia dell'importo blocca la correzione solo quando il piano la tiene (una voce senza copia si corregge e basta), che togliere la voce ne cancella i
 * tratti di competenza (si riscrivono solo ricreando il piano), che un sottoconto tolto dal piano non si riaggiunge con «Sincronizza» (riaggiunge solo i capitoli),
 * che la copia di un capitolo che la divide fra i sottoconti non cambia togliendo il capitolo (la pagina ripropone i sottoconti) e che riportare in bozza
 * un piano approvato azzera il numero del verbale e la nota.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo del primo foglio, quello aperto di default; la guida è solo in italiano.
 *
 * Cosa resta scoperto: le altre sezioni della guida; la traduzione.
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

describe('PianoRateDettaglioGuide — la voce di spesa negativa', () => {
    test('il soggetto della frase c\'è: sono le versioni precedenti a permettere la voce negativa, e il calcolo la addebita in positivo', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Le versioni precedenti permettevano una voce di spesa con un importo negativo, e il calcolo delle quote la addebita in positivo.');
    });

    test('la correzione della voce non basta solo se il piano ne tiene una copia', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Se il piano tiene una copia dell\'importo della voce, correggere la voce non basta');
        expect(testo).not.toContain('Correggere l\'importo della voce non basta, perché il piano ne tiene una copia');
    });

    test('un sottoconto tolto dal piano non si riaggiunge con «Sincronizza», e nemmeno la copia di un capitolo con i sottoconti: si elimina il piano e si ricrea', () => {
        const testo = testoDellaGuida();

        // `SpeseNegativeNelPiano::frasiDiCorrezione`: per la copia di un capitolo che la divide fra i sottoconti (`capitolo`) la strada è «elimina il piano e ricrealo».
        expect(testo).toContain('si riaggiunge con «Sincronizza» (un sottoconto, o un capitolo che divide la copia fra i sottoconti, no: si elimina il piano e si ricrea)');
    });

    test('«Ricalcola» si ferma dove il calcolo addebiterebbe la voce in positivo, non per ogni voce negativa nel piano', () => {
        const testo = testoDellaGuida();

        // `SpeseNegativeNelPiano::visita`: il sottoconto negativo di un capitolo con la copia positiva ferma il piano solo se i pesi dei sottoconti sommano più di zero.
        expect(testo).toContain('«Ricalcola» si ferma dove il calcolo la addebiterebbe in positivo, la nomina e dice che cosa fare');
        expect(testo).not.toContain('«Ricalcola» si ferma finché ce n\'è una nel piano');
    });

    test('togliere la voce ne cancella i tratti di competenza, da annotare prima e da riscrivere ricreando il piano', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('togliendola si perdono i suoi tratti di competenza, da annotare prima e da riscrivere ricreando il piano');
    });

    test('un piano approvato va prima riportato in bozza, e questo azzera il numero del verbale e la nota dell\'approvazione', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Un piano approvato va prima riportato in bozza, e questo azzera il numero del verbale e la nota dell\'approvazione: annotali prima.');
    });

    test('«Sposta spesa» non lavora con la voce che il piano conta con un importo negativo, e non dà budget a una spesa scritta con il meno', () => {
        const testo = testoDellaGuida();

        // `BudgetMovementService::controllaSpesaNegativa`: sorgente o destinazione nominata dall'avviso (con il capitolo del piano da cui il motore scende fino
        // a lei); come destinazione anche ogni spesa con l'importo negativo e il capitolo fuori dal piano che passerebbe la copia a un sottoconto così.
        expect(testo).toContain('Una voce di spesa che il piano conta con un importo negativo, il suo o quello della copia, non cede e non riceve budget, e nemmeno il '
            + 'capitolo del piano che la ripartisce: il piano la addebita in positivo, e lo spostamento cambierebbe il totale. È la voce che nomina l\'avviso in cima alla pagina. '
            + 'Non riceve budget neanche una voce di spesa scritta con il meno che il piano non conta così, né un capitolo fuori dal piano che la ripartirebbe: il piano '
            + 'la addebiterebbe in positivo. I menu non offrono queste voci dove il programma le rifiuterebbe.');
    });

    test('T297.54 la guida non dice che il capitolo sopra la voce negativa non riceve budget comunque: lo dice del capitolo che la ripartisce', () => {
        const testo = testoDellaGuida();

        // Un capitolo con le sue tabelle sopra un sottoconto con la sua riga negativa non lo ripartisce, e lo spostamento lascia il totale com'è.
        expect(testo).not.toContain('il capitolo del piano che la porta');
        expect(testo).not.toContain('che lo passerebbe a un suo sottoconto così');
    });

    test('controprova — la guida dice ancora che con più voci così si elimina il piano e si ricrea', () => {
        expect(testoDellaGuida()).toContain('si elimina il piano e si ricrea');
    });
});
