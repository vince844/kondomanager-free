// @vitest-environment jsdom

/**
 * 1.11.0-beta.51 — la guida in-app «Registrare un passaggio» (`PassaggioProprietaGuide.vue`): il punto sulla fermata dice che un passaggio che
 * cambia chi paga al posto dell'inquilino si ferma sulla parte della fine «Scrivi» e che «conta solo il periodo in cui nessun conduttore era
 * registrato: i giorni di un conduttore li rimborsa lui, con il prospetto». Vero finché il conduttore c'è; DL7 lo smentisce quando esce (Coda 254): i
 * giorni che copriva tornano a chi paga al posto dell'inquilino da quel giorno, e se questo non è più chi ha preso la coppia la parte non si sposta da sola.
 * La guida deve dirlo, nel punto stesso, e il suo incipit deve dire che si ferma anche la fine di un conduttore che libera i giorni.
 *
 * Nessun test leggeva quella frase (misurato: grep su tests, resources, lang, docs: solo la guida e quattro documenti che la citano). Si monta la guida con i
 * segnaposto del foglio e si legge il testo; la guida è solo in italiano.
 *
 * Il testo che la guida deve avere: all'incipit del punto, «… un passaggio che cambia chi paga al posto dell'inquilino, o la fine di un
 * conduttore che ne libera i giorni, si ferma su quella parte»; dopo «lo storico del passaggio lo ricorda», «Lo stesso vale quando finisce la
 * locazione di un conduttore entrato dopo, anche se è la stessa persona uscita e poi rientrata: i giorni che copriva tornano a chi paga al posto
 * dell'inquilino da quel giorno, …», con l'esempio «da € 30,58 a € 61,15 con la fine di Luca». I test cercano questi pezzi, non la frase intera: le
 * parole fra l'uno e l'altro le sceglie chi scrive.
 *
 * La frase che c'era già («Conta solo il periodo in cui nessun conduttore era registrato») deve restare.
 *
 * Cosa NON copre: le altre guide; la traduzione (la guida è solo in italiano).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';

function testoDellaGuida(): string {
    const w = mount(PassaggioProprietaGuide, {
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

describe('PassaggioProprietaGuide — la fine di un conduttore (Coda 254)', () => {
    test('controllo — la guida dice ancora che conta il periodo in cui nessun conduttore era registrato e che i giorni di un conduttore li rimborsa lui', () => {
        expect(testoDellaGuida()).toContain('Conta solo il periodo in cui nessun conduttore era registrato: i giorni di un conduttore li rimborsa lui, con il prospetto.');
    });

    test('l\'incipit del punto dice che si ferma anche la fine di un conduttore che libera i giorni', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('la fine di un conduttore che ne libera i giorni');
        expect(testo).toContain('si ferma su quella parte');
    });

    test('dice che lo stesso vale quando finisce la locazione di un conduttore entrato dopo, anche se è la stessa persona uscita e poi rientrata', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Lo stesso vale quando finisce la locazione di un conduttore entrato dopo');
        expect(testo).toContain('la stessa persona uscita e poi rientrata');
    });

    test('dice dove vanno i giorni (a chi paga al posto dell\'inquilino da quel giorno), che la parte non si sposta da sola, e che il pannello chiede la spunta', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('tornano a chi paga al posto dell\'inquilino da quel giorno');
        expect(testo).toContain('la parte non si sposta da sola');
        expect(testo).toContain('chiede la spunta');
    });

    test('dà l\'esempio della parte che sale (da € 30,58 a € 61,15 con la fine di Luca) quando per quei giorni c\'era già una fermata', () => {
        expect(testoDellaGuida()).toContain('da € 30,58 a € 61,15');
    });
});

/**
 * La chiusura dei subentri (1.11.0-beta.51): le frasi della guida che dicono ciò che la beta cambia oltre alla fine di un conduttore — il piano da
 * ricalcolare dopo un annullamento, la guardia dell'anteprima dell'inizio di una locazione, l'inizio che azzera una fermata, la nota di solidarietà
 * al netto della coppia, il cancello del defunto dopo i saldi e le parole del prospetto. Si cercano i pezzi, non le frasi intere.
 */
describe('PassaggioProprietaGuide — la chiusura dei subentri', () => {
    test('dopo l\'annullamento di una vendita, di un usufrutto o di una successione il piano va ricalcolato prima di emettere, incassare o rimborsare', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Dopo l\'annullamento di una vendita, di un usufrutto o di una successione');
        expect(testo).toContain('va ricalcolato prima di emettere, di incassare o di rimborsare');
        expect(testo).toContain('Ricalcola prima il piano …');
        expect(testo).toContain('La locazione resta fuori.');
    });

    test('l\'inizio di una locazione che sfora le quote si rifiuta già nell\'anteprima, con il testo della registrazione', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('non può superare 100');
        expect(testo).toContain('si rifiuta già nell\'anteprima');
    });

    test('l\'inizio che azzera una fermata è un\'informazione, senza una seconda spunta, e il residuo di più fermate è uno per persona', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('l\'inizio azzera la fermata');
        expect(testo).toContain('senza una seconda spunta');
        expect(testo).toContain('il residuo da regolare è uno solo per persona');
    });

    test('la nota di solidarietà è al netto della coppia, che sta nella stessa frase, e torna lorda se la coppia è assorbita da un secondo piano', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('è al netto della coppia del conguaglio');
        expect(testo).toContain('torna alla cifra lorda');
    });

    test('il cancello del defunto ritorna dopo i saldi con un\'altra frase, e con due successioni la cifra si divide per ruolo o decide l\'amministratore', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('i saldi ci sono già');
        expect(testo).toContain('la cifra si divide per ruolo');
        expect(testo).toContain('decide l\'amministratore');
    });

    test('il prospetto segue una voce che matura dopo un cambio d\'inquilino e legge un totale negativo come «a favore di»', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Una voce che matura tutta dopo un cambio d\'inquilino non è di chi è uscito');
        expect(testo).toContain('si legge «a favore di …» e non con il segno meno');
    });
});
