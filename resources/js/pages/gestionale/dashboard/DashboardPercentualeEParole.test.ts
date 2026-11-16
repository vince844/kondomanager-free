// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La dashboard del condominio, le parti di prima della pagina: la percentuale della copertura all'italiana («87,5 %», mai «87.5%») e le parole
 * dell'amministratore, con le maiuscole solo a inizio frase («Coperture attive», non «Coperture Attive»; «Differenza», non «Delta»).
 * Il montaggio è quello di `DashboardCoperturaEAttivita.test.ts`.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;
let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

const base = { spese: 120000, entrate: 0, da_ripartire: 120000, pianificato: 100000, virtuale: 5000, addebiti_personali: 3000, uscite_totali: 50000, scoperto: 0, delta: 15000,
    percentuale: 87.5, is_completo: false, has_sforo: true, has_scoperti_documentati: false, orfani: [{ id: 1, nome: 'Pulizie', importo: 15000, strategia: 'rata', gestione: 'Ordinaria 2026' }],
    scoperto_count: 1 };

async function monta(altro: Record<string, unknown>) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 7, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [], copertura: null,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets: {}, oggi: '2026-11-15', ...altro,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                SeiMesiPerAgireWidget: true, SeiMesiRisposte: true, RisposteDelCompito: true, DashboardCondominioGuide: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
    await flushPromises();
}

describe('la dashboard del condominio: cifre e parole', () => {
    test('la percentuale della copertura all\'italiana: «87,5 %», non «87.5%»', async () => {
        await monta({ copertura: base });
        const card = document.body.querySelector('[data-testid="copertura-bilancio"]') as HTMLElement;
        expect(pulito(card.textContent)).toContain('87,5 %');
        expect(pulito(card.textContent)).not.toContain('87.5');
    });

    test('le parole dell\'amministratore e le maiuscole solo a inizio frase, anche nelle parti di prima della pagina', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/Dashboard.vue'), 'utf8');
        const trovate = ['Bilancio Sotto Controllo', 'Inbox Condominiale', 'i task', 'Coperture Attive', 'Avanzamento Coperture Condivise', 'Proiezione Uscite',
            'Ricalcolo Necessario', 'Verifica Eccedenza', 'Sforo Recuperato', 'Audit spese', 'Sforamenti Budget Preventivo', 'Fondo Riserva', 'A Consuntivo', 'Rata Integrativa',
            'Sforo Aperto', 'Delta:', 'Addebiti Diretti', 'Art. 63', 'nel Piano Rate'].filter((f) => s.includes(f));
        expect(trovate).toEqual([]);
    });
});
