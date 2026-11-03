// @vitest-environment jsdom

/**
 * La scheda «Modifica voce» e il blocco dei piani approvati.
 *
 * Il salvataggio rifiuta l'importo, il padre, il tipo, la tabella e le percentuali di una voce che un piano approvato tiene, con la sua riga o con quella
 * di un capitolo sopra di lei, o che un piano straordinario approvato finanzia con una spesa fuori preventivo registrata sulla voce, e lo spostamento sotto un capitolo che un
 * piano approvato tiene (`ContoController::update`, `Conto::pianiCheBloccanoLaVoce`, `Beta53BloccoDellaVoceTest`, `Beta53BloccoDellaVoceTabelleTest`,
 * `Beta53BloccoDellaVocePianoStraordinarioTest`). La scheda segue la stessa regola: il server le manda `blocco` (i piani e la frase, con la strada della bozza)
 * per la voce, e `blocco` per ogni capitolo dell'elenco dei capitoli padre; con la voce bloccata l'importo, il tipo, il padre, la tabella, le percentuali e gli
 * interruttori di capitolo e sottoconto non si cambiano, e la frase lo dice; un capitolo bloccato non si sceglie come padre. `has_rate_emesse` non decide l'importo:
 * aveva la condizione sulla data di creazione che il salvataggio non ha (un sottoconto nato dopo il piano che tiene il suo capitolo restava scrivibile).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (i test Pest citati); il modulo nuovo, che non ha un importo da bloccare.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive, ref } from 'vue';

const capitoliFinti = ref<Array<{ id: number; nome: string; blocco?: string | null }>>([]);

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), put: vi.fn(), post: vi.fn(), transform: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', props: ['options', 'disabled', 'selectable', 'modelValue'], template: '<div class="vselect-finto"><slot name="option" v-for="o in options" v-bind="o" /></div>' } }));
vi.mock('@/composables/useCapitoliConti', () => ({
    useCapitoliConti: () => ({ capitoli: capitoliFinti, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }),
}));

const ModalModificaConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue')).default;

const FRASE = 'L\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: riporta prima il '
    + 'piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; '
    + 'con delle rate emesse, prima vanno annullate le emissioni.';

const voce = {
    id: 7, nome: 'Rimborso', tipo: 'spesa', parent_id: 3, is_capitolo: false,
    importo: '€ 100,00', importo_raw: 10000, budget_originale_raw: 10000, speso_raw: 0, impegnato: 0, tabelle_millesimali: [], sottoconti: [],
};

let finestra: VueWrapper | null = null;

async function monta(conto: Record<string, unknown>) {
    finestra = mount(ModalModificaConto, {
        attachTo: document.body,
        props: { show: true, conto: conto as never, condominioId: 1, esercizioId: 1, pianoContoId: 1, tabelle: [], fornitori: [] },
    });
    await flushPromises();
    await new Promise((r) => setTimeout(r, 0));
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const campo = (selettore: string) => document.querySelector(selettore) as HTMLInputElement | HTMLButtonElement;
const selettoreDelPadre = () => finestra!.findAllComponents({ name: 'VSelectFinto' })[0];
const percentuali = () => Array.from(document.querySelectorAll<HTMLInputElement>('input[placeholder="100"], input[placeholder="0"]'));

beforeEach(() => {
    document.body.innerHTML = '';
    capitoliFinti.value = [{ id: 3, nome: 'Manutenzione', blocco: null }, { id: 5, nome: 'Pulizie', blocco: 'nel piano «Preventivo B», approvato' }];
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('la voce che un piano approvato tiene', () => {
    test('l\'importo, il tipo, il padre e gli interruttori non si cambiano, e la scheda dice la frase del server', async () => {
        await monta({ ...voce, blocco: { piani: [{ id: 9, nome: 'Preventivo B' }], frase: FRASE } });

        expect(campo('input#importo').disabled).toBe(true);
        expect(campo('input#spesa').disabled).toBe(true);
        expect(campo('input#entrata').disabled).toBe(true);
        expect(campo('#editIsSottoConto').disabled).toBe(true);
        expect(campo('#editIsCapitolo').disabled).toBe(true);
        expect(selettoreDelPadre().props('disabled')).toBe(true);
        expect(testo()).toContain(FRASE);
    });

    test('la tabella e le percentuali non si cambiano', async () => {
        await monta({ ...voce, blocco: { piani: [{ id: 9, nome: 'Preventivo B' }], frase: FRASE } });

        expect(finestra!.findAllComponents({ name: 'VSelectFinto' })[1].props('disabled')).toBe(true);
        expect(percentuali().length).toBe(3);
        expect(percentuali().every((i) => i.disabled)).toBe(true);
    });

    test('un sottoconto nato dopo il piano che tiene il suo capitolo: il blocco viene dal server anche senza `has_rate_emesse`', async () => {
        await monta({ ...voce, has_rate_emesse: false, blocco: { piani: [{ id: 9, nome: 'Preventivo B' }], frase: FRASE } });

        expect(campo('input#importo').disabled).toBe(true);
    });
});

describe('la voce che nessun piano approvato tiene', () => {
    test('l\'importo, il tipo e il padre si cambiano', async () => {
        await monta({ ...voce, has_rate_emesse: false, blocco: null });

        expect(campo('input#importo').disabled).toBe(false);
        expect(campo('input#entrata').disabled).toBe(false);
        expect(campo('#editIsSottoConto').disabled).toBe(false);
        expect(selettoreDelPadre().props('disabled')).toBe(false);
        expect(testo()).not.toContain('non si cambiano');
    });

    test('controprova — la tabella e le percentuali si cambiano', async () => {
        await monta({ ...voce, has_rate_emesse: false, blocco: null });

        expect(finestra!.findAllComponents({ name: 'VSelectFinto' })[1].props('disabled')).toBeFalsy();
        expect(percentuali().length).toBe(3);
        expect(percentuali().every((i) => !i.disabled)).toBe(true);
    });

    test('un capitolo che un piano approvato tiene non si sceglie come padre, e l\'elenco dice in quale piano è', async () => {
        await monta({ ...voce, blocco: null });

        const selezionabile = selettoreDelPadre().props('selectable') as (c: unknown) => boolean;
        expect(selezionabile(capitoliFinti.value[0])).toBe(true);
        expect(selezionabile(capitoliFinti.value[1])).toBe(false);
        expect(testo()).toContain('Pulizie (nel piano «Preventivo B», approvato)');
        expect(testo()).toContain('I capitoli che un piano approvato tiene non si scelgono finché il piano non torna in bozza.');
    });

    test('controprova — senza capitoli bloccati l\'elenco non ha la riga dei capitoli che non si scelgono', async () => {
        capitoliFinti.value = [{ id: 3, nome: 'Manutenzione', blocco: null }];
        await monta({ ...voce, blocco: null });

        expect(testo()).not.toContain('non si scelgono');
    });
});

describe('l\'errore della scelta fra voce e capitolo', () => {
    test('il rifiuto del server sull\'interruttore compare nella scheda, anche per un capitolo', async () => {
        await monta({ id: 5, nome: 'Pulizie', tipo: 'spesa', parent_id: null, is_capitolo: true, importo: '€ 0,00', importo_raw: 0, budget_originale_raw: 0, speso_raw: 0, impegnato: 0,
            tabelle_millesimali: [], sottoconti: [], blocco: null });
        (finestra!.vm as any).form.errors = { isCapitolo: 'Modifica inibita: il capitolo non torna una voce finché è nel piano «Preventivo B», approvato' };
        await flushPromises();

        expect(testo()).toContain('Modifica inibita: il capitolo non torna una voce finché è nel piano «Preventivo B», approvato');
    });
});

describe('l\'errore del tipo', () => {
    test('il rifiuto del server sul tipo compare sotto la scelta del tipo', async () => {
        await monta({ ...voce, blocco: null });
        (finestra!.vm as any).form.errors = { tipo: 'Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano' };
        await flushPromises();

        expect(testo()).toContain('Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano');
    });
});
