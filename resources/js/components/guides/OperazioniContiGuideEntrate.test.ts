// @vitest-environment jsdom

/**
 * La guida del piano dei conti (`OperazioniContiGuide.vue`) e le voci di entrata.
 *
 * Il badge «Preventivo» della testata somma le sole voci di spesa (`PianoContiController`: `Conto::segnoDelTipo($conto->tipo) > 0` va nel
 * preventivo, ogni altro tipo nelle entrate, in valore assoluto), e con delle entrate accanto compare il badge «Entrate», che nel `title` dice quanto
 * resta da ripartire (`ContiNew.vue`, `v-if="(props.totaleEntrate ?? 0) > 0"`). La guida diceva soltanto «Preventivo è il totale deliberato», e con
 * un affitto fra le voci quel numero non è più ciò che il piano chiede.
 *
 * L'importo di una voce si scrive senza il segno: il modale della voce nuova non lascia digitare il meno e il server lo rifiuta
 * (`CreateContoRequest`, `ImportoSenzaSegno`); il segno lo decide il tipo («Spesa (uscita)» o «Entrata», le etichette del modale), e le entrate si sottraggono alle spese
 * (`Conto::segnoDelTipo`). La guida, nel punto «Creare le voci», non lo diceva.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: la modifica di una voce già negativa scritta con una versione precedente (il server rifiuta il meno solo se l'importo
 * cambia: lo coprono i test del modale e della richiesta); la traduzione; il tema scuro.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import OperazioniContiGuide from './OperazioniContiGuide.vue';

function testoDellaGuida(): string {
    const w = mount(OperazioniContiGuide, {
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

describe('OperazioniContiGuide — le voci di entrata', () => {
    test('in testata «Preventivo» è il totale delle voci di spesa, e con delle entrate accanto compare «Entrate» con il da ripartire sul mouse', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Preventivo è il totale deliberato delle voci di spesa e non cresce quando si sfora');
        expect(testo).toContain('con voci di entrata accanto compare Entrate, il loro totale, e passandoci sopra il mouse leggi quanto resta da ripartire');
    });

    test('controprova — la frase vecchia, che metteva le entrate nel preventivo, non c\'è più', () => {
        expect(testoDellaGuida()).not.toContain('Preventivo è il totale deliberato e non cresce');
    });

    test('nel punto «Creare le voci»: l\'importo si scrive senza il segno, lo decide il tipo di movimento, e le entrate si sottraggono alle spese', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('L\'importo si scrive senza il segno, anche per un affitto o un canone: lo decide il Tipo di movimento, «Spesa (uscita)» o «Entrata», e le entrate si sottraggono alle spese.');
    });

    test('controprova — la guida dice ancora come si crea un sottoconto e che cosa sono le sopravvenienze in testata', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('seleziona quella voce nel campo Capitolo padre');
        expect(testo).toContain('Sopravvenienze raccoglie a parte le voci nate fuori preventivo');
    });
});
