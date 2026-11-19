// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'Action inbox sa a chi è assegnata una segnalazione (decisioni 486 e 487).
 *
 * - Sotto il titolo del compito di una segnalazione: «assegnata a te», «assegnata a Anna Neri», o in grigio «non assegnata»; chi non può più
 *   riceverla lo dice («non ha più accesso al pannello»). Le righe degli altri compiti non dicono niente.
 * - Accanto alle schede «Tutti / Scaduti / In arrivo» il pulsante «Le mie» con il numero del server (la prop `le_mie`), anche 0. Un clic rilegge solo l'elenco
 *   con `mie=1` nell'indirizzo, con una visita parziale (`only`, `preserveState`, `preserveScroll`); acceso, sta fra i filtri attivi e si
 *   toglie da sé.
 *
 * Che cosa non copre: quali compiti restano con «Le mie» e i numeri (li prova il Pest `tests/Feature/Dashboard/ActionInboxLeMieTest.php`),
 * i grafici e i sei mesi (li prova `ActionInbox.test.ts`, da cui viene il montaggio).
 */
import { readFileSync } from 'node:fs';
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

const ActionInbox = (await import('./ActionInbox.vue')).default;

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
    counts: { all: 113, scaduti: 107, settimana: 0, entro_30: 6, bonifici: 0, segnalazioni: 0, segnalazioni_urgenti: 0, da_moderare: 0, piu_vecchio: '2025-12-29' },
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
        fuori: { segnalazioni: 0, bonifici: 0, da_moderare: 0, oltre_30: 0 },
    },
    dove: [{ condominio_id: null, nome: 'Generale', valore: 24 }, { condominio_id: 1, nome: 'Via dei Tigli 51', valore: 24 }, { condominio_id: 2, nome: 'Prova subentro B3a', valore: 20 }],
    gruppi: { scaduti: 107, segnalazioni: 0, bonifici: 0, da_moderare: 0, avanti: 6 },
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


const segnalazione = (id: number, assegnata: Record<string, unknown> | null) => riga(id, {
    title: `Segnalazione ${id}`, titolo: `Segnalazione ${id}`, sotto: 'Il cancello non chiude', gruppo: 'segnalazioni', fascia: 'segnalazioni',
    type: 'segnalazione_guasto', azione: { testo: 'Apri la segnalazione', url: `/admin/segnalazioni/${id}` }, assegnata,
});

function rigaDi(p: VueWrapper, titolo: string) {
    return p.findAll('[data-testid="riga-compito"]').find((r) => r.text().includes(titolo))!;
}

describe('la riga del compito di una segnalazione dice chi la segue', () => {
    test('«assegnata a te», «assegnata a Anna Neri», «non assegnata» in grigio; chi non ha più il pannello lo dice; gli altri compiti niente', async () => {
        const righe = [
            segnalazione(21, { nome: 'Vincenzo', te: true, attivo: true }),
            segnalazione(22, { nome: 'Anna Neri', te: false, attivo: true }),
            segnalazione(23, { nome: null, te: false, attivo: true }),
            segnalazione(24, { nome: 'Ida Sospesa', te: false, attivo: false }),
            compiti[0],
        ];
        const p = monta({ modo: 'elenco', tasks: { data: righe, total: righe.length }, anteprima: [] });
        await caricata();

        expect(rigaDi(p, 'Segnalazione 21').get('[data-testid="assegnata"]').text()).toBe('assegnata a te');
        expect(rigaDi(p, 'Segnalazione 22').get('[data-testid="assegnata"]').text()).toBe('assegnata a Anna Neri');
        const nessuno = rigaDi(p, 'Segnalazione 23').get('[data-testid="assegnata"]');
        expect(nessuno.text()).toBe('non assegnata');
        expect(nessuno.classes()).toContain('text-slate-400');
        expect(rigaDi(p, 'Segnalazione 24').get('[data-testid="assegnata-non-attiva"]').text()).toContain('non ha più accesso al pannello');
        expect(rigaDi(p, 'Segnalazione 22').find('[data-testid="assegnata-non-attiva"]').exists()).toBe(false);
        // Dopo chi la segue, la riga di sempre.
        expect(testo(rigaDi(p, 'Segnalazione 22'))).toContain('assegnata a Anna Neri · Il cancello non chiude');
        expect(rigaDi(p, 'Emettere rata 1').find('[data-testid="assegnata"]').exists()).toBe(false);
    });
});

describe('il pulsante «Le mie»', () => {
    test('sta accanto alle schede con il numero del server, anche 0', async () => {
        const p = monta({ schede: { tutti: 113, scaduti: 107, in_arrivo: 6 }, le_mie: 0 });
        await caricata();

        const pulsante = p.get('[data-testid="filtro-le-mie"]');
        expect(testo(pulsante)).toBe('Le mie 0');
        expect(pulsante.attributes('aria-pressed')).toBe('false');
        // Nella stessa fila delle schede, dopo il loro gruppo.
        expect(pulsante.element.previousElementSibling?.getAttribute('role')).toBe('group');

        await p.setProps({ schede: { tutti: 113, scaduti: 107, in_arrivo: 6 }, le_mie: 3 } as never);
        expect(testo(p.get('[data-testid="filtro-le-mie"]'))).toBe('Le mie 3');
    });

    test('un clic rilegge solo l\'elenco con «mie=1» nell\'indirizzo, con una visita parziale', async () => {
        const p = monta({ schede: { tutti: 113, scaduti: 107, in_arrivo: 6 }, le_mie: 3 });
        await caricata();

        await p.get('[data-testid="filtro-le-mie"]').trigger('click');

        const [indirizzo, dati, opzioni] = inertia.get.mock.calls.at(-1)!;
        expect(indirizzo).toBe('/admin.inbox');
        expect(dati).toEqual({ mie: 1 });
        expect(opzioni.only).toEqual(['tasks', 'schede', 'gruppi', 'modo', 'anteprima', 'filtri']);
        expect(opzioni.preserveState).toBe(true);
        expect(opzioni.preserveScroll).toBe(true);
    });

    test('acceso è premuto, sta fra i filtri attivi e si toglie da sé; «Togli i filtri» lo toglie', async () => {
        const p = monta({ schede: { tutti: 3, scaduti: 0, in_arrivo: 0 }, le_mie: 3, filtri: { quando: null, tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, mie: true, scheda: 'tutti' } });
        await caricata();

        expect(p.get('[data-testid="filtro-le-mie"]').attributes('aria-pressed')).toBe('true');
        const chip = p.findAll('[data-testid="filtro-attivo"]').find((b) => b.text().includes('Le mie'));
        expect(chip, 'manca «Le mie» fra i filtri attivi').toBeDefined();

        await chip!.trigger('click');
        expect(inertia.get.mock.calls.at(-1)![1]).toEqual({});

        await p.get('[data-testid="filtro-le-mie"]').trigger('click');
        expect(inertia.get.mock.calls.at(-1)![1]).toEqual({});

        await p.get('[data-testid="togli-filtri"]').trigger('click');
        expect(inertia.get.mock.calls.at(-1)![1]).toEqual({});
    });
});
