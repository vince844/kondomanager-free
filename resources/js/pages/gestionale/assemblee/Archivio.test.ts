// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La testa dell'archivio delle assemblee (decisione 657, rilievo C29): la riga dei pulsanti non va a capo. Fuori dal menu restano «Prepara l'assemblea» (il gesto
 * principale) e il resto della testa; «Il registro dei verbali» e «Registra una delibera a mano» stanno nel menu «Azioni», ognuno con la riga grigia sotto, e la
 * seconda apre la sua finestra come prima.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn(), visit: vi.fn(), post: vi.fn(), reload: vi.fn() }, Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, InfiniteScroll: { template: '<div><slot /></div>' },
    usePage: () => ({ url: '/', props: { auth: { user: { name: 'Andrea Costa', roles: ['amministratore'], permissions: [] } }, flash: {} } }) }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div />' } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
async function monta(puoGestire = true) {
    const Pagina = (await import('./Archivio.vue')).default;
    w = mount(Pagina as never, { attachTo: document.body, props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, filtri: { anno: null, stato: null },
        assemblee: { data: [], total: 0 }, anni: [], card: { prossima: { valore: '—', sotto: null }, in_attesa: { valore: '0', sotto: null }, delibere: { valore: '0', sotto: null } },
        raccolta: null, puo: { gestire: puoGestire, registrare_il_verbale: puoGestire } } as never,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, RegistraDeliberaAMano: { props: ['open'], template: '<div data-testid="finestra-a-mano" :data-aperta="open" />' },
            PageHeaderGuide: { props: ['pageTitle'], template: '<header><h1>{{ pageTitle }}</h1><slot name="actions" /></header>' } } } });
    await flushPromises();
    return w;
}
async function apriMenu() {
    const t = document.body.querySelector('[data-testid="menu-archivio"]') as HTMLElement;
    t.focus();
    t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await flushPromises();
    await new Promise((r) => setTimeout(r, 30));
    await flushPromises();
}

describe('la testa dell\'archivio delle assemblee', () => {
    test('in testa solo «Azioni» e «Prepara l\'assemblea»: il registro e la delibera a mano sono nel menu, con la riga grigia sotto', async () => {
        const pg = await monta();
        expect(pg.find('[data-testid="registro-dei-verbali"]').exists()).toBe(false);
        expect(pg.find('[data-testid="registra-a-mano"]').exists()).toBe(false);
        expect(pg.find('[data-testid="menu-archivio"]').text()).toContain('Azioni');
        expect(pg.find('[data-testid="prepara"]').classes()).toContain('bg-slate-900');
        await apriMenu();
        const voci = [...document.body.querySelectorAll('[data-testid="nome-voce"]')].map((x) => `${x.textContent} — ${x.nextElementSibling?.textContent}`);
        expect(voci).toEqual(['Il registro dei verbali — I verbali registrati, in ordine', 'Registra una delibera a mano — Una riunione tenuta senza il programma']);
    });

    test('«Registra una delibera a mano» apre la sua finestra; chi non gestisce ha solo il registro', async () => {
        const pg = await monta();
        await apriMenu();
        (document.body.querySelector('[data-testid="registra-a-mano"]') as HTMLElement).click();
        await flushPromises();
        expect(pg.find('[data-testid="finestra-a-mano"]').attributes('data-aperta')).toBe('true');
        w!.unmount(); document.body.innerHTML = '';
        await monta(false);
        await apriMenu();
        expect(document.body.querySelector('[data-testid="registro-dei-verbali"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="registra-a-mano"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="prepara"]')).toBeNull();
    });
});
