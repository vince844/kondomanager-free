// @vitest-environment jsdom

/**
 * La guida del Wallet (`SaldiGuide.vue`), sezione «A chi è intestato», e i limiti del modulo che aggiunge un saldo.
 *
 * Il server ammette, per esercizio, gestione e unità, un solo saldo per l'intero immobile e uno per persona, senza contare le righe scritte da un
 * passaggio (`CreateSaldoRequest`: `->whereNull('subentro_id')`, poi `->whereNull('anagrafica_id')->exists()` o `->where('anagrafica_id', …)->exists()`).
 * Il modulo conta allo stesso modo (`SaldiDetailPanel.vue`, `haUnSaldoSolidale` e `idConUnSaldo`, con `s.subentro_id == null`): dove l'intero
 * immobile ha già il suo saldo la scelta «Intero immobile» è spenta, con la frase «Questa gestione ha già un saldo per l'intero immobile: modifica
 * quello esistente.», e il menu di «Soggetto specifico» offre solo chi non ha già il suo. La guida non lo diceva.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: il saldo che non si modifica perché un piano che non si riscrive più l'ha assorbito; la traduzione.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import SaldiGuide from './SaldiGuide.vue';

function testoDellaGuida(): string {
    const w = mount(SaldiGuide, {
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

describe('SaldiGuide — a chi è intestato il saldo', () => {
    test('un solo saldo per l\'intero immobile e uno per persona, per gestione ed esercizio, senza contare quelli scritti da un passaggio', () => {
        // «può avere»: è il massimo che il server ammette, non un saldo che c'è sempre.
        expect(testoDellaGuida()).toContain('Per ogni gestione ed esercizio l\'unità può avere un solo saldo per l\'intero immobile e uno per persona, senza contare quelli scritti da un passaggio');
    });

    test('dove l\'intero immobile ha già il suo saldo la scelta è spenta, e il menu della persona offre solo chi non ha già il suo', () => {
        expect(testoDellaGuida()).toContain('dove l\'intero immobile ha già il suo, la scelta è spenta e il modulo chiede di modificare quello esistente; il menu della persona offre solo chi non ha già il suo.');
    });

    test('controprova — la guida dice ancora che la scelta cambia chi si vedrà addebitato il saldo', () => {
        expect(testoDellaGuida()).toContain('La scelta cambia chi se lo vedrà addebitato quando le rate vengono emesse.');
    });
});
