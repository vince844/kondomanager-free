// @vitest-environment jsdom

/**
 * Cosa resta scoperto: quali piani entrano nell'elenco «Ricalcolo necessario» lo decide il server (`PianoRateQuoteService::eDisallineato`, che dalla
 * Coda 414 non conta i piani in «quota aperta»: i test Pest di `Beta59PianoQuoteNonAssegnateTest`); lo stato «Scoperto documentato» del riquadro, che con
 * le props che il server costruisce non si raggiunge (il suo conteggio e la sua somma filtrano le stesse voci); il colore e la larghezza a 375 px.
 *
 * Coda 414 (D138): la frase dell'elenco «Ricalcolo necessario» del cruscotto. Diceva «Hai modificato il preventivo spese, ma le rate generate non sono più
 * aggiornate. Ricalcola i seguenti piani», ma l'elenco ha anche piani che non si ricalcolano più (rate emesse, incassi) e piani scostati per un'altra
 * ragione (un già versato scritto dopo, una voce che ha cambiato tipo): la frase nuova dice dove si guarda, senza promettere il ricalcolo.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * La pagina è quella vera. Si stubbano la cornice, la guida, i widget e i suggerimenti a comparsa.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;

const copertura = {
    spese: 350000, entrate: 0, da_ripartire: 350000, pianificato: 350000, virtuale: 0, addebiti_personali: 0, uscite_totali: 0, delta: 0, scoperto: 0,
    percentuale: 100, is_completo: true, has_sforo: false, has_scoperti_documentati: false, orfani: [], scoperto_count: 0,
};

async function monta(pianiDisallineati: unknown[]) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: { condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [], copertura: copertura as never, pianiDisallineati: pianiDisallineati as never, fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets: {} },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('l\'elenco «Ricalcolo necessario» del cruscotto (Coda 414, D138)', () => {
    test('la frase dice dove si guarda, senza dire che il preventivo è cambiato né promettere il ricalcolo', async () => {
        await monta([{ id: 7, nome: 'Riscaldamento 2026', gestione: 'Gestione ordinaria', delta: -2500 }]);

        expect(testo()).toContain('Le rate di questi piani non corrispondono a quello che il piano chiede. Apri il piano: «Copertura spese» dice di quanto, e il pulsante «Ricalcola» dice se si ricalcola ancora.');
        expect(testo()).not.toContain('Hai modificato il preventivo spese');
        expect(testo()).not.toContain('Ricalcola i seguenti piani');
    });

    test('controprova — ogni piano dell\'elenco ha il suo scarto e il pulsante per aprirlo', async () => {
        await monta([{ id: 7, nome: 'Riscaldamento 2026', gestione: 'Gestione ordinaria', delta: -2500 }]);

        expect(testo()).toContain('Riscaldamento 2026');
        expect(testo()).toContain('Delta: € -25,00');
        expect(testo()).toContain('Apri');
    });

    test('controprova — senza piani nell\'elenco (un piano in quota aperta non c\'è) il riquadro «Ricalcolo necessario» non compare', async () => {
        await monta([]);

        expect(testo()).not.toContain('Ricalcolo Necessario');
    });
});
