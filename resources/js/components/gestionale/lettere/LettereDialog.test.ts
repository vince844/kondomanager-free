// @vitest-environment jsdom

/**
 * La finestra delle lettere, montata con le risposte del server scritte a mano come le mandano `LettereController::opzioni()` e `anteprima()`:
 * il tipo di serie (sollecito dalle rate scadute, promemoria dalla vista «In scadenza») e mai ricordato; il termine e il conto ricordati; il
 * testo di partenza del programma; «Salva come modello…» solo a chi gestisce le impostazioni, nel blocco e nella lettera di una
 * persona; l'anteprima con il canale per persona e «togli»; l'invio che porta le persone viste e il canale di ognuna; la diffida senza
 * l'email.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;
const { SOLO_CARTA, FRASE_TESTO_SINGOLO } = await import('@/lib/gestionale/lettere/lettere');

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
    studio_compilato: false, senza_server_posta: false, puo_salvare_il_testo: true,
    ...extra,
});
const anteprima = {
    tipo: 'sollecito', alla: '2026-03-20', conteggi: { email: 2, stampa: 0, zip: 0 }, messaggi: 2, credito: [{ nome: 'Bice', credito: 2000 }], senza_niente: [],
    studio_compilato: false, senza_server_posta: false, tetto_messaggi: null, tetto: 200, oltre_tetto: false,
    persone: [
        { id: 2, nome: 'Bice', totale: 8000, righe: 2, credito: 2000, canale: 'email', canali: ['email', 'stampa', 'zip'], indirizzi: [{ tipo: 'email', indirizzo: 'bice@x.it' }], senza_email: false, solo_pec: false, senza_indirizzo_postale: false },
        { id: 1, nome: 'Ugo', totale: 18000, righe: 3, credito: 0, canale: 'email', canali: ['email', 'stampa', 'zip'], indirizzi: [{ tipo: 'email', indirizzo: 'ugo@x.it' }], senza_email: false, solo_pec: false, senza_indirizzo_postale: true },
    ],
};

let finestra: VueWrapper | null = null;
async function apri(props: Record<string, unknown> = {}, risposta = opzioni()) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: { fascia: '31_90' }, ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const tipoScelto = () => (document.body.querySelector('input[name="tipo-lettera"]:checked') as HTMLInputElement | null)?.value;

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('lo studio', () => {
    test('senza il nome dello studio la finestra dice che la lettera porta il solo condominio; con la carta intestata delle lettere dice la carta', async () => {
        const { FRASE_STUDIO_NON_COMPILATO, FRASE_STUDIO_SULLA_CARTA } = await import('@/lib/gestionale/lettere/lettere');
        await apri();
        expect(pulsante('studio-non-compilato')?.textContent?.trim()).toBe(FRASE_STUDIO_NON_COMPILATO);
        finestra?.unmount();
        document.body.innerHTML = '';
        await apri({}, opzioni({ carta_intestata: true }));
        expect(pulsante('studio-non-compilato')?.textContent?.trim()).toBe(FRASE_STUDIO_SULLA_CARTA);
        expect(testo()).not.toContain('la lettera porta il solo condominio');
    });
});

describe('il tipo, il testo e le scelte ricordate', () => {
    test('dalle rate scadute il tipo di serie è il sollecito, dalla vista «In scadenza» il promemoria; il termine e il conto sono quelli dell\'ultima volta', async () => {
        await apri();
        expect(tipoScelto()).toBe('sollecito');
        expect((pulsante('testo') as HTMLTextAreaElement).value).toBe('Testo del sollecito, [nome]');
        expect((pulsante('termine') as HTMLInputElement).value).toBe('15');
        expect(testo()).toContain('IBAN IT60X0542811101000000123456');
        finestra?.unmount();
        document.body.innerHTML = '';
        await apri({ vista: 'in_scadenza' });
        expect(tipoScelto()).toBe('promemoria');
        expect((pulsante('testo') as HTMLTextAreaElement).value).toBe('Testo del promemoria');
    });

    test('«Salva come modello…» c\'è per chi gestisce le impostazioni, nel blocco e nella lettera di una persona, e salva il testo a video con il nome e «vale per»', async () => {
        await apri();
        const nuovo = { id: 9, tipo: 'sollecito', nome: 'Il mio', testo: 'Nuovo', per_tutti: true };
        axiosPost.mockResolvedValue({ status: 201, data: { modello: nuovo, modelli: [nuovo] } });
        const area = pulsante('testo') as HTMLTextAreaElement;
        area.value = 'Nuovo';
        area.dispatchEvent(new Event('input'));
        await flushPromises();
        (pulsante('salva-modello') as HTMLButtonElement).click();
        await flushPromises();
        const nome = pulsante('nome-modello') as HTMLInputElement;
        nome.value = 'Il mio';
        nome.dispatchEvent(new Event('input'));
        (document.body.querySelector('input[name="vale-per"][value="tutti"]') as HTMLInputElement).click();
        await flushPromises();
        (pulsante('conferma-modello') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledWith('/gestionale.lettere.modelli.store', { tipo: 'sollecito', nome: 'Il mio', testo: 'Nuovo', per_tutti: true });
        finestra?.unmount();
        document.body.innerHTML = '';

        await apri({ persona: { id: 1, nome: 'Ugo' }, selezione: null });
        expect(pulsante('salva-modello')).not.toBeNull();
        expect(testo()).toContain(FRASE_TESTO_SINGOLO);
        finestra?.unmount();
        document.body.innerHTML = '';
        await apri({}, opzioni({ puo_salvare_il_testo: false }));
        expect(pulsante('salva-modello')).toBeNull();
    });

    test('la diffida non ha l\'email: la finestra lo dice e non offre «Invia»', async () => {
        await apri();
        (document.body.querySelector('input[value="diffida"]') as HTMLInputElement).click();
        await flushPromises();
        expect(testo()).toContain(SOLO_CARTA);
        axiosPost.mockResolvedValue({ data: { ...anteprima, tipo: 'diffida', persone: anteprima.persone.map((p) => ({ ...p, canale: 'stampa', canali: ['stampa', 'zip'] })) } });
        (pulsante('anteprima') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulsante('invia')).toBeNull();
        expect(pulsante('stampa')?.textContent).toContain('Stampa 2 lettere');
        expect(document.body.querySelector('[data-testid="canale-1"]')?.textContent).not.toContain('Email');
    });
});

describe('l\'anteprima e l\'invio', () => {
    test('l\'anteprima mostra il credito accanto e chi non ha l\'indirizzo di casa; l\'invio porta le persone viste e il canale di ognuna', async () => {
        await apri();
        axiosPost.mockResolvedValueOnce({ data: anteprima });
        (pulsante('anteprima') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('Bice ha un credito di € 20,00: puoi usarlo prima di scrivere.');
        expect(testo()).toContain('senza indirizzo di casa: in alto c\'è il solo nome, senza indirizzo');
        expect(pulsante('invia')?.textContent).toContain('Invia 2 email');

        // Ugo si stampa: l'email va solo a Bice. Bice resta nell'invio; poi si toglie Ugo dalla stampa.
        const select = document.body.querySelector('[data-testid="canale-1"]') as HTMLSelectElement;
        select.value = 'stampa';
        select.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(pulsante('invia')?.textContent).toContain('Invia 1 email');
        expect(pulsante('stampa')?.textContent).toContain('Stampa 1 lettera');

        axiosPost.mockResolvedValueOnce({ data: { persone: 1, messaggi: 1, inviate: 0, in_coda: 1, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null, aggiunte: 1, uscite: 0 } });
        (pulsante('invia') as HTMLButtonElement).click();
        await flushPromises();
        const [indirizzo, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
        expect(indirizzo).toBe('/gestionale.lettere.invia');
        expect(corpo).toMatchObject({ tipo: 'sollecito', tutte: 1, filtri: { fascia: '31_90' }, viste: [2, 1], canali: { 1: 'stampa', 2: 'email' }, termine: '15', cassa_id: 4 });
        expect(testo()).toContain('In coda 1.');
        expect(testo()).toContain('1 persona è entrata nell\'elenco dopo l\'anteprima');
    });

    test('togliere una persona la toglie dalle persone viste', async () => {
        await apri();
        axiosPost.mockResolvedValueOnce({ data: anteprima });
        (pulsante('anteprima') as HTMLButtonElement).click();
        await flushPromises();
        (document.body.querySelector('[aria-label="Togli Ugo"]') as HTMLElement).click();
        await flushPromises();
        expect(pulsante('invia')?.textContent).toContain('Invia 1 email');
        axiosPost.mockResolvedValueOnce({ data: { indirizzo: 'studio@x.it', persona: 'Bice' } });
        (pulsante('prova') as HTMLButtonElement).click();
        await flushPromises();
        const [indirizzo, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
        expect(indirizzo).toBe('/gestionale.lettere.prova');
        expect(corpo.viste).toEqual([2]);
        expect(testo()).toContain('Mandata a studio@x.it: è la lettera di Bice, con «PROVA» in testa.');
    });
});
