// @vitest-environment jsdom

/**
 * La guida della registrazione di un incasso (`IncassoRateGuide.vue`), scheda del credito, e il promemoria «In attesa della compensazione».
 *
 * Quando una persona non ha altro da versare ma una sua quota da pagare aspetta il credito che ha su un'altra quota della stessa rata, il promemoria
 * è `partial` con `da_compensare` maggiore di zero (`EventiRataCondomino::stato()`), e sulla rata emessa il portale lo mostra «In attesa della
 * compensazione» (`inAttesaDellaCompensazione` in `lib/eventi/compensazione.ts`: `status === 'partial'`, `is_emitted !== false`,
 * `da_compensare > 0`, `importo_restante <= 0`), con la cifra e la frase che la compensazione la registra l'amministratore. Lo stato resta finché
 * l'amministratore non usa il credito: la guida, dove dice che il credito non si usa da solo, deve dirlo.
 *
 * Le schede si montano tutte, con i segnaposto, e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: il promemoria segnalato o respinto, che conserva il suo stato fino al primo versamento; le altre schede; la traduzione.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import IncassoRateGuide from './IncassoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };

function testoDellaGuida(): string {
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

describe('IncassoRateGuide — «In attesa della compensazione» nel portale', () => {
    test('finché il credito non si usa, il portale mostra la rata «In attesa della compensazione», con la cifra e la frase della compensazione', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Finché non lo usi, il portale lo dice al condòmino: quando non ha altro da versare ma una sua quota da pagare aspetta il credito che ha su un\'altra quota della stessa rata');
        expect(testo).toContain('la rata emessa compare «In attesa della compensazione», con la cifra da compensare e la frase che la compensazione la registra l\'amministratore.');
    });

    test('il credito delle entrate dell\'anno: «usarlo o restituirlo» solo se sulla stessa rata non c\'è altro da pagare, altrimenti la compensazione', () => {
        const testo = testoDellaGuida();

        // `EventiRataCondomino::descrizione`: la frase del credito che resta solo con `$restante < 0 && $daCompensare === 0`; con una quota da compensare
        // ogni frase dice che la compensazione la registra l'amministratore.
        expect(testo).toContain('Il portale del condòmino mostra quel credito: se sulla stessa rata non ha altro da pagare dice che l\'amministratore può usarlo o restituirlo, altrimenti che la compensazione la registra l\'amministratore.');
        expect(testo).not.toContain('Il portale del condòmino mostra quel credito e dice che l\'amministratore può usarlo o restituirlo.');
    });

    test('controprova — la guida dice ancora che il credito non si usa finché non si preme «Usa credito», e che un secondo clic lo rilascia', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Nessun credito viene impiegato finché non premi «Usa credito»');
        expect(testo).toContain('Un secondo clic lo rilascia.');
    });
});
