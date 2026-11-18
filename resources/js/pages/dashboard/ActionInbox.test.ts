// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'Action inbox, montata con Chart.js finto e le richieste dei sei mesi finte.
 *
 * - L'intestazione: «Centro operativo», «Action inbox» con il numero dei compiti aperti, la descrizione; «Guida» con il cassetto, il selettore dei condomini
 *   con la ricerca, «Dashboard»; i tre pulsanti alti uguali.
 * - Le cinque card filtro: un clic filtra l'elenco (i parametri nell'indirizzo), un altro clic toglie il filtro; gli zeri grigi; la card «Sei mesi» apre il
 *   cassetto e si completa mentre i condomini arrivano; sotto «Segnalazioni» quante sono urgenti.
 * - L'elenco a due terzi: le schede «Tutti / Scaduti / In arrivo» con il numero, la ricerca e l'ordine (un `vSelect`); con «Tutti» l'anteprima dei gruppi e
 *   «e altri N», che apre la scheda; nelle schede «Mostra altri 15» e «Mostrati N di M». Le righe con la prossima azione del tipo; «Segna come fatto» apre la
 *   risposta «Fatto» del menu «⋯», che resta.
 * - La colonna di destra: i due grafici (caricati a parte, con la descrizione; un clic su una barra filtra), «Sei mesi per agire» e il cassetto, chiuso di
 *   serie (si apre con «Apri l'elenco» o con la card, con le tre sezioni e «Apri le persone →» in ogni riga), «Dove si accumulano» (un clic sceglie il condominio).
 * - La casella si legge con «Visualizza scadenze in agenda»; le risposte chiedono «Modifica scadenza in agenda»: senza, né il menu né «Segna come fatto».
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

describe('l\'intestazione', () => {
    test('«Centro operativo», il titolo con il numero dei compiti aperti, la descrizione; «Guida», il selettore e «Dashboard» alti uguali', async () => {
        const p = monta();
        await caricata();

        expect(testo(p)).toContain('Centro operativo');
        expect(p.get('h1').text()).toBe('Action inbox');
        expect(p.get('[data-testid="numero-aperti"]').text()).toBe('113');
        expect(p.get('[data-testid="numero-aperti"]').classes()).toContain('rounded-md');
        expect(testo(p)).toContain('I compiti aperti di tutti i condomini.');
        const pulsanti = ['[data-testid="pulsante-guida"]', '[data-testid="filtro-condominio"]', '[data-testid="torna-dashboard"]'].map((s) => p.get(s));
        expect(pulsanti.every((b) => b.classes().includes('h-8'))).toBe(true);
        expect(p.get('[data-testid="filtro-condominio"]').text()).toContain('Tutti i condomini');
        expect(p.get('[data-testid="torna-dashboard"]').attributes('href')).toBe('/admin.dashboard');
    });

    test('«Guida» apre la guida nel cassetto', async () => {
        const p = monta();
        expect(document.body.querySelector('[data-testid="guida-action-inbox"]')).toBeNull();
        await p.get('[data-testid="pulsante-guida"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-action-inbox"]')).not.toBeNull();
    });

    test('il selettore dei condomini con la ricerca: la scelta rilegge la pagina con quel condominio e tiene i filtri', async () => {
        const p = monta({ filtri: { quando: 'scaduti', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'scaduti' } });
        await p.get('[data-testid="filtro-condominio"]').trigger('click');
        await flushPromises();
        const voci = [...document.body.querySelectorAll('[data-testid="scelta-condominio"] [role="option"]')];
        expect(voci.map((v) => (v.textContent ?? '').trim())).toEqual(['Tutti i condomini', 'Via dei Tigli 51', 'Prova subentro B3a']);
        expect(document.body.querySelector('[data-testid="scelta-condominio"] input')).not.toBeNull();
        (voci[2] as HTMLElement).click();
        await flushPromises();
        expect(inertia.get).toHaveBeenCalledWith('/admin.inbox', { quando: 'scaduti', condominio_id: 2 }, expect.objectContaining({ preserveState: true, preserveScroll: true }));
        // Un altro condominio cambia tutta la pagina (le card, i grafici, i sei mesi): niente «only».
        expect(inertia.get.mock.calls.at(-1)![2].only).toBeUndefined();
    });
});

describe('le sei card', () => {
    test('le etichette, i numeri e le frasi; gli zeri grigi, gli scaduti rossi', async () => {
        const p = monta();
        await caricata();
        const card = p.findAll('[data-testid^="card-"]');

        expect(card.map((c) => c.attributes('data-testid'))).toEqual(['card-scaduti', 'card-settimana', 'card-bonifici', 'card-segnalazioni', 'card-sei-mesi', 'card-da-moderare']);
        expect(testo(card[0])).toContain('Scaduti');
        expect(testo(card[0])).toContain('107');
        expect(testo(card[0])).toContain('il più vecchio del 29/12/2025');
        expect(testo(card[0])).toContain('Azione richiesta');
        expect(card[0].get('[data-testid="numero"]').classes()).toContain('text-red-600');
        expect(testo(card[1])).toContain('poi 6 entro 30 giorni');
        expect(card[1].get('[data-testid="numero"]').classes()).toContain('text-slate-300');
        expect(testo(card[2])).toContain('dai condòmini, da verificare');
        expect(testo(card[3])).toContain('guasti aperti');
        expect(testo(card[4])).toContain('Art. 1129 e 1130 c.c.');
        expect(testo(card[1])).toContain('Questa settimana');
        p.unmount();
        const urgenti = monta({ counts: { ...props().counts, segnalazioni: 4, segnalazioni_urgenti: 2 } });
        expect(testo(urgenti.get('[data-testid="card-segnalazioni"]'))).toContain('di cui 2 urgenti');
    });

    test('un clic filtra l\'elenco con i parametri nell\'indirizzo; sulla card scelta toglie il filtro', async () => {
        const p = monta();
        await p.get('[data-testid="card-scaduti"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'scaduti' }, expect.objectContaining({ preserveState: true, preserveScroll: true }));
        await p.get('[data-testid="card-bonifici"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'verifica_pagamento' }, expect.anything());
        p.unmount();

        const scelta = monta({ filtri: { quando: 'scaduti', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: 1, scheda: 'scaduti' } });
        expect(scelta.get('[data-testid="card-scaduti"]').attributes('aria-pressed')).toBe('true');
        await scelta.get('[data-testid="card-scaduti"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { condominio_id: 1 }, expect.anything());
    });

    test('la card «Sei mesi» si completa mentre i condomini arrivano, e apre il cassetto', async () => {
        const p = monta();
        await caricata();

        expect(seiMesi.chieste).toEqual([1, 2]);
        expect(p.get('[data-testid="card-sei-mesi"] [data-testid="numero"]').text()).toBe('1');
        expect(testo(p.get('[data-testid="card-sei-mesi"]'))).toContain('rendiconto da portare');
        expect(document.body.querySelector('[data-testid="cassetto-sei-mesi"]')).toBeNull();
        await p.get('[data-testid="card-sei-mesi"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="cassetto-sei-mesi"]')).not.toBeNull();
    });
});

describe('i due grafici, nella colonna di destra', () => {
    test('caricati a parte, con la descrizione e la chiave «scaduti / in arrivo»; un clic su una barra filtra l\'elenco', async () => {
        const p = monta();
        await caricata();

        expect(p.findAll('[data-testid="canvas"]')).toHaveLength(2);
        expect(p.get('[data-testid="grafico-eta"]').attributes('role')).toBe('img');
        expect(p.get('[data-testid="grafico-eta"]').attributes('aria-label')).toContain('52 scaduti da oltre 90 giorni');
        expect(p.get('[data-testid="grafico-tipi"]').attributes('aria-label')).toBe('Compiti per tipo: Verifica incassi 51, Emissione rate 33.');
        expect(testo(p.get('[data-testid="chiave-eta"]'))).toBe('scaduti in arrivo');

        // Le età su due righe, senza abbreviazioni.
        const eta = disegni.ultimi.find((d) => Array.isArray(d.data.labels[0]) && d.data.labels[0][0] === 'oltre 90')!;
        expect(eta.data.labels[1]).toEqual(['da 31 a 90', 'giorni fa']);
        eta.options.onClick({}, [{ index: 1 }]);
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'da_31_a_90' }, expect.anything());
        const tipi = disegni.ultimi.find((d) => d.data.labels[0] === 'Verifica incassi')!;
        tipi.options.onClick({}, [{ index: 0 }]);
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'controllo_incassi' }, expect.anything());
    });
});

describe('l\'elenco', () => {
    test('le colonne fisse; con «Tutti» l\'anteprima dei gruppi con il numero del server; le righe con il titolo, la riga sotto, il condominio, la data e i giorni', async () => {
        const p = monta();
        await caricata();
        const intestazione = p.get('[data-testid="intestazione-elenco"]');

        expect(intestazione.classes()).toEqual(expect.arrayContaining(['whitespace-nowrap', 'xl:grid']));
        expect(intestazione.findAll('span').map((s) => s.text()).filter(Boolean)).toEqual(['Compito', 'Condominio', 'Scadenza', 'Prossima azione']);
        expect(p.findAll('[data-testid="gruppo"]').map((g) => testo(g))).toEqual(['Scaduti 107', 'Più avanti 6']);
        expect(testo(p.get('[data-testid="aperti-elenco"]'))).toBe('113 aperti');

        const prima = p.findAll('[data-testid="riga-compito"]')[0];
        expect(testo(prima)).toContain('Emettere rata 1');
        expect(testo(prima)).not.toContain('Emettere rata 1 - Via dei Tigli 51');
        expect(testo(prima)).toContain('Rata 1 · € 100,01');
        expect(testo(prima)).toContain('Via dei Tigli 51');
        expect(testo(prima)).toContain('29/12/2025');
        expect(testo(prima)).toContain('da 322 giorni');
        expect(prima.get('[data-testid="data"]').classes()).toContain('text-red-700');
        const avanti = p.findAll('[data-testid="riga-compito"]')[3];
        expect(testo(avanti)).toContain('fra 12 giorni');
        expect(avanti.get('[data-testid="data"]').classes()).not.toContain('text-red-700');
        expect(testo(p)).toContain('Il promemoria nell\'agenda è stato tolto: il compito è tornato.');
        expect(p.findAll('[data-testid="e-altri"]').map((e) => testo(e))).toEqual(['e altri 104 scaduti', 'e altri 5 più avanti']);
        expect(testo(p.get('[data-testid="mostrati"]'))).toBe('Mostrati 4 di 113');
        expect(p.find('[data-testid="mostra-altri"]').exists()).toBe(false);
    });

    test('«e altri N» apre la scheda del gruppo; i bonifici da verificare, che non hanno una scheda, il loro tipo', async () => {
        const p = monta({ anteprima: [props().anteprima[0], { gruppo: 'bonifici', numero: 12, righe: [] }, props().anteprima[1]] });
        const altri = p.findAll('[data-testid="e-altri"]');

        await altri[0].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'scaduti' }, expect.anything());
        await altri[2].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'in_arrivo' }, expect.anything());
        await altri[1].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'verifica_pagamento' }, expect.anything());
    });

    test('le schede «Tutti / Scaduti / In arrivo» con il loro numero: la scelta è nell\'indirizzo; quella in uso è segnata', async () => {
        const p = monta();
        const schede = ['tutti', 'scaduti', 'in_arrivo'].map((k) => p.get(`[data-testid="scheda-${k}"]`));

        expect(schede.map((s) => testo(s))).toEqual(['Tutti 113', 'Scaduti 107', 'In arrivo 6']);
        expect(schede[0].attributes('aria-pressed')).toBe('true');
        await schede[1].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'scaduti' }, expect.objectContaining({ preserveState: true }));
        await schede[2].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'in_arrivo' }, expect.anything());
        p.unmount();

        // Una barra dell'età dentro la scheda: la scheda «Scaduti» è segnata, e la barra si dice sopra l'elenco.
        const barra = monta({ filtri: { quando: 'da_31_a_90', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'scaduti' }, modo: 'elenco' });
        expect(barra.get('[data-testid="scheda-scaduti"]').attributes('aria-pressed')).toBe('true');
        expect(barra.findAll('[data-testid="filtro-attivo"]').map((f) => testo(f))).toEqual(['Da 31 a 90 giorni']);
        await barra.get('[data-testid="scheda-tutti"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', {}, expect.anything());
    });

    test('una scheda rilegge solo l\'elenco, con la pagina dov\'è e senza la barra in cima; il segno di caricamento accanto a «Compiti»; il condominio rilegge la pagina', async () => {
        const p = monta();
        await p.get('[data-testid="scheda-scaduti"]').trigger('click');
        const opzioni = inertia.get.mock.calls.at(-1)![2];
        // Solo le prop dell'elenco: le card, i grafici, «Dove si accumulano» e i sei mesi non si rifanno, e la colonna dei sei mesi non richiede i condomini.
        expect(opzioni.only).toEqual(['tasks', 'schede', 'gruppi', 'modo', 'anteprima', 'filtri']);
        expect(opzioni).toMatchObject({ reset: ['tasks'], preserveState: true, preserveScroll: true, showProgress: false });
        expect(p.find('[data-testid="elenco-in-caricamento"]').exists()).toBe(false);
        opzioni.onStart();
        await flushPromises();
        expect(p.find('[data-testid="elenco-in-caricamento"]').exists()).toBe(true);
        expect(p.get('[data-testid="sezione-elenco"]').attributes('aria-busy')).toBe('true');
        opzioni.onFinish();
        await flushPromises();
        expect(p.find('[data-testid="elenco-in-caricamento"]').exists()).toBe(false);
        // La ricerca e l'ordine come le schede; il condominio no: cambia tutta la pagina.
        await p.get('[data-testid="cerca"]').setValue('Rossi');
        await p.get('[data-testid="cerca"]').trigger('keydown.enter');
        expect(inertia.get.mock.calls.at(-1)![2].only).toEqual(['tasks', 'schede', 'gruppi', 'modo', 'anteprima', 'filtri']);
    });

    test('nelle schede l\'elenco 15 alla volta: «Mostra altri 15» chiede la pagina dopo, e in fondo «Mostrati N di M»', async () => {
        const p = monta({ modo: 'elenco', anteprima: [], filtri: { quando: 'scaduti', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'scaduti' },
            tasks: { data: compiti.slice(0, 3), total: 107 } });

        expect(p.findAll('[data-testid="gruppo"]').map((g) => testo(g))).toEqual(['Scaduti 107']);
        expect(p.findAll('[data-testid="riga-compito"]')).toHaveLength(3);
        expect(testo(p.get('[data-testid="mostrati"]'))).toBe('Mostrati 3 di 107');
        expect(testo(p.get('[data-testid="mostra-altri"]'))).toBe('Mostra altri 15');
        await p.get('[data-testid="mostra-altri"]').trigger('click');
        expect(inertia.altri).toHaveBeenCalledTimes(1);
        expect(p.find('[data-testid="e-altri"]').exists()).toBe(false);
    });

    test('la ricerca è nell\'indirizzo: con Invio subito, scrivendo dopo una pausa; vuota toglie il filtro', async () => {
        vi.useFakeTimers();
        const p = monta();
        const campo = p.get('[data-testid="cerca"]');

        // Il segnaposto corto non si tronca; il nome per chi non vede il campo dice dove cerca.
        expect(campo.attributes('placeholder')).toBe('Cerca fra i compiti');
        expect(campo.attributes('aria-label')).toBe('Cerca fra i compiti per titolo, condominio o persona');
        await campo.setValue('rossi');
        await campo.trigger('keydown', { key: 'Enter' });
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { cerca: 'rossi' }, expect.anything());
        inertia.get.mockClear();
        await campo.setValue('idraulica');
        await campo.trigger('input');
        expect(inertia.get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(450);
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { cerca: 'idraulica' }, expect.anything());
        vi.useRealTimers();
        p.unmount();

        const cercata = monta({ filtri: { quando: null, tipo: null, cerca: 'rossi', ordine: 'vecchi', condominio_id: null, scheda: 'tutti' } });
        expect((cercata.get('[data-testid="cerca"]').element as HTMLInputElement).value).toBe('rossi');
        await cercata.get('[data-testid="cerca"]').setValue('');
        await cercata.get('[data-testid="cerca"]').trigger('keydown', { key: 'Enter' });
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', {}, expect.anything());
    });

    test('l\'ordine in un vSelect: «I più vecchi prima» di serie, «I più recenti prima», «Per condominio»; la scelta è nell\'indirizzo', async () => {
        const vSelect = (await import('vue-select')).default;
        const p = monta();
        const menu = p.getComponent(vSelect as never);

        expect((menu.props() as { options: Array<{ etichetta: string }> }).options.map((o) => o.etichetta)).toEqual(['I più vecchi prima', 'I più recenti prima', 'Per condominio']);
        expect(testo(p.get('[data-testid="ordine"]'))).toContain('I più vecchi prima');
        menu.vm.$emit('update:modelValue', 'recenti');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { ordine: 'recenti' }, expect.anything());
    });

    test('la prossima azione del tipo porta alla sua pagina; «Segna come fatto» apre la risposta «Fatto» del menu; il menu «⋯» resta', async () => {
        const p = monta();
        const righe = p.findAll('[data-testid="riga-compito"]');

        const emetti = righe[0].get('[data-testid="prossima-azione"]');
        expect(testo(emetti)).toBe('Emetti la rata');
        expect(emetti.attributes('href')).toBe('/admin/gestionale/1/piani/3');
        expect(righe[1].get('[data-testid="prossima-azione"]').attributes('href')).toBe('/admin/gestionale/2/movimenti-rate/create');
        expect(righe.every((r) => r.find('[data-testid="menu-del-compito"]').exists())).toBe(true);

        const fatto = righe[2].get('[data-testid="prossima-azione"]');
        expect(testo(fatto)).toBe('Segna come fatto');
        expect(fatto.element.tagName).toBe('BUTTON');
        await fatto.trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="finestra-risposta"]')?.textContent).toContain('F24 Ritenuta — Studio Bianchi (Generale)');
        expect(corpo()).toContain('Una nota, se vuoi');
        (document.body.querySelector('[data-testid="conferma"]') as HTMLElement).click();
        expect(inertia.post).toHaveBeenCalledWith('/admin.inbox.risposta/13', { tipo: 'fatto', nota: '' }, expect.anything());
    });

    test('la segnalazione: il cartellino della priorità in minuscolo; «aperta il …» con i giorni in grigio; il menu ha solo «Ricordamelo il…»', () => {
        const segnalazione = riga(21, { title: 'Gas', titolo: 'Gas', type: 'segnalazione_guasto', priorita: 'urgente', gruppo: 'segnalazioni', fascia: 'segnalazioni',
            giorno: '2026-02-22', giorni: -267, azione: { testo: 'Apri la segnalazione', url: '/admin/segnalazioni/4' } });
        const p = monta({ modo: 'elenco', anteprima: [], tasks: { data: [segnalazione], total: 1 }, gruppi: { scaduti: 0, segnalazioni: 1, bonifici: 0, avanti: 0 } });
        const r = p.get('[data-testid="riga-compito"]');

        expect(testo(p.get('[data-testid="gruppo"]'))).toBe('Segnalazioni aperte 1');
        expect(testo(r.get('[data-testid="priorita"]'))).toBe('urgente');
        expect(r.get('[data-testid="priorita"]').classes()).toContain('rounded-md');
        expect(r.get('[data-testid="priorita"]').classes()).not.toContain('uppercase');
        expect(testo(r.get('[data-testid="data"]'))).toBe('aperta il 22/02/2026');
        expect(testo(r)).toContain('da 267 giorni');
        expect(r.get('[data-testid="data"]').classes()).not.toContain('text-red-700');
        expect(r.html()).not.toContain('text-red-600');
        expect(r.findAll('[data-testid^="voce-"]').map((v) => v.text())).toEqual(['Ricordamelo il…']);
    });

    test('con «Tutti» i gruppi nell\'ordine: «Scaduti», «Segnalazioni aperte», «Bonifici da verificare», «Più avanti»; «e altre N» delle segnalazioni filtra il loro tipo', async () => {
        const segnalazione = riga(31, { title: 'Ascensore', titolo: 'Ascensore', type: 'segnalazione_guasto', gruppo: 'segnalazioni', fascia: 'segnalazioni' });
        const bonifico = riga(32, { title: 'Bonifico', titolo: 'Bonifico', type: 'verifica_pagamento', gruppo: 'bonifici', fascia: 'bonifici' });
        const p = monta({ anteprima: [props().anteprima[0], { gruppo: 'segnalazioni', numero: 9, righe: [segnalazione] }, { gruppo: 'bonifici', numero: 1, righe: [bonifico] },
            props().anteprima[1]] });

        expect(p.findAll('[data-testid="gruppo"]').map((g) => testo(g))).toEqual(['Scaduti 107', 'Segnalazioni aperte 9', 'Bonifici da verificare 1', 'Più avanti 6']);
        const altre = p.findAll('[data-testid="e-altri"]').find((e) => testo(e).includes('segnalazioni'))!;
        expect(testo(altre)).toBe('e altre 8 segnalazioni aperte');
        await altre.trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'segnalazione_guasto' }, expect.anything());
    });

    test('il nome del condominio va su due righe al più, ed è intero nel suggerimento', () => {
        const lungo = riga(41, { condominio: 'Condominio Via dei Tigli 51 e Via dei Platani 3' });
        const p = monta({ modo: 'elenco', anteprima: [], tasks: { data: [lungo], total: 1 } });
        const c = p.get('[data-testid="condominio"] span');

        expect(c.classes()).toContain('line-clamp-2');
        expect(c.classes()).not.toContain('truncate');
        expect(c.attributes('title')).toBe('Condominio Via dei Tigli 51 e Via dei Platani 3');
    });

    test('con la sola consultazione si vedono i compiti e le loro pagine, senza il menu e con «Segna come fatto» spento', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda'];
        const p = monta();
        const righe = p.findAll('[data-testid="riga-compito"]');

        expect(righe).toHaveLength(4);
        expect(p.find('[data-testid="menu-del-compito"]').exists()).toBe(false);
        expect(testo(righe[0].get('[data-testid="prossima-azione"]'))).toBe('Emetti la rata');
        expect(righe[2].get('[data-testid="prossima-azione"]').attributes('disabled')).toBeDefined();
    });

    test('i filtri attivi si dicono sopra l\'elenco, e si tolgono uno alla volta (la barra lascia la sua scheda) o tutti', async () => {
        const p = monta({ filtri: { quando: 'da_31_a_90', tipo: 'emissione_rata', cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'scaduti' } });
        const filtri = p.findAll('[data-testid="filtro-attivo"]');

        expect(filtri.map((f) => testo(f))).toEqual(['Da 31 a 90 giorni', 'Emissione rate']);
        await filtri[1].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'da_31_a_90' }, expect.anything());
        await filtri[0].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { quando: 'scaduti', tipo: 'emissione_rata' }, expect.anything());
        await p.get('[data-testid="togli-filtri"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', {}, expect.anything());
    });
});

describe('«Sei mesi per agire» e il cassetto', () => {
    test('la colonna: il rendiconto con l\'azione del caso, la riga «N condomini più avanti, il primo il …», «Apri l\'elenco»', async () => {
        const p = monta();
        await caricata();
        const colonna = p.get('[data-testid="sei-mesi-colonna"]');

        expect(testo(colonna)).toContain('Sei mesi per agire');
        expect(testo(colonna)).toContain('Nessuna rata da seguire entro 60 giorni.');
        expect(testo(colonna)).toContain('Rendiconto in assemblea');
        expect(colonna.get('[data-testid="azione-rendiconto"]').attributes('href')).toBe('/sintesi/7');
        expect(testo(colonna.get('[data-testid="azione-rendiconto"]'))).toBe('Apri il rendiconto');
        expect(testo(colonna)).toContain('2 condomini più avanti, il primo il 30/06/2027');
    });

    test('il cassetto è chiuso di serie; «Apri l\'elenco» lo apre con le tre sezioni, ogni riga con «Apri le persone →»; «Chiudi» lo chiude', async () => {
        const p = monta();
        await caricata();
        expect(document.body.querySelector('[data-testid="cassetto-sei-mesi"]')).toBeNull();

        await p.get('[data-testid="apri-elenco"]').trigger('click');
        await flushPromises();
        const cassetto = document.body.querySelector('[data-testid="cassetto-sei-mesi"]')!;
        const t = (cassetto.textContent ?? '').replace(/\s+/g, ' ');
        expect(t).toContain('Sei mesi per agire · tutti i condomini');
        expect(t).toContain('Da seguire adesso');
        expect(t).toContain('Nessuna data passata o entro 60 giorni.');
        expect(t).toContain('Rendiconto in assemblea');
        expect(t).toContain('Più avanti · 2 condomini');
        const righe = [...cassetto.querySelectorAll('[data-testid="riga-studio"]')];
        expect(righe).toHaveLength(2);
        expect(righe.every((r) => (r.querySelector('[data-testid="azione-riga"]')?.textContent ?? '').includes('Apri le persone'))).toBe(true);
        expect(righe[0].querySelector('[data-testid="azione-riga"]')?.getAttribute('href')).toContain('#sei-mesi-');

        (cassetto.querySelector('[data-testid="chiudi-cassetto"]') as HTMLElement).click();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="cassetto-sei-mesi"]')).toBeNull();
    });

    test('con un condominio scelto la colonna chiede solo quello', async () => {
        monta({ filtri: { quando: null, tipo: null, cerca: null, ordine: 'vecchi', condominio_id: 2, scheda: 'tutti' }, seiMesiCondomini: [{ id: 2, nome: 'Prova subentro B3a' }] });
        await caricata();

        expect(seiMesi.chieste).toEqual([2]);
    });
});

describe('«Dove si accumulano»', () => {
    test('i condomini con la barra e il numero; un clic sceglie il condominio; «Generale» non è un condominio', async () => {
        const p = monta();
        const voci = p.findAll('[data-testid="dove"]');

        expect(voci.map((v) => testo(v))).toEqual(['Generale 24', 'Via dei Tigli 51 24', 'Prova subentro B3a 20']);
        expect(voci[0].element.tagName).not.toBe('BUTTON');
        await voci[2].trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { condominio_id: 2 }, expect.anything());
    });
});

describe('le scritte della pagina', () => {
    const sorgenti = ['resources/js/pages/dashboard/ActionInbox.vue', 'resources/js/components/inbox/RigaDelCompito.vue', 'resources/js/components/inbox/CardDellInbox.vue',
        'resources/js/components/gestionale/seiMesi/SeiMesiColonna.vue', 'resources/js/components/gestionale/seiMesi/SeiMesiCassetto.vue',
        'resources/js/components/gestionale/seiMesi/RigaDelloStudio.vue', 'resources/js/components/guides/ActionInboxGuide.vue']
        .map((f) => readFileSync(resolve(process.cwd(), f), 'utf8'));

    test('niente sotto gli 11 px; gli 11 px solo per le etichette maiuscole; nessuna pillola (i pallini sì)', () => {
        for (const s of sorgenti) {
            expect(s).not.toMatch(/text-\[(9|10)px\]/);
            for (const m of s.matchAll(/class="([^"]*text-\[11px\][^"]*)"/g)) {
                expect(m[1]).toContain('uppercase');
            }
            for (const m of s.matchAll(/class="([^"]*rounded-full[^"]*)"/g)) {
                expect(m[1]).toMatch(/w-1\.5 h-1\.5|h-1\.5 w-1\.5/);
            }
        }
    });

    test('a video «prossima azione», mai «passo»; nessun menu del browser', () => {
        for (const s of sorgenti) {
            expect(s).not.toMatch(/>[^<]*\b[Pp]ass[oi]\b[^<]*</);
            expect(s).not.toContain('<select');
        }
    });
});

describe('la sesta card e il gruppo «Da moderare» (decisioni 445 e 449)', () => {
    const moderare = [
        riga(21, { title: '2 commenti in attesa', titolo: '2 commenti in attesa', sotto: 'Comunicazione «Prova commenti»', gruppo: 'da_moderare', fascia: 'da_moderare', type: 'da_moderare',
            giorno: '2026-11-17', giorni: 0, azione: { testo: 'Modera i commenti', url: '/admin/commenti?stato=in_attesa&tipo=comunicazioni&elemento=5' } }),
        riga(22, { title: '1 commento e 2 allegati in attesa', titolo: '1 commento e 2 allegati in attesa', sotto: 'Segnalazione «Ascensore fermo»', gruppo: 'da_moderare',
            fascia: 'da_moderare', type: 'da_moderare', giorno: '2026-11-16', giorni: -1, condominio: 'Condominio test', condominio_id: 3,
            azione: { testo: 'Modera i commenti', url: '/admin/commenti?stato=in_attesa&tipo=segnalazioni&elemento=9' } }),
    ];
    const conModerazione = (altro: Record<string, unknown> = {}) => ({
        counts: { ...props().counts, da_moderare: 3 },
        gruppi: { scaduti: 107, segnalazioni: 8, bonifici: 2, da_moderare: 3, avanti: 6 },
        anteprima: [
            props().anteprima[0],
            { gruppo: 'segnalazioni', numero: 8, righe: [] },
            { gruppo: 'bonifici', numero: 2, righe: [] },
            { gruppo: 'da_moderare', numero: 3, righe: moderare },
            props().anteprima[1],
        ],
        ...altro,
    });

    // Fase 1-bis della 1.12.0-beta.11: il numero conta anche le comunicazioni, i documenti e gli eventi da approvare (decisioni 445 e 449), quindi la frase
    // dice «da approvare» e non più «commenti e allegati in attesa».
    test('la card è la sesta: «Da moderare», il numero in ambra, «da approvare», «Dal portale e dallo studio» in fondo; lo zero è grigio', async () => {
        const p = monta(conModerazione());
        const card = p.get('[data-testid="card-da-moderare"]');

        expect(p.findAll('[data-testid^="card-"]')).toHaveLength(6);
        expect(testo(card)).toContain('Da moderare');
        expect(card.get('[data-testid="numero"]').text()).toBe('3');
        expect(card.get('[data-testid="numero"]').classes()).toContain('text-amber-600');
        expect(testo(card)).toContain('da approvare');
        expect(testo(card)).toContain('Dal portale e dallo studio');
        expect(testo(card)).not.toContain('commenti e allegati');
        expect(testo(card)).not.toContain('Commenti e allegati');
        p.unmount();

        const zero = monta();
        expect(zero.get('[data-testid="card-da-moderare"] [data-testid="numero"]').classes()).toContain('text-slate-300');
    });

    test('un clic sulla card filtra l\'elenco sul gruppo (il tipo), e sulla card scelta toglie il filtro', async () => {
        const p = monta(conModerazione());
        await p.get('[data-testid="card-da-moderare"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'da_moderare' }, expect.objectContaining({ preserveState: true, preserveScroll: true }));
        p.unmount();

        const scelta = monta(conModerazione({ filtri: { quando: null, tipo: 'da_moderare', cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'tutti', tipo_nome: 'Da moderare' } }));
        expect(scelta.get('[data-testid="card-da-moderare"]').attributes('aria-pressed')).toBe('true');
        await scelta.get('[data-testid="card-da-moderare"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', {}, expect.anything());
    });

    test('nell\'elenco il gruppo sta dopo le segnalazioni e i bonifici, prima degli altri, con il pallino ambra', async () => {
        const p = monta(conModerazione());
        const gruppi = p.findAll('[data-testid="gruppo"]');

        expect(gruppi.map((g) => testo(g))).toEqual(['Scaduti 107', 'Segnalazioni aperte 8', 'Bonifici da verificare 2', 'Da moderare 3', 'Più avanti 6']);
        expect(gruppi[3].get('span').classes()).toContain('bg-amber-500');
    });

    test('la riga: i numeri veri nel titolo, la riga sotto, la data «dal …» e «da N giorni» in grigio (mai rossa), la prossima azione verso la pagina filtrata', async () => {
        const p = monta(conModerazione());
        const righe = p.findAll('[data-testid="riga-compito"]').filter((r) => testo(r).includes('in attesa'));
        const seconda = righe[1];

        expect(testo(righe[0])).toContain('2 commenti in attesa');
        expect(testo(seconda)).toContain('1 commento e 2 allegati in attesa');
        expect(testo(seconda)).toContain('Segnalazione «Ascensore fermo»');
        expect(testo(seconda)).toContain('Condominio test');
        expect(seconda.get('[data-testid="data"]').text()).toBe('dal 16/11/2026');
        expect(seconda.get('[data-testid="data"]').classes()).not.toContain('text-red-700');
        expect(testo(seconda)).toContain('da ieri');
        const azione = seconda.get('[data-testid="prossima-azione"]');
        expect(azione.text()).toBe('Modera i commenti');
        expect(azione.attributes('href')).toBe('/admin/commenti?stato=in_attesa&tipo=segnalazioni&elemento=9');
    });

    test('«e altri N» del gruppo apre il suo tipo', async () => {
        const p = monta(conModerazione({ anteprima: [{ gruppo: 'da_moderare', numero: 12, righe: moderare }] }));

        expect(testo(p.get('[data-testid="e-altri"]'))).toBe('e altri 10 da moderare');
        await p.get('[data-testid="e-altri"]').trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/admin.inbox', { tipo: 'da_moderare' }, expect.anything());
    });
});
