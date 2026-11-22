// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra delle lettere con la PEC dello studio (1.12.0-beta.13), con le risposte del server scritte a mano come le manda `EmissioneLettere::anteprima()`:
 * il canale per persona è un `v-select` con la PEC dello studio e «PEC e carta»; accanto la PEC e, se la persona non l'ha comunicata, il badge «PEC non
 * comunicata dalla persona» (neutro: l'anagrafica non ha il genere) con la scheda; sopra la tabella la riga delle PEC non comunicate e «Usa la PEC per chi
 * ce l'ha (N)»; senza la casella la voce è spenta,
 * con «Imposta la casella PEC»; «Manda N PEC» con la conferma che dice la casella; dopo, la frase, e la stampa delle copie di «PEC e carta» resta da fare.
 * Al telefono una card per persona.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vSelect from 'vue-select';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;

const CASELLA = { impostata: true, indirizzo: 'studio.costa@pec.it', perche: null, dove: null, avviso: null };
const opzioni = (casella = CASELLA) => ({
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', senza_email: false, conservato: false, canali: ['email', 'stampa', 'zip'] },
        { tipo: 'sollecito', nome: 'Sollecito', senza_email: false, conservato: true, canali: ['email', 'pec', 'pec_e_carta', 'stampa', 'zip'] },
        { tipo: 'diffida', nome: 'Diffida', senza_email: true, conservato: true, canali: ['pec', 'pec_e_carta', 'stampa', 'zip'] },
        { tipo: 'messa_in_mora', nome: 'Messa in mora', senza_email: true, conservato: true, canali: ['pec', 'pec_e_carta', 'stampa', 'zip'] },
    ],
    testi: { promemoria: 'P', sollecito: 'S', diffida: 'Diffida a [nome].', messa_in_mora: 'M' },
    conti: [], scelta_ricordata: { termini: { sollecito: null }, cassa_id: null, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: false, casella_pec: casella,
});
const CANALI = ['pec', 'pec_e_carta', 'stampa', 'zip'];
const persona = (id: number, nome: string, canale: string, altro: Record<string, unknown> = {}) => ({
    id, nome, totale: 64000, righe: 2, credito: 0, canale, canali: CANALI, canali_spenti: [], indirizzi: [], senza_email: true, solo_pec: false, frase_solo_pec: null,
    pec: `${nome.toLowerCase().replace(' ', '.')}@pec.it`, pec_comunicata_il: '2026-03-12', pec_non_comunicata: false, scheda: `/anagrafiche/${id}/edit`,
    senza_indirizzo_postale: false, ...altro,
});
const anteprima = (persone: unknown[], casella = CASELLA) => ({
    tipo: 'diffida', alla: '2026-12-03', gestione: null, conteggi: {}, messaggi: 0, credito: [], senza_niente: [], studio_compilato: true, senza_server_posta: false,
    tetto_messaggi: null, tetto_pec: 50, tetto: 200, oltre_tetto: false, casella_pec: casella, persone,
});
const tre = () => [
    persona(1, 'Neri Carla', 'pec'),
    persona(2, 'Neri Paolo', 'pec'),
    persona(3, 'Rossi Mario', 'stampa', { pec_comunicata_il: null, pec_non_comunicata: true }),
];

let finestra: VueWrapper | null = null;
async function conAnteprima(persone = tre(), casella = CASELLA) {
    axiosGet.mockResolvedValue({ data: opzioni(casella) });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-12-03', selezione: { tutte: true, ids: [] }, filtri: {},
        tipoIniziale: 'diffida' } });
    await finestra.setProps({ open: true });
    await flushPromises();
    axiosPost.mockResolvedValueOnce({ data: anteprima(persone, casella) });
    (pulsante('anteprima') as HTMLButtonElement).click();
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const menuDelCanale = (id: number) => finestra!.findAllComponents(vSelect).find((c) => c.attributes('data-testid') === `canale-${id}`)!;
async function scegliCanale(id: number, canale: string) {
    menuDelCanale(id).vm.$emit('update:modelValue', canale);
    await flushPromises();
}

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('il canale per persona', () => {
    test('è un v-select con la PEC dello studio e «PEC e carta»; il menu del browser non c\'è più', async () => {
        await conAnteprima();
        const voci = (menuDelCanale(1).props('options') as Array<{ nome: string }>).map((o) => o.nome);
        expect(voci).toEqual(['PEC dello studio', 'PEC e carta', 'Stampa per la posta', 'PDF per persona (.zip)']);
        expect(document.body.querySelector('select')).toBeNull();
        expect(testo()).toContain('neri.carla@pec.it');
    });

    test('la PEC non comunicata: il badge accanto quando la si sceglie, con la scheda; la riga sopra la tabella', async () => {
        await conAnteprima();
        expect(pulsante('pec-non-comunicata-3')).toBeNull();
        await scegliCanale(3, 'pec');
        const badge = pulsante('pec-non-comunicata-3')!;
        expect(badge.textContent).toContain('PEC non comunicata dalla persona');
        expect(badge.className).toContain('rounded-md');
        expect(document.body.querySelector('[data-testid="scheda-3"]')?.getAttribute('href')).toBe('/anagrafiche/3/edit');
        expect(testo()).toContain('1 PEC non risulta comunicata dalla persona');
    });

    test('«Usa la PEC per chi ce l\'ha (N)» mette la PEC a chi ce l\'ha e non è già per PEC', async () => {
        await conAnteprima();
        expect(pulsante('usa-la-pec')?.textContent).toContain('Usa la PEC per chi ce l\'ha (1)');
        (pulsante('usa-la-pec') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulsante('manda-pec')?.textContent).toContain('Manda 3 PEC');
    });

    test('senza la casella PEC dello studio: la voce è spenta con il perché, e «Imposta la casella PEC» a chi apre le impostazioni', async () => {
        const senza = { impostata: false, indirizzo: null, perche: 'Prima imposta la casella PEC dello studio.', dove: '/impostazioni/mail?apri=pec', avviso: null };
        const spenti = { pec: 'Prima imposta la casella PEC dello studio.', pec_e_carta: 'Prima imposta la casella PEC dello studio.' };
        await conAnteprima([persona(1, 'Ferri Luca', 'stampa', { canali_spenti: spenti, solo_pec: true,
            frase_solo_pec: 'Ha solo la PEC: imposta la casella PEC dello studio, o stampala.' })], senza);
        const voci = menuDelCanale(1).props('options') as Array<{ valore: string; spento: boolean; sotto: string }>;
        expect(voci.find((v) => v.valore === 'pec')).toMatchObject({ spento: true, sotto: 'Prima imposta la casella PEC dello studio.' });
        expect((menuDelCanale(1).props('selectable') as (o: unknown) => boolean)(voci[0])).toBe(false);
        expect(testo()).toContain('Ha solo la PEC: imposta la casella PEC dello studio, o stampala.');
        expect(document.body.querySelector('[data-testid="imposta-casella-pec"]')?.getAttribute('href')).toBe('/impostazioni/mail?apri=pec');
        expect(pulsante('manda-pec')).toBeNull();
        expect(pulsante('usa-la-pec')).toBeNull();
    });
});

describe('«Manda N PEC»', () => {
    test('la conferma dice la casella; poi la richiesta porta i canali, e la frase dice le partite', async () => {
        await conAnteprima();
        expect(pulsante('manda-pec')?.textContent).toContain('Manda 2 PEC');
        expect(pulsante('stampa')?.textContent).toContain('Stampa 1 lettera');
        (pulsante('manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        const conferma = pulsante('conferma-pec')!;
        expect(conferma.textContent).toContain('Mandare 2 diffide per PEC?');
        expect(conferma.textContent).toContain('studio.costa@pec.it');
        axiosPost.mockResolvedValueOnce({ data: { partite: 2, non_partite: 0, non_provate: 0, errore: null, restanti: null, aggiunte: 0, uscite: 0,
            frase: '2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.' } });
        (pulsante('conferma-manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        const [indirizzo, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
        expect(indirizzo).toBe('/gestionale.lettere.pec');
        expect(corpo).toMatchObject({ tipo: 'diffida', viste: [1, 2, 3], canali: { 1: 'pec', 2: 'pec', 3: 'stampa' } });
        expect(testo()).toContain('2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.');
        expect(testo()).toContain('Le trovi nella corrispondenza di ognuno, nella pagina del suo estratto conto.');
        // La PEC è fatta; la stampa di Rossi Mario resta da fare.
        expect((pulsante('manda-pec') as HTMLButtonElement).disabled).toBe(true);
        expect((pulsante('stampa') as HTMLButtonElement).disabled).toBe(false);
    });

    test('«PEC e carta» conta fra le PEC e fra le lettere da stampare; oltre il tetto, «Manda le altre»', async () => {
        await conAnteprima([persona(1, 'Neri Carla', 'pec_e_carta'), persona(2, 'Neri Paolo', 'pec')]);
        expect(pulsante('manda-pec')?.textContent).toContain('Manda 2 PEC');
        expect(pulsante('stampa')?.textContent).toContain('Stampa 1 lettera');
        (pulsante('manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { partite: 1, non_partite: 0, non_provate: 0, errore: null, restanti: { persone: [2] }, aggiunte: 0, uscite: 0,
            frase: '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.' } });
        (pulsante('conferma-manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('Ne resta 1 da mandare per PEC: premi «Manda le altre».');
        axiosPost.mockResolvedValueOnce({ data: { partite: 1, non_partite: 0, non_provate: 0, errore: null, restanti: null, aggiunte: 0, uscite: 0, frase: 'x' } });
        (pulsante('manda-le-altre') as HTMLButtonElement).click();
        await flushPromises();
        expect((axiosPost.mock.calls.at(-1) as [string, Record<string, any>])[1].viste).toEqual([2]);
    });
});

describe('al telefono e gli standard', () => {
    test('una card per persona, con il canale sotto il nome; nessuna scritta sotto i 12 pixel e niente v-html', async () => {
        await conAnteprima();
        expect(document.body.querySelectorAll('[data-testid="card-persona"]').length).toBe(3);
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/lettere/LettereDialog.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(9|10|11)px\]/);
        expect(sorgente).not.toContain('v-html');
    });
});
