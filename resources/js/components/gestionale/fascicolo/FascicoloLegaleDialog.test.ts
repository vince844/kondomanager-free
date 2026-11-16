// @vitest-environment jsdom

/**
 * La finestra del fascicolo per il legale: in testa «Che cosa metti nel fascicolo», tutto scelto di serie (gli esercizi con le loro rate, che si aprono con la
 * freccia; le parti); sotto «Che cosa c'è» e «Che cosa manca» con i collegamenti; la frase in fondo; «Scarica il fascicolo (.zip)», spento senza rate. L'anteprima
 * si legge una volta, all'apertura: le caselle nascondono nella pagina i pezzi, i fatti e le mancanze tolti, con i segni del server, senza un'altra richiesta.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Finestra = (await import('./FascicoloLegaleDialog.vue')).default;

const rata = (chiave: string, nome: string, scadenza: string, residuo: number) => ({ chiave, nome, unita: 'Interno A', scadenza, residuo });
const R25 = ['11|3', '12|3'];
const R26 = ['13|3', '14|3', '15|3'];
const TUTTE = [...R25, ...R26];
const impronta = (stato: string, breve: string, frase: string) => ({ stato, cifre: 'a1b2c3d4e5f6', breve, frase });

const anteprima = () => ({
    oggi: '2026-05-20',
    persona: { id: 7, nome: 'Rossi', unita: ['Interno A'] },
    condominio: 'Via Verdi 12',
    esercizi: [
        { chiave: 'esercizio-25', id: 25, nome: 'Esercizio 2025', data: '2026-06-30',
            rate: [rata(R25[0], 'Preventivo 2026, rata 1', '2025-11-05', 10000), rata(R25[1], 'Preventivo 2026, rata 2', '2025-12-05', 10000)] },
        { chiave: 'esercizio-26', id: 26, nome: 'Esercizio 2026', data: '2027-06-30',
            rate: [rata(R26[0], 'Preventivo 2026, rata 3', '2026-03-05', 10000), rata(R26[1], 'Preventivo 2026, rata 4', '2026-04-05', 10000),
                rata(R26[2], 'Preventivo 2026, rata 5', '2026-05-05', 10000)] },
    ],
    parti: ['lettere', 'estratti', 'rendiconto'],
    pezzi: [
        { tipo: 'indice', file: '00 Indice.pdf', cosa: 'L\'indice: che cosa c\'è e che cosa manca', data: '2026-05-20', impronta: null, parte: null, rate: null },
        { tipo: 'prospetto', file: '01 Rate non versate.pdf', cosa: 'Rate non versate', data: '2026-05-20', impronta: null, parte: null, rate: null },
        { tipo: 'estratto', file: '02 Estratto conto 2026.pdf', cosa: 'Estratto conto 2026', data: '2026-05-20', impronta: null, parte: 'estratti', rate: TUTTE },
        { tipo: 'lettera', file: '03 Diffida 2026-03-02 1015 r482.pdf', cosa: 'Diffida del 02/03/2026, spedita per raccomandata A/R il 02/03/2026', data: '2026-03-02',
            impronta: impronta('uguale', 'il file è quello conservato', 'Il file è quello conservato il 02/03/2026: l\'impronta è la stessa.'), parte: 'lettere', rate: null, invio_id: 482 },
        { tipo: 'rendiconto', file: '04 Rendiconto 2025 approvato.pdf', cosa: 'Rendiconto 2025, approvato il 20/04/2026 (verbale n. 3)', data: '2026-04-20',
            impronta: impronta('uguale', 'il file è quello conservato', 'Il file è quello conservato il 10/04/2026: l\'impronta è la stessa.'), parte: 'rendiconto', rate: R25 },
    ],
    fatti: [
        { frase: 'Piano «Preventivo 2026»: delibera del 12/12/2025 (verbale n. 4).', parte: null, rate: TUTTE },
        { frase: 'Il saldo dell\'estratto conto comprende anche le rate non ancora scadute e il credito: non è lo scaduto.', parte: 'estratti', rate: TUTTE },
        { frase: 'Esercizio 2026: non ancora finito, non ha un rendiconto.', parte: 'rendiconto', rate: R26 },
    ],
    mancanze: [
        { frase: 'La diffida del 02/03/2026 non ha una prova di spedizione: allegala dal registro delle lettere, nell\'estratto conto della persona.',
            azione: { etichetta: 'Allega la prova', url: '/estratto-conto?scheda=corrispondenza' }, parte: 'lettere', rate: null },
        { frase: 'Il rendiconto dell\'esercizio 2025 è approvato il 20/04/2026, ma il verbale non è caricato.', azione: null, parte: 'rendiconto', rate: R25 },
    ],
    ultimo: null,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const spunta = (testid: string) => document.body.querySelector(`[data-testid="${testid}"] button[role="checkbox"]`) as HTMLElement;
const scarica = () => document.body.querySelector('[data-testid="scarica-fascicolo"]') as HTMLButtonElement;

async function monta(dati = anteprima()) {
    axiosGet.mockResolvedValue({ data: dati });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persona: { id: 7, nome: 'Rossi' } } });
    await flushPromises();

    return w;
}

describe('la finestra del fascicolo per il legale', () => {
    test('il titolo, la persona, le due liste, i collegamenti, la frase in fondo', async () => {
        await monta();
        expect(axiosGet).toHaveBeenCalledTimes(1);
        expect(axiosGet.mock.calls[0][0]).toContain('anagrafiche.fascicolo-legale');
        const t = testo();
        expect(t).toContain('Fascicolo per il legale');
        expect(t).toContain('Rossi · Via Verdi 12');
        expect(t).toContain('Che cosa metti nel fascicolo');
        expect(t).toContain('Esercizio 2025 · 2 rate · € 200,00');
        expect(t).toContain('Esercizio 2026 · 3 rate · € 300,00');
        expect(t).toContain('Che cosa c\'è');
        expect(t).toContain('01 Rate non versate.pdf');
        expect(t).toContain('Rate non versate: 5 rate, € 500,00 al 20/05/2026');
        expect(t).toContain('03 Diffida 2026-03-02 1015 r482.pdf');
        expect(t).toContain('Diffida del 02/03/2026, spedita per raccomandata A/R il 02/03/2026 · il file è quello conservato');
        expect(t).toContain('Piano «Preventivo 2026»: delibera del 12/12/2025 (verbale n. 4).');
        expect(t).toContain('Che cosa manca');
        expect(t).toContain('Il rendiconto dell\'esercizio 2025 è approvato il 20/04/2026, ma il verbale non è caricato.');
        const allega = document.body.querySelector('[data-testid="mancanze"] a') as HTMLAnchorElement;
        expect(allega.textContent).toContain('Allega la prova');
        expect(allega.getAttribute('href')).toBe('/estratto-conto?scheda=corrispondenza');
        expect(t).toContain('Questo indice dice che cosa il programma conserva per questa persona e che cosa non trova. Se basta per agire lo valuta il legale.');
        expect(t).toContain('Le rate scadute in questo fascicolo, al 20/05/2026: € 500,00');
        expect(scarica().textContent).toContain('Scarica il fascicolo (.zip)');
        expect(scarica().disabled).toBe(false);
    });

    test('gli esercizi si aprono con la freccia sulle loro rate; togliere un esercizio toglie le sue rate, il suo rendiconto e la sua cifra, senza un\'altra lettura', async () => {
        await monta();
        expect(testo()).not.toContain('Preventivo 2026, rata 1 · scaduta il 05/11/2025 · € 100,00');
        (document.body.querySelector('[data-testid="apri-esercizio-25"]') as HTMLElement).click();
        await flushPromises();
        expect(testo()).toContain('Preventivo 2026, rata 1 · scaduta il 05/11/2025 · € 100,00');

        spunta('esercizio-esercizio-25').click();
        await flushPromises();
        const t = testo();
        expect(t).toContain('Le rate scadute in questo fascicolo, al 20/05/2026: € 300,00');
        expect(t).toContain('Rate non versate: 3 rate, € 300,00 al 20/05/2026');
        expect(t).not.toContain('04 Rendiconto 2025 approvato.pdf');
        expect(t).not.toContain('ma il verbale non è caricato');
        expect(spunta('rata-11|3').getAttribute('data-state')).toBe('unchecked');
        expect(axiosGet).toHaveBeenCalledTimes(1);
    });

    test('togliere tutte le rate di un esercizio toglie l\'esercizio; una sola lo lascia a metà', async () => {
        await monta();
        (document.body.querySelector('[data-testid="apri-esercizio-25"]') as HTMLElement).click();
        await flushPromises();
        spunta('rata-11|3').click();
        await flushPromises();
        expect(spunta('esercizio-esercizio-25').getAttribute('data-state')).toBe('indeterminate');
        expect(testo()).toContain('Esercizio 2025 · 1 rata su 2 · € 100,00');
        spunta('rata-12|3').click();
        await flushPromises();
        expect(spunta('esercizio-esercizio-25').getAttribute('data-state')).toBe('unchecked');
        expect(testo()).not.toContain('04 Rendiconto 2025 approvato.pdf');
    });

    test('togliere una parte toglie i suoi pezzi, i suoi fatti e le sue mancanze', async () => {
        await monta();
        spunta('parte-lettere').click();
        spunta('parte-estratti').click();
        await flushPromises();
        const t = testo();
        expect(t).not.toContain('03 Diffida 2026-03-02 1015 r482.pdf');
        expect(t).not.toContain('non ha una prova di spedizione');
        expect(t).not.toContain('02 Estratto conto 2026.pdf');
        expect(t).not.toContain('Il saldo dell\'estratto conto');
        expect(t).toContain('04 Rendiconto 2025 approvato.pdf');
        expect(axiosGet).toHaveBeenCalledTimes(1);
    });

    test('senza rate il pulsante è spento e dice perché; con le rate manda le rate e le parti scelte, scarica l\'archivio e lo dice', async () => {
        await monta();
        spunta('esercizio-esercizio-25').click();
        spunta('esercizio-esercizio-26').click();
        await flushPromises();
        expect(scarica().disabled).toBe(true);
        expect(testo()).toContain('Scegli almeno una rata.');

        spunta('esercizio-esercizio-26').click();
        spunta('parte-rendiconto').click();
        await flushPromises();
        const blob = new Blob(['PK'], { type: 'application/zip' });
        axiosPost.mockResolvedValue({ data: blob, headers: { 'content-type': 'application/zip', 'content-disposition': 'attachment; filename="fascicolo.zip"' } });
        (globalThis as unknown as { URL: { createObjectURL: unknown; revokeObjectURL: unknown } }).URL.createObjectURL = vi.fn(() => 'blob:x');
        (globalThis as unknown as { URL: { createObjectURL: unknown; revokeObjectURL: unknown } }).URL.revokeObjectURL = vi.fn();
        scarica().click();
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(axiosPost.mock.calls[0][0]).toContain('anagrafiche.fascicolo-legale.prepara');
        expect(axiosPost.mock.calls[0][1]).toEqual({ rate: R26, parti: ['lettere', 'estratti'] });
        expect(axiosPost.mock.calls[0][2]).toEqual({ responseType: 'blob' });
        expect(testo()).toContain('Fascicolo preparato: resta scritto nel registro della persona.');
        expect(w!.emitted('fatto')).toHaveLength(1);
    });

    test('l\'ultimo fascicolo preparato, se c\'è', async () => {
        await monta({ ...anteprima(), ultimo: { giorno: '2026-05-12', utente: 'Vincenzo' } });
        expect(testo()).toContain('L\'ultimo fascicolo è del 12/05/2026 (Vincenzo).');
    });

    test('le parole: nessuna parola vietata nel testo a video, e mai che il fascicolo basta per agire', async () => {
        await monta();
        (document.body.querySelector('[data-testid="apri-esercizio-25"]') as HTMLElement).click();
        await flushPromises();
        const t = testo().toLowerCase().replace('se basta per agire lo valuta il legale.', ' ');
        const vietate = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'ingiungibil', 'ingiunzion', 'decadut', 'termine scaduto', 'consegnata',
            'notificata', 'valore legale', 'transazione', 'errore', 'in ritardo', 'deve agire contro', 'basta per agire', 'passo'];
        expect(vietate.filter((p) => t.includes(p))).toEqual([]);
    });

    test('il sorgente: niente scritte sotto i 12 px, niente <select>, badge e non pillole', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/fascicolo/FascicoloLegaleDialog.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(9|10|11)px\]/);
        expect(sorgente).not.toContain('<select');
        expect(sorgente).not.toContain('rounded-full');
        expect(sorgente).toContain('Collapsible');
    });
});
