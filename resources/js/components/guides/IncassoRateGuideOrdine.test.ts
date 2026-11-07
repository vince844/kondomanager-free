// @vitest-environment jsdom

/**
 * La guida dell'incasso dice come lavora il modulo dalla 1.12.0-beta.3: «Mostra scadute» tiene le righe che il server dice scadute (la
 * scadenza passata, emesse o no), l'automatico riempie le righe nell'ordine delle scadenze proposto dal programma, e quando il versamento
 * non segue quell'ordine il modulo chiede chi ha scelto, con la nota che va o non va sulla ricevuta.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import IncassoRateGuide from './IncassoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };

function testoDellaGuidaOrdine(): string {
    const w = mount(IncassoRateGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('IncassoRateGuide — l\'ordine delle scadenze', () => {
    test('«Mostra scadute» non guarda la descrizione: tiene le rate con la scadenza passata, anche non ancora emesse', () => {
        const testo = testoDellaGuidaOrdine();

        expect(testo).not.toContain('«saldo», «rata 0» o «pregresso»');
        expect(testo).toContain('Restano le rate con la scadenza passata, anche quelle non ancora emesse');
    });

    test('l\'automatico segue l\'ordine delle scadenze, e la guida dice che cosa succede quando il versamento non lo segue', () => {
        const testo = testoDellaGuidaOrdine();

        expect(testo).toContain('dalla scadenza più vecchia, emesse o no');
        expect(testo).toContain('Quando il versamento non segue l\'ordine delle scadenze');
        expect(testo).toContain('«Indicata da chi ha versato»');
        expect(testo).toContain('«Scelta da te»');
        expect(testo).toContain('va sulla ricevuta');
        expect(testo).toContain('fuori ordine, non dichiarata');
    });
});
