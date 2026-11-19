// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu «Metodo di distribuzione» del nuovo piano rate, con il mouse. Nella prova a video di tutto il processo della beta.12 (18/11/2026) due volte la
 * scelta fatta con il mouse non è rimasta (presa con la tastiera la terza). Qui si prova a riprodurlo sulla pagina vera, con `vue-select` vero (non un
 * segnaposto) e la correzione che l'applicazione carica prima di ogni pagina (`vSelectChiusoDopoLaScelta.ts`): gli eventi del mouse nell'ordine del
 * browser (sul campo `mousedown`; sulla voce `mousedown`, `mouseup`, `click`, che è dove `vue-select` 4.0.0-beta.6 fa la scelta), due scelte di seguito,
 * una con la pagina che si ridisegna fra la pressione e il rilascio, e la scelta con la tastiera come controllo.
 *
 * Che cosa NON copre: il clic vero di un browser (le coordinate, ciò che sta sopra il menu, il passaggio del puntatore sulla «i» accanto all'etichetta);
 * il clic dello strumento che ha guidato la prova a video. Se queste prove sono verdi, il difetto non è nella pagina né nel menu come li monta jsdom.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import '@/lib/vSelectChiusoDopoLaScelta';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true,
    ScopertoWarning: true,
    DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
};

let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

async function monta() {
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 16, nome: 'Via roma' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [{ id: 35, nome: 'Ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' }] as never,
            // I saldi da distribuire ci sono: il riquadro con il menu si vede.
            saldoInfo: { saldo: 5000, applicabile: true, has_movimenti: true, is_primo_anno: false, motivo: '' }, anagraficheDisponibili: [], hasPianoEsistente: false,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
/** Il menu «Metodo di distribuzione»: il primo `v-select` dopo l'etichetta. */
function menu(): HTMLElement {
    const etichetta = [...document.body.querySelectorAll('label')].find((l) => l.textContent?.trim() === 'Metodo di distribuzione');
    if (!etichetta) throw new Error('Il menu «Metodo di distribuzione» non c\'è.');
    const blocco = etichetta.closest('div')!.parentElement!;

    return blocco.querySelector('.v-select') as HTMLElement;
}
const mouse = (el: Element, tipo: string) => el.dispatchEvent(new MouseEvent(tipo, { bubbles: true, cancelable: true, button: 0 }));
const scelto = () => menu().querySelector('.vs__selected')?.textContent?.trim();

/** Apre il menu come il mouse (pressione sul campo) e sceglie la voce con pressione, rilascio e clic; `inMezzo` succede fra pressione e rilascio. */
async function scegliColMouse(voce: string, inMezzo: () => void = () => {}) {
    mouse(menu().querySelector('.vs__dropdown-toggle')!, 'mousedown');
    await flushPromises();
    const li = [...menu().querySelectorAll('.vs__dropdown-option')].find((x) => x.textContent?.trim() === voce);
    if (!li) throw new Error(`La voce «${voce}» non è nel menu aperto.`);
    mouse(li, 'mouseover');
    mouse(li, 'mousedown');
    inMezzo();
    await flushPromises();
    mouse(li, 'mouseup');
    mouse(li, 'click');
    await flushPromises();
}

describe('«Metodo di distribuzione»: la scelta con il mouse resta', () => {
    test('due scelte di seguito con il mouse: ognuna resta nel modulo e nel campo, e il menu si chiude', async () => {
        await monta();
        expect(form().metodo_distribuzione).toBe('rata_zero');

        await scegliColMouse('Somma alla prima rata ordinaria');
        expect(form().metodo_distribuzione).toBe('prima_rata');
        expect(scelto()).toBe('Somma alla prima rata ordinaria');
        expect(menu().querySelector('.vs__dropdown-menu')).toBeNull();

        await scegliColMouse('Spalma matematicamente su tutte le rate');
        expect(form().metodo_distribuzione).toBe('tutte_rate');
        expect(scelto()).toBe('Spalma matematicamente su tutte le rate');
        expect(menu().querySelector('.vs__dropdown-menu')).toBeNull();
    });

    test('con la pagina che si ridisegna fra la pressione e il rilascio (un errore che si toglie, il nome che cambia) la scelta resta', async () => {
        await monta();
        form().setError('metodo_distribuzione', 'Scegli il metodo');
        await flushPromises();

        await scegliColMouse('Somma alla prima rata ordinaria', () => { form().nome = 'Saldi della successione'; });
        expect(form().metodo_distribuzione).toBe('prima_rata');
        expect(form().errors.metodo_distribuzione).toBeUndefined();
        expect(scelto()).toBe('Somma alla prima rata ordinaria');
    });

    test('controllo: con la tastiera (freccia giù e invio) la scelta resta, come a video', async () => {
        await monta();
        const campo = menu().querySelector('input.vs__search') as HTMLInputElement;
        campo.focus();
        await flushPromises();
        // jsdom non legge `keyCode` dalle opzioni dell'evento, e `vue-select` guarda quello: si mette a mano.
        const tasto = async (codice: number) => {
            const evento = new KeyboardEvent('keydown', { bubbles: true, cancelable: true });
            Object.defineProperty(evento, 'keyCode', { value: codice });
            campo.dispatchEvent(evento);
            await flushPromises();
        };
        const evidenziata = () => menu().querySelector('.vs__dropdown-option--highlight')?.textContent?.trim();
        // Freccia giù fino alla voce (dove parte l'evidenza dipende da `vue-select`), poi invio.
        for (let i = 0; i < 3 && evidenziata() !== 'Somma alla prima rata ordinaria'; i++) await tasto(40);
        expect(evidenziata()).toBe('Somma alla prima rata ordinaria');
        await tasto(13);

        expect(form().metodo_distribuzione).toBe('prima_rata');
        expect(scelto()).toBe('Somma alla prima rata ordinaria');
    });
});
