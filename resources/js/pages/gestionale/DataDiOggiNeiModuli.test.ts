// @vitest-environment jsdom

/**
 * La data di oggi che un modulo propone è il giorno di chi usa la pagina (1.12.0-beta.3, decisione 217, D5): alle 00:30 del 01/01/2027 a Roma
 * (le 23:30 del 31/12 in UTC) ogni modulo propone il 01/01/2027. Con `new Date().toISOString().substring(0, 10)` proponevano il 31/12/2026, e
 * un incasso, un pagamento, una fattura, un giroconto, una regolazione, un rimborso o un versamento F24 nascevano nell'anno prima.
 *
 * Si monta ogni pagina e si legge il valore con cui crea il suo modulo (`useForm`); il resto della pagina non conta qui. Il fuso del test è
 * quello di Roma, fissato in testa: la macchina che lancia la suite può stare altrove.
 */
process.env.TZ = 'Europe/Rome';

import { afterAll, beforeAll, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const moduli = vi.hoisted(() => [] as Array<Record<string, unknown>>);

vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: {} })), post: vi.fn(async () => ({ data: {} })) } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: { render: () => null },
        Link: { render: () => null },
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
        usePage: () => ({ url: '/', props: { flash: {}, errors: {}, auth: { user: { id: 1, name: 'Anna', roles: ['amministratore'], permissions: [] } } } }),
        useForm: (dati: Record<string, unknown>) => {
            moduli.push({ ...dati });
            const form: any = reactive({ ...dati, errors: {}, processing: false, hasErrors: false, isDirty: false });
            form.clearErrors = vi.fn(); form.reset = vi.fn(); form.post = vi.fn(); form.put = vi.fn(); form.data = () => ({ ...dati });
            form.transform = () => form; form.setError = vi.fn(); form.defaults = vi.fn();
            return form;
        },
    };
});
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (n: string) => n, generatePath: (p: string) => p, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as any).route = (nome: string) => `/${nome}`;

beforeAll(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-12-31T23:30:00Z'));
});
afterAll(() => vi.useRealTimers());

const CONDOMINIO = { id: 1, nome: 'Condominio Test', data: { id: 1, nome: 'Condominio Test' } };
const ESERCIZIO = { id: 3, nome: 'Esercizio 2027', stato: 'aperto', data_inizio: '2027-01-01', data_fine: '2027-12-31' };

/** Monta la pagina e restituisce i dati con cui ha creato i suoi moduli. Un errore del disegno dopo la creazione dei moduli non conta qui. */
async function moduliDi(percorso: string, props: Record<string, unknown>) {
    moduli.length = 0;
    const pagina = (await import(/* @vite-ignore */ percorso)).default;
    try {
        shallowMount(pagina, { props: { condominio: CONDOMINIO, condomini: [], esercizio: ESERCIZIO, esercizi: [ESERCIZIO], ...props }, global: { mocks: { route: (n: string) => `/${n}` } } });
    } catch {
        // Il disegno può chiedere altro; i moduli sono già creati.
    }
    return [...moduli];
}

const campo = (forme: Array<Record<string, unknown>>, nome: string) => forme.filter((f) => nome in f).map((f) => f[nome]);

describe('alle 00:30 del 01/01/2027 a Roma ogni modulo propone il 01/01/2027', () => {
    test('il giorno UTC del test è ancora il 31/12', () => {
        expect(new Date().toISOString().slice(0, 10)).toBe('2026-12-31');
    });

    test('il nuovo incasso: la data del versamento', async () => {
        expect(campo(await moduliDi('./movimenti/incassi/IncassoRateNew.vue', { risorse: [], persone: [], immobili: [], gestioni: [] }), 'data_pagamento')).toEqual(['2027-01-01']);
    });

    test('il pagamento a un fornitore, nuovo e in modifica senza una data', async () => {
        expect(campo(await moduliDi('./movimenti/pagamenti/PagamentoNew.vue', { fornitori: [], contiCorrenti: [], conti_correnti: [], fatture: [] }), 'data_pagamento')).toEqual(['2027-01-01']);
        const pagamento = { id: 9, fornitore: { id: 2 }, conto_corrente: { id: 4 }, data_pagamento: null, metodo_pagamento: 'bonifico', righe: [], fatture: [] };
        expect(campo(await moduliDi('./movimenti/pagamenti/PagamentoEdit.vue', { pagamento, fornitori: [], contiCorrenti: [], conti_correnti: [] }), 'data_pagamento')).toEqual(['2027-01-01']);
    });

    test('il pagamento in modifica tiene il giorno che il server manda, anche con l\'ora', async () => {
        const pagamento = { id: 9, fornitore: { id: 2 }, conto_corrente: { id: 4 }, data_pagamento: '2026-03-10T00:00:00.000000Z', metodo_pagamento: 'bonifico', righe: [], fatture: [] };
        expect(campo(await moduliDi('./movimenti/pagamenti/PagamentoEdit.vue', { pagamento, fornitori: [], contiCorrenti: [], conti_correnti: [] }), 'data_pagamento')).toEqual(['2026-03-10']);
    });

    test('la nuova fattura: la data del documento', async () => {
        expect(campo(await moduliDi('./movimenti/fatture/FatturaRegisterNew.vue', { fornitori: [], conti: [], gestioni: [], casse: [] }), 'data_documento')).toEqual(['2027-01-01']);
    });

    test('il giroconto e la regolazione immediata: la data dell\'operazione', async () => {
        expect(campo(await moduliDi('./movimenti/giroconti/GirocontoNew.vue', { casse: [], gestioni: [] }), 'data_operazione')).toEqual(['2027-01-01']);
        expect(campo(await moduliDi('./movimenti/regolazioni/RegolazioneImmediataNew.vue', { casse: [], gestioni: [], conti: [], fornitori: [] }), 'data_operazione')).toEqual(['2027-01-01']);
    });

    test('la delega F24: la data del versamento e quella dello storno', async () => {
        const delega = { id: 7, stato: 'confermata', plafond: 'soglia_500_tre_finestre', data_scadenza: '2027-01-18', data_versamento: null, denominazione_contribuente: 'Condominio Test',
            cf_contribuente: '80012345678', totale_debito: 24000, motivo_codice: null, note: null, righe: [] };
        const forme = await moduliDi('../gestionale/movimenti/f24/F24Show.vue', { delega, banche: [], motivo_blocco_modifica: null, campi_mancanti: [], esercizio_non_corrente: null });
        expect([...campo(forme, 'data_versamento'), ...campo(forme, 'data_storno')]).toEqual(['2027-01-01', '2027-01-01']);
    });

    test('il rimborso di un credito dall\'estratto conto', async () => {
        const forme = await moduliDi('./pianiRate/EstrattoContoAnagrafica.vue', { anagrafica: { id: 5, nome: 'Ugo' }, movimenti: [], stats: {}, crediti: [], rate: [], casse: [] });
        expect(campo(forme, 'data_rimborso')).toEqual(['2027-01-01']);
    });
});
