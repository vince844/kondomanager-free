// @vitest-environment jsdom

/**
 * La pagina «Rate scadute» ha la scelta delle righe per pagina, come le altre tabelle del programma: i valori sono quelli del server
 * (`paginazione.consentite`), con dentro quello in uso; la scelta torna alla pagina 1 con gli stessi filtri, e il server la ricorda per chi
 * lavora (la richiesta è di Inertia). C'è anche con una pagina sola, così si torna a meno righe.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const visite: Array<{ url: string; opzioni: Record<string, unknown> }> = [];
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (url: string, _d: unknown, opzioni: Record<string, unknown>) => visite.push({ url, opzioni }), reload: vi.fn() },
    usePage: () => ({ props: { paginazione: { consentite: [10, 15, 20, 30, 40, 50, 100] } } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).flatMap(([k, v]) => (v === undefined ? [] : [[k, String(v)]]))).toString()}`;

const Pagina = (await import('./RateScadute.vue')).default;

// Il menu della libreria reso come un `select` vero: si legge e si sceglie.
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true,
    Select: { props: ['modelValue'], emits: ['update:modelValue'], template: '<select data-testid="righe-per-pagina" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>' },
    SelectTrigger: { template: '<slot />' }, SelectValue: { template: '<span />' }, SelectContent: { template: '<slot />' },
    SelectItem: { props: ['value'], template: '<option :value="value"><slot /></option>' },
};
const riga = (id: number) => ({ anagrafica_id: id, nome: `P${id}`, unita: [], importo: 1000, credito: 0, fasce: { '1_30': 1000, '31_90': 0, oltre_90: 0 }, ritardo_testo: '',
    sei_mesi: { data: null, dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false, rate: [] });
const proprieta = (meta: Record<string, number>) => ({
    condominio: { id: 3, nome: 'Le Rose' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga(1), riga(2)], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2, ...meta } }, totale: 2000, persone_totali: 2,
    filtri: { alla: '2027-01-17', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: '31_90', minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto' },
    oggi: '2027-01-17', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»',
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; visite.length = 0; });

describe('le righe per pagina', () => {
    test('il menu ha i valori del server con dentro quello in uso, anche con una pagina sola', () => {
        w = mount(Pagina, { props: proprieta({ per_page: 25 }) as any, global: { stubs } });
        const valori = w.get('[data-testid="righe-per-pagina"]').findAll('option').map((o) => Number(o.attributes('value')));
        expect(valori).toEqual([10, 15, 20, 25, 30, 40, 50, 100]);
    });

    test('la scelta torna alla pagina 1, con gli stessi filtri e le righe scelte', async () => {
        w = mount(Pagina, { props: proprieta({ per_page: 10, current_page: 2, last_page: 3, total: 25 }) as any, global: { stubs } });
        await w.get('[data-testid="righe-per-pagina"]').setValue('50');
        expect(visite).toHaveLength(1);
        const parametri = new URL(visite[0].url, 'http://x').searchParams;
        expect(parametri.get('per_page')).toBe('50');
        expect(parametri.get('fascia')).toBe('31_90');
        expect(parametri.get('page')).toBeNull();
        expect(visite[0].opzioni).toMatchObject({ preserveState: true, preserveScroll: true, replace: true });
    });
});
