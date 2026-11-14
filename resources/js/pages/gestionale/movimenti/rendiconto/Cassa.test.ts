// @vitest-environment jsdom

/**
 * La scheda «Cassa» del rendiconto: le entrate e le uscite per cassa come le dà il server, la cassa che apre il registro su quella cassa, il raccordo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Cassa.vue')).default;

const cassa = {
    data: '2026-12-31', stato_esercizio: 'chiuso',
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31',
        righe: [{ cassa_id: 4, conto_contabile_id: 20, cassa: 'Banca principale', tipo: 'banca', iniziale: 500000, entrate: 394000, uscite: 372100, finale: 521900, negativa: false, minimo: null, minimo_il: null }],
        totale: { iniziale: 500000, entrate: 394000, uscite: 372100, finale: 521900 }, giroconti: { entrate: 0, uscite: 0 }, stornate: { coppie: 0, importo: 0 },
    },
    raccordo: { righe: [{ voce: 'Risultato di gestione dell\'esercizio', natura: 'quote registrate in questo esercizio − costi', effetto: -2100 }], somma: 21900, variazione_liquidita: 21900, scarto: 0, quadra: true },
    risultato_gestione: { risultato: -2100 },
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

describe('la scheda «Cassa»', () => {
    test('le casse con il riporto, la cassa che apre il registro su quella cassa, il raccordo; la scheda accesa', () => {
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        const riga = w.find('[data-cassa="4"]');
        expect(riga.findAll('td').map((td) => td.text().replace(/\s+/g, ' ').trim()).slice(1)).toEqual(['€ 5.000,00', '€ 3.940,00', '€ 3.721,00', '€ 5.219,00']);
        expect(riga.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/registro-contabilita?search=Banca%20principale');
        expect(w.find('[data-test="raccordo"]').text()).toContain('Variazione della liquidità nell\'esercizio');
        expect(w.find('[data-scheda="cassa"]').attributes('aria-current')).toBe('page');
    });
});
