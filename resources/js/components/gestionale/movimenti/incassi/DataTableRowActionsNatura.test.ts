// @vitest-environment jsdom

/**
 * La conferma dello storno di un incasso chiede la natura (1.12.0-beta.3, decisione 217, D1), senza una risposta già scelta: «il versamento
 * non c'è mai stato» o «c'era ed è tornato indietro», con accanto da quale data vale ognuna. Senza la scelta non si conferma; la scelta non
 * si ricorda alla riapertura. Se l'esercizio della data del versamento è chiuso lo dice prima. Una risposta dell'anteprima arrivata dopo che
 * la finestra è stata chiusa e riaperta non riscrive quella nuova.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const axiosGet = vi.fn();
const routerPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a: unknown[]) => routerPost(...a), visit: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as any).route = (nome: string) => `/${nome}`;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

const stubs = {
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuContent: { template: '<div><slot /></div>' },
    DropdownMenuItem: { template: '<div><slot /></div>' },
    DropdownMenuLabel: { template: '<div><slot /></div>' },
    DropdownMenuSeparator: { template: '<hr />' },
    RicevutaDialog: true,
    ConfirmDialog: {
        props: ['modelValue', 'disabled', 'loading'],
        emits: ['confirm'],
        template: '<div v-if="modelValue" data-conferma><slot /><button data-conferma-ok :disabled="disabled || loading" @click="$emit(\'confirm\')">Sì</button></div>',
    },
};

const DATE = { errore_registrazione: { vale_dal: '2026-12-10', esercizio_chiuso: false }, versamento_tornato: { vale_dal: '2027-01-15' } };
const risposta = (natura = DATE) => ({ data: { voci: [], frase: null, ricevuta: null, natura } });

function monta(importo = 30000) {
    return mount(DataTableRowActions, {
        props: { incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato: 'registrata', importo_totale_cents: importo }, condominioId: 3 },
        global: { stubs },
    });
}

async function apri(w: any) {
    (w.vm as any).handleStorno();
    await flushPromises();
}

const conferma = (w: any) => w.find('[data-conferma-ok]');
const scelta = (w: any, valore: string) => w.find(`input[type="radio"][value="${valore}"]`);

beforeEach(() => {
    axiosGet.mockReset();
    routerPost.mockReset();
});
afterEach(() => vi.useRealTimers());

describe('la natura dello storno', () => {
    test('nessuna già scelta: non si conferma; accanto a ognuna da quando vale', async () => {
        axiosGet.mockResolvedValue(risposta());
        const w = monta();
        await apri(w);

        expect(scelta(w, 'errore_registrazione').element.checked).toBe(false);
        expect(scelta(w, 'versamento_tornato').element.checked).toBe(false);
        expect(conferma(w).attributes('disabled')).toBeDefined();
        const voci = w.findAll('[role="radiogroup"] label').map((l: any) => l.findAll(':scope > span > span').map((s: any) => s.text()));
        expect(voci).toEqual([
            ['Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo. Lo storno vale dal giorno del versamento.', 'Vale dal 10/12/2026.'],
            ['Il versamento c\'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato). Lo storno vale da oggi.', 'Vale dal 15/01/2027.'],
        ]);
    });

    test('un incasso fatto solo di credito: le parole della compensazione, con le stesse date', async () => {
        axiosGet.mockResolvedValue(risposta());
        const w = monta(0);
        await apri(w);

        const voci = w.findAll('[role="radiogroup"] label').map((l: any) => l.findAll(':scope > span > span').map((s: any) => s.text()));
        expect(voci).toEqual([
            ['La compensazione è sbagliata: si toglie dal giorno in cui è stata fatta.', 'Vale dal 10/12/2026.'],
            ['La compensazione era giusta e si annulla da oggi.', 'Vale dal 15/01/2027.'],
        ]);
        expect(w.find('[data-conferma]').text()).not.toContain('versamento');
    });

    test('l\'anteprima fallita: la conferma dice che la data non si è potuta sapere', async () => {
        axiosGet.mockRejectedValue(new Error('rete'));
        const w = monta();
        await apri(w);

        expect(w.find('[data-conferma]').text()).toContain('Non si è potuto sapere da quale data vale lo storno');
    });

    test('scelta la natura si conferma, e la richiesta la porta', async () => {
        axiosGet.mockResolvedValue(risposta());
        const w = monta();
        await apri(w);

        await scelta(w, 'errore_registrazione').setValue(true);
        expect(conferma(w).attributes('disabled')).toBeUndefined();
        await conferma(w).trigger('click');

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][1]).toEqual({ natura_storno: 'errore_registrazione' });
    });

    test('riaprendo la finestra la scelta di prima non c\'è più', async () => {
        axiosGet.mockResolvedValue(risposta());
        const w = monta();
        await apri(w);
        await scelta(w, 'versamento_tornato').setValue(true);

        await apri(w);

        expect(scelta(w, 'versamento_tornato').element.checked).toBe(false);
        expect(conferma(w).attributes('disabled')).toBeDefined();
    });

    test('l\'esercizio della data chiuso: la conferma lo dice prima', async () => {
        axiosGet.mockResolvedValue(risposta({ errore_registrazione: { vale_dal: '2027-01-15', esercizio_chiuso: true }, versamento_tornato: { vale_dal: '2027-01-15' } }));
        const w = monta();
        await apri(w);

        expect(w.find('[data-conferma]').text()).toContain('L\'esercizio che contiene la data del versamento è chiuso, o non c\'è: se è una registrazione sbagliata, lo storno vale da oggi, 15/01/2027.');
    });

    test('una risposta arrivata dopo la riapertura non riscrive le date della finestra nuova', async () => {
        let rispondiVecchia: (v: unknown) => void = () => {};
        axiosGet.mockImplementationOnce(() => new Promise((r) => { rispondiVecchia = r; }));
        axiosGet.mockResolvedValueOnce(risposta());
        const w = monta();
        await apri(w);
        await apri(w);

        rispondiVecchia(risposta({ errore_registrazione: { vale_dal: '2020-01-01', esercizio_chiuso: true }, versamento_tornato: { vale_dal: '2020-01-01' } }));
        await flushPromises();

        const testo = w.find('[data-conferma]').text();
        expect(testo).toContain('Vale dal 10/12/2026.');
        expect(testo).not.toContain('2020');
    });

    test('il rifiuto del server si legge nella finestra', async () => {
        axiosGet.mockResolvedValue(risposta());
        routerPost.mockImplementation((_u: string, _d: unknown, opzioni: any) => { opzioni.onError({ natura_storno: 'Scegli perché storni questo incasso.' }); opzioni.onFinish?.(); });
        const w = monta();
        await apri(w);
        await scelta(w, 'versamento_tornato').setValue(true);
        await conferma(w).trigger('click');
        await flushPromises();

        expect(w.find('[data-conferma]').text()).toContain('Scegli perché storni questo incasso.');
    });
});
