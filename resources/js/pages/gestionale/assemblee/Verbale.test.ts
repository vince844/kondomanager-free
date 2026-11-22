// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina «Il verbale» (le bozze approvate dell'editor e del verbale; la bozza delle scene del verbale): durante l'assemblea le card dei punti con la
 * discussione e chi scrive; chiusa la sala «Genera il verbale dal modello»; in bozza l'editor con le parti della sala, l'avviso delle cifre con «Rimetti»,
 * «Stampa la bozza» che chiede prima; registrato il testo in sola lettura, «Sostituisci la copia» e la trasmissione con i trenta giorni e la copia di prima.
 * E le forme: 375 pixel (la barra in due righe, le versioni nel cassetto, i pulsanti larghi), il tema scuro, niente sotto i 12 pixel.
 *
 * **La tenuta senza rete montata davvero nella pagina** (la catena «il testo scritto senza rete nella pagina vera → il disco → il ritorno della rete → la
 * versione nel server»): la discussione scritta senza rete va sul disco del browser (l'IndexedDB finto), non in memoria; la pagina chiusa e riaperta la
 * ritrova e la manda; la parte con due versioni è ferma; «Stampa la bozza» parte solo con il testo arrivato; su http il numero del salvataggio è un UUID.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { pagina, registrato, verbale } from '@/components/assemblee/verbale/verbaleDiProva';
import { apriDepositoIndexedDb } from '@/lib/senzaRete/deposito';
import { IndexedDbFinto } from '@/lib/senzaRete/indexedDbFinto';
import { PAUSA_MS as PAUSA } from '@/lib/senzaRete/testo';
import fileInComune from '../../../../../tests/Fixtures/verbale/lotti_senza_rete.json';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), get: vi.fn(), visit: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const rete = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/9/il-verbale', props: { auth: { user: { name: 'Andrea Costa', roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('axios', () => ({ default: rete }));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => h('div', { 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => h('button', {
                    type: 'button', 'data-opzione': i, onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o),
                }, String((o as Record<string, unknown>)[p.label ?? 'label']))));
            },
        }),
    };
});
// La pagina monta l'editor (Tiptap): su una macchina carica supera i cinque secondi di serie.
vi.setConfig({ testTimeout: 20000 });
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { ClipboardEvent: unknown }).ClipboardEvent ??= class extends Event { clipboardData = null; };
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

let w: VueWrapper | null = null;
/** Il disco del browser di queste prove: un IndexedDB finto, nuovo a ogni prova; sopravvive allo smontare la pagina (il browser chiuso e riaperto). */
let disco: IndexedDbFinto;
beforeEach(() => {
    disco = new IndexedDbFinto();
    (globalThis as unknown as { indexedDB: unknown }).indexedDB = disco;
    globalThis.sessionStorage?.clear();
    Object.values(inertia).forEach((f) => f.mockReset());
    rete.post.mockReset().mockImplementation((u: string, corpo: { gesti?: { gesto: string; dati: { parte: string } }[]; parte?: string }) => (String(u).endsWith('/chiedi')
        ? Promise.resolve({ data: { parte: corpo.parte, scrive: { nome: 'Andrea Costa', dispositivo: 'portatile', sono_io: false, dal: '' },
            richiesta: { nome: 'Marco Galli', dispositivo: 'questo', mia: true, per_me: false, secondi: 60 }, esito_della_richiesta: null, aperto_da: [] } })
        : Promise.resolve({ data: {
        ora_del_server: '2026-12-15T20:14:00Z', risposte: (corpo?.gesti ?? []).map((g) => ({ gesto: g.gesto, tipo: 'testo', esito: 'applicato', messaggio: null, verbale_id: 7,
            parte: g.dati.parte, revisione: 4, impronta: 'x', versione: null })) } })));
    rete.get.mockReset().mockRejectedValue(new TypeError('senza rete nella prova'));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

/** Lascia lavorare il disco finto del browser (le sue risposte arrivano con un timer), con l'orologio vero o con quello finto. */
async function asciuga(ms = 30) {
    if (vi.isFakeTimers()) await vi.advanceTimersByTimeAsync(ms);
    else await new Promise((r) => setTimeout(r, ms));
    await flushPromises();
}

async function monta(prop: ReturnType<typeof pagina>) {
    const Pagina = (await import('./Verbale.vue')).default;
    w = mount(Pagina as never, { attachTo: document.body, props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], verbale: prop, ritorno: { url: '/admin/gestionale/3/assemblee/9', testo: 'L\'assemblea' } } as never,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { props: ['pageTitle'], template: '<header><h1>{{ pageTitle }}</h1><slot name="accanto-al-titolo" /><slot name="actions" /></header>' } } } });
    await flushPromises();
    await asciuga();
    return w;
}

/** La testa della pagina (decisione 659): il ritorno da dove si è venuti e le tre card corte della guida. */
async function montaLaTesta(prop: ReturnType<typeof pagina>, ritorno: { url: string; testo: string }) {
    const Pagina = (await import('./Verbale.vue')).default;
    w = mount(Pagina as never, { attachTo: document.body, props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], verbale: prop, ritorno } as never,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { props: ['pageTitle', 'guides', 'backUrl', 'backText'],
            template: '<header><h1>{{ pageTitle }}</h1><a v-if="backUrl" data-testid="indietro" :href="backUrl">{{ backText }}</a>'
                + '<p v-for="g in guides" :key="g.title" data-testid="card-della-guida">{{ g.title }}: {{ g.description }}</p><slot name="actions" /></header>' } } } });
    await flushPromises();
    await asciuga();
    return w;
}

/** I gesti in coda sul disco del browser, per l'assemblea 9. */
async function sulDiscoDelBrowser() {
    const letti = apriDepositoIndexedDb(disco as unknown as IDBFactory).then((d) => d.inCoda('verbale:9'));
    await asciuga();
    return letti;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('durante l\'assemblea', () => {
    test('una card per punto con la riga della sala, la discussione e «Aggiungi una dichiarazione»; il punto che scrive un altro si legge e si chiede', async () => {
        const v = verbale({ stato: 'in_sala', cifre: [], chi_scrive: { 'punto:44': { parte: 'punto:44', scrive: { nome: 'Andrea Costa', dispositivo: 'portatile', sono_io: false,
            dal: '' }, richiesta: null, esito_della_richiesta: null, aperto_da: [] } } });
        const p = pagina(v);
        p.riunioni[0].chiusa_il = null;
        const pg = await monta(p);
        expect(pg.findAll('[data-testid="punto-del-verbale"]')).toHaveLength(3);
        expect(testo()).toContain('Approvata · 9 favorevoli per 506,40 millesimi, 2 contrari, 1 astenuto, 1 non ha votato');
        expect(testo()).toContain('Andrea Costa scrive il punto 4.');
        const quattro = pg.find('[data-punto="4"]');
        expect(quattro.find('[data-testid="barra-dell-editor"]').exists()).toBe(false);
        expect(quattro.text()).toContain('L\'amministratore illustra i tre preventivi ricevuti.');
        expect(quattro.find('[data-testid="chiedi-di-scrivere"]').exists()).toBe(true);
        expect(pg.find('[data-punto="3"] [data-testid="barra-dell-editor"]').exists()).toBe(true);
        expect(pg.findAll('[data-testid="aggiungi-dichiarazione"]')).toHaveLength(3);
        rete.post.mockClear();
        await quattro.find('[data-testid="chiedi-di-scrivere"]').trigger('click');
        expect(rete.post).toHaveBeenCalledWith(expect.stringContaining('/il-verbale/7/chiedi'), expect.objectContaining({ parte: 'punto:44' }));
        await flushPromises();
        expect(quattro.text()).toContain('Aspetto la risposta ancora 1:00.');
    });

    test('la discussione scritta nella pagina parte pochi secondi dopo la pausa, con il verbale e la base', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        const p = pagina(verbale({ stato: 'in_sala', cifre: [] }));
        p.riunioni[0].chiusa_il = null;
        const pg = await monta(p);
        await vi.advanceTimersByTimeAsync(50);
        const editor = (pg.find('[data-punto="3"]').findComponent({ name: 'EditorDelVerbale' }).vm as unknown as { editor: { commands: { insertContent: (t: string) => void } } }).editor;
        editor.commands.insertContent('Il sig. Ferri chiede di anticipare.');
        await vi.advanceTimersByTimeAsync(PAUSA + 500);
        const chiamata = rete.post.mock.calls.find(([u]) => String(u).endsWith('/il-verbale/gesti'));
        expect(chiamata?.[1].gesti[0]).toMatchObject({ tipo: 'testo', base: 0, dati: { verbale_id: 7, parte: 'punto:43' } });
    });
});

describe('la tenuta senza rete, montata nella pagina', () => {
    const senzaRete = () => Promise.reject(Object.assign(new Error('Network Error'), { isAxiosError: true, request: {} }));
    const editorDelPunto = (pg: VueWrapper, numero: number) => (pg.find(`[data-punto="${numero}"]`).findComponent({ name: 'EditorDelVerbale' }).vm as unknown as
        { editor: { commands: { insertContent: (t: string) => void } } }).editor;

    test('senza rete la discussione va sul disco del browser, non in memoria; chiusa e riaperta la pagina, il testo si ritrova e parte quando c\'è la rete', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        rete.post.mockImplementation(senzaRete);
        const p = pagina(verbale({ stato: 'in_sala', cifre: [] }));
        p.riunioni[0].chiusa_il = null;
        const pg = await monta(p);
        await vi.advanceTimersByTimeAsync(50);
        editorDelPunto(pg, 3).commands.insertContent('Il sig. Ferri chiede di anticipare i lavori.');
        // Al più 300 millisecondi dopo la lettera, il testo è sul disco: un gesto «testo» per la parte del punto 3.
        await vi.advanceTimersByTimeAsync(400);
        const sulDisco = await sulDiscoDelBrowser();
        expect(sulDisco.map((x) => [x.tipo, (x.dati as { parte: string }).parte])).toEqual([['testo', 'punto:43']]);
        expect(JSON.stringify(sulDisco[0].dati)).toContain('anticipare i lavori');
        await vi.advanceTimersByTimeAsync(PAUSA + 200);
        expect(document.body.querySelector('[data-testid="stato-del-salvataggio"]')?.getAttribute('data-stato')).toBe('senza_rete');

        // Il portatile si spegne: la pagina si smonta senza rete. Riacceso, la rete c'è: il testo ritrovato parte da solo.
        w?.unmount();
        w = null;
        document.body.innerHTML = '';
        rete.post.mockReset().mockImplementation((u: string, corpo: { gesti?: { gesto: string; dati: { parte: string } }[] }) => Promise.resolve({ data: {
            ora_del_server: '2026-12-15T20:31:00Z', risposte: (corpo?.gesti ?? []).map((g) => ({ gesto: g.gesto, tipo: 'testo', esito: 'applicato', messaggio: null,
                verbale_id: 7, parte: g.dati.parte, revisione: 1, impronta: 'x', versione: null })) } }));
        const di = pagina(verbale({ stato: 'in_sala', cifre: [] }));
        di.riunioni[0].chiusa_il = null;
        await monta(di);
        await vi.advanceTimersByTimeAsync(200);
        const partito = rete.post.mock.calls.find(([u]) => String(u).endsWith('/il-verbale/gesti'));
        expect(JSON.stringify(partito?.[1].gesti[0].dati)).toContain('anticipare i lavori');
        // Il lotto della pagina ha la forma del file in comune che il server riceve nella sua catena (`CateneSenzaReteDelVerbaleTest.php`).
        const chiavi = (x: unknown): unknown => (x && typeof x === 'object' && !Array.isArray(x) ? Object.fromEntries(Object.keys(x).sort()
            .filter((k) => k !== 'documento').map((k) => [k, chiavi((x as Record<string, unknown>)[k])])) : Array.isArray(x) ? x.slice(0, 1).map(chiavi) : typeof x);
        const delFile = { ...fileInComune.lotti[0], gesti: fileInComune.lotti[0].gesti.map((g) => ({ ...g, dati: { ...g.dati, verbale_id: 7 } })) };
        expect(chiavi(partito?.[1])).toEqual(chiavi(delFile));
        expect(testo()).toContain('Ho ritrovato 1 modifica che non era partita');
        expect(await sulDiscoDelBrowser()).toEqual([]);
    });

    test('su un indirizzo senza https (niente crypto.randomUUID) il numero del salvataggio è un UUID, come il server lo vuole', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        const prima = globalThis.crypto.randomUUID;
        Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true });
        try {
            const p = pagina(verbale({ stato: 'in_sala', cifre: [] }));
            p.riunioni[0].chiusa_il = null;
            const pg = await monta(p);
            await vi.advanceTimersByTimeAsync(50);
            editorDelPunto(pg, 3).commands.insertContent('Testo.');
            await vi.advanceTimersByTimeAsync(PAUSA + 500);
            const chiamata = rete.post.mock.calls.find(([u]) => String(u).endsWith('/il-verbale/gesti'));
            expect(chiamata?.[1].gesti[0].gesto).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
            expect(chiamata?.[1].gesti[0].dati.scheda).toMatch(/^[A-Za-z0-9-]{1,40}$/);
        } finally {
            Object.defineProperty(globalThis.crypto, 'randomUUID', { value: prima, configurable: true });
        }
    });

    test('con due versioni da scegliere la parte è ferma: l\'editor non c\'è, e la rete lo dice con chi e quando', async () => {
        const v = verbale({ in_attesa: [{ id: 91, parte: 'intero', numero: 4, perche: 'due versioni', impronta: 'y', dispositivo: 'tablet di Marco Galli#b2c3d4e5',
            segnata_il: '2026-12-15T20:24:00Z', arrivata_il: '2026-12-15T20:31:00Z', chi: 'Marco Galli', in_attesa: true, attesa: 'due_versioni', scelta: null,
            scelta_il: null, nome_della_parte: 'il verbale', documento: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Dal tablet.' }] }] } }] });
        const pg = await monta(pagina(v));
        await flushPromises();
        expect(pg.find('[data-testid="editor-del-verbale"]').exists()).toBe(false);
        expect(testo()).toContain('cambiata anche dal tablet di Marco Galli');
    });

    test('«Ripristina» pochi secondi dopo l\'ultima lettera: l\'editor prende il testo ripristinato, e la lettera dopo non lo disfa', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        const p = pagina(verbale({ cifre: [] }));
        const pg = await monta(p);
        await vi.advanceTimersByTimeAsync(50);
        const editor = (pg.findComponent({ name: 'EditorDelVerbale' }).vm as unknown as { editor: { commands: { insertContent: (t: string) => void }; getText: () => string } }).editor;
        editor.commands.insertContent('Una frase scritta adesso. ');
        await vi.advanceTimersByTimeAsync(PAUSA + 500);
        // Al quinto secondo, «Ripristina» la versione 2: il server ha il testo di allora, alla revisione 9.
        const ripristinato = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Il testo della versione 2.' }] }] };
        const v = verbale({ cifre: [] });
        v.parti.intero = { ...v.parti.intero!, documento: ripristinato as never, revisione: 9 };
        await pg.setProps({ verbale: { ...pagina(v), ora_del_server: '2026-12-15T20:20:00Z' } });
        await vi.advanceTimersByTimeAsync(50);
        expect(editor.getText()).toContain('Il testo della versione 2.');
        expect(editor.getText()).not.toContain('Una frase scritta adesso.');
    });

    test('«Stampa la bozza» senza rete non stampa il testo del server: aspetta che il testo scritto qui arrivi', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        rete.post.mockImplementation((u: string) => (String(u).endsWith('/il-verbale/gesti') ? senzaRete() : Promise.resolve({ data: new Blob(['%PDF']) })));
        const pg = await monta(pagina(verbale({ cifre: [] })));
        await vi.advanceTimersByTimeAsync(50);
        const editor = (pg.findComponent({ name: 'EditorDelVerbale' }).vm as unknown as { editor: { commands: { insertContent: (t: string) => void } } }).editor;
        editor.commands.insertContent('Una correzione che non è ancora arrivata.');
        await vi.advanceTimersByTimeAsync(400);
        await pg.find('[data-testid="stampa-la-bozza"]').trigger('click');
        await vi.advanceTimersByTimeAsync(100);
        expect(rete.post.mock.calls.some(([u]) => String(u).endsWith('/bozza'))).toBe(false);
        expect(pg.find('[data-testid="esito-della-pagina"]').text()).toContain('non è ancora tutto sul server');
    });
});

describe('a fine assemblea', () => {
    test('le tre card e «Genera il verbale dal modello», con il modello e che cosa entra', async () => {
        const pg = await monta(pagina(null));
        expect(testo()).toContain('Il verbale non c\'è ancora');
        expect(testo()).toContain('entro il 22/12');
        await pg.find('[data-testid="genera-il-verbale"]').trigger('click');
        await asciuga();
        expect(testo()).toContain('Che cosa entra nel verbale');
        (document.body.querySelector('[data-testid="conferma-genera"]') as HTMLButtonElement).click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('/il-verbale/genera'), { seduta_id: 2, modello_id: 5 }, expect.anything());
    });
});

describe('la bozza', () => {
    test('l\'editor con la parte «Dati della sala»; «Rimetti 506,40» rimette la cifra e la salva; «Stampa la bozza» chiede prima', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        const pg = await monta(pagina(verbale()));
        await vi.advanceTimersByTimeAsync(50);
        expect(pg.find('[data-testid="dati-della-sala"]').text()).toContain('Dati della sala');
        expect(testo()).toContain('Nel punto 4 il verbale dice «496,40», i voti registrati dicono 506,40.');
        await pg.find('[data-testid="rimetti"]').trigger('click');
        await vi.advanceTimersByTimeAsync(PAUSA + 500);
        const chiamata = rete.post.mock.calls.find(([u]) => String(u).endsWith('/il-verbale/gesti'));
        expect(JSON.stringify(chiamata?.[1].gesti[0].dati.documento)).toContain('506,40 millesimi');
        expect(chiamata?.[1].gesti[0].base).toBe(3);
        await pg.find('[data-testid="stampa-la-bozza"]').trigger('click');
        await flushPromises();
        expect(testo()).toContain('Una cifra non torna con i voti registrati');
    });
});

describe('registrato', () => {
    test('il testo firmato in sola lettura, il numero, la copia, e la trasmissione con i trenta giorni calcolati in modo prudente', async () => {
        const pg = await monta(pagina(registrato()));
        expect(pg.find('[data-testid="editor-del-verbale"]').exists()).toBe(false);
        expect(pg.find('[data-testid="testo-del-verbale"]').text()).toContain('11 condòmini su 26');
        expect(pg.find('[data-testid="badge-dello-stato"]').text()).toBe('Registrato · n. 42');
        expect(testo()).toContain('Chi riceve il verbale n. 42');
        expect(testo()).toContain('Non sono mai entrati · 1');
        expect(testo()).toContain('Fuori al voto di un punto · 1');
        expect(pg.find('[data-anagrafica="20"] [data-testid="trenta-giorni"]').text()).toContain('punti 3, 4 e 6, fino al 14/01/2027');
        expect(pg.find('[data-anagrafica="3"] [data-testid="trenta-giorni"]').text()).toContain('punti 4 e 6, dalla consegna');
        expect(pg.find('[data-anagrafica="3"] [data-testid="trenta-giorni"]').text()).toContain('punto 3, favorevole, nessun termine');
        expect(testo()).toContain('calcolato in modo prudente');
        expect(testo()).toContain('Punto 4 · Romano Ida (contrario) · fino al 14/01/2027');
        expect(pg.find('[data-testid="copia-di-prima"]').exists()).toBe(false);
    });

    test('dopo «Sostituisci la copia» chi ha la copia di prima riceve l\'avviso «rimandala», con l\'elenco; «Rimanda la copia nuova» apre prima la finestra', async () => {
        const v = registrato();
        v.copia!.sostituita_il = '2026-12-18T09:00:00Z';
        const pg = await monta(pagina(v));
        expect(pg.find('[data-testid="copia-di-prima"]').text()).toContain('1 ha la copia di prima: rimandala.');
        expect(pg.find('[data-testid="copia-di-prima"]').text()).toContain('Galli Franco (pec)');
        await pg.find('[data-testid="rimanda-la-copia"]').trigger('click');
        await flushPromises();
        // Il clic non manda niente da solo: la finestra dice chi la riceve e come, e «Manda» la manda.
        expect(inertia.post).not.toHaveBeenCalled();
        expect(document.body.querySelector('[data-testid="finestra-rimanda"]')?.textContent?.replace(/\s+/g, ' ')).toContain('Galli Franco · per PEC');
        (document.body.querySelector('[data-testid="manda-la-copia"]') as HTMLElement).click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('/il-verbale/7/pec'), { anagrafiche: [20], di_nuovo: true }, expect.anything());
    });

    test('«Sostituisci la copia» dice a chi è già partito', async () => {
        const pg = await monta(pagina(registrato()));
        // «Sostituisci la copia» sta nel «⋯» della card (decisione 656), non fra i pulsanti.
        expect(pg.find('[data-testid="sostituisci-la-copia"]').exists()).toBe(false);
        await apriMenu('menu-del-verbale');
        (document.body.querySelector('[data-testid="sostituisci-la-copia"]') as HTMLElement).click();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="gia-partito"]')?.textContent).toContain('1 ha già la copia di prima: Galli Franco');
    });
});

/** Apre un menu a tendina del programma dalla tastiera (Invio sul pulsante): il contenuto arriva nel body. */
async function apriMenu(testid: string) {
    const t = document.body.querySelector(`[data-testid="${testid}"]`) as HTMLElement;
    t.focus();
    t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await flushPromises();
    await asciuga();
}

describe('la pagina compatta (decisione 656)', () => {
    test('la testa: «Vai a» raccoglie la sala, il registro e l\'assemblea, ognuno con la riga grigia sotto; fuori dal menu non restano pulsanti', async () => {
        const pg = await monta(pagina(registrato()));
        expect(pg.find('[data-testid="vai-alla-sala"]').exists()).toBe(false);
        expect(pg.find('[data-testid="vai-a"]').text()).toContain('Vai a');
        await apriMenu('vai-a');
        const voci = [...document.body.querySelectorAll('[data-testid="voce-vai-a"]')].map((x) => [...x.children].map((c) => c.textContent?.trim()).join(' '));
        expect(voci).toEqual(['La sala In sola lettura', 'Il registro dei verbali I verbali di Via Roma 12', 'L\'assemblea I sei passi']);
        expect(document.body.querySelector('[data-testid="vai-alla-sala"]')?.getAttribute('href')).toContain('/sala');
        expect(document.body.querySelector('[data-testid="vai-al-registro"]')?.getAttribute('href')).toContain('assemblee-registro');
    });

    test('«Stato del verbale» registrato: la riga e il badge in testa; sotto, divisi, «La copia firmata» e «La pagina per il libro di carta» con i pulsanti piccoli in riga', async () => {
        const pg = await monta(pagina(registrato()));
        const card = pg.find('[data-testid="stato-del-verbale"]');
        const testata = card.find('[data-testid="testata-dello-stato"]');
        expect(testata.text()).toContain('Registrato alle');
        expect(testata.find('[data-testid="badge-dello-stato"]').text()).toBe('Registrato · n. 42');
        expect(testata.find('[data-testid="menu-del-verbale"]').exists()).toBe(true);
        const copia = card.find('[data-testid="la-copia"]');
        expect(copia.text()).toContain('La copia firmata');
        expect(copia.text()).toMatch(/Verbale n\. 42 del 15\/12\/2026 · 2,4 MB/);
        expect(copia.find('a').text()).toBe('Apri');
        const libro = card.find('[data-testid="nel-libro"]');
        expect(libro.text()).toContain('La pagina per il libro dei verbali di carta');
        expect(libro.find('[data-testid="i-libro-di-carta"]').attributes('aria-label')).toBe('Il registro dei verbali si tiene anche su un libro di carta: «Stampa la pagina» prepara il foglio da incollare, «Segna incollata nel libro» registra che l\'hai incollato.');
        // «Apri» apre la copia nel browser (`?come=pdf`, risponde inline), in una scheda nuova.
        expect(copia.find('[data-testid="apri-la-copia"]').attributes('href')).toMatch(/[?&]come=pdf$/);
        expect(copia.find('[data-testid="apri-la-copia"]').attributes('target')).toBe('_blank');
        expect(libro.text()).toContain('Da stampare e incollare');
        for (const id of ['pagina-per-il-libro', 'segna-incollata']) {
            const b = libro.find(`[data-testid="${id}"]`);
            expect(b.classes(), id).toEqual(expect.arrayContaining(['h-7', 'text-xs']));
            expect(b.classes(), id).not.toContain('h-8');
        }
        expect(libro.find('[data-testid="pagina-per-il-libro"]').text()).toBe('Stampa la pagina');
        expect(libro.find('[data-testid="segna-incollata"]').text()).toBe('Segna incollata nel libro');
        expect(card.findAll('[data-testid="la-copia"], [data-testid="nel-libro"]').every((x) => x.classes().includes('border-t'))).toBe(true);
    });

    test('«Stato del verbale» in bozza: gli stessi pulsanti, piccoli', async () => {
        const pg = await monta(pagina(verbale()));
        for (const id of ['stampa-la-bozza', 'rigenera']) expect(pg.find(`[data-testid="${id}"]`).classes(), id).toEqual(expect.arrayContaining(['h-7', 'text-xs']));
    });

    test('«Le versioni»: righe compatte divise da una linea, la più recente in alto, l\'ora e il gesto, chi sotto, «Vedi» piccolo; niente riquadri dentro il riquadro', async () => {
        const base = verbale().versioni[0];
        // L'ordine del server: dalla più recente (`VersioniDelVerbale::elenco()`, `orderByDesc('numero')`). La pagina le mostra così, senza capovolgerle
        // (a video il 21/11 sera la più vecchia stava in alto).
        const v = verbale({ versioni: [{ ...base, id: 32, numero: 2, perche: 'bozza stampata', chi: 'Marco Galli', segnata_il: '2026-12-15T20:10:00Z', arrivata_il: '2026-12-15T20:10:00Z' }, base] });
        const pg = await monta(pagina(v));
        const righe = pg.findAll('[data-testid="le-versioni"] [data-versione]');
        expect(righe.map((r) => r.attributes('data-versione'))).toEqual(['2', '1']);
        expect(righe[0].text()).toContain('21:10');
        expect(righe[0].text()).toContain('Bozza stampata');
        expect(righe[0].find('[data-testid="chi-della-versione"]').text()).toBe('Marco Galli');
        expect(righe[0].classes()).toContain('border-t');
        expect(righe[0].classes()).not.toContain('rounded-lg');
        expect(righe[0].find('[data-testid="vedi-versione"]').classes()).toEqual(expect.arrayContaining(['h-7', 'text-xs']));
    });

    test('C28: i pulsanti di «Chi riceve il verbale» sono piccoli e chiari (h-7 text-xs); scuro solo il gesto principale, piccolo anche lui; al telefono a tutta riga', async () => {
        const pg = await monta(pagina(registrato()));
        const ids = ['stampa-le-lettere', 'manda-le-pec', 'registra-la-spedizione', 'aggiungi-una-persona', 'la-prova-della-trasmissione'];
        for (const id of ids) {
            const b = pg.find(`[data-testid="${id}"]`);
            expect(b.exists(), id).toBe(true);
            expect(b.classes(), id).toEqual(expect.arrayContaining(['h-7', 'text-xs', 'w-full', 'sm:w-auto']));
            expect(b.classes(), id).not.toContain('h-9');
            expect(b.classes(), id).not.toContain('text-sm');
        }
        // C'è una lettera da stampare: il gesto principale è «Stampa le lettere», l'unico scuro.
        expect(ids.filter((id) => pg.find(`[data-testid="${id}"]`).classes().includes('bg-slate-900'))).toEqual(['stampa-le-lettere']);
    });

    test('«Le versioni» a verbale registrato: la frase che dice che «Ripristina» non c\'è più resta', async () => {
        const pg = await monta(pagina(registrato()));
        expect(pg.find('[data-testid="le-versioni"]').text()).toContain('«Ripristina» non c\'è più.');
    });
});

describe('le forme della pagina', () => {
    const cartella = 'resources/js/components/assemblee/verbale';
    const file = [...(readdirSync(resolve(process.cwd(), cartella), { recursive: true }) as string[])].filter((f) => f.endsWith('.vue')).map((f) => join(cartella, f))
        .concat(['resources/js/pages/gestionale/assemblee/Verbale.vue', 'resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue']);
    const leggi = (f: string) => readFileSync(resolve(process.cwd(), f), 'utf8');

    test('375 pixel: la barra va a capo, le versioni stanno in un cassetto, i pulsanti principali sono larghi quanto lo schermo, le righe diventano card', () => {
        expect(leggi(`${cartella}/BarraDellEditor.vue`)).toContain('flex flex-wrap');
        expect(leggi(`${cartella}/VersioniDelVerbale.vue`)).toMatch(/lg:hidden[\s\S]*<Sheet/);
        expect(leggi('resources/js/pages/gestionale/assemblee/Verbale.vue')).toContain('lg:grid-cols-[minmax(0,1fr)_20rem]');
        expect(leggi(`${cartella}/TrasmissioneDelVerbale.vue`)).toMatch(/lg:grid-cols-\[/);
        expect(leggi(`${cartella}/StatoDelVerbale.vue`)).toContain('w-full sm:w-auto');
    });

    test('il tema scuro: ogni sfondo bianco ha il suo scuro; niente sotto i 12 pixel; badge con gli angoli, mai pillole; niente bordo spesso a sinistra', () => {
        for (const f of file) {
            const s = leggi(f);
            for (const m of s.matchAll(/class="([^"]*\bbg-white\b[^"]*)"/g)) expect(m[1], f).toMatch(/dark:bg-/);
            expect(s, f).not.toMatch(/text-\[(1[01]|[0-9])px\]|text-\[0\.[0-6]/);
            expect(s, f).not.toMatch(/rounded-full[^"]*\bpx-2\b|border-l-(2|4|8)\b/);
        }
    });

    test('il tema scuro: ogni grigio del testo ha il suo scuro', () => {
        for (const f of file) {
            const s = leggi(f);
            for (const m of s.matchAll(/class="([^"]*\btext-slate-(?:4|5|6|7)00\b[^"]*)"/g)) expect(m[1], f).toMatch(/dark:text-/);
        }
    });

    test('i menu sono di vue-select, i giorni del calendario del programma, le caselle e i file del programma', () => {
        for (const f of file) {
            const s = leggi(f);
            expect(s, f).not.toMatch(/<select\b|type="date"|type="checkbox"|<input\b[^>]*type="file"(?![^>]*(hidden|sr-only))/);
        }
    });
});

describe('la testa della pagina del verbale', () => {
    test('il pulsante con la freccia porta da dove si è venuti, e in testa ci sono tre card corte', async () => {
        const sala = await montaLaTesta(pagina(verbale()), { url: '/admin/gestionale/3/assemblee/9/sala', testo: 'La sala' });
        const indietro = sala.find('[data-testid="indietro"]');
        expect(indietro.text()).toBe('La sala');
        expect(indietro.attributes('href')).toBe('/admin/gestionale/3/assemblee/9/sala');
        const card = sala.findAll('[data-testid="card-della-guida"]').map((c) => c.text());
        expect(card).toEqual([
            'Il testo: Si scrive qui e si salva da solo. I dati della sala li mette il programma.',
            'Senza rete: Questo computer tiene il testo finché la rete torna. Poi si manda da solo.',
            'La registrazione: Il numero del registro e la copia firmata. Dopo, il testo non cambia.',
        ]);
    });
});
