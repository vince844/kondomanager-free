// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le lettere per PEC non partite, o con l'avviso di mancata consegna, nella pagina «Rate scadute» (1.12.0-beta.13): l'avviso le conta accanto alle email da
 * rifare; «Rimanda le PEC non partite» le rifà in blocco dalla casella dello studio, «Stampale per la posta» le stampa con le cifre di oggi. E la lettera non
 * partita sotto l'ultima, che dice la PEC.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
const reload = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: (...a: unknown[]) => reload(...a), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
const apri = vi.fn();
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({ ...(await originale<object>()), apriPdfInUnaScheda: (...a: unknown[]) => apri(...a) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RateScadute.vue')).default;

const riga = (extra: Record<string, unknown> = {}) => ({
    anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 0,
    fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 }, ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2027-06-30', dispensa: null, mancano: 0 },
    con_pregresso: false, origine_non_dichiarata: false, rate: [],
    ultima_lettera: null,
    lettera_in_sospeso: { tipo: 'diffida', data: '2026-03-20', canale: 'pec', stato: 'non_partita', pec: 'non_consegnata', mancata_consegna_il: '2026-03-21' },
    ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } },
    totale: 15000, persone_totali: 1,
    filtri: { alla: '2026-03-21', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false,
        ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false },
    filtri_ricordati: false, oggi: '2026-03-21', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: 'Norma',
    lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0, pec_da_rimandare: 2,
    ...extra,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true };
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { props: proprieta(extra) as any, global: { stubs } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); reload.mockReset(); apri.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le PEC non partite nelle rate scadute', () => {
    test('l\'avviso le conta, con «Rimanda le PEC non partite» e «Stampale per la posta»; senza, non c\'è', () => {
        const avviso = monta().get('[data-testid="pec-da-rimandare"]');
        expect(pulito(avviso.text())).toContain('2 lettere per PEC non sono partite, o hanno l\'avviso di mancata consegna.');
        expect(avviso.get('[data-testid="rimanda-le-pec"]').text()).toBe('Rimanda le PEC non partite');
        expect(avviso.get('[data-testid="stampa-le-pec"]').text()).toBe('Stampale per la posta');
        w!.unmount();
        expect(monta({ pec_da_rimandare: 0 }).find('[data-testid="pec-da-rimandare"]').exists()).toBe(false);
    });

    test('«Rimanda le PEC non partite» le rifà in blocco e dice la frase del server; poi la pagina si ricarica', async () => {
        monta();
        axiosPost.mockResolvedValueOnce({ data: { partite: 2, non_partite: 0, frase: '2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.' } });
        await w!.get('[data-testid="rimanda-le-pec"]').trigger('click');
        await flushPromises();
        expect(axiosPost.mock.calls.at(-1)?.[0]).toBe('/gestionale.lettere.pec-non-partite');
        expect(pulito(w!.text())).toContain('2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.');
        expect(reload).toHaveBeenCalled();
    });

    test('«Stampale per la posta» apre il PDF delle lettere rifatte', async () => {
        monta();
        apri.mockResolvedValueOnce(undefined);
        await w!.get('[data-testid="stampa-le-pec"]').trigger('click');
        await flushPromises();
        expect(apri).toHaveBeenCalledTimes(1);
        axiosPost.mockResolvedValueOnce({ data: new Blob(['%PDF']), headers: {} });
        await (apri.mock.calls[0][0] as () => Promise<unknown>)();
        expect(axiosPost.mock.calls.at(-1)?.[0]).toBe('/gestionale.lettere.pec-non-partite.stampa');
    });

    test('la lettera sotto l\'ultima dice la PEC con l\'avviso di mancata consegna, a 12 pixel', () => {
        const colonna = monta().get('[data-testid="ultima-lettera"]');
        expect(pulito(colonna.text())).toContain('poi: Diffida · PEC non consegnata, avviso del gestore del 21/03/2026');
        expect(colonna.html()).not.toMatch(/text-\[(9|10|11)px\]/);
    });
});
