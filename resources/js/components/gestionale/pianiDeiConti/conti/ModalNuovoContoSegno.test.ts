// @vitest-environment jsdom

/**
 * Il modulo «Nuova voce» non fa scrivere il segno dell'importo.
 *
 * Il segno lo decide il tipo della voce (spesa: positivo; entrata: il motore la legge con il segno opposto): un meno scritto sull'importo farebbe
 * addebitare in positivo una spesa a −€ 240,00 (piano da € 960,00 a € 1.440,00). Come nel Wallet dei saldi e nel modulo del piano, il campo ha
 * `disableNegative`: scrivendo «-240,00» si legge «240,00». Il server rifiuta comunque il meno (`CreateContoRequest`, `Beta52VoceEntrataImportoNegativoTest`):
 * il rifiuto compare sotto il campo, il campo si riscrive e «Entrata» si sceglie.
 *
 * `vue-select` si sostituisce come nel test di «Modifica voce»: il suo albero non c'entra con l'importo.
 *
 * Il tasto «−» si prova con gli eventi di tastiera, come arrivano al campo: il meno digitato per primo, o dopo le cifre, non lascia mai un importo negativo nel modulo.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il modulo di modifica (`ModalModificaContoSegno.test.ts`); la tastiera di un telefono.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', template: '<div />' } }));
vi.mock('@/composables/useTabelle', () => ({ useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }) }));
vi.mock('@/composables/useCapitoliConti', () => ({ useCapitoliConti: () => ({ capitoli: { value: [] }, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ModalNuovoConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue')).default;

let finestra: VueWrapper | null = null;

async function monta() {
    finestra = mount(ModalNuovoConto, { attachTo: document.body, props: { show: true, condominioId: 1, esercizioId: 1, pianoContoId: 1, fornitori: [] } });
    await flushPromises();
}

const campo = () => document.body.querySelector('input#importo') as HTMLInputElement;
const modulo = () => (finestra!.vm as any).form;

async function tasto(chiave: string) {
    const evento = new KeyboardEvent('keydown', { key: chiave, bubbles: true, cancelable: true });
    campo().dispatchEvent(evento);
    await flushPromises();
    if (!evento.defaultPrevented && chiave.length === 1) {
        campo().value = campo().value + chiave;
        campo().dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
    }
}

async function scrivi(testo: string) {
    campo().value = testo;
    campo().dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('il campo dell\'importo', () => {
    test('scrivendo «-240,00» si legge «240,00», nel campo e nel modulo', async () => {
        await monta();

        await scrivi('-240,00');

        expect(campo().value).toBe('240,00');
        expect(modulo().importo).toBe('240,00');
    });

    test('digitando il meno e poi le cifre il campo e il modulo restano senza segno', async () => {
        await monta();

        for (const chiave of ['-', '2', '4', '0']) await tasto(chiave);

        expect(campo().value).toBe('2,40');
        expect(modulo().importo).toBe('2,40');
    });

    test('digitando il meno dopo le cifre il campo e il modulo non portano il segno', async () => {
        await monta();

        for (const chiave of ['2', '4', '0', '-']) await tasto(chiave);

        expect(campo().value).not.toContain('-');
        expect(String(modulo().importo)).not.toContain('-');
    });

    test('controprova — un importo senza il segno resta com\'è scritto', async () => {
        await monta();

        await scrivi('1.200,00');

        expect(campo().value).toBe('1.200,00');
    });

    test('la riga sotto il campo dice che si scrive senza il segno', async () => {
        await monta();

        expect(document.body.textContent).toContain('Si scrive senza il segno: lo decide il tipo della voce.');
    });
});

describe('il rifiuto del server', () => {
    test('controprova — l\'errore sta sotto il campo, il campo si riscrive e «Entrata» si sceglie', async () => {
        await monta();
        modulo().errors = { importo: 'L\'importo di una voce di entrata si scrive senza il segno: è il tipo «Entrata» a ridurre quanto si chiede ai condòmini.' };
        await flushPromises();

        expect(document.body.textContent).toContain('si scrive senza il segno: è il tipo «Entrata»');
        expect(campo().disabled).toBe(false);

        (document.body.querySelector('input#entrata') as HTMLInputElement).click();
        await flushPromises();

        expect(modulo().tipo).toBe('entrata');
    });
});
