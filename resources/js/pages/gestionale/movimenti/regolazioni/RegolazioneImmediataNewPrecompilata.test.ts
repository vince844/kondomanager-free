// @vitest-environment jsdom

/**
 * Il modulo della regolazione immediata aperto già compilato: la correzione consigliata del controllo della banca («Registra la spesa») porta la cassa,
 * l'esercizio, la data dell'estratto, l'importo della differenza e la causale. Il modulo li mostra, e l'amministratore li rilegge e salva lui: niente si
 * registra da solo. Senza la precompilazione il modulo resta quello di sempre.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const RegolazioneImmediataNew = (await import('@/pages/gestionale/movimenti/regolazioni/RegolazioneImmediataNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    RegolazioneImmediataGuide: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

let pagina: VueWrapper | null = null;

async function monta(precompilata: Record<string, unknown> | null) {
    pagina = mount(RegolazioneImmediataNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Via Verdi' }, condomini: [], esercizi: [{ id: 41, nome: '2026' }, { id: 42, nome: '2027' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            capitoli: [{ id: 55, nome: 'Spese bancarie', parent_nome: null, label: 'Spese bancarie' }],
            casse: [{ id: 3, nome: 'Banca principale', tipo: 'banca' }, { id: 4, nome: 'Cassa contanti', tipo: 'contanti' }], fornitori: [], prossimo_protocollo: 'RIM-2026-00001',
            precompilata,
        } as any,
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

describe('la regolazione immediata aperta già compilata', () => {
    test('dalla correzione consigliata della banca: cassa, esercizio, voce, data, importo e causale; niente si salva da solo', async () => {
        await monta({ cassa_id: 3, esercizio_id: 41, conto_id: 55, data_operazione: '2026-12-31', importo: '100.00', causale: 'Spese della banca dall\'estratto conto al 31/12/2026' });
        const form = (pagina!.vm as any).form;
        expect([form.cassa_id, form.esercizio_id, form.conto_id, form.data_operazione, form.importo, form.causale])
            .toEqual([3, 41, 55, '2026-12-31', 100, 'Spese della banca dall\'estratto conto al 31/12/2026']);
        // La gestione unica dell'esercizio scelto si sceglie come sempre.
        expect(form.gestione_id).toBe(35);
        expect(form.processing).toBe(false);
    });

    test('senza la precompilazione il modulo è quello di sempre', async () => {
        await monta(null);
        const form = (pagina!.vm as any).form;
        expect([form.cassa_id, form.esercizio_id, form.conto_id, form.importo, form.causale]).toEqual([null, null, null, 0, '']);
    });
});
