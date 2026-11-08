// @vitest-environment jsdom

/**
 * La voce della commissione nella registrazione di un pagamento al fornitore.
 *
 * Le voci offerte sono quelle di spesa della gestione delle fatture scelte; la proposta è la voce dell'ultima commissione del condominio in
 * quella gestione (`voce_commissione_proposta`, dal server), e se quella voce non c'è più il campo resta vuoto. Con una commissione più di zero
 * e senza voce il modulo non si invia, e lo dice sul campo con la frase del server. La commissione si digita in euro e parte in centesimi una
 * volta sola, all'invio; la casella in euro non entra nel corpo.
 *
 * Il lato del server è in `tests/Feature/Gestionale/CommissioneConVoceTest.php`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
// `vue-select` non ha un nome: uno stub per nome non lo sostituisce, e la prova legge le sue `options` sul componente vero.
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { pendenze: [], has_netting: false, totale_nc: 0, totale_ft: 0 } })) } }));

import PagamentoNew from './PagamentoNew.vue';

(globalThis as any).route = (nome: string) => `/${nome}`;
const mocks = { route: (nome: string) => `/${nome}` };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const FRASE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';
const voci = [
    { id: 5, nome: 'Spese bancarie', label: 'Spese bancarie', gestione_id: 10 },
    { id: 6, nome: 'Bolli', label: 'Bolli', gestione_id: 10 },
    { id: 9, nome: 'Bancarie del tetto', label: 'Bancarie del tetto', gestione_id: 20 },
];
const fattura = (id: number, gestione: number) => ({
    id, tipo_documento: 'fattura', numero_documento: `FT-${id}`, data_documento: '2026-03-10', importo_lordo: 100_000, netto_a_pagare: 100_000,
    residuo: 100_000, stato_pagamento: 'aperta', is_scaduta: false, is_nota_credito: false, gestione_id: gestione, stato_approvazione: 'approvata',
    selezionata: true, importo_allocato: 1000, tipo_allocazione: 'pagamento',
});

let pagina: VueWrapper | null = null;

async function apri(proposte: Array<{ gestione_id: number; voce_id: number | null }>, gestione = 10) {
    pagina = mount(PagamentoNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo KM' },
            condomini: [{ id: 1, nome: 'Condominio Demo KM' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 4, nome: '2026', stato: 'aperto' }],
            fornitori: [{ id: 3, ragione_sociale: 'Idraulica Rossi S.r.l.', soggetto_ritenuta: false }],
            banche: [{ id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 900_000 }],
            gestioni: [{ id: 10, nome: 'Ordinaria', tipo: 'ordinaria' }, { id: 20, nome: 'Tetto', tipo: 'straordinaria' }],
            voci_commissione: voci,
            voce_commissione_proposta: proposte,
        } as never,
        global: { stubs, mocks },
    });
    const stato = pagina.vm as any;
    stato.pendenze = [fattura(1, gestione)];
    stato.form.importo_commissioni_euro = 2.5;
    await nextTick();
    await nextTick();

    return stato;
}

function invia() {
    const stato = (pagina!.vm as any);
    const corpi: Record<string, unknown>[] = [];
    let trasformazione: ((dati: Record<string, unknown>) => Record<string, unknown>) | null = null;
    stato.form.transform = (callback: typeof trasformazione) => { trasformazione = callback; return stato.form; };
    stato.form.post = (_url: string, opzioni: { onSuccess?: () => void }) => {
        corpi.push(trasformazione!(stato.form.data()));
        opzioni.onSuccess?.();
    };
    stato.handleSubmit();

    return corpi;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la voce della commissione nella registrazione', () => {
    test('propone la voce dell\'ultima commissione della gestione delle fatture scelte, e la manda con la commissione in centesimi', async () => {
        const stato = await apri([{ gestione_id: 10, voce_id: 6 }, { gestione_id: 20, voce_id: 9 }]);

        expect(stato.form.voce_commissione_id).toBe(6);
        const [corpo] = invia();
        expect(corpo).toMatchObject({ voce_commissione_id: 6, importo_commissioni_cents: 250 });
        expect(corpo).not.toHaveProperty('importo_commissioni_euro');
    });

    test('offre solo le voci della gestione delle fatture scelte', async () => {
        await apri([], 20);

        const scelta = pagina!.findAllComponents(vSelect as never).find((c) => (c.props('options') as any[])?.some?.((o: any) => o.label === 'Bancarie del tetto'));
        expect((scelta!.props('options') as any[]).map((o) => o.id)).toEqual([9]);
    });

    test('la voce ricordata che non c\'è più lascia il campo vuoto, e il modulo non si invia: lo dice sul campo', async () => {
        const stato = await apri([{ gestione_id: 10, voce_id: 99 }]);

        expect(stato.form.voce_commissione_id).toBeNull();
        expect(invia()).toEqual([]);
        await nextTick();
        expect(document.body.textContent).toContain(FRASE);
    });

    test('sotto il campo, la via d\'uscita per metterla su una voce di un\'altra gestione: la commissione a zero e una regolazione immediata, con il collegamento', async () => {
        await apri([], 20);

        expect(document.body.textContent).toContain('Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e registrala con una regolazione immediata.');
        expect(pagina!.find('[data-test="regolazione-immediata"]').exists()).toBe(true);
        // Il collegamento si apre in un'altra scheda: chi lo segue mentre scrive il pagamento non lo perde.
        expect(pagina!.find('[data-test="regolazione-immediata"]').attributes('target')).toBe('_blank');
    });

    test('senza commissione la voce non serve', async () => {
        const stato = await apri([]);
        stato.form.importo_commissioni_euro = 0;

        expect(invia()[0]).toMatchObject({ importo_commissioni_cents: 0 });
    });
});
