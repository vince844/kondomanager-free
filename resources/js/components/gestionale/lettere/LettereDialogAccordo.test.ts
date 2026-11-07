// @vitest-environment jsdom

/**
 * La finestra delle lettere con l'accordo di rientro: l'anteprima dice, per persona, quanto è in accordo e non è nella lettera, e chi ha solo
 * rate in accordo; «Metti nella lettera anche le rate in accordo di rientro» non è spuntata di serie, rifà l'anteprima e va nella richiesta.
 * Le cifre sono quelle di Giulia al 04/11/2026: € 750,00 nella lettera, € 994,10 in accordo.
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
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false },
        { tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true },
        { tipo: 'diffida', nome: 'Diffida', solo_carta: true, conservato: true },
        { tipo: 'messa_in_mora', nome: 'Messa in mora', solo_carta: true, conservato: true },
    ],
    testi: { promemoria: 'Testo del promemoria', sollecito: 'Testo del sollecito, [nome]', diffida: '', messa_in_mora: '' },
    conti: [{ id: 4, nome: 'Banca', iban: 'IT60X0542811101000000123456', intestatario: 'Condominio' }],
    scelta_ricordata: { termini: { sollecito: { forma: 'giorni', giorni: 15 } }, cassa_id: 4, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: true,
    ...extra,
});
const persona = (id: number, nome: string, canale = 'email') => ({
    id, nome, totale: 8000, righe: 2, credito: 0, canale, canali: ['email', 'stampa', 'zip'], indirizzi: [{ tipo: 'email', indirizzo: `${nome.toLowerCase()}@x.it` }],
    senza_email: false, solo_pec: false, senza_indirizzo_postale: false,
});
const anteprima = (extra: Record<string, unknown> = {}) => ({
    tipo: 'sollecito', alla: '2026-03-20', gestione: null, conteggi: { email: 2, stampa: 0, zip: 0 }, messaggi: 2, credito: [], senza_niente: [],
    studio_compilato: true, senza_server_posta: false, tetto_messaggi: null, tetto: 200, oltre_tetto: false,
    persone: [persona(2, 'Bice'), persona(1, 'Ugo')],
    ...extra,
});
const riepilogo = { persone: 2, messaggi: 2, inviate: 0, in_coda: 2, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null, aggiunte: 0, uscite: 0 };

let finestra: VueWrapper | null = null;
async function apri(props: Record<string, unknown> = {}, risposta = opzioni()) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: { fascia: '31_90' }, ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
async function conAnteprima(dati = anteprima(), props: Record<string, unknown> = {}) {
    await apri(props);
    axiosPost.mockResolvedValueOnce({ data: dati });
    (pulsante('anteprima') as HTMLButtonElement).click();
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const ultimaChiamata = () => axiosPost.mock.calls.at(-1) as [string, Record<string, any>];

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('l\'accordo di rientro nell\'anteprima', () => {
    test('quanto è in accordo, per persona; chi ha solo rate in accordo; la scelta di rimetterle, non spuntata di serie', async () => {
        await conAnteprima(anteprima({ persone: [{ ...persona(1, 'Giulia'), totale: 75000, righe: 1, in_accordo: 99410 }], solo_in_accordo: ['Marco'], anche_in_accordo: false }));
        expect(pulsante('in-accordo')?.textContent?.trim()).toBe('€ 994,10 in accordo non sono nella lettera.');
        expect(pulsante('solo-in-accordo')?.textContent?.trim()).toBe('Solo rate in accordo di rientro, non nella lettera: Marco.');
        // Nella prima richiesta non c'era la scelta.
        expect(ultimaChiamata()[1].anche_in_accordo).toBeUndefined();

        const scelta = pulsante('anche-in-accordo')!.querySelector('button[role="checkbox"]') as HTMLElement;
        expect(scelta.getAttribute('aria-checked')).toBe('false');
        scelta.click();
        await flushPromises();
        // L'anteprima di prima non vale più: si rifà, e la richiesta porta la scelta.
        expect(pulsante('anteprima-persone')).toBeNull();
        axiosPost.mockResolvedValueOnce({ data: anteprima({ persone: [{ ...persona(1, 'Giulia'), totale: 174410, righe: 3, in_accordo: 0 }], solo_in_accordo: [] }) });
        (pulsante('anteprima') as HTMLButtonElement).click();
        await flushPromises();
        expect(ultimaChiamata()[1].anche_in_accordo).toBe(1);
        expect(testo()).toContain('€ 1.744,10');
        expect(pulsante('in-accordo')).toBeNull();
    });

    test('senza accordi la scelta non c\'è', async () => {
        await conAnteprima();
        expect(pulsante('anche-in-accordo')).toBeNull();
        expect(pulsante('solo-in-accordo')).toBeNull();
    });
});
