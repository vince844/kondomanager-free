// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra delle lettere con la PEC, i casi che contano (1.12.0-beta.13):
 *
 * - il badge dice «PEC non comunicata dalla persona», neutro; la «i» è un pulsante che prende il fuoco e, toccato, apre la spiegazione; al telefono la card
 *   ha il badge con la «i» e «Apri la scheda»;
 * - chi ha già oggi la PEC dello stesso tipo partita: la riga lo dice, e «Manda N PEC» non la conta;
 * - la conferma dice quante partono adesso, con il tetto per volta, e il pulsante della conferma manda quelle;
 * - la pagina che non ha avuto la risposta dice di guardare la corrispondenza prima di rimandare;
 * - il menu del canale si apre sopra la finestra (`append-to-body`), anche per le ultime righe.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;

const CASELLA = { impostata: true, indirizzo: 'studio.costa@pec.it', perche: null, dove: null, avviso: null };
const opzioni = () => ({
    tipi: [
        { tipo: 'sollecito', nome: 'Sollecito', senza_email: false, conservato: true, canali: ['email', 'pec', 'pec_e_carta', 'stampa', 'zip'] },
        { tipo: 'diffida', nome: 'Diffida', senza_email: true, conservato: true, canali: ['pec', 'pec_e_carta', 'stampa', 'zip'] },
    ],
    testi: { promemoria: 'P', sollecito: 'S', diffida: 'Diffida a [nome].', messa_in_mora: 'M' },
    conti: [], scelta_ricordata: { termini: { sollecito: null }, cassa_id: null, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: false, casella_pec: CASELLA,
});
const persona = (id: number, nome: string, canale: string, altro: Record<string, unknown> = {}) => ({
    id, nome, totale: 64000, righe: 2, credito: 0, canale, canali: ['pec', 'pec_e_carta', 'stampa', 'zip'], canali_spenti: [], indirizzi: [], senza_email: true,
    solo_pec: false, frase_solo_pec: null, pec: `p${id}@pec.it`, pec_comunicata_il: '2026-03-12', pec_non_comunicata: false, pec_partita_oggi: null,
    scheda: `/anagrafiche/${id}/edit`, senza_indirizzo_postale: false, ...altro,
});
const anteprima = (persone: unknown[], tetto = 20) => ({
    tipo: 'diffida', alla: '2026-12-03', gestione: null, conteggi: {}, messaggi: 0, credito: [], senza_niente: [], studio_compilato: true, senza_server_posta: false,
    tetto_messaggi: null, tetto_pec: tetto, tetto: 200, oltre_tetto: false, casella_pec: CASELLA, persone,
});

let finestra: VueWrapper | null = null;
async function conAnteprima(persone: unknown[], tetto = 20) {
    axiosGet.mockResolvedValue({ data: opzioni() });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-12-03', selezione: { tutte: true, ids: [] }, filtri: {},
        tipoIniziale: 'diffida' } });
    await finestra.setProps({ open: true });
    await flushPromises();
    axiosPost.mockResolvedValueOnce({ data: anteprima(persone, tetto) });
    (pulsante('anteprima') as HTMLButtonElement).click();
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('la PEC non comunicata', () => {
    test('il badge dice «dalla persona»; la «i» è un pulsante che prende il fuoco e apre la spiegazione; al telefono la card ha la «i» e la scheda', async () => {
        await conAnteprima([persona(1, 'Rossi Maria', 'pec', { pec_comunicata_il: null, pec_non_comunicata: true })]);
        expect(pulsante('pec-non-comunicata-1')?.textContent).toContain('PEC non comunicata dalla persona');
        expect(testo()).not.toContain('da lui');
        const i = pulsante('perche-pec-1') as HTMLButtonElement;
        expect(i.tagName).toBe('BUTTON');
        expect(i.getAttribute('aria-label')).toBe('Perché «PEC non comunicata dalla persona»');
        expect(pulsante('spiegazione-pec-1')).toBeNull();
        i.click();
        await flushPromises();
        expect(pulsante('spiegazione-pec-1')?.textContent).toContain('La PEC c\'è nella scheda, ma la persona non l\'ha comunicata. Esempio: scritta dallo studio da un vecchio contratto.');
        expect(pulsante('perche-pec-card-1')?.tagName).toBe('BUTTON');
        expect(pulsante('scheda-card-1')?.getAttribute('href')).toBe('/anagrafiche/1/edit');
    });
});

describe('«Manda N PEC»', () => {
    test('chi ha già oggi la PEC partita: la riga lo dice, e il pulsante non la conta', async () => {
        await conAnteprima([persona(1, 'Neri Carla', 'pec', { pec_partita_oggi: '11:00' }), persona(2, 'Neri Paolo', 'pec')]);
        expect(testo()).toContain('Già partita oggi alle 11:00: non parte di nuovo.');
        expect(pulsante('manda-pec')?.textContent).toContain('Manda 1 PEC');
    });

    test('oltre il tetto per volta, la conferma dice quante partono adesso, e il suo pulsante manda quelle', async () => {
        await conAnteprima(Array.from({ length: 23 }, (_, i) => persona(i + 1, `Persona ${i + 1}`, 'pec')));
        expect(pulsante('manda-pec')?.textContent).toContain('Manda 23 PEC');
        (pulsante('manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulsante('conferma-pec')?.textContent).toContain('Ne partono 20 adesso; le altre 3 con «Manda le altre», dopo.');
        expect(pulsante('conferma-manda-pec')?.textContent).toContain('Manda 20 PEC');
    });

    test('la pagina senza la risposta del programma: guarda la corrispondenza prima di rimandare', async () => {
        await conAnteprima([persona(1, 'Neri Carla', 'pec')]);
        (pulsante('manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        axiosPost.mockRejectedValueOnce(Object.assign(new Error('timeout of 0ms exceeded'), { response: { status: 504, data: '<html>Gateway Timeout</html>' } }));
        (pulsante('conferma-manda-pec') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulsante('errore')?.textContent).toContain('La pagina non ha avuto la risposta del programma: alcune PEC possono essere partite.');
    });

    test('il menu del canale si apre sopra la finestra, anche per le ultime righe', async () => {
        await conAnteprima([persona(1, 'Neri Carla', 'pec')]);
        const menu = finestra!.findAllComponents(vSelect).find((c) => c.attributes('data-testid') === 'canale-1')!;
        expect(menu.props('appendToBody')).toBe(true);
    });
});
