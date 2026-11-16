// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scelta del condominio fra quelli dello studio, con la ricerca, sul modello del selettore della dashboard social (`BuildingsDropdown.vue`):
 * un riquadro con «Cerca condominio…», l'elenco che scorre dentro il riquadro (al massimo 300 pixel), «Nessun condominio trovato.» quando la ricerca
 * non trova niente; il condominio in uso segnato, in grassetto e con la spunta; la scelta torna a chi usa il componente. Con `tutti`, una prima voce
 * per «nessun condominio» (un filtro).
 *
 * Poi l'intestazione delle pagine (`PageHeaderGuide.vue`): con più condomini il pulsante di oggi apre questo riquadro, e la scelta porta dove portava
 * (`selectCondominio`: lo stesso indirizzo con l'altro condominio).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const visita = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: visita },
    usePage: () => ({ url: '/admin/gestionale/3/rate-scadute?vista=scadute', props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n }) }));

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
// jsdom non ha `scrollIntoView`: l'elenco ci porta la voce evidenziata.
(Element.prototype as unknown as { scrollIntoView: unknown }).scrollIntoView = () => undefined;

const SceltaCondominio = (await import('./SceltaCondominio.vue')).default;
const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

const condomini = [
    { id: 3, nome: 'Condominio Le Rose' }, { id: 4, nome: 'Via Verdi 3' }, { id: 5, nome: 'Residenza Aurora' },
    ...Array.from({ length: 22 }, (_, i) => ({ id: 10 + i, nome: `Condominio numero ${i + 1}` })),
];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; visita.mockReset(); });

const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const riquadro = () => document.body.querySelector('[data-testid="scelta-condominio"]');
const voci = () => [...document.body.querySelectorAll('[data-testid="scelta-condominio"] [role="option"]')].map((e) => pulito(e.textContent));

async function apri(wrapper: VueWrapper, pulsante = '[data-testid="apri"]') {
    await wrapper.get(pulsante).trigger('click');
    await flushPromises();
}

async function cerca(testo: string) {
    const campo = riquadro()!.querySelector('input') as HTMLInputElement;
    campo.value = testo;
    campo.dispatchEvent(new Event('input'));
    await flushPromises();
}

function montaScelta(altro: Record<string, unknown> = {}) {
    w = mount({
        components: { SceltaCondominio },
        props: ['scelto', 'tutti'],
        emits: ['scegli'],
        template: '<SceltaCondominio :condomini="condomini" :scelto="scelto" :tutti="tutti" @scegli="(id) => $emit(\'scegli\', id)"><button type="button" data-testid="apri">Apri</button></SceltaCondominio>',
        data: () => ({ condomini }),
    }, { attachTo: document.body, props: { scelto: 3, ...altro } });

    return w;
}

describe('la scelta del condominio', () => {
    test('il riquadro ha il campo «Cerca condominio…» e l\'elenco con il tetto dei 300 pixel, che scorre dentro', async () => {
        await apri(montaScelta());
        expect(riquadro()).not.toBeNull();
        expect((riquadro()!.querySelector('input') as HTMLInputElement).placeholder).toBe('Cerca condominio…');
        const elenco = riquadro()!.querySelector('[role="listbox"]') ?? riquadro()!.querySelector('.max-h-\\[300px\\]');
        expect(riquadro()!.querySelector('.max-h-\\[300px\\].overflow-y-auto')).not.toBeNull();
        expect(elenco).not.toBeNull();
        expect(voci()).toHaveLength(25);
        // La larghezza del modello, o quella del pulsante se è più largo.
        expect(riquadro()!.className).toContain('w-[300px]');
        expect(riquadro()!.className).toContain('min-w-[var(--reka-popover-trigger-width)]');
    });

    test('la ricerca filtra i nomi; senza risultati «Nessun condominio trovato.»', async () => {
        await apri(montaScelta());
        await cerca('verdi');
        expect(voci()).toEqual(['Via Verdi 3']);
        await cerca('rose');
        expect(voci()).toEqual(['Condominio Le Rose']);
        await cerca('zzz');
        expect(voci()).toEqual([]);
        expect(pulito(riquadro()!.textContent)).toContain('Nessun condominio trovato.');
    });

    test('il condominio in uso è segnato, in grassetto e con la spunta; gli altri no', async () => {
        await apri(montaScelta());
        const inUso = [...document.body.querySelectorAll('[data-testid="scelta-condominio"] [role="option"]')].filter((e) => e.querySelector('[data-testid="in-uso"]'));
        expect(inUso.map((e) => pulito(e.textContent))).toEqual(['Condominio Le Rose']);
        expect(inUso[0].className).toContain('font-semibold');
        expect(inUso[0].getAttribute('aria-current')).toBe('true');
    });

    test('la scelta torna a chi usa il componente, e il riquadro si chiude', async () => {
        await apri(montaScelta());
        const voce = [...document.body.querySelectorAll('[data-testid="scelta-condominio"] [role="option"]')].find((e) => pulito(e.textContent) === 'Via Verdi 3') as HTMLElement;
        voce.click();
        await flushPromises();
        expect(w!.emitted('scegli')).toEqual([[4]]);
        expect(riquadro()).toBeNull();
    });

    test('con `tutti` la prima voce è «nessun condominio», e torna null; segnata quando non c\'è una scelta', async () => {
        await apri(montaScelta({ scelto: null, tutti: 'Tutti i condomini' }));
        expect(voci()[0]).toBe('Tutti i condomini');
        const prima = document.body.querySelector('[data-testid="scelta-condominio"] [role="option"]') as HTMLElement;
        expect(prima.querySelector('[data-testid="in-uso"]')).not.toBeNull();
        prima.click();
        await flushPromises();
        expect(w!.emitted('scegli')).toEqual([[null]]);
    });
});

describe('nell\'intestazione delle pagine', () => {
    const intestazione = (lista = condomini) => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: { pageTitle: 'Rate scadute', guides: [], condominio: lista[0] as never, condomini: lista as never } });

        return w;
    };

    test('il pulsante resta quello di oggi (alto 8, l\'edificio, il nome, la freccia) e apre il riquadro con la ricerca', async () => {
        const pagina = intestazione();
        const pulsante = pagina.get('[data-testid="scegli-condominio"]');
        expect(pulsante.classes()).toEqual(expect.arrayContaining(['inline-flex', 'h-8', 'rounded-lg', 'border', 'border-slate-200', 'dark:border-slate-800']));
        expect(pulito(pulsante.text())).toBe('Condominio Le Rose');
        expect(pulsante.findAll('svg')).toHaveLength(2);
        await apri(pagina, '[data-testid="scegli-condominio"]');
        expect(voci()).toHaveLength(25);
        expect(document.body.querySelectorAll('[role="menuitem"]')).toHaveLength(0);
    });

    test('scegliere un condominio porta dove portava: lo stesso indirizzo con l\'altro condominio', async () => {
        const pagina = intestazione();
        await apri(pagina, '[data-testid="scegli-condominio"]');
        await cerca('aurora');
        (document.body.querySelector('[data-testid="scelta-condominio"] [role="option"]') as HTMLElement).click();
        await flushPromises();
        expect(visita).toHaveBeenCalledWith('/admin/gestionale/5/rate-scadute?vista=scadute', { preserveState: false, preserveScroll: true });
    });

    test('con un condominio solo resta il nome, senza riquadro', () => {
        const pagina = intestazione([condomini[0]]);
        expect(pagina.find('[data-testid="scegli-condominio"]').exists()).toBe(false);
        expect(pagina.text()).toContain('Condominio Le Rose');
    });
});
