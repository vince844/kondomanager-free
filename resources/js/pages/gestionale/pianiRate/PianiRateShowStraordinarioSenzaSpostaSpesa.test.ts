// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Nel piano straordinario le voci sono le fatture: il pulsante «Sposta spesa» e il cestino della voce («Rimuovi voce e ricalcola») non ci sono. Nel piano
 * ordinario restano (la controprova). Il server li rifiuta comunque: `Beta15SpostaSpesaStraordinarioTest`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), put: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn((o?: { onFinish?: () => void }) => o?.onFinish?.()) },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(tipo: string) {
    const capitolo = { id: 7, nome: 'Ft. FT-FACCIATA (Fornitore di prova Srl)', importo: 100000, importo_originale: 100000, is_parent: false, tipo: 'spesa' };
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Facciata 2026', stato: 'bozza', tipo, numero_rate: 12, totale_spese: 120000, totale_entrate: 24000, totale_da_ripartire: 96000,
                budget_movements: [], capitoli: [{ is_frazionato: false, note: null, figli_names: '', ...capitolo }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` } },
    });
    await flushPromises();
}

async function apriCopertura() {
    const intestazione = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Copertura spese')) as HTMLButtonElement;
    intestazione.click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 150));
    await flushPromises();
}

const haSpostaSpesa = () => [...document.body.querySelectorAll('button')].some((b) => b.textContent?.includes('Sposta spesa'));

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('«Sposta spesa» e il cestino nel piano straordinario', () => {
    test('nel piano straordinario non ci sono né «Sposta spesa» né il cestino della voce', async () => {
        await monta('straordinario');
        await apriCopertura();

        expect(haSpostaSpesa()).toBe(false);
        expect(document.body.querySelector('button[title="Rimuovi voce e ricalcola"]')).toBeNull();
    });

    test('controprova: nel piano ordinario ci sono tutti e due', async () => {
        await monta('ordinario');
        await apriCopertura();

        expect(haSpostaSpesa()).toBe(true);
        expect(document.body.querySelector('button[title="Rimuovi voce e ricalcola"]')).not.toBeNull();
    });
});
