// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il passo «Documenti» della pagina dell'assemblea (la bozza approvata dei documenti dei punti, scene 1 e 1b), con l'ordinaria di Via Roma 12:
 *
 * - in cima la riga che dice che cosa si fa: «Che cosa riceve ogni condòmino insieme all'avviso. Poi «Avanti: Convocazione».»;
 * - una riga per punto (non più la tabella a tre colonne): il numero, il testo con la riga grigia sotto, a destra «Aggiungi un documento»; la copertina con
 *   «Pronti», il rendiconto «Fermato», il preventivo con «Ferma il preventivo» al posto di «Aggiungi un documento»;
 * - i documenti come etichette con il nome, le pagine e la × di «Togli» (una DELETE che ricarica il passo); con molti, le prime tre e «e altri 4 · Mostra
 *   tutti», «Mostra meno» richiude;
 * - la proposta del programma su una riga azzurra, «Proposto dalla fattura n. GUIDA-02» con «Aggiungi» (un POST, mai da sola); più PDF proposti in una riga
 *   con «Aggiungili tutti» e «Scegli», che apre la finestra con quelli già spuntati;
 * - «Nessun documento.» al posto della frase che prometteva un modo che non c'era;
 * - il piede come nelle finestre: il riepilogo «Nel fascicolo: …» a sinistra (arancio vicino al tetto), «Uno per persona (.zip)» e «Componi il fascicolo»;
 * - senza «Gestisce le assemblee», o dalla prima spedizione, niente «Aggiungi», niente × e niente proposte.
 *
 * Che cosa non copre: la finestra «Aggiungi un documento» (`FinestraDocumentiDelPunto.test.ts`); il fascicolo vero (il server); la larghezza vera a 375
 * pixel (la prova a video: qui le classi della colonna sola).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), delete: vi.fn() }));
const file = vi.hoisted(() => ({ chiesto: [] as unknown[], scaricati: [] as string[] }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { post: vi.fn(async (...a: unknown[]) => {
    file.chiesto.push(a);
    return { data: new Blob(['PK\u0003\u0004']), headers: { 'content-type': 'application/zip', 'content-disposition': 'attachment; filename="avvisi-per-persona.zip"' } };
}) } }));
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({
    ...(await originale<typeof import('@/lib/gestionale/incassi/ricevute')>()),
    apriPdfInUnaScheda: vi.fn(async (chiedi: () => Promise<unknown>) => { await chiedi(); return { modo: 'scheda', nome: 'fascicolo.pdf' }; }),
    verificaIlFile: vi.fn(async () => undefined),
    scaricaFile: vi.fn((_f: Blob, nome: string) => { file.scaricati.push(nome); }),
}));

const { fascicoloDellaBozza } = await import('../convocazione/viaRoma12');
const Scheda = (await import('./SchedaFascicolo.vue')).default;

/** La finestra finta: dice se è aperta, per quale punto e con quali documenti già spuntati. */
const Finestra = defineComponent({
    name: 'FinestraDocumentiDelPunto', props: ['open', 'punto', 'scelti', 'rotte', 'condominio'],
    setup: (p) => () => h('div', { 'data-finto': 'finestra', 'data-prop': JSON.stringify(p) }),
});

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockClear(); inertia.delete.mockClear(); file.chiesto = []; file.scaricati = []; });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(fascicolo: Record<string, unknown> = fascicoloDellaBozza() as unknown as Record<string, unknown>) {
    w = mount(Scheda, { attachTo: document.body, props: { fascicolo }, global: { stubs: { FinestraDocumentiDelPunto: Finestra } } });
    await flushPromises();

    return w;
}
const pulsante = (etichetta: string, dove: VueWrapper | ReturnType<VueWrapper['get']> = w!) => dove.findAll('button').find((b) => b.text().replace(/\s+/g, ' ').trim() === etichetta);
const riga = (numero: string) => w!.get(`[data-testid="riga-fascicolo"][data-punto="${numero}"]`);
const testo = (el: { text: () => string }) => el.text().replace(/\s+/g, ' ');
const finestra = () => JSON.parse(w!.get('[data-finto="finestra"]').attributes('data-prop')!);

describe('il passo «Documenti»', () => {
    test('in cima la riga che dice che cosa si fa nel passo', async () => {
        await monta();
        expect(testo(w!.get('[data-testid="riga-in-cima"]'))).toBe('Che cosa riceve ogni condòmino insieme all\'avviso. Poi «Avanti: Convocazione».');
    });

    test('una riga per punto, non più la tabella: la copertina «Pronti», il rendiconto «Fermato», il preventivo con «Ferma il preventivo»', async () => {
        await monta();
        expect(w!.find('table').exists()).toBe(false);
        expect(w!.findAll('[data-testid="riga-fascicolo"]')).toHaveLength(6);
        expect(testo(riga('—'))).toContain('Copertina e avviso');
        expect(testo(riga('—'))).toContain('Sulla carta dello studio, con l\'ordine del giorno e la delega');
        expect(testo(riga('—'))).toContain('Pronti');
        expect(testo(riga('1'))).toContain('Versione 2 fermata il 12/11 · impronta 7f3a…');
        expect(testo(riga('1'))).toContain('Fermato');
        // Il rendiconto e il preventivo restano come oggi: niente «Aggiungi un documento».
        expect(pulsante('Aggiungi un documento', riga('1'))).toBeUndefined();
        expect(pulsante('Aggiungi un documento', riga('2'))).toBeUndefined();
        expect(testo(riga('2'))).toContain('Il preventivo non è ancora fermato: lo fermi prima di spedire');
        await pulsante('Ferma il preventivo', riga('2'))!.trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/a/1/fascicolo/preventivo', {}, expect.objectContaining({ preserveScroll: true, only: ['fascicolo', 'passi', 'diario'] }));
    });

    test('un punto: il testo con la riga grigia della fattura, «Aggiungi un documento» alto 28 pixel, che apre la finestra del punto', async () => {
        await monta();
        expect(testo(riga('3'))).toContain('Fattura n. GUIDA-02 · Mario Rossi Impianti s.r.l. · € 3.450,00');
        const aggiungi = riga('3').get('[data-testid="aggiungi-documento"]');
        expect(testo(aggiungi)).toBe('Aggiungi un documento');
        expect(aggiungi.classes()).toEqual(expect.arrayContaining(['h-7', 'text-xs']));
        expect(finestra().open).toBe(false);
        await aggiungi.trigger('click');
        expect(finestra()).toMatchObject({ open: true, punto: { id: 13, numero: '3' }, scelti: [], condominio: 'Via Roma 12' });
    });

    test('un documento che non si legge più come PDF ha l\'etichetta arancio «non si legge»: non partirebbe', async () => {
        const f = fascicoloDellaBozza();
        f.righe[3].documenti = [{ id: 31, nome: 'Preventivo quadro di manovra.pdf', pagine: null }];
        await monta(f as never);
        expect(testo(riga('3').get('[data-testid="documento-che-non-si-legge"]'))).toBe('non si legge');
    });

    test('i documenti come etichette con il nome e le pagine; la × è «Togli», una DELETE che ricarica il passo', async () => {
        await monta();
        const etichetta = riga('3').get('[data-testid="documento-del-punto"]');
        expect(testo(etichetta)).toContain('Preventivo quadro di manovra.pdf');
        expect(testo(etichetta)).toContain('3 pagine');
        expect(etichetta.classes()).toContain('rounded-md');
        const togli = etichetta.get('[data-testid="togli-documento"]');
        expect(togli.attributes('aria-label')).toBe('Togli «Preventivo quadro di manovra.pdf»');
        await togli.trigger('click');
        expect(inertia.delete).toHaveBeenCalledWith('/a/1/fascicolo/punti/13/documenti/31', expect.objectContaining({ preserveScroll: true, only: ['fascicolo', 'passi', 'diario'] }));
    });

    test('la proposta «Proposto dalla fattura n. GUIDA-02» su una riga, con «Aggiungi»: un POST, mai da sola', async () => {
        await monta();
        const proposta = riga('3').get('[data-testid="proposta"]');
        // Dal tablet in su «Proposto dalla fattura n. GUIDA-02», al telefono la parola corta.
        expect(proposta.get('[data-testid="da-lungo"]').text()).toBe('Proposto dalla fattura n. GUIDA-02');
        expect(proposta.get('[data-testid="da-breve"]').text()).toBe('Dalla fattura');
        expect(testo(proposta)).toContain(': fattura-GUIDA-02.pdf · 2 pagine');
        expect(inertia.post).not.toHaveBeenCalled();
        await pulsante('Aggiungi', proposta)!.trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/a/1/fascicolo/punti/13/documenti', { documenti: [41] },
            expect.objectContaining({ preserveScroll: true, only: ['fascicolo', 'passi', 'diario'] }));
    });

    test('con molti documenti: le prime tre e «e altri 4 · Mostra tutti», «Mostra meno» richiude; più PDF proposti con «Aggiungili tutti» e «Scegli»', async () => {
        await monta();
        expect(riga('4').findAll('[data-testid="documento-del-punto"]')).toHaveLength(3);
        await pulsante('e altri 4 · Mostra tutti', riga('4'))!.trigger('click');
        expect(riga('4').findAll('[data-testid="documento-del-punto"]')).toHaveLength(7);
        await pulsante('Mostra meno', riga('4'))!.trigger('click');
        expect(riga('4').findAll('[data-testid="documento-del-punto"]')).toHaveLength(3);

        const proposta = riga('4').get('[data-testid="proposta"]');
        expect(proposta.get('[data-testid="da-lungo"]').text()).toBe('Proposti dalla fattura n. 51');
        expect(testo(proposta)).toContain(': 4 PDF · 9 pagine');
        await pulsante('Aggiungili tutti', proposta)!.trigger('click');
        expect(inertia.post).toHaveBeenLastCalledWith('/a/1/fascicolo/punti/14/documenti', { documenti: [61, 62, 63, 64] }, expect.anything());
        await pulsante('Scegli', proposta)!.trigger('click');
        expect(finestra()).toMatchObject({ open: true, punto: { id: 14 }, scelti: [61, 62, 63, 64] });
    });

    test('«Nessun documento.» e il pulsante; al telefono la riga va in colonna, il pulsante sotto', async () => {
        await monta();
        expect(riga('6').get('[data-testid="nessun-documento"]').text()).toBe('Nessun documento.');
        expect(w!.text()).not.toContain('aggiungilo se serve');
        expect(riga('6').classes()).toEqual(expect.arrayContaining(['grid-cols-[24px_minmax(0,1fr)]', 'sm:grid-cols-[28px_minmax(0,1fr)_auto]']));
        expect(riga('6').get('[data-testid="azione-della-riga"]').classes()).toEqual(expect.arrayContaining(['order-last', 'sm:order-none']));
    });

    test('il piede: il riepilogo del fascicolo a sinistra, «Uno per persona (.zip)» e «Componi il fascicolo» a destra', async () => {
        await monta();
        const riepilogo = w!.get('[data-testid="riepilogo-fascicolo"]');
        expect(testo(riepilogo)).toBe('Nel fascicolo: 9 documenti · 35 pagine, più la copertina e l\'avviso.');
        expect(riepilogo.classes()).not.toContain('text-amber-800');
        await pulsante('Componi il fascicolo')!.trigger('click');
        await flushPromises();
        expect(file.chiesto.at(-1)).toEqual(['/a/1/fascicolo/componi', {}, { responseType: 'blob' }]);
        await pulsante('Uno per persona (.zip)')!.trigger('click');
        await flushPromises();
        expect(file.scaricati).toEqual(['avvisi-per-persona.zip']);
    });

    test('il peso: dalla metà del tetto lo dice, vicino al tetto è arancio con «manda i documenti più pesanti a parte»; senza documenti lo dice', async () => {
        await monta(fascicoloDellaBozza({ riepilogo: { documenti: 11, pagine: 48, mb: 36, tetto: 40, dire: true, vicino: true } }) as never);
        const riepilogo = w!.get('[data-testid="riepilogo-fascicolo"]');
        expect(testo(riepilogo)).toBe('Nel fascicolo: 11 documenti · 48 pagine, più la copertina e l\'avviso · 36 MB su 40: manda i documenti più pesanti a parte.');
        expect(riepilogo.classes()).toContain('text-amber-800');
        w!.unmount();
        await monta(fascicoloDellaBozza({ riepilogo: { documenti: 0, pagine: 0, mb: 0, tetto: 40, dire: false, vicino: false } }) as never);
        expect(testo(w!.get('[data-testid="riepilogo-fascicolo"]'))).toBe('Nel fascicolo: la copertina e l\'avviso, nessun documento.');
    });

    test('un pulsante spento dice perché', async () => {
        await monta(fascicoloDellaBozza({ componi: { quante: 0, attivo: false, perche: 'Ferma prima il preventivo.' } }) as never);
        expect(pulsante('Componi il fascicolo')!.attributes('disabled')).toBeDefined();
        expect(w!.find('[data-testid="perche-componi"]').text()).toBe('Ferma prima il preventivo.');
    });

    test('senza «Gestisce le assemblee», o dalla prima spedizione: i documenti si leggono, senza «Aggiungi», senza × e senza proposte', async () => {
        const daLeggere = fascicoloDellaBozza({ gestisce: false, modificabile: false });
        daLeggere.righe = daLeggere.righe.map((r) => ({ ...r, aggiungi: false, togli: false, proposte: [] }));
        await monta(daLeggere as never);
        expect(w!.find('[data-testid="aggiungi-documento"]').exists()).toBe(false);
        expect(w!.find('[data-testid="togli-documento"]').exists()).toBe(false);
        expect(w!.find('[data-testid="proposta"]').exists()).toBe(false);
        expect(testo(riga('3'))).toContain('Preventivo quadro di manovra.pdf');
        expect(pulsante('Ferma il preventivo')).toBeUndefined();
        expect(pulsante('Componi il fascicolo')).toBeUndefined();
    });

    test('vuota, una riga con parole semplici', async () => {
        await monta({});
        expect(w!.find('[data-testid="fascicolo-vuoto"]').text()).toContain('Il fascicolo si compone quando l\'assemblea ha il suo ordine del giorno.');
    });
});
