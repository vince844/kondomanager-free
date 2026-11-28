// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina del piano mostra una riga informativa per ogni unità in usufrutto con delle voci dell'ordinaria sul «Proprietario», che il piano dà al nudo
 * proprietario: la frase arriva dal server (`UsufruttiConLOrdinariaAlNudo::perLaPagina`, prova `UsufruttoVociSulProprietarioTest`), la pagina la scrive
 * com'è, senza pulsanti e senza `role="alert"`. Senza righe il riquadro non c'è (la controprova).
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

const FRASE = 'Int. 3 è in usufrutto: le voci «Ascensore» e «Pulizie scale» sono sul «Proprietario» e in questo piano vanno a Laura Rossi, nudo proprietario. '
    + 'Per darle a Paolo Rossi, usufruttuario, mettile su «Usufruttuario» nella tabella della voce e ricalcola.';

async function monta(righe?: Array<{ immobile_id: number; frase: string }>) {
    const capitolo = { id: 7, nome: 'Ascensore', importo: 100000, importo_originale: 100000, is_parent: false, tipo: 'spesa' };
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, totale_spese: 100000, totale_entrate: 0, totale_da_ripartire: 100000,
                budget_movements: [], capitoli: [{ is_frazionato: false, note: null, figli_names: '', ...capitolo }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
            ...(righe === undefined ? {} : { usufrutti_con_l_ordinaria_al_nudo: righe }),
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` } },
    });
    await flushPromises();
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la riga dell\'unità in usufrutto nella pagina del piano', () => {
    test('una riga per unità, con la frase del server, senza pulsanti e senza blocco', async () => {
        await monta([{ immobile_id: 3, frase: FRASE }, { immobile_id: 4, frase: FRASE.replace('Int. 3', 'Int. 4') }]);
        const riquadro = document.body.querySelector('[data-testid="usufrutti-ordinaria-al-nudo"]');

        expect(riquadro).not.toBeNull();
        expect(riquadro!.querySelectorAll('p')).toHaveLength(2);
        expect(riquadro!.textContent?.replace(/\s+/g, ' ')).toContain(FRASE);
        expect(riquadro!.textContent).toContain('Int. 4 è in usufrutto');
        expect(riquadro!.querySelector('button')).toBeNull();
        expect(riquadro!.querySelector('[role="alert"]')).toBeNull();
    });

    test('controprova: senza righe il riquadro non c\'è', async () => {
        await monta([]);
        expect(document.body.querySelector('[data-testid="usufrutti-ordinaria-al-nudo"]')).toBeNull();
        pagina?.unmount();
        document.body.innerHTML = '';
        await monta();
        expect(document.body.querySelector('[data-testid="usufrutti-ordinaria-al-nudo"]')).toBeNull();
    });
});
