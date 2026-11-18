// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La proposta degli interessi dall'elenco «Rate scadute»: le persone con le loro righe, i tratti a richiesta, la frase sulla domanda giudiziale; il
 * totale delle righe tenute; togliere una riga o una persona; la voce di entrata per gestione, proposta se c'è l'ultima scelta; la registrazione con le
 * righe tenute, il totale visto e le voci. Una riga ferma (il tasso che manca) non si tiene.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./ProponiInteressiDialog.vue')).default;

const tratto = (dal: string, al: string, giorni: number, capitale: number, esatti: number, causa: string) => ({ dal, al, giorni, capitale, tasso: '1,60 %', fonte: 'tasso legale', divisore: 365, esatti, causa });
const proposta = {
    alla: '2026-06-29',
    persone: [
        { anagrafica_id: 1, nome: 'Neri', totale: 92, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0, righe: [
            { chiave: '1|10|5', rata_id: 10, immobile_id: 5, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
                scadenza: '2026-03-31', dal: '2026-04-01', gia_registrati_al: null, interessi: 92, fermo: null,
                tratti: [tratto('2026-04-01', '2026-04-30', 30, 30000, 3945, 'scadenza'), tratto('2026-05-01', '2026-06-29', 60, 20000, 5260, 'versamento')] },
        ] },
        { anagrafica_id: 2, nome: 'Bianchi', totale: 71, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 9000, righe: [
            { chiave: '2|10|6', rata_id: 10, immobile_id: 6, unita: 'U2', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
                scadenza: '2026-03-31', dal: '2026-04-01', gia_registrati_al: null, interessi: 71, fermo: null, tratti: [tratto('2026-04-01', '2026-06-29', 90, 18000, 7101, 'scadenza')] },
            { chiave: '2|3|6', rata_id: 3, immobile_id: 6, unita: 'U2', rata: 'Rata 0 (pregresso) — Preventivo 2018', piano: 'Preventivo 2018', gestione_id: 2, gestione: 'Ordinaria 2026',
                scadenza: '2018-11-30', dal: '2018-12-01', gia_registrati_al: null, interessi: 0, fermo: 'Manca il tasso legale del 2018: scrivi un tasso con la sua fonte nelle regole del recupero.', tratti: [] },
        ] },
    ],
    totale: 163,
    gestioni: [{ gestione_id: 2, gestione: 'Ordinaria 2026', scrive: { gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)' }, perche: null,
        voci: [{ id: 40, nome: 'Interessi di mora' }, { id: 41, nome: 'Affitto antenna' }], voce_proposta: null }],
    regole: { righe: [], di_oggi: 'Senza una regola vale la legge: il tasso legale dell\'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza.', avviso: null },
    frase_domanda_giudiziale: 'Dopo la domanda giudiziale il tasso è un altro (art. 1284 c. 4 c.c.): gli interessi di una causa li quantifica il legale.',
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const corpo = () => document.body;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = (persone: number[] | null, filtri: Record<string, unknown> = {}) => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, oggi: '2026-06-29', persone, filtri } });

    return w;
};
const registra = () => corpo().querySelector<HTMLButtonElement>('[data-testid="registra-interessi"]')!;
/** Il menu della voce di una gestione (`v-select`): la voce scelta, e la scelta di una voce con un clic. */
const menuVoce = (g: number) => corpo().querySelector(`[data-testid="voce-${g}"] .v-select`) as HTMLElement;
const voceScelta = (g: number) => pulito(menuVoce(g).querySelector('.vs__selected')?.textContent);
const scegliVoce = async (g: number, testo: string) => {
    menuVoce(g).querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    ([...menuVoce(g).querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};

describe('la proposta degli interessi', () => {
    test('le persone scelte, riga per riga; i tratti a richiesta; la riga ferma non si tiene; accanto le quote di inquilini', async () => {
        axiosGet.mockResolvedValue({ data: proposta });
        monta([1, 2]);
        await flushPromises();
        expect(axiosGet.mock.calls[0][1]).toEqual({ params: { persone: [1, 2], alla: '2026-06-29' } });
        expect(pulito(corpo().querySelector('[data-testid="domanda-giudiziale"]')?.textContent)).toBe(proposta.frase_domanda_giudiziale);
        expect(corpo().querySelectorAll('[data-testid="persona-interessi"]')).toHaveLength(2);
        expect(pulito(corpo().querySelector('[data-testid="fermo"]')?.textContent)).toContain('Manca il tasso legale del 2018');
        expect(pulito(corpo().querySelector('[data-testid="accanto"]')?.textContent)).toContain('Quote di inquilini scadute: € 90,00.');
        // Le righe con una cifra tenute di serie: € 0,92 + € 0,71.
        expect(pulito(registra().textContent)).toBe('Registra € 1,63');
        // La voce non è scelta: niente si registra.
        expect(registra().disabled).toBe(true);

        corpo().querySelector<HTMLButtonElement>('[data-testid="apri-tratti"]')!.click();
        await flushPromises();
        expect([...corpo().querySelectorAll('[data-testid="tratti"] li')].map((l) => pulito(l.textContent))).toEqual([
            'dal 01/04/2026 al 30/04/2026, 30 giorni su € 300,00 al 1,60 % (tasso legale), su 365: € 0,3945 (dalla scadenza)',
            'dal 01/05/2026 al 29/06/2026, 60 giorni su € 200,00 al 1,60 % (tasso legale), su 365: € 0,5260 (dopo un versamento)',
        ]);
    });

    test('togliere Bianchi, scegliere la voce e registrare: le righe tenute, il totale visto, la voce della gestione', async () => {
        axiosGet.mockResolvedValue({ data: proposta });
        monta([1, 2]);
        await flushPromises();
        corpo().querySelectorAll<HTMLButtonElement>('[data-testid="togli-persona"]')[1].click();
        await flushPromises();
        expect(pulito(registra().textContent)).toBe('Registra € 0,92');
        // All'apertura nessun menu è aperto; la voce si sceglie con il menu del programma.
        expect(corpo().querySelectorAll('.vs--open')).toHaveLength(0);
        await scegliVoce(2, 'Interessi di mora');
        expect(registra().disabled).toBe(false);

        axiosPost.mockResolvedValueOnce({ data: { rate: [99], messaggio: 'Interessi registrati: 1 addebito, € 0,92.' } });
        registra().click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toBe('/gestionale.addebiti.interessi.store');
        expect(axiosPost.mock.calls[0][1]).toEqual({ alla: '2026-06-29', righe: ['1|10|5'], atteso: 92, voci: { 2: 40 } });
        expect(pulito(corpo().querySelector('[data-testid="esito"]')?.textContent)).toBe('Interessi registrati: 1 addebito, € 0,92.');
        expect(w!.emitted('fatto')).toHaveLength(1);
    });

    test('«tutte le N dei filtri»: il server rilegge le persone dai filtri; l\'ultima voce scelta è proposta', async () => {
        axiosGet.mockResolvedValue({ data: { ...proposta, gestioni: [{ ...proposta.gestioni[0], voce_proposta: 40 }] } });
        monta(null, { fascia: 'oltre_90' });
        await flushPromises();
        expect(axiosGet.mock.calls[0][1]).toEqual({ params: { tutte: 1, filtri: { fascia: 'oltre_90' }, alla: '2026-06-29' } });
        expect(voceScelta(2)).toBe('Interessi di mora');
        expect(registra().disabled).toBe(false);
    });

    test('una riga con gli interessi registrati che il giornale di oggi darebbe diversi lo dice', async () => {
        const frase = 'Gli interessi registrati al 29/06/2026 erano € 1,18; con il giornale di oggi quei giorni darebbero € 0,92: per cambiarli, annulla l\'addebito e rifai la proposta.';
        const conRiallineo = JSON.parse(JSON.stringify(proposta));
        conRiallineo.persone[0].righe[0].gia_registrati_al = '2026-06-29';
        conRiallineo.persone[0].righe[0].riallineo = frase;
        axiosGet.mockResolvedValue({ data: conRiallineo });
        monta([1, 2]);
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="riallineo"]')?.textContent)).toBe(frase);
        expect(corpo().querySelectorAll('[data-testid="riallineo"]')).toHaveLength(1);
    });

    test('una riga a zero con la frase del riallineo (nessun giorno nuovo, i giorni già registrati darebbero un\'altra cifra): si legge, senza casella, e non si manda', async () => {
        const frase = 'Gli interessi registrati al 20/07/2026 erano € 1,05; con il giornale di oggi quei giorni darebbero € 1,02: per cambiarli, annulla l\'addebito e rifai la proposta.';
        const aZero = JSON.parse(JSON.stringify(proposta));
        aZero.persone[0].totale = 0;
        aZero.persone[0].righe[0] = { ...aZero.persone[0].righe[0], interessi: 0, tratti: [], gia_registrati_al: '2026-07-14', riallineo: frase };
        aZero.totale = 71;
        axiosGet.mockResolvedValue({ data: aZero });
        monta([1, 2]);
        await flushPromises();
        const righe = corpo().querySelectorAll('[data-testid="riga-interessi"]');
        expect(pulito(righe[0].querySelector('[data-testid="riallineo"]')?.textContent)).toBe(frase);
        expect(righe[0].querySelector('button[role="checkbox"]')).toBeNull();
        expect(righe[1].querySelector('button[role="checkbox"]')).not.toBeNull();
        expect(corpo().querySelector('[data-testid="togli-persona"]')).not.toBeNull();
        await scegliVoce(2, 'Interessi di mora');
        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Registrati.' } });
        registra().click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toMatchObject({ righe: ['2|10|6'], atteso: 71 });
    });

    test('una rata coperta da un accordo di rientro in regola: la riga dice che arriva al giorno prima dell\'accordo, e accanto la regola (decisione 438)', async () => {
        const conAccordo = JSON.parse(JSON.stringify(proposta));
        conAccordo.persone[0].in_accordo = 20000;
        conAccordo.persone[0].righe[0].al = '2026-06-14';
        conAccordo.persone[0].righe[0].accordo_dal = '2026-06-15';
        axiosGet.mockResolvedValue({ data: conAccordo });
        monta([1, 2]);
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="fino-all-accordo"]')?.textContent)).toBe('Fino al 14/06/2026: dal 15/06/2026 la rata è in un accordo di '
            + 'rientro, e i giorni dell\'accordo restano fuori finché l\'accordo non è decaduto e il calendario non è finito, o è versato tutto.');
        // Solo la riga con l'accordo.
        expect(corpo().querySelectorAll('[data-testid="fino-all-accordo"]')).toHaveLength(1);
        expect([...corpo().querySelectorAll('[data-testid="accanto"]')].map((p) => pulito(p.textContent))).toContain('€ 200,00 sono in un accordo di rientro in corso: '
            + 'la proposta lascia fuori i giorni dalla data dell\'accordo; i giorni prima dell\'accordo restano nella proposta.');
    });
});
