// @vitest-environment jsdom

/**
 * La finestra della ricevuta scrive, sotto «A che cosa va il versamento», la riga del criterio dell'imputazione che la ricevuta stampa
 * (1.12.0-beta.3, decisione 218, punto 4). La frase la compone il server (`RicevutaIncasso::fraseImputazione`); la finestra la mostra com'è,
 * e senza la riga (fuori ordine, incassi di prima, copie di prima) non scrive niente.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { csrf_token: 'gettone' } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const RicevutaDialog = (await import('./RicevutaDialog.vue')).default;

function opzioni(imputazione: string | null | undefined) {
    return {
        id: 5, numero: 'INC-2026-00005', data_versamento: '2026-02-01', importo: 15000, causale: null, compensazioni_escluse: [],
        quote: [{ rata: 'Rata 1', piano: 'Preventivo 2026', gestione: 'Ordinaria 2026', unita: 'Int. 4', importo: 15000, imputato: 15000, dal_credito: 0, dopo: 0 }],
        parte_in_piu: [], credito_usato: 0, sopra_soglia: false, annullato: false, inviabile: true, emettibile: true, motivo: null, persone_dell_incasso: [],
        senza_server_posta: false, copia: null, storno: null, posizione: { id: 1, nome: 'Anna Bruni' }, versato_da: null, destinatari: [], emissioni: [],
        bozza_email: { oggetto: 'Ricevuta', saluto: 'Buongiorno,', saluti: {}, righe: [], chiusura: 'Cordiali saluti' },
        scelta_ricordata: { bollo_sopra: null, bollo_sotto: null, modo_bollo: null, estremi_bollo: null, stampa_causale: null },
        ...(imputazione === undefined ? {} : { imputazione }),
    };
}

let finestra: VueWrapper | null = null;

async function apri(risposta: Record<string, unknown>) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(RicevutaDialog, { attachTo: document.body, props: { open: false, condominioId: 3, incassoId: 5 } });
    await finestra.setProps({ open: true });
    await flushPromises();
}

const riga = () => document.body.querySelector('[data-testid="criterio-imputazione"]')?.textContent?.trim() ?? null;

beforeEach(() => axiosGet.mockReset());
afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('la riga del criterio dell\'imputazione', () => {
    test('c\'è, com\'è, sotto il titolo delle quote', async () => {
        await apri(opzioni('Imputazione: indicata da chi ha versato: «Bonifico per la rata di febbraio».'));

        expect(riga()).toBe('Imputazione: indicata da chi ha versato: «Bonifico per la rata di febbraio».');
    });

    test('non c\'è per un incasso fuori ordine, di prima, o per la copia di un originale emesso prima', async () => {
        await apri(opzioni(null));
        expect(riga()).toBeNull();
        finestra?.unmount();
        document.body.innerHTML = '';

        await apri(opzioni(undefined));
        expect(riga()).toBeNull();
    });
});
