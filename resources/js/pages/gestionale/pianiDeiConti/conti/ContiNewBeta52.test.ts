// @vitest-environment jsdom

/**
 * Cosa resta scoperto: l'albero dei conti, i modali e le guide (non leggono i totali); il colore e la larghezza a 375 px (si guardano a video); la
 * guida «Operazioni e struttura» e il suo testo sulla distinta.
 *
 * I totali sopra l'elenco dei conti, con le voci di entrata.
 *
 * Il badge «Preventivo» somma le sole voci di spesa (decisione 87.3: nel piano dei conti «Preventivo» resta sulle spese); con almeno un'entrata
 * compare un secondo badge, «Entrate: € 240,00», con il suggerimento che dice il netto: «Le entrate si sottraggono alle spese: da ripartire € 960,00.».
 * Senza entrate il secondo badge non c'è e la pagina è quella di sempre. La scheda «Preventivo e Consuntivo» dice che il preventivo conta le sole voci
 * di spesa. Le cifre arrivano dal server in centesimi (`totalePreventivo`, `totaleEntrate`, `totaleDaRipartire`).
 *
 * Il suggerimento del badge («Le entrate si sottraggono alle spese: …») detta una regola e si firma con la clausola del motore, letta alla lettera:
 * `in_array($conto->tipo, ['spesa', 'uscita']) ? abs($importo) : -abs($importo)` (`CalcoloQuoteService::processaConti`, il ramo con l'importo del piano e quello con l'importo della voce).
 *
 * La pagina è quella vera: si stubbano la cornice, i modali, l'albero, la finestra dei movimenti e il dettaglio della voce. La guida in testa
 * alla pagina è quella vera, e si apre per leggere le schede.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const ContiNew = (await import('@/pages/gestionale/pianiDeiConti/conti/ContiNew.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(totali: Record<string, number>) {
    pagina = mount(ContiNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never, esercizi: [], condomini: [],
            pianoConti: { id: 1, nome: 'Piano dei conti', stato: 'bozza' } as never, conti: [], fornitori: [], tabelle: [],
            totaleSopravvenienze: 0, totaleConsuntivo: 0, ...totali,
        } as never,
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, ModalNuovoConto: true, ModalModificaConto: true, AlberoDeiConti: true, MovimentiVoceDialog: true,
                DettaglioConto: true, ConfirmDialog: true, ModalAssociaTabella: true, RipartoUsufruttoGuide: true, OperazioniContiGuide: true, GiaVersatoGuide: true,
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const badge = (inizio: string) => [...document.body.querySelectorAll('span')].find((s) => normalizza(s.textContent).startsWith(inizio)) as HTMLElement | undefined;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('i badge sopra l\'elenco dei conti', () => {
    test('con un\'entrata: «Preventivo: € 1.200,00» sulle sole spese e «Entrate: € 240,00» con il suggerimento sul netto', async () => {
        await monta({ totalePreventivo: 120000, totaleEntrate: 24000, totaleDaRipartire: 96000 });

        expect(badge('Preventivo:')).toBeDefined();
        expect(normalizza(badge('Preventivo:')!.textContent)).toBe('Preventivo: € 1.200,00');
        expect(normalizza(badge('Entrate:')?.textContent)).toBe('Entrate: € 240,00');
        expect(normalizza(badge('Entrate:')!.getAttribute('title'))).toBe('Le entrate si sottraggono alle spese: da ripartire € 960,00.');
    });

    test('controprova: senza entrate c\'è solo «Preventivo: € 1.200,00», come prima (la risposta porta solo le chiavi di prima)', async () => {
        await monta({ totalePreventivo: 120000 });

        expect(normalizza(badge('Preventivo:')!.textContent)).toBe('Preventivo: € 1.200,00');
        expect(badge('Entrate:')).toBeUndefined();
    });

    test('con la sola entrata «Preventivo» è € 0,00 e il netto del suggerimento è negativo', async () => {
        await monta({ totalePreventivo: 0, totaleEntrate: 24000, totaleDaRipartire: -24000 });

        expect(normalizza(badge('Preventivo:')!.textContent)).toBe('Preventivo: € 0,00');
        expect(normalizza(badge('Entrate:')?.textContent)).toBe('Entrate: € 240,00');
        expect(normalizza(badge('Entrate:')!.getAttribute('title'))).toBe('Le entrate si sottraggono alle spese: da ripartire € -240,00.');
    });
});

describe('la scheda «Preventivo e Consuntivo» in testa alla pagina', () => {
    test('dice che il preventivo conta le sole voci di spesa e che le entrate hanno il loro totale', async () => {
        await monta({ totalePreventivo: 120000, totaleEntrate: 24000, totaleDaRipartire: 96000 });

        expect(testo()).toContain('Per ogni voce vedi quanto avevi previsto e quanto hai speso davvero. Clicca il consuntivo per scoprire da quali movimenti nasce.');
        expect(testo()).toContain('Il preventivo conta le sole voci di spesa: le entrate hanno il loro totale.');
    });
});
