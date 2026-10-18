// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le altre guide; la traduzione (la guida è solo in italiano); il tema scuro e la lunghezza della pagina.
 *
 * La guida «Ruoli e usufrutto» (`RipartoUsufruttoGuide.vue`) e le voci di entrata: un affitto o un canone sono un frutto civile e spettano
 * all'usufruttuario (art. 984 c.c.), senza dire che l'articolo ammette un diverso titolo: che il titolo possa dire altro, e valga fra le parti, lo dice
 * la guida a parte; il programma non lo applica da solo, decide la voce; con un usufrutto inserito a mano la
 * data di inizio a metà anno non divide l'entrata (salvo una riga di usufrutto chiusa il giorno prima) e la data di fine sì; il giorno per
 * giorno lo fa «Registra passaggio».
 *
 * Una voce di entrata del programma non è solo un affitto o un canone (un rimborso, un recupero spese non sono frutti civili): la guida non dice che
 * ogni entrata è un frutto civile, e dice che la ragione dell'art. 984 c.c. vale «per un affitto o un canone».
 *
 * Le cifre dell'esempio sono quelle che il motore dà (`Beta52UsufruttoVoceDiEntrataTest.php`): spesa € 1.200,00 sul «Proprietario» e
 * affitto € 240,00, nuda proprietaria Elsa e usufruttuario Ugo. Con l'affitto sul «Proprietario» Elsa paga € 960,00 e Ugo niente; con
 * l'affitto sull'«Usufruttuario» Elsa paga € 1.200,00 e a Ugo vanno € 240,00 a credito.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; si cercano i pezzi, non le frasi intere.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import RipartoUsufruttoGuide from './RipartoUsufruttoGuide.vue';

function testoDellaGuida(): string {
    const w = mount(RipartoUsufruttoGuide, {
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

describe('RipartoUsufruttoGuide — le voci di entrata e l\'art. 984 c.c.', () => {
    test('controprova — la guida dice ancora che l\'usufruttuario deve le spese ordinarie (art. 1004 c.c.) e il nudo proprietario le straordinarie (art. 1005 c.c.)', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('l\'usufruttuario deve le spese ordinarie (art. 1004 c.c.)');
        expect(testo).toContain('il nudo proprietario le straordinarie (art. 1005 c.c.)');
    });

    test('dice che un affitto o un canone che il condominio incassa è un frutto civile e spetta all\'usufruttuario (art. 984 c.c.), e che se il titolo dice altro vale fra le parti', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Un affitto o un canone che il condominio incassa è un frutto civile e spetta all\'usufruttuario (art. 984 c.c.); se il titolo dell\'usufrutto dice altro, vale fra le parti.');
        expect(testo).toContain('L\'art. 984 c.c. dà gli affitti e i canoni all\'usufruttuario; il programma non lo applica da solo');
    });

    test('controprova — non attribuisce all\'art. 984 c.c. la riserva del titolo: «salvo diverso titolo» non c\'è', () => {
        expect(testoDellaGuida()).not.toContain('salvo diverso titolo');
    });

    test('controprova — non dice che ogni entrata è un frutto civile: un rimborso o un recupero non lo sono', () => {
        const testo = testoDellaGuida();

        expect(testo).not.toContain('Le entrate che il condominio incassa, un affitto o un canone, sono frutti civili');
        expect(testo).not.toContain('le entrate sono frutti civili');
    });

    test('dice che si imposta associando la voce di spesa o di entrata alla tabella, e che il coefficiente dice chi sostiene la spesa o riceve l\'entrata', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('associando la voce di spesa o di entrata alla tabella millesimale');
        expect(testo).toContain('Dice quale ruolo sostiene la spesa o riceve l\'entrata');
    });

    test('dice che decide la voce, nei due versi, e che dove non c\'è usufrutto l\'entrata va al proprietario', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Per una voce di entrata è l\'impostazione che dà l\'entrata al nudo proprietario');
        expect(testo).toContain('Per una voce di entrata è l\'impostazione dell\'art. 984 c.c.');
        expect(testo).toContain('Dove non c\'è usufrutto l\'entrata va al proprietario');
    });

    test('dice che il programma non applica l\'art. 984 c.c. da solo: decide la voce', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('il programma non lo applica da solo, decide la voce');
        expect(testo).not.toContain('il programma lo applica da solo');
    });

    test('nel passaggio dice come tenere un\'entrata al nudo proprietario: togliere la spunta', () => {
        expect(testoDellaGuida()).toContain('Togli la spunta a un\'entrata che deve restare al nudo proprietario');
    });

    test('l\'esempio ha le cifre del motore: € 960,00 con l\'affitto sul «Proprietario», € 1.200,00 e € 240,00 a credito con l\'affitto sull\'«Usufruttuario»', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Elsa paga € 960,00');
        expect(testo).toContain('Elsa paga € 1.200,00 e a Ugo vanno € 240,00 a credito');
    });

    test('avverte che con un usufrutto inserito a mano una data di inizio a metà anno non divide l\'entrata e una data di fine sì', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('una data di inizio a metà anno non divide l\'entrata giorno per giorno, salvo una riga di usufrutto chiusa il giorno prima');
        expect(testo).toContain('l\'usufruttuario la riceve dall\'inizio dell\'anno');
        expect(testo).toContain('Una data di fine invece la divide');
    });

    test('dice che il giorno per giorno lo fa «Registra passaggio»', () => {
        expect(testoDellaGuida()).toContain('«Registra passaggio» divide l\'entrata giorno per giorno');
    });

    test('nel passaggio le entrate passano all\'«Usufruttuario» con l\'etichetta «Entrata»; per un affitto o un canone la ragione è l\'art. 984 c.c., non l\'art. 1004 c.c.', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Anche le voci di entrata sul «Proprietario» passano sull\'«Usufruttuario»');
        expect(testo).toContain('etichetta «Entrata»');
        expect(testo).toContain('per un affitto o un canone la ragione è l\'art. 984 c.c., non l\'art. 1004 c.c., che riguarda le spese');
    });
});
