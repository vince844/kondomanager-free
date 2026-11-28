// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Rendi incassabili questi saldi»: la pagina dei saldi offre il pulsante per ogni gestione con dei saldi che nessun piano tiene, e la creazione del
 * piano arriva già compilata (la gestione, il nome, una rata, la prima rata ordinaria) con il riquadro della bozza. Le parole vengono dalla bozza
 * approvata `beta15_saldi_incassabili_bozza.html`. Il server: `PianoDiSoliSaldiTest`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (_u: string, _d: unknown, o: any) => o?.onSuccess?.({ props: { saldoInfo: { applicabile: true } } }), post: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}?${new URLSearchParams(p as Record<string, string> ?? {}).toString()}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;
const SaldiList = (await import('@/pages/gestionale/saldi/SaldiList.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' }, StrutturaLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true, SaldiGuide: true, SaldiDetailPanel: true, DichiaraOrigineDialog: true, ScopertoWarning: true, DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' }, Head: { template: '<span />' },
};
const CONSUNTIVO = { id: 52, nome: 'CONSUNTIVO 2025', tipo: 'ordinaria', data_inizio: '2026-01-01' };
const testo = () => document.body.textContent!.replace(/\s+/g, ' ');

let pagina: VueWrapper | null = null;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('«Rendi incassabili questi saldi»', () => {
    test('la pagina dei saldi dice quanti saldi non sono in un piano, quanto fanno, e porta alla creazione con la gestione', async () => {
        pagina = mount(SaldiList, {
            attachTo: document.body,
            props: { condominio: { id: 28, nome: 'Via dei Tigli 4' } as never, condomini: [], esercizio: { id: 41, nome: '2026' }, immobili: [], gestioni: [CONSUNTIVO],
                saldi_incassabili: [{ gestione_id: 52, gestione: 'CONSUNTIVO 2025', numero: 3, totale: 33201 }] },
            global: { stubs, mocks: { route: (n: string) => `/${n}` } },
        });
        await flushPromises();

        expect(testo()).toContain('Saldi iniziali · CONSUNTIVO 2025');
        expect(testo()).toContain('3 saldi non sono ancora in un piano rate: € 332,01 da incassare. Un saldo si incassa solo dentro una rata.');
        const link = [...document.body.querySelectorAll('a')].find((a) => a.textContent!.includes('Rendi incassabili questi saldi')) as HTMLAnchorElement;
        expect(link.getAttribute('href')).toContain('gestionale.esercizi.piani-rate.create');
        expect(link.getAttribute('href')).toContain('origine=saldi');
        expect(link.getAttribute('href')).toContain('gestione_id=52');
    });

    test('controprova: senza saldi liberi il riquadro non c\'è', async () => {
        pagina = mount(SaldiList, {
            attachTo: document.body,
            props: { condominio: { id: 28, nome: 'Via dei Tigli 4' } as never, condomini: [], esercizio: { id: 41, nome: '2026' }, immobili: [], gestioni: [CONSUNTIVO], saldi_incassabili: [] },
            global: { stubs, mocks: { route: (n: string) => `/${n}` } },
        });
        await flushPromises();

        expect(document.body.querySelector('[data-testid="saldi-incassabili"]')).toBeNull();
    });

    test('la creazione arriva compilata, con il riquadro della bozza', async () => {
        pagina = mount(PianiRateNew, {
            attachTo: document.body,
            props: {
                condominio: { id: 28, nome: 'Via dei Tigli 4' } as never, esercizio: { id: 41, nome: '2026' } as never, gestioni: [CONSUNTIVO] as never,
                saldoInfo: { saldo: 0, applicabile: true, motivo: '' }, anagraficheDisponibili: [], hasPianoEsistente: false,
                precompilato: { origine: 'saldi', gestione_id: 52, nome: 'Saldi iniziali CONSUNTIVO 2025', numero_rate: 1, metodo_distribuzione: 'prima_rata', totale: 33201 },
            },
            global: { stubs, mocks: { route: (n: string) => `/${n}` } },
        });
        await flushPromises();
        const form = (pagina.vm as any).form;

        expect([form.tipo, form.gestione_id, form.nome, form.numero_rate, form.metodo_distribuzione]).toEqual(['ordinario', 52, 'Saldi iniziali CONSUNTIVO 2025', 1, 'prima_rata']);
        // Il server riconosce il piano dei saldi da `origine` e lo genera subito; la scelta delle voci e «Genera subito» non si vedono.
        expect([form.origine, form.genera_subito]).toEqual(['saldi', true]);
        expect(document.body.querySelector('[data-testid="scelta-delle-voci"]')).toBeNull();
        expect(document.body.querySelector('#genera_subito')).toBeNull();
        const riquadro = document.body.querySelector('[data-testid="piano-dei-saldi"]')!.textContent!.replace(/\s+/g, ' ');
        expect(riquadro).toContain('Nuovo piano rate, già compilato');
        expect(riquadro).toContain('Voci: nessuna: il piano chiede solo i saldi.');
        expect(riquadro).toContain('Il piano nasce in bozza con una rata da € 332,01. Si approva con la data della delibera del consuntivo da cui vengono i saldi, poi si emette.');
    });
});

describe('la guida dei saldi', () => {
    test('dice perché, che cosa fa «Rendi incassabili questi saldi» e quando i saldi entrano nello stato patrimoniale', async () => {
        const SaldiGuide = (await import('@/components/guides/SaldiGuide.vue')).default;
        const conSlot = { template: '<div><slot /></div>' };
        pagina = mount(SaldiGuide, { attachTo: document.body, props: { open: true },
            global: { stubs: { Sheet: conSlot, SheetContent: conSlot, SheetHeader: conSlot, SheetTitle: conSlot, SheetDescription: conSlot } } });
        await flushPromises();
        const t = document.body.querySelector('[data-testid="guida-saldi-incassabili"]')!.textContent!.replace(/\s+/g, ' ');

        expect(t).toContain('Perché: un incasso si registra solo su una rata.');
        expect(t).toContain('Quando: una gestione ha dei saldi iniziali che nessun piano tiene, anche se ha delle voci.');
        expect(t).toContain('Che cosa fa: crea un piano con i soli saldi; le voci della gestione restano libere per il piano del preventivo.');
        expect(t).toContain('Stato patrimoniale: i saldi entrano quando la rata si emette.');
    });
});
