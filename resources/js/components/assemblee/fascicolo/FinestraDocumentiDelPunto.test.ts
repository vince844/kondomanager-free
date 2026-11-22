// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Aggiungi un documento» di un punto (la scena 2 della bozza approvata dei documenti dei punti), nella forma delle finestre:
 *
 * - ancorata in alto, il corpo che scorre dentro, il piede fisso con il riepilogo a sinistra e «Aggiungi» nero a destra;
 * - all'apertura chiede la finestra al server; tre gruppi con il titolo piccolo: «Proposti dal programma», «Dai documenti di Via Roma 12» con «Cerca per
 *   nome», «Un PDF nuovo» con «Scegli il file»;
 * - i proposti non sono spuntati da soli; con «Scegli» la finestra si apre con quelli già spuntati;
 * - il riepilogo: «Si aggiungono 2 documenti al punto 3: 5 pagine nel fascicolo.»; un aggiornamento in corso è un segno piccolo nel riepilogo, e «Aggiungi»
 *   resta spento finché non finisce;
 * - un documento che non è un PDF non si sceglie e lo dice accanto al nome; uno già nel punto è spuntato e spento;
 * - «Aggiungi» manda i documenti scelti e il PDF nuovo in un POST con il file, e si chiude quando il server risponde.
 *
 * Che cosa non copre: le regole del server (`DocumentiDeiPuntiTest`); il calendario del file del browser (è nascosto dietro «Scegli il file»).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
const axiosGet = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a) } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDocumentiDelPunto.vue')).default;

const dalServer = () => ({
    punto: { numero: 3, testo: 'Ratifica della spesa oltre il preventivo di € 3.450,00', sotto: 'Fattura n. GUIDA-02 · Mario Rossi Impianti s.r.l. · € 3.450,00' },
    proposti: [{ id: 41, nome: 'fattura-GUIDA-02.pdf', pagine: 2, da: 'dalla fattura' }],
    condominio: {
        nome: 'Via Roma 12',
        documenti: [
            { id: 31, nome: 'Preventivo quadro di manovra.pdf', pdf: true, pagine: 3, categorie: 'Assemblee', caricato_il: '12/11/2026', gia: false },
            { id: 32, nome: 'Foto della facciata.jpg', pdf: false, pagine: null, categorie: '', caricato_il: '03/10/2026', gia: false },
            { id: 33, nome: 'Regolamento di condominio.pdf', pdf: true, pagine: 12, categorie: 'Regolamenti', caricato_il: '15/01/2026', gia: true },
        ],
        altri: 4,
    },
    tetto: '20 MB',
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); axiosGet.mockReset(); });
const corpo = () => document.body.querySelector('[data-testid="corpo-finestra"]') as HTMLElement;
const testo = (el: Element | null) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
const qs = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const riepilogo = () => testo(qs('[data-testid="riepilogo"]'));
const casella = (id: number) => qs(`[data-testid="scelta-${id}"] button[role="checkbox"]`);
const aggiungi = () => qs('[data-testid="aggiungi"]') as HTMLButtonElement;

async function monta(piu: Record<string, unknown> = {}, risposta = dalServer()) {
    axiosGet.mockResolvedValue({ data: risposta });
    w = mount(Finestra, { attachTo: document.body, props: {
        open: true, punto: { id: 13, numero: '3' }, scelti: [], condominio: 'Via Roma 12',
        rotte: { documenti: '/a/1/fascicolo/punti/__PUNTO__/documenti', aggiungi: '/a/1/fascicolo/punti/__PUNTO__/documenti' }, ...piu,
    } });
    await flushPromises();
}

describe('«Aggiungi un documento», ciò che parte davvero', () => {
    test('il documento di una persona si sceglie, ma dice che arriva a tutti; il «PDF» che non si legge non si sceglie', async () => {
        const r = dalServer();
        r.condominio.documenti.push({ id: 34, nome: 'Lettera dell\'avvocato di Bianchi.pdf', pdf: true, pagine: 2, categorie: '', caricato_il: '01/12/2026', gia: false,
            di_una_persona: true } as never, { id: 35, nome: 'verbale-scansione.pdf', pdf: true, pagine: null, categorie: '', caricato_il: '01/12/2026', gia: false } as never);
        await monta({}, r);
        expect(testo(qs('[data-testid="scelta-34"] [data-testid="di-una-persona"]'))).toBe('È di una persona: arriva a tutti i convocati.');
        expect(casella(34).hasAttribute('disabled')).toBe(false);
        expect(testo(qs('[data-testid="scelta-35"] [data-testid="non-si-legge"]'))).toBe('Non si legge come PDF: non va nel fascicolo.');
        expect(casella(35).hasAttribute('disabled')).toBe(true);
    });
});

describe('«Aggiungi un documento»', () => {
    test('la forma delle finestre: ancorata in alto, il corpo che scorre, il piede fisso con il riepilogo e «Aggiungi» nero', async () => {
        await monta();
        const finestra = qs('[data-testid="finestra-documenti"]');
        expect(finestra.className).toContain('top-[5vh]');
        expect(finestra.className).toContain('translate-y-0');
        expect(finestra.className).toContain('max-h-[90vh]');
        expect(corpo().className).toContain('overflow-y-auto');
        const piede = qs('[data-testid="piede-finestra"]');
        expect(piede.contains(qs('[data-testid="riepilogo"]'))).toBe(true);
        expect(piede.contains(aggiungi())).toBe(true);
        expect(testo(piede)).toContain('Chiudi');
    });

    test('all\'apertura chiede la finestra al server: il titolo del punto, la riga della fattura, i tre gruppi con il titolo piccolo', async () => {
        await monta();
        expect(axiosGet).toHaveBeenCalledWith('/a/1/fascicolo/punti/13/documenti', { params: {} });
        expect(testo(qs('[data-testid="titolo-finestra"]'))).toBe('Aggiungi un documento al punto 3');
        expect(testo(document.body)).toContain('Ratifica della spesa oltre il preventivo di € 3.450,00 (Fattura n. GUIDA-02 · Mario Rossi Impianti s.r.l. · € 3.450,00)');
        const titoli = [...corpo().querySelectorAll('[data-testid="titolo-gruppo"]')].map((t) => testo(t));
        expect(titoli).toEqual(['Proposti dal programma', 'Dai documenti di Via Roma 12', 'Un PDF nuovo']);
        expect(testo(qs('[data-testid="scelta-41"]'))).toContain('2 pagine · dalla fattura');
        expect(testo(corpo())).toContain('3 pagine · Assemblee · caricato il 12/11/2026');
        expect(testo(corpo())).toContain('e altri 4: cercali per nome.');
        expect(testo(corpo())).toContain('Va nei documenti di Via Roma 12, nella categoria «Assemblee», e si collega al punto. Solo PDF: il fascicolo è un PDF solo.');
        expect(testo(qs('[data-testid="scegli-file"]'))).toBe('Scegli il file');
    });

    test('i proposti non sono spuntati da soli; spuntati, il riepilogo conta i documenti e le pagine', async () => {
        await monta();
        expect(casella(41).getAttribute('aria-checked')).toBe('false');
        expect(riepilogo()).toBe('Scegli un documento da aggiungere al punto 3.');
        expect(aggiungi().disabled).toBe(true);
        casella(41).click();
        await flushPromises();
        expect(riepilogo()).toBe('Si aggiunge 1 documento al punto 3: 2 pagine nel fascicolo.');
        casella(31).click();
        await flushPromises();
        expect(riepilogo()).toBe('Si aggiungono 2 documenti al punto 3: 5 pagine nel fascicolo.');
        expect(aggiungi().disabled).toBe(false);
    });

    test('un file che non è un PDF non si sceglie e lo dice accanto al nome; uno già nel punto è spuntato e spento', async () => {
        await monta();
        expect(casella(32).hasAttribute('disabled') || casella(32).getAttribute('data-disabled') !== null).toBe(true);
        expect(testo(qs('[data-testid="scelta-32"]'))).toContain('Non è un PDF: non va nel fascicolo.');
        expect(casella(33).getAttribute('aria-checked')).toBe('true');
        expect(casella(33).hasAttribute('disabled') || casella(33).getAttribute('data-disabled') !== null).toBe(true);
        expect(testo(qs('[data-testid="scelta-33"]'))).toContain('Già nel punto');
    });

    test('«Scegli» apre la finestra con i proposti già spuntati', async () => {
        await monta({ scelti: [41] });
        expect(casella(41).getAttribute('aria-checked')).toBe('true');
        expect(riepilogo()).toBe('Si aggiunge 1 documento al punto 3: 2 pagine nel fascicolo.');
    });

    test('la ricerca per nome chiede al server con «cerca»; mentre arriva, il segno nel riepilogo e «Aggiungi» spento', async () => {
        await monta({ scelti: [41] });
        let rispondi: (v: unknown) => void = () => {};
        axiosGet.mockReturnValueOnce(new Promise((r) => { rispondi = r; }));
        const cerca = qs('[data-testid="cerca-documenti"]') as HTMLInputElement;
        cerca.value = 'quadro';
        cerca.dispatchEvent(new Event('input'));
        cerca.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();
        expect(axiosGet).toHaveBeenLastCalledWith('/a/1/fascicolo/punti/13/documenti', { params: { cerca: 'quadro' } });
        expect(qs('[data-testid="aggiorno"]')).not.toBeNull();
        expect(aggiungi().disabled).toBe(true);
        rispondi({ data: { ...dalServer(), condominio: { ...dalServer().condominio, documenti: [dalServer().condominio.documenti[0]], altri: 0 } } });
        await flushPromises();
        expect(qs('[data-testid="aggiorno"]')).toBeNull();
        expect(corpo().querySelectorAll('[data-testid^="scelta-3"]')).toHaveLength(1);
        // La scelta di prima resta.
        expect(casella(41).getAttribute('aria-checked')).toBe('true');
    });

    test('«Aggiungi» manda i documenti scelti e il PDF nuovo in un POST con il file, e si chiude quando il server risponde', async () => {
        await monta({ scelti: [41] });
        const campo = qs('[data-testid="file-documento"]') as HTMLInputElement;
        const pdf = new File(['%PDF-1.4'], 'Relazione del tecnico.pdf', { type: 'application/pdf' });
        Object.defineProperty(campo, 'files', { value: [pdf] });
        campo.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(riepilogo()).toBe('Si aggiungono 2 documenti al punto 3: 2 pagine nel fascicolo, più quelle del PDF nuovo.');
        aggiungi().click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/fascicolo/punti/13/documenti', { documenti: [41], file: pdf },
            expect.objectContaining({ forceFormData: true, preserveScroll: true, only: ['fascicolo', 'passi', 'diario'] }));
        inertia.post.mock.calls[0][2].onSuccess();
        await flushPromises();
        expect(w!.emitted('update:open')).toEqual([[false]]);
    });

    test('un file che non è un PDF lo dice e non parte; l\'errore del server si legge nel corpo', async () => {
        await monta();
        const campo = qs('[data-testid="file-documento"]') as HTMLInputElement;
        Object.defineProperty(campo, 'files', { value: [new File(['x'], 'foto.jpg', { type: 'image/jpeg' })] });
        campo.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(testo(corpo())).toContain('«foto.jpg» non è un PDF: scegli un file PDF.');
        expect(aggiungi().disabled).toBe(true);

        w!.unmount();
        document.body.innerHTML = '';
        await monta({ scelti: [41] });
        aggiungi().click();
        await flushPromises();
        inertia.post.mock.calls.at(-1)![2].onError({ assemblea: 'Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.' });
        await flushPromises();
        expect(testo(qs('[data-testid="errore-finestra"]'))).toBe('Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.');
    });
});
