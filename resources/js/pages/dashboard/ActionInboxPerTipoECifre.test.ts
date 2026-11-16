// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'Action inbox: la regola delle 8 nel riquadro «Per tipo» e le cifre all'italiana.
 *
 * - «Per tipo» ha al più 8 barre; oltre, «e altri N tipi» apre una finestra con tutti i tipi, e un clic su un tipo filtra come una barra.
 * - Le cifre come nei grafici e in «Mostrati 15 di 1.250»: «1.250», mai «1250» (`toLocaleString('it-IT')` non raggruppa le quattro cifre).
 * - «Mostra altri» dice quanti ne arrivano davvero: con 18 compiti e 15 mostrati, «Mostra altri 3».
 *
 * Il montaggio è quello di `ActionInbox.test.ts` (i finti di `vi.mock` valgono per file).
 */
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), altri: vi.fn() }));
const disegni = vi.hoisted(() => ({ ultimi: [] as Array<{ data: any; options: any }> }));
const seiMesi = vi.hoisted(() => ({ risposte: {} as Record<number, unknown>, chieste: [] as number[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /><slot name="next" :fetch="fetch" :has-more="true" :loading="false" /></div>', setup: () => ({ fetch: inertia.altri }) },
    router: { patch: inertia.patch, get: inertia.get, post: inertia.post, visit: vi.fn(), on: () => () => undefined },
    usePage: () => ({
        url: '/admin/inbox',
        props: { flash: {}, auth: { user: { roles: [], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url: string) => {
            const id = Number(new URL(url, 'http://x').searchParams.get('condominio'));
            seiMesi.chieste.push(id);

            return { data: seiMesi.risposte[id] };
        }),
    },
}));
vi.mock('vue-chartjs', () => ({
    Bar: defineComponent({
        name: 'BarFinto',
        props: ['data', 'options'],
        setup(props) {
            disegni.ultimi.push({ data: props.data, options: props.options });

            return () => h('canvas', { 'data-testid': 'canvas' });
        },
    }),
}));
vi.mock('chart.js', () => ({ Chart: { register: vi.fn() }, BarController: {}, BarElement: {}, CategoryScale: {}, LinearScale: {}, Tooltip: {} }));

(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown> | number) =>
    (typeof p === 'object' ? `/${nome}?${new URLSearchParams(p as Record<string, string>).toString()}` : p === undefined ? `/${nome}` : `/${nome}/${p}`);
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(Element.prototype as unknown as { scrollIntoView: unknown }).scrollIntoView = () => undefined;

const ActionInbox = (await import('@/pages/dashboard/ActionInbox.vue')).default;

const passa = { template: '<div><slot /></div>' };

const riga = (id: number, altro: Record<string, unknown>) => ({
    id, title: `Compito ${id}`, titolo: `Compito ${id}`, sotto: '', description: '', date: '2026-01-01T09:00:00Z', giorno: '2026-01-01', giorni: -10, fascia: 'ultimi_30',
    gruppo: 'scaduti', condominio: 'Via dei Tigli 51', condominio_id: 1, type: 'emissione_rata', azione: { testo: 'Emetti la rata', url: '/admin/gestionale/1/piani/3' },
    context: { anagrafica_nome: null, action_url: '/admin/gestionale/1/piani/3', related_id: null }, risposta: null, promemoria_a_parte: false, scadenza: null, fatto_spento: null,
    ...altro,
});

const compiti = [
    riga(11, { title: 'Emettere rata 1 - Via dei Tigli 51', titolo: 'Emettere rata 1', sotto: 'Rata 1 · € 100,01', giorno: '2025-12-29', giorni: -322, fascia: 'oltre_90' }),
    riga(12, { title: 'Verifica incassi - Rata 1 (Prova subentro B3a)', titolo: 'Verifica incassi - Rata 1', sotto: 'Rata 1 · € 115,00', condominio: 'Prova subentro B3a',
        type: 'controllo_incassi', azione: { testo: 'Registra gli incassi', url: '/admin/gestionale/2/movimenti-rate/create' }, giorno: '2026-01-09', giorni: -311 }),
    riga(13, { title: 'F24 Ritenuta — Studio Bianchi (Generale)', titolo: 'F24 Ritenuta — Studio Bianchi', sotto: 'F24 delle ritenute · € 20,00', condominio: 'Generale',
        condominio_id: null, type: 'versamento_ritenuta', azione: { testo: 'Segna come fatto', url: null }, giorno: '2026-08-29', giorni: -79,
        risposta: { tornato: 'Il promemoria nell\'agenda è stato tolto: il compito è tornato.', registro: [{ tipo: 'ricordamelo', quando: '20/10/2026 10:00', utente: 'Vincenzo', nota: '' }] } }),
    riga(14, { titolo: 'Emettere rata 12', sotto: 'Rata 12 · € 115,00', gruppo: 'avanti', fascia: 'fra_8_e_30', giorno: '2026-11-28', giorni: 12 }),
];

const props = (altro: Record<string, unknown> = {}) => ({
    tasks: { data: compiti, total: 113 },
    counts: { all: 113, scaduti: 107, settimana: 0, entro_30: 6, bonifici: 0, segnalazioni: 0, segnalazioni_urgenti: 0, piu_vecchio: '2025-12-29' },
    schede: { tutti: 113, scaduti: 107, in_arrivo: 6 },
    modo: 'anteprima',
    anteprima: [{ gruppo: 'scaduti', numero: 107, righe: compiti.slice(0, 3) }, { gruppo: 'avanti', numero: 6, righe: compiti.slice(3) }],
    grafici: {
        eta: [
            { chiave: 'oltre_90', etichetta: 'oltre 90 giorni fa', valore: 52, scaduti: true },
            { chiave: 'da_31_a_90', etichetta: 'da 31 a 90 giorni', valore: 47, scaduti: true },
            { chiave: 'ultimi_30', etichetta: 'ultimi 30 giorni', valore: 8, scaduti: true },
            { chiave: 'prossimi_7', etichetta: 'prossimi 7 giorni', valore: 0, scaduti: false },
            { chiave: 'fra_8_e_30', etichetta: 'fra 8 e 30 giorni', valore: 6, scaduti: false },
        ],
        tipi: [{ tipo: 'controllo_incassi', etichetta: 'Verifica incassi', valore: 51 }, { tipo: 'emissione_rata', etichetta: 'Emissione rate', valore: 33 }],
        fuori: { segnalazioni: 0, bonifici: 0, oltre_30: 0 },
    },
    dove: [{ condominio_id: null, nome: 'Generale', valore: 24 }, { condominio_id: 1, nome: 'Via dei Tigli 51', valore: 24 }, { condominio_id: 2, nome: 'Prova subentro B3a', valore: 20 }],
    gruppi: { scaduti: 107, segnalazioni: 0, bonifici: 0, avanti: 6 },
    filtri: { quando: null, tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'tutti' },
    condomini: [{ id: 1, nome: 'Via dei Tigli 51' }, { id: 2, nome: 'Prova subentro B3a' }],
    seiMesiCondomini: [{ id: 1, nome: 'Via dei Tigli 51' }, { id: 2, nome: 'Prova subentro B3a' }],
    oggi: '2026-11-16',
    ...altro,
});

const rigaSeiMesi = (eid: number, data: string, giorni: number, colore: string) => ({
    chiave: `sei-mesi-${eid}`, esercizio: { id: eid, nome: 'Esercizio 2026' }, data, giorni, colore, quante: 2, cifra: 125000,
    persone: [{ anagrafica_id: eid * 10, nome: 'Rossi', cifra: 62500 }, { anagrafica_id: eid * 10 + 1, nome: 'Bianchi', cifra: 62500 }],
    chiuse: { agito: 0, dispensa: 0 }, compito: giorni <= 60, compito_tornato: null, risposta: null,
});
const rendiconto = { chiave: 'rendiconto-7', esercizio: { id: 7, nome: 'Esercizio 2024/2025' }, data: '2026-04-29', giorni: -200, colore: 'passata', stato: 'bozza',
    breve: 'Bozza', frase: 'Bozza.', passo: 'apri', url: '/sintesi/7', compito: true, risposta: null };

let w: VueWrapper | null = null;
function monta(altro: Record<string, unknown> = {}) {
    w = mount(ActionInbox, {
        attachTo: document.body,
        props: props(altro) as never,
        global: {
            mocks: { route: (globalThis as Record<string, unknown>).route },
            stubs: {
                AppLayout: passa,
                DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa, DropdownMenuItem: passa, DropdownMenuSeparator: passa,
            },
        },
    });

    return w;
}
async function caricata() {
    await vi.dynamicImportSettled();
    await flushPromises();
}
const testo = (x: { text: () => string }) => x.text().replace(/\s+/g, ' ');
const corpo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

beforeEach(() => {
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'];
    inertia.get.mockClear();
    inertia.post.mockClear();
    inertia.altri.mockClear();
    disegni.ultimi = [];
    seiMesi.chieste = [];
    seiMesi.risposte = {
        1: { condominio: { id: 1, nome: 'Via dei Tigli 51' }, oggi: '2026-11-16', troppe: null, righe: [rigaSeiMesi(31, '2027-06-30', 226, 'lontana')], rendiconti: [rendiconto] },
        2: { condominio: { id: 2, nome: 'Prova subentro B3a' }, oggi: '2026-11-16', troppe: null, righe: [rigaSeiMesi(41, '2027-06-30', 226, 'lontana')], rendiconti: [] },
    };
});
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('l\'Action inbox: la regola delle 8 in «Per tipo» e le cifre all\'italiana', () => {
    test('«Per tipo» con 12 tipi aperti ha 8 barre; «e altri 4 tipi» apre la finestra con tutti, e un clic su un tipo filtra', async () => {
        const tipi = Array.from({ length: 12 }, (_, i) => ({ tipo: `tipo_${i}`, etichetta: `Tipo ${i}`, valore: 1300 - i }));
        const p = monta({ grafici: { ...props().grafici, tipi } });
        await caricata();
        const altezza = parseFloat(p.get('[data-testid="grafico-tipi"]').attributes('style')!.match(/height:\s*([\d.]+)rem/)![1]);
        expect(altezza).toBeLessThanOrEqual(8 * 1.75 + 1);
        expect(disegni.ultimi.at(-1)!.data.labels).toHaveLength(8);
        expect(testo(p.get('[data-testid="altri-tipi"]'))).toBe('e altri 4 tipi');

        await p.get('[data-testid="altri-tipi"]').trigger('click');
        await flushPromises();
        const finestra = document.body.querySelector('[data-testid="finestra-tipi"]') as HTMLElement;
        const voci = [...finestra.querySelectorAll('[data-testid="voce-tipo"]')] as HTMLElement[];
        expect(voci.map((v) => v.textContent!.replace(/\s+/g, ' ').trim())).toEqual(tipi.map((t) => `${t.etichetta} ${(t.valore).toLocaleString('it-IT', { useGrouping: 'always' } as Intl.NumberFormatOptions)}`));
        expect(finestra.textContent).toContain('Chiudi');
        voci[10].click();
        await flushPromises();
        expect(inertia.get).toHaveBeenCalled();
        expect(JSON.stringify(inertia.get.mock.calls.at(-1))).toContain('tipo_10');
    });

    test('il controllo: con 8 tipi o meno tutte le barre e nessun «e altri»', async () => {
        const p = monta();
        await caricata();
        expect(p.find('[data-testid="altri-tipi"]').exists()).toBe(false);
    });

    test('«Mostra altri» dice quanti ne arrivano: con 18 compiti e 15 mostrati, «Mostra altri 3»', async () => {
        const quindici = Array.from({ length: 15 }, (_, i) => riga(100 + i, { titolo: `Compito ${100 + i}` }));
        const p = monta({ modo: 'elenco', anteprima: [], filtri: { quando: 'scaduti', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'scaduti' },
            tasks: { data: quindici, total: 18 }, gruppi: { scaduti: 18, segnalazioni: 0, bonifici: 0, avanti: 0 }, schede: { tutti: 18, scaduti: 18, in_arrivo: 0 } });
        expect(testo(p.get('[data-testid="mostrati"]'))).toBe('Mostrati 15 di 18');
        expect(testo(p.get('[data-testid="mostra-altri"]'))).toBe('Mostra altri 3');
    });

    test('le cifre all\'italiana come nei grafici della stessa pagina: 1.250, non 1250', async () => {
        const p = monta({ counts: { ...props().counts, all: 1250, scaduti: 1244 }, schede: { tutti: 1250, scaduti: 1244, in_arrivo: 6 } });
        await caricata();
        expect(testo(p.get('[data-testid="numero-aperti"]'))).toBe('1.250');
        expect(testo(p.get('[data-testid="aperti-elenco"]'))).toBe('1.250 aperti');
        expect(testo(p.get('[data-testid="card-scaduti"] [data-testid="numero"]'))).toBe('1.244');
    });
});
