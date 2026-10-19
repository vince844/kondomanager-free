// @vitest-environment jsdom

/**
 * La guida della regolazione immediata (`RegolazioneImmediataGuide.vue`) e il residuo del capitolo.
 *
 * La guida diceva «Il capitolo mostra il suo residuo e il movimento pesa sul budget come ogni spesa.»: la prima metà è falsa, perché il menu del capitolo
 * della regolazione non riceve un residuo (solo id, nome, capitolo padre ed etichetta) e la pagina non ne nomina uno, mentre la fattura lo mostra per ogni
 * voce. La seconda metà è vera: la regolazione alza il consuntivo della voce e il residuo che la fattura mostra scende della stessa cifra
 * (`Beta53RegolazioneResiduoTest`, che firma la frase nuova con il codice).
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: la regolazione oltre il preventivo, che si registra senza chiedere di motivare lo sforo come fa la fattura (la guida non lo dice);
 * la descrizione in testa alla pagina della regolazione («budget e riparto funzionano come per una fattura»); il tema scuro.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import RegolazioneImmediataGuide from './RegolazioneImmediataGuide.vue';

function testoDellaGuida(): string {
    const w = mount(RegolazioneImmediataGuide, {
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

describe('RegolazioneImmediataGuide — il residuo del capitolo', () => {
    test('la guida dice che il capitolo non mostra il residuo, a differenza della fattura, e che la spesa alza il consuntivo della voce', () => {
        expect(testoDellaGuida()).toContain('Il capitolo non mostra il residuo, a differenza della fattura: la spesa alza il consuntivo della voce.');
    });

    test('la guida non dice che il capitolo mostra il suo residuo', () => {
        expect(testoDellaGuida()).not.toContain('mostra il suo residuo');
    });

    test('controprova — la guida dice ancora che il capitolo è sempre una voce di spesa e che i fondi non compaiono fra le casse', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Il capitolo è sempre una voce di spesa: un\'entrata non si registra qui, perché la regolazione è un\'uscita di cassa.');
        expect(testo).toContain('I fondi non compaiono fra le casse');
    });
});
