// @vitest-environment jsdom

/**
 * Il badge «Incassato» sopra l'elenco dei conti: con un incasso da terzi su una voce di entrata compare accanto a «Consuntivo», che resta delle sole
 * spese; senza incassi non c'è. Le cifre arrivano dal server in centesimi (`totaleConsuntivo`, `totaleIncassato`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const ContiNew = (await import('@/pages/gestionale/pianiDeiConti/conti/ContiNew.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(totali: Record<string, number>) {
    pagina = mount(ContiNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never, esercizi: [], condomini: [],
            pianoConti: { id: 1, nome: 'Piano dei conti', stato: 'bozza' } as never, conti: [], fornitori: [], tabelle: [],
            totalePreventivo: 120000, totaleEntrate: 24000, totaleDaRipartire: 96000, totaleSopravvenienze: 0, ...totali,
        } as never,
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, ModalNuovoConto: true, ModalModificaConto: true, AlberoDeiConti: true, MovimentiVoceDialog: true,
                DettaglioConto: true, ConfirmDialog: true, ModalAssociaTabella: true, RipartoUsufruttoGuide: true, OperazioniContiGuide: true, GiaVersatoGuide: true,
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il badge «Incassato»', () => {
    test('con un incasso: «Incassato: € 240,00» accanto a «Consuntivo: € 1.200,00»', async () => {
        await monta({ totaleConsuntivo: 120000, totaleIncassato: 24000 });

        expect(normalizza(document.body.querySelector('[data-totale="incassato"]')?.textContent)).toBe('Incassato: € 240,00');
        expect(normalizza(document.body.textContent)).toContain('Consuntivo: € 1.200,00');
    });

    test('senza incassi non c\'è', async () => {
        await monta({ totaleConsuntivo: 120000, totaleIncassato: 0 });

        expect(document.body.querySelector('[data-totale="incassato"]')).toBeNull();
    });
});
