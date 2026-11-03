// @vitest-environment jsdom

/**
 * Il modulo «Nuova voce» e il blocco dei piani approvati.
 *
 * Una voce nuova sotto un capitolo che un piano approvato, emesso o chiuso tiene si rifiuta nel salvataggio (`CreateContoRequest`, `Conto::pianiCheBloccanoLaVoce`,
 * `Beta53BloccoDellaVoceTabelleTest`): il piano addebiterebbe anche lei. Il modulo segue la stessa regola dell'elenco dei capitoli padre della scheda di modifica:
 * il server manda `blocco` per ogni capitolo, il capitolo bloccato non si sceglie e l'elenco dice in quale piano è.
 *
 * `vue-select` si sostituisce come nel test di «Modifica voce»: si prova la funzione che dice quali capitoli si scelgono, e l'elenco che si vede.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (il test Pest citato).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive, ref } from 'vue';

const capitoliFinti = ref<Array<{ id: number; nome: string; blocco?: string | null }>>([]);

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', props: ['options', 'selectable'], template: '<div class="vselect-finto"><slot name="option" v-for="o in options" v-bind="o" /></div>' } }));
vi.mock('@/composables/useTabelle', () => ({ useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }) }));
vi.mock('@/composables/useCapitoliConti', () => ({ useCapitoliConti: () => ({ capitoli: capitoliFinti, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ModalNuovoConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue')).default;

let finestra: VueWrapper | null = null;

async function monta() {
    finestra = mount(ModalNuovoConto, { attachTo: document.body, props: { show: true, condominioId: 1, esercizioId: 1, pianoContoId: 1, fornitori: [] } });
    await flushPromises();
    // L'elenco dei capitoli padre compare con l'interruttore «sottoconto».
    (document.body.querySelector('#isSottoConto') as HTMLButtonElement).click();
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
/** La regola dell'elenco dei capitoli padre: l'unico elenco del modulo che la dichiara. */
const selezionabile = () => finestra!.findAllComponents({ name: 'VSelectFinto' }).map((e) => e.props('selectable') as ((c: unknown) => boolean) | undefined).find((f) => typeof f === 'function');

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('l\'elenco dei capitoli padre', () => {
    test('un capitolo che un piano approvato tiene non si sceglie, e l\'elenco dice in quale piano è', async () => {
        capitoliFinti.value = [{ id: 3, nome: 'Manutenzione', blocco: null }, { id: 5, nome: 'Pulizie', blocco: 'nel piano «Preventivo B», approvato' }];
        await monta();

        expect(selezionabile()).toBeTypeOf('function');
        expect(selezionabile()!(capitoliFinti.value[0])).toBe(true);
        expect(selezionabile()!(capitoliFinti.value[1])).toBe(false);
        expect(testo()).toContain('Pulizie (nel piano «Preventivo B», approvato)');
        expect(testo()).toContain('I capitoli che un piano approvato tiene non si scelgono finché il piano non torna in bozza.');
    });

    test('controprova — senza capitoli bloccati l\'elenco non ha la riga dei capitoli che non si scelgono', async () => {
        capitoliFinti.value = [{ id: 3, nome: 'Manutenzione', blocco: null }];
        await monta();

        expect(testo()).not.toContain('non si scelgono');
    });
});
