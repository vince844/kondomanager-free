// @vitest-environment jsdom

/**
 * Il modale «Sposta spesa» offre solo le voci dello stesso tipo, e il rifiuto del server lascia la via d'uscita.
 *
 * Lo spostamento fra una spesa e un'entrata cambia il totale del piano del doppio (€ 50,00 spostati: da € 960,00 a € 860,00): il server lo rifiuta
 * (`BudgetMovementService::moveBudget`, `Beta52VoceEntrataSpostaSpesaTest`) e il modale non offre le voci di altro tipo. Si monta il modale vero,
 * con la `Select` vera (reka-ui): le opzioni si leggono aprendo la casella, come le legge l'amministratore. Il `Select` non si stubba: uno stub
 * non proverebbe che la destinazione giusta resta raggiungibile dopo il rifiuto.
 *
 * Il rifiuto del server arriva come `form.errors.destination_id`: il test lo scrive sul modulo dopo l'invio, come fa Inertia, e controlla che il testo
 * stia sotto la destinazione e che la via d'uscita (un'altra destinazione dello stesso tipo, poi «Esegui spostamento») sia visibile e cliccabile.
 *
 * Quando il tipo manca (piano straordinario: le sorgenti sono fatture) il filtro lascia passare tutto, come prima, e il modale non dice che si sposta fra voci
 * dello stesso tipo: sarebbe falso. Un capitolo con spese ed entrate insieme arriva come «misto»: come sorgente si vede ma non si sceglie, come
 * destinazione non si offre; il modale dice perché. Le voci che l'avviso della pagina nomina per un importo negativo, di spesa o di entrata, e i
 * capitoli che le ripartiscono, arrivano già fuori dai menu, e come destinazione anche le voci di spesa scritte con il meno (`BudgetMovementService`):
 * il modale lo dice sotto la sorgente e sotto la destinazione, solo per il menu da cui il server ne ha tolta qualcuna (`speseNegativeFuori`,
 * `BudgetMovementService::speseNegativeFuoriDaiMenu`), perché le frasi rimandano all'avviso della pagina, che c'è solo con le sorgenti tolte (T297.57).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server (`Beta52VoceEntrataSpostaSpesaTest`); la scelta con la tastiera; il modo scuro e le larghezze.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';

let moduloCorrente: any = null;
vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => {
        moduloCorrente = reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn() });
        return moduloCorrente;
    },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ModalSpostaSpesa = (await import('@/components/gestionale/pianiRate/ModalSpostaSpesa.vue')).default;

const RIFIUTO = '«Spese generali» è una spesa e «Affitto antenna» è un\'entrata: il budget si sposta solo fra voci dello stesso tipo, altrimenti il totale del piano cambierebbe. Scegli una voce dello stesso tipo.';

const spese = [
    { id: 1, nome: 'Spese generali', tipo: 'spesa' }, { id: 3, nome: 'Pulizie', tipo: 'spesa' },
];
const entrate = [
    { id: 2, nome: 'Affitto antenna', tipo: 'entrata' }, { id: 4, nome: 'Proventi bacheca', tipo: 'entrata' },
];
const sorgenti = [
    { id: 1, nome: 'Spese generali', importo_residuo: 120000, formatted_residuo: '1.200,00', tipo: 'spesa' },
    { id: 2, nome: 'Affitto antenna', importo_residuo: 24000, formatted_residuo: '240,00', tipo: 'entrata' },
];

let finestra: VueWrapper | null = null;

async function monta(sources: Record<string, unknown>[], destinations: Record<string, unknown>[], speseNegativeFuori?: { sorgenti: boolean; destinazioni: boolean }) {
    finestra = mount(ModalSpostaSpesa, { attachTo: document.body, props: { show: true, pianoRateId: 1, condominioId: 1, sources: sources as never, destinations: destinations as never, speseNegativeFuori } });
    await flushPromises();
}

const caselle = () => [...document.body.querySelectorAll('[role="combobox"]')] as HTMLElement[];
const sorgente = () => caselle()[0];
const destinazione = () => caselle()[1];

async function apri(casella: HTMLElement) {
    casella.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, ctrlKey: false, pointerType: 'mouse' }));
    await flushPromises();
}

async function scegli(testo: string) {
    const opzione = [...document.body.querySelectorAll('[role="option"]')].find((o) => o.textContent?.includes(testo)) as HTMLElement | undefined;
    if (!opzione) throw new Error(`L'opzione «${testo}» non c'è nel menu.`);
    opzione.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' }));
    opzione.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' }));
    opzione.click();
    await flushPromises();
}

/** Le voci del menu aperto, come le legge chi guarda: il nome, e «(spenta)» dove non si può scegliere. */
const opzioniAperte = () => [...document.body.querySelectorAll('[role="option"]')].map((o) => (o.textContent ?? '').replace(/\s+/g, ' ').trim() + (o.hasAttribute('data-disabled') ? ' (spenta)' : ''));

const pulsante = () => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Esegui spostamento')) as HTMLButtonElement;

beforeEach(() => {
    (window as any).HTMLElement.prototype.hasPointerCapture = () => false;
    (window as any).HTMLElement.prototype.scrollIntoView = () => {};
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('le destinazioni offerte', () => {
    test('con la sorgente di spesa la destinazione offre solo le voci di spesa', async () => {
        await monta(sorgenti, [...spese, ...entrate]);
        await apri(sorgente());
        await scegli('Spese generali');

        await apri(destinazione());

        expect(opzioniAperte()).toEqual(['Spese generali (spenta)', 'Pulizie']);
    });

    test('con la sorgente di entrata la destinazione offre solo le voci di entrata', async () => {
        await monta(sorgenti, [...spese, ...entrate]);
        await apri(sorgente());
        await scegli('Affitto antenna');

        await apri(destinazione());

        expect(opzioniAperte()).toEqual(['Affitto antenna (spenta)', 'Proventi bacheca']);
    });

    test('controprova — senza il tipo sulla sorgente (piano straordinario) la destinazione offre tutte le voci', async () => {
        await monta([{ id: 9, nome: 'Ft. 12 (Impresa Rossi)', importo_residuo: 50000, formatted_residuo: '500,00' }], [...spese, ...entrate]);
        await apri(sorgente());
        await scegli('Ft. 12');

        await apri(destinazione());

        expect(opzioniAperte()).toEqual(['Spese generali', 'Pulizie', 'Affitto antenna', 'Proventi bacheca']);
    });

    test('cambiando la sorgente una destinazione non più ammessa si svuota', async () => {
        await monta(sorgenti, [...spese, ...entrate]);
        await apri(sorgente());
        await scegli('Spese generali');
        await apri(destinazione());
        await scegli('Pulizie');
        expect(moduloCorrente.destination_id).toBe('3');

        await apri(sorgente());
        await scegli('Affitto antenna');

        expect(moduloCorrente.destination_id).toBe('');
        expect(pulsante().disabled).toBe(true);
    });

    test('il modale dice che si sposta fra voci dello stesso tipo', async () => {
        await monta(sorgenti, [...spese, ...entrate]);
        await flushPromises();

        expect(document.body.textContent).toContain('Si sposta fra voci dello stesso tipo: spese con spese, entrate con entrate.');
    });
});

describe('un capitolo con spese ed entrate insieme («misto»)', () => {
    const capitolo = { id: 5, nome: 'Antenna', tipo: 'misto' };
    const sorgentiConMisto = [...sorgenti, { id: 5, nome: 'Antenna', importo_residuo: 50000, formatted_residuo: '500,00', tipo: 'misto' }];

    test('la sorgente «misto» c\'è nel menu ma non si sceglie, e dice perché', async () => {
        await monta(sorgentiConMisto, [...spese, ...entrate, capitolo]);
        await apri(sorgente());

        expect(opzioniAperte()).toEqual([
            'Spese generali (Disp: € 1.200,00)',
            'Affitto antenna (Disp: € 240,00)',
            'Antenna (capitolo con spese ed entrate: non si sposta) (spenta)',
        ]);
    });

    test('la destinazione «misto» non si offre a una sorgente di spesa', async () => {
        await monta(sorgentiConMisto, [...spese, ...entrate, capitolo]);
        await apri(sorgente());
        await scegli('Spese generali');

        await apri(destinazione());

        expect(opzioniAperte()).toEqual(['Spese generali (spenta)', 'Pulizie']);
    });

    test('il modale dice che i capitoli con spese ed entrate e le voci già coperte dal capitolo o dai sottoconti nel piano non si offrono', async () => {
        await monta(sorgentiConMisto, [...spese, ...entrate, capitolo]);
        await flushPromises();

        expect(document.body.textContent).toContain('Non si offrono i capitoli con spese ed entrate insieme');
        expect(document.body.textContent).toContain('sposta il budget sulla voce che c\'è');
    });
});

describe('senza il tipo sulle sorgenti (piano straordinario: le sorgenti sono fatture)', () => {
    const fatture = [{ id: 9, nome: 'Ft. 12 (Impresa Rossi)', importo_residuo: 50000, formatted_residuo: '500,00' }];
    const antenna = { id: 5, nome: 'Antenna', tipo: 'misto' };

    test('il modale non dice che si sposta fra voci dello stesso tipo né che non si offrono i capitoli con spese ed entrate: lì il menu offre tutto', async () => {
        await monta(fatture, [...spese, ...entrate, antenna]);
        await apri(sorgente());
        await scegli('Ft. 12');
        await apri(destinazione());

        expect(opzioniAperte()).toEqual(['Spese generali', 'Pulizie', 'Affitto antenna', 'Proventi bacheca', 'Antenna']);
        expect(document.body.textContent).not.toContain('Non si offrono i capitoli con spese ed entrate insieme');
        expect(document.body.textContent).not.toContain('Si sposta fra voci dello stesso tipo');
        expect(document.body.textContent).not.toContain('sposta il budget sulla voce che c\'è');
        expect(document.body.textContent).toContain('Sposta su (Destinazione)');
    });

    test('controprova — con il tipo sulle sorgenti il modale lo dice, anche prima di scegliere la sorgente', async () => {
        await monta(sorgenti, [...spese, ...entrate, antenna]);

        expect(document.body.textContent).toContain('Si sposta fra voci dello stesso tipo: spese con spese, entrate con entrate.');
        expect(document.body.textContent).toContain('Non si offrono i capitoli con spese ed entrate insieme');
    });
});

describe('le voci di spesa scritte con il meno', () => {
    // `BudgetMovementService::destinazioniPerLaPagina` e `sorgentiPerLaPagina` (`Beta53SpesaNegativaSpostaSpesaTest`, T297.41–T297.54): i menu arrivano già
    // senza queste voci, e il modale dice perché mancano.
    const testoNormalizzato = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

    test('T297.57 sotto la destinazione il modale nomina le voci di spesa scritte con il meno, i capitoli che le ripartiscono e le voci che l\'avviso nomina', async () => {
        await monta(sorgenti, [...spese, ...entrate], { sorgenti: true, destinazioni: true });

        expect(testoNormalizzato()).toContain('Non si offrono i capitoli con spese ed entrate insieme, né le voci che si sovrapporrebbero a un capitolo o ai sottoconti già nel '
            + 'piano: sposta il budget sulla voce che c\'è. Non si offrono neanche le voci di spesa scritte con il meno, che il piano addebiterebbe in positivo, né i '
            + 'capitoli che le ripartiscono, né le voci che l\'avviso della pagina nomina.');
    });

    test('T297.57 sotto la sorgente il modale dice che le voci contate con un importo negativo, di spesa o di entrata, e i capitoli che le ripartiscono, non si offrono', async () => {
        await monta(sorgenti, [...spese, ...entrate], { sorgenti: true, destinazioni: true });

        expect(testoNormalizzato()).toContain('Non si offrono le voci che il piano conta con un importo negativo, né i capitoli che le ripartiscono: '
            + 'l\'avviso in cima alla pagina le nomina.');
        expect(testoNormalizzato()).not.toContain('le voci di spesa che il piano conta con un importo negativo');
    });

    test('T297.57 senza voci di spesa scritte con il meno fuori dai menu il modale non ne parla, e la frase sulla destinazione resta', async () => {
        await monta(sorgenti, [...spese, ...entrate], { sorgenti: false, destinazioni: false });

        expect(testoNormalizzato()).not.toContain('scritte con il meno');
        expect(testoNormalizzato()).not.toContain('l\'avviso in cima alla pagina');
        expect(testoNormalizzato()).toContain('Non si offrono i capitoli con spese ed entrate insieme, né le voci che si sovrapporrebbero a un capitolo o ai sottoconti già nel '
            + 'piano: sposta il budget sulla voce che c\'è.');
        finestra?.unmount();
        document.body.innerHTML = '';

        await monta(sorgenti, [...spese, ...entrate]);
        expect(testoNormalizzato()).not.toContain('scritte con il meno');
        expect(testoNormalizzato()).not.toContain('l\'avviso in cima alla pagina');
    });

    test('T297.57 con una spesa scritta con il meno fuori dal piano, tolta solo dalle destinazioni, il modale lo dice sotto la destinazione e basta', async () => {
        await monta(sorgenti, [...spese, ...entrate], { sorgenti: false, destinazioni: true });

        expect(testoNormalizzato()).toContain('Non si offrono neanche le voci di spesa scritte con il meno, che il piano addebiterebbe in positivo, né i capitoli che le ripartiscono.');
        expect(testoNormalizzato()).not.toContain('l\'avviso in cima alla pagina');
        expect(testoNormalizzato()).not.toContain('l\'avviso della pagina');
    });

    test('controprova — T297.57 nel piano straordinario, dove le sorgenti sono fatture, il modale non lo dice', async () => {
        await monta([{ id: 9, nome: 'Ft. 12 (Impresa Rossi)', importo_residuo: 50000, formatted_residuo: '500,00' }], [...spese, ...entrate], { sorgenti: false, destinazioni: true });

        expect(testoNormalizzato()).not.toContain('scritte con il meno');
        expect(testoNormalizzato()).not.toContain('con un importo negativo');
    });
});

describe('il rifiuto del server e la via d\'uscita', () => {
    test('il testo sta sotto la destinazione e un\'altra destinazione dello stesso tipo si sceglie e si invia', async () => {
        await monta(sorgenti, [...spese, ...entrate]);
        await apri(sorgente());
        await scegli('Spese generali');
        await apri(destinazione());
        await scegli('Pulizie');
        moduloCorrente.errors = { destination_id: RIFIUTO }; // il rifiuto di un invio vecchio: la destinazione era un'entrata
        await flushPromises();

        expect(document.body.textContent).toContain(RIFIUTO);
        expect(document.body.textContent).toContain('Scegli una voce dello stesso tipo.');

        await apri(destinazione());
        expect(opzioniAperte()).not.toContain('Affitto antenna');
        await scegli('Pulizie');
        moduloCorrente.amount = '50.00';
        moduloCorrente.reason = 'Pulizie extra';
        await flushPromises();

        expect(pulsante().disabled).toBe(false);
    });
});
