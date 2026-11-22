// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La linguetta «Assemblee» della scheda della persona (decisione 651): le tre card (quante assemblee e in quanti condomini, «Può impugnare», come si convoca),
 * i filtri `v-select` che fanno una **visita parziale** (`righe`, `meta`, `filtri`; `preserveState`, `preserveScroll`) e tornano alla prima pagina, l'elenco con
 * il badge «Per lei» (`rounded-md`), la prima riga aperta e le altre che un tocco apre, la dichiarazione a verbale come **testo**, le pagine; la linguetta
 * con il numero e solo con «Vede le assemblee».
 *
 * Che cosa non copre: il server (`AssembleeDellaPersonaTest`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import type { PersonaInAssemblea } from '@/lib/assemblee/persona';

const routerGet = vi.fn();
const chi = vi.hoisted(() => ({ permessi: ['Vede le assemblee'] as string[] }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    router: { get: (...a: unknown[]) => routerGet(...a) },
    usePage: () => ({ url: '/admin/anagrafiche/7/assemblee', props: { flash: {}, anagrafica: { id: 7 }, assemblee_riepilogo: { totale: 7 } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (n: string) => n,
        generatePath: (p: string, q?: Record<string, number>) => `/admin/${p.replace(':anagrafica', String(q?.anagrafica))}`,
        hasPermission: (p: string[]) => p.some((x) => chi.permessi.includes(x)),
    }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Pagina = (await import('./AnagraficheAssemblee.vue')).default;
const Layout = (await import('@/layouts/anagrafiche/AnagraficaLayout.vue')).default;

const dicembre: PersonaInAssemblea = { id: 7, condominio_id: 3, condominio: 'Via Roma 12', data: '15/12/2026', anno: 2026, per_lei: { parola: 'Può impugnare', tono: 'ambra' },
    nome: 'Ordinaria del 15/12/2026', verbale: '42', convocazione: 'Per PEC · Consegnata il 05/12/2026', in_sala: 'Per delega a Bianchi Giorgio', per_delega_da: 'Bianchi Giorgio',
    favorevole_ai_punti: [1, 2, 3, 5],
    altri_voti: [{ punto: 4, voto: 'Contrario', testo: 'Portone', dichiarazioni: ['<b>Il portone</b> si può riparare.'] }],
    puo_impugnare_fino_al: '14/01/2027', impugnare_dopo_la_consegna: false, sulle_sue_cose: [] };
const ottobre: PersonaInAssemblea = { id: 6, condominio_id: 4, condominio: 'Via Verdi 3', data: '22/10/2025', anno: 2025, per_lei: { parola: 'Assente', tono: 'grigio' },
    nome: 'Straordinaria del 22/10/2025', verbale: '41', convocazione: 'Per PEC · Consegnata il 10/10/2025', in_sala: 'Assente', per_delega_da: null, favorevole_ai_punti: [],
    altri_voti: [], puo_impugnare_fino_al: null, impugnare_dopo_la_consegna: false, sulle_sue_cose: [{ frase: 'Punto 2, il suo piano di rientro, approvata', url: null }] };

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: {
        anagrafica: { id: 7, nome: 'Mario Rossi', mezzo_convocazione: 'pec' },
        riepilogo: { totale: 7, condomini: 2, ultima: '15/12/2026', puo_impugnare: { quante: 1, fino_al: '21/12/2026' } },
        righe: [dicembre, ottobre], meta: { current_page: 1, last_page: 3, per_page: 20, total: 45 },
        condomini: [{ id: 3, nome: 'Via Roma 12' }, { id: 4, nome: 'Via Verdi 3' }], anni: [2026, 2025], filtri: { condominio: null, anno: null }, ...extra,
    } as never, global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<header />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } } });
    return w;
};
const testo = (e: Element) => (e.textContent ?? '').replace(/\s+/g, ' ');
const righe = () => Array.from(document.body.querySelectorAll<HTMLElement>('[data-testid="assemblea-della-persona"]'));

beforeEach(() => { chi.permessi = ['Vede le assemblee']; routerGet.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('la linguetta «Assemblee»', () => {
    test('le tre card in testa', async () => {
        monta();
        await flushPromises();
        expect(testo(document.querySelector('[data-testid="card-assemblee"]')!)).toBe('Assemblee7 assemblee in 2 condomini');
        expect(testo(document.querySelector('[data-testid="card-impugnare"]')!)).toBe('Può impugnare1, fino al 21/12/2026');
        expect(testo(document.querySelector('[data-testid="card-convocazione"]')!)).toBe('Come si convocaPEC');
        w!.unmount();
        document.body.innerHTML = '';
        monta({ riepilogo: { totale: 1, condomini: 1, ultima: null, puo_impugnare: { quante: 0, fino_al: null } }, anagrafica: { id: 7, nome: 'Mario Rossi', mezzo_convocazione: null } });
        await flushPromises();
        expect(document.querySelector('[data-testid="card-impugnare"]')!.textContent).toContain('Nessuna');
    });

    test('l\'elenco: «Per lei» è un badge rounded-md; la prima riga aperta, l\'altra si apre con un tocco; la dichiarazione è testo', async () => {
        monta();
        await flushPromises();
        const badge = righe()[0].querySelector('[data-testid="per-lei"]')!;
        expect(badge.textContent).toBe('Può impugnare');
        expect(badge.className).toContain('rounded-md');
        expect(badge.className).toContain('amber');
        expect(righe()[1].querySelector('[data-testid="per-lei"]')!.className).toContain('slate');
        expect(testo(righe()[0])).toContain('Via Roma 12');
        expect(testo(righe()[0])).toContain('Dichiarazione a verbale: «<b>Il portone</b> si può riparare.»');
        expect(righe()[0].querySelector('b')).toBeNull();
        expect(righe()[0].querySelector('[data-testid="assemblea-aperta"]')).not.toBeNull();
        expect(righe()[1].querySelector('[data-testid="assemblea-aperta"]')).toBeNull();
        righe()[1].querySelector<HTMLButtonElement>('button')!.click();
        await flushPromises();
        expect(testo(righe()[1].querySelector('[data-testid="assemblea-aperta"]')!)).toContain('Punto 2, il suo piano di rientro, approvata');
    });

    test('i filtri e le pagine sono visite parziali che tengono lo stato e tornano alla prima pagina', async () => {
        monta({ filtri: { condominio: 3, anno: null }, meta: { current_page: 2, last_page: 3, per_page: 20, total: 45 } });
        await flushPromises();
        expect(document.querySelector('[data-testid="filtro-condominio"] .vs__selected')!.textContent).toContain('Via Roma 12');
        expect(document.querySelector('select')).toBeNull();
        const filtroAnno = document.querySelector('[data-testid="filtro-anno"] .v-select')!;
        filtroAnno.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        (Array.from(filtroAnno.querySelectorAll('.vs__dropdown-option')).find((o) => (o.textContent ?? '').trim() === '2025') as HTMLElement).click();
        await flushPromises();
        const [url, dati, opzioni] = routerGet.mock.calls[0];
        expect(url).toBe('/admin/anagrafiche/7/assemblee');
        expect(dati).toEqual({ condominio: 3, anno: 2025, per_page: 20 });
        expect(opzioni).toMatchObject({ preserveState: true, preserveScroll: true, only: ['righe', 'meta', 'filtri'] });
        document.querySelector<HTMLButtonElement>('[data-testid="pagina-successiva"]')!.click();
        await flushPromises();
        expect(routerGet.mock.calls[1][1]).toEqual({ condominio: 3, per_page: 20, page: 3 });
        expect(testo(document.querySelector('[data-testid="pagina-di"]')!)).toBe('Pagina 2 di 3');
    });

    test('senza righe, una frase', async () => {
        monta({ righe: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
        await flushPromises();
        expect(document.querySelector('[data-testid="nessuna-assemblea"]')!.textContent).toContain('Nessuna assemblea per questi filtri.');
    });
});

describe('la barra delle linguette', () => {
    test('«Assemblee» c\'è, con il numero, solo con «Vede le assemblee»', () => {
        const barra = () => mount(Layout, { global: { stubs: { NavSezione: { props: ['items'], template: '<ul><li v-for="v in items" :key="v.title">{{ v.title }}|{{ v.badge }}|{{ v.href }}</li></ul>' } } } }).text();
        expect(barra()).toContain('Assemblee|7|/admin/anagrafiche/7/assemblee');
        chi.permessi = [];
        expect(barra()).not.toContain('Assemblee');
        expect(barra()).toContain('Documenti');
    });
});
