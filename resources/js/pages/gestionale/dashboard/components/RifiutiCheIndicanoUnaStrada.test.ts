// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Dove un rifiuto o un'azione dice che cosa fare, la strada c'è.
 *
 * - La finestra «Emetti le rate»: «Una delle rate scelte non è di questo piano: scegli di nuovo le rate.» arriva quando le rate della finestra non sono più
 *   quelle del piano (il piano è stato ricalcolato in un'altra pagina, e le sue rate hanno un id nuovo). Per scegliere di nuovo la finestra deve rileggere le
 *   rate: senza, le stesse righe rimandano gli stessi id e il rifiuto torna uguale.
 * - Il cassetto delle azioni consigliate: «Manca € … anche con tutte le rate» senza un esercizio aperto non ha il pulsante «Prepara un piano straordinario»;
 *   allora l'azione dice che cosa fare, con un pulsante o con una frase.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), pagina: null as unknown as { props: Record<string, any> } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: (...a: unknown[]) => inertia.reload(...a), post: (...a: unknown[]) => inertia.post(...a), visit: vi.fn() },
    usePage: () => inertia.pagina,
}));
vi.mock('vue-chartjs', () => ({ Line: defineComponent({ props: ['data', 'options', 'plugins'], setup: () => () => h('canvas') }) }));
vi.mock('chart.js', () => ({ Chart: { register: vi.fn() }, LineController: {}, LineElement: {}, PointElement: {}, LinearScale: {}, CategoryScale: {}, Filler: {}, Tooltip: {} }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./EmissioneRapidaDialog.vue')).default;
const AzioniConsigliate = (await import('./AzioniConsigliate.vue')).default;
const { datiDelleAzioniDellEsempio, recuperoDellEsempio, saldoDellEsempio } = await import('./tesoreriaDiProva');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.reload.mockReset(); inertia.post.mockReset(); });
const q = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

const datiDellaFinestra = () => ({
    oggi: '2026-11-16', fino: '2026-12-16', piani: [
        { pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, persone: 4, rate: [
            { rataId: 91, numero: 9, scadenza: '2026-09-30', cifra: 120000, persone: 4, chi: null },
            { rataId: 92, numero: 10, scadenza: '2026-10-31', cifra: 120000, persone: 4, chi: null },
        ] },
    ],
});

describe('dove un rifiuto o un\'azione dice che cosa fare, la strada c\'è', () => {
    test('«scegli di nuovo le rate»: dopo il rifiuto la finestra rilegge le rate del piano', async () => {
        inertia.pagina = reactive({ props: { flash: {} } });
        inertia.reload.mockImplementation((o: any) => { o.onSuccess?.({ props: { emissioneRapida: datiDellaFinestra() } }); o.onFinish?.(); });
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 7, pianoId: 9, oggi: '2026-11-16' } as any });
        await flushPromises();
        expect(inertia.reload).toHaveBeenCalledTimes(1);

        (q('emetti') as HTMLButtonElement).click();
        await flushPromises();
        const [, , o] = inertia.post.mock.calls.at(-1)!;
        o.onError?.({ 'rate_ids.0': 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.' });
        o.onFinish?.();
        await flushPromises();

        expect(pulito(q('errori')?.textContent)).toContain('scegli di nuovo le rate');
        // Le rate della finestra sono quelle di prima: per sceglierle di nuovo servono quelle di adesso.
        expect(inertia.reload).toHaveBeenCalledTimes(2);
        expect(inertia.reload.mock.calls[1][0].only).toEqual(['emissioneRapida']);
    });

    test('«Manca … anche con tutte le rate» senza un esercizio aperto dice che cosa fare', async () => {
        w = mount(AzioniConsigliate, {
            attachTo: document.body,
            // Senza rate già scadute anche «Se pagano tutti» va sotto zero: c'è l'azione del piano straordinario (come nella prova delle sei in più).
            props: { open: true, saldoPrevisto: { ...saldoDellEsempio(), rateScaduteCents: 0 }, recupero: recuperoDellEsempio(), suggerisce: true, condominioId: 7, oggi: '2026-11-15',
                datiDelleAzioni: { ...datiDelleAzioniDellEsempio(), esercizioId: null } } as any,
            global: { stubs: { LettereDialog: true, EmissioneRapidaDialog: true } },
        });
        await vi.dynamicImportSettled();
        await flushPromises();
        const carta = document.body.querySelector('[data-testid="azione-consigliata"][data-chiave="straordinario"]') as HTMLElement | null;
        expect(carta).not.toBeNull();
        const pulsante = carta!.querySelector('[data-testid="pulsante-azione"]');
        // Un pulsante verso una pagina che c'è, o una frase che dice come arrivarci: l'esercizio da aprire.
        expect(pulsante !== null || /esercizio/i.test(pulito(carta!.textContent))).toBe(true);
    });
});
