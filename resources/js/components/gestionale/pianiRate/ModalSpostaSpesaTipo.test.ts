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
 * destinazione non si offre; il modale dice perché.
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

async function monta(sources: Record<string, unknown>[], destinations: Record<string, unknown>[]) {
    finestra = mount(ModalSpostaSpesa, { attachTo: document.body, props: { show: true, pianoRateId: 1, condominioId: 1, sources: sources as never, destinations: destinations as never } });
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
