// @vitest-environment jsdom

/**
 * La finestra delle lettere: il conto ricordato che oggi non c'è più fra i conti non si manda (con un conto solo la finestra prende quello di
 * oggi); chi ha un credito che copre la lettera si toglie in un clic, per cento come per una; quando le lettere sono più del tetto per volta, la
 * finestra dice quante restano e come mandarle.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;

const opzioni = (extra: Record<string, unknown> = {}) => ({
    tipi: [{ tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true }, { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false }],
    testi: { promemoria: 'Testo', sollecito: 'Testo del sollecito, [nome]', diffida: '', messa_in_mora: '' },
    conti: [{ id: 2, nome: 'Banca nuova', iban: 'IT02A0301503200000003517230', intestatario: 'Condominio' }],
    scelta_ricordata: { termini: { sollecito: { forma: 'giorni', giorni: 15 } }, cassa_id: 1, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: true,
    ...extra,
});
const persona = (id: number, nome: string, totale = 8000, credito = 0) => ({
    id, nome, totale, righe: 2, credito, canale: 'email', canali: ['email', 'stampa', 'zip'], indirizzi: [{ tipo: 'email', indirizzo: `${nome.toLowerCase()}@x.it` }],
    senza_email: false, solo_pec: false, senza_indirizzo_postale: false,
});
const anteprima = (persone: unknown[]) => ({
    tipo: 'sollecito', alla: '2026-03-20', gestione: null, conteggi: { email: persone.length, stampa: 0, zip: 0 }, messaggi: persone.length, credito: [], senza_niente: [],
    studio_compilato: true, senza_server_posta: false, tetto_messaggi: null, tetto: 200, oltre_tetto: false, persone,
});

let finestra: VueWrapper | null = null;
async function apri(risposta = opzioni()) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: {} } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
async function conAnteprima(persone: unknown[]) {
    await apri();
    axiosPost.mockResolvedValueOnce({ data: anteprima(persone) });
    (pulsante('anteprima') as HTMLButtonElement).click();
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const ultimaChiamata = () => axiosPost.mock.calls.at(-1) as [string, Record<string, any>];

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; });

describe('il conto ricordato', () => {
    test('il conto ricordato che non è fra i conti di oggi non si manda: con un conto solo la finestra prende quello', async () => {
        await apri();
        expect(testo()).toContain('IBAN IT02A0301503200000003517230');
        axiosPost.mockResolvedValueOnce({ data: anteprima([persona(1, 'Ugo')]) });
        (pulsante('anteprima') as HTMLButtonElement).click();
        await flushPromises();
        expect(ultimaChiamata()[1].cassa_id).toBe(2);
    });
});

describe('chi ha un credito che copre la lettera', () => {
    test('«Togli chi ha un credito che copre la lettera» toglie in un clic chi ha il credito di almeno il totale, e lascia gli altri', async () => {
        await conAnteprima([persona(1, 'Ugo', 8000, 0), persona(2, 'Bice', 8000, 8000), persona(3, 'Carla', 8000, 2000)]);
        expect(testo()).toContain('Togli chi ha un credito che copre la lettera (1)');
        (pulsante('togli-coperti') as HTMLButtonElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { persone: 2, messaggi: 2, inviate: 0, in_coda: 2, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null } });
        (pulsante('invia') as HTMLButtonElement).click();
        await flushPromises();
        expect(ultimaChiamata()[1].viste).toEqual([1, 3]);
    });

    test('senza nessuno coperto dal credito il pulsante non c\'è', async () => {
        await conAnteprima([persona(1, 'Ugo', 8000, 2000)]);
        expect(pulsante('togli-coperti')).toBeNull();
    });
});

describe('oltre il tetto per volta', () => {
    test('l\'esito dice quante lettere restano e come mandarle', async () => {
        await conAnteprima([persona(1, 'Ugo'), persona(2, 'Bice')]);
        axiosPost.mockResolvedValueOnce({ data: { persone: 1, messaggi: 1, inviate: 0, in_coda: 1, non_partite: 0, errori: [], senza_indirizzo: [], restanti: { persone: [2], messaggi: 1 } } });
        (pulsante('invia') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('Ne resta 1 da mandare: premi «Invia le altre».');
    });
});
