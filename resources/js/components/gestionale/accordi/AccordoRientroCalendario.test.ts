// @vitest-environment jsdom

/**
 * La finestra dell'accordo di rientro con il calendario: la proposta dal numero delle rate e dal giorno della prima (€ 225,92 in tre: 75,31 + 75,31 +
 * 75,30, il 31/07, il 31/08, il 30/09), la somma che non torna che tiene spento «Registra l'accordo», la regola dell'ultimo accordo del condominio già
 * proposta, la richiesta con il calendario e la regola; un accordo con le rate versate o no e i segnali con il giorno; «Registra la decadenza» con la
 * data e la nota, che il programma non fa da sé.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Finestra = (await import('./AccordoRientroDialog.vue')).default;

const possibili = [
    { rata_id: 1, immobile_id: 1, numero_rata: 1, piano: 'Preventivo 2026', unita: 'U1', scadenza: '2026-03-31', residuo: 20000, causale: null },
    { rata_id: 11, immobile_id: 1, numero_rata: 1, piano: 'Addebiti individuali 2026', unita: 'U1', scadenza: '2026-06-29', residuo: 92, causale: 'Interessi di mora al 29/06/2026' },
    { rata_id: 12, immobile_id: 1, numero_rata: 2, piano: 'Addebiti individuali 2026', unita: 'U1', scadenza: '2026-06-29', residuo: 2500, causale: 'Spese di sollecito' },
];
const risposta = (extra: Record<string, unknown> = {}) => ({ data: { persona: { id: 5, nome: 'Neri' }, data: '2026-07-15', oggi: '2026-07-15', rate: possibili, accordi: [],
    regola_di_serie: { rate: 2, giorni: 15 }, ...extra } });

let w: VueWrapper | null = null;
const monta = (oggi = '2026-07-15') => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persona: { id: 5, nome: 'Neri' }, oggi } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const corpo = () => document.body;
const scrivi = async (sel: string, valore: string) => {
    const el = corpo().querySelector<HTMLInputElement>(sel)!;
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
};

describe('il calendario nella finestra dell\'accordo', () => {
    test('la proposta: 75,31 + 75,31 + 75,30 ogni mese dal 31/07; una somma che non torna tiene spento «Registra»; la richiesta porta calendario e regola', async () => {
        axiosGet.mockResolvedValue(risposta());
        monta();
        await flushPromises();
        // I nomi degli addebiti sono le loro causali.
        expect(pulito(corpo().querySelectorAll('[data-testid="rata-possibile"]')[1].textContent)).toContain('U1, Interessi di mora al 29/06/2026');
        // La regola dell'ultimo accordo del condominio, già proposta.
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="decadenza-rate"]')!.value).toBe('2');
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="decadenza-giorni"]')!.value).toBe('15');
        expect(pulito(corpo().querySelector('[data-testid="regola-di-serie"]')?.textContent)).toBe('Proposta la regola dell\'ultimo accordo di questo condominio.');

        corpo().querySelectorAll<HTMLElement>('[data-testid="rata-possibile"] button[role="checkbox"]').forEach((b) => b.click());
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="calendario"]')?.textContent)).toContain('Il debito riconosciuto: € 225,92');
        await scrivi('[data-testid="numero-rate"]', '3');
        await scrivi('[data-testid="prima-rata"]', '2026-07-31');
        const righe = Array.from(corpo().querySelectorAll('[data-testid="rata-del-calendario"]'))
            .map((r) => Array.from(r.querySelectorAll('input')).map((i) => (i as HTMLInputElement).value));
        expect(righe).toEqual([['2026-07-31', '75,31'], ['2026-08-31', '75,31'], ['2026-09-30', '75,30']]);
        expect(pulito(corpo().querySelector('[data-testid="fino-al"]')?.textContent)).toBe('Fino al 30/09/2026: l\'ultima rata del calendario.');

        // Un centesimo in meno sulla seconda: la frase con le due cifre, e «Registra l'accordo» spento.
        corpo().querySelector<HTMLInputElement>('input[name="autorizzato"][value="0"]')!.click();
        await flushPromises();
        const importo2 = corpo().querySelectorAll<HTMLInputElement>('[data-testid="rata-del-calendario"] input')[3];
        importo2.value = '75,30';
        importo2.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="controllo-calendario"]')?.textContent))
            .toBe('La somma delle rate del calendario è € 225,91 e il debito riconosciuto è € 225,92: correggi una rata, la differenza è € 0,01.');
        const registra = corpo().querySelector<HTMLButtonElement>('[data-testid="registra-accordo"]')!;
        expect(registra.disabled).toBe(true);

        importo2.value = '75,31';
        importo2.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(corpo().querySelector('[data-testid="controllo-calendario"]')).toBeNull();
        expect(registra.disabled).toBe(false);

        axiosPost.mockResolvedValueOnce({ data: { id: 9, messaggio: 'Accordo registrato.' } });
        registra.click();
        await flushPromises();
        const dati = axiosPost.mock.calls[0][1] as FormData;
        expect([dati.get('scadenze[0][data]'), dati.get('scadenze[0][importo]'), dati.get('scadenze[2][data]'), dati.get('scadenze[2][importo]')])
            .toEqual(['2026-07-31', '75,31', '2026-09-30', '75,30']);
        expect([dati.get('decadenza_rate'), dati.get('decadenza_giorni'), dati.get('fino_al')]).toEqual(['2', '15', null]);
        expect(dati.get('rate[1][importo]')).toBe('0,92');
    });

    test('una regola vuota non parte; i giorni fuori ordine lo dicono', async () => {
        axiosGet.mockResolvedValue(risposta({ regola_di_serie: null }));
        monta();
        await flushPromises();
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="decadenza-rate"]')!.value).toBe('');
        expect(corpo().querySelector('[data-testid="regola-di-serie"]')).toBeNull();
        (corpo().querySelector('[data-testid="rata-possibile"] button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        await scrivi('[data-testid="numero-rate"]', '2');
        await scrivi('[data-testid="prima-rata"]', '2026-07-31');
        const data2 = corpo().querySelectorAll<HTMLInputElement>('[data-testid="rata-del-calendario"] input')[2];
        data2.value = '2026-07-20';
        data2.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="controllo-calendario"]')?.textContent))
            .toBe('Le rate del calendario vanno in ordine di giorno, una per giorno, dal giorno dell\'accordo in poi; ogni rata più di zero.');
    });
});

describe('un accordo con il calendario', () => {
    const accordo = { id: 7, data_accordo: '2026-07-15', fino_al: '2026-09-30', riconosciuti: 22592, oggi: 7531, invariato: false, fino_al_passato: true, versati_oltre: 0,
        autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null, decadenza_registrata_il: null,
        in_corso: true, documento: null, rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 20000, unita: 'U1', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-03-31' }],
        regola: { rate: 2, giorni: 15 }, rate_non_versate: 2, prossima: { numero: 2, data: '2026-08-31', importo: 7531, mancano: 1 },
        calendario: [
            { numero: 1, data: '2026-07-31', importo: 7531, versato: 7531, mancano: 0, versata: true, scaduta: true },
            { numero: 2, data: '2026-08-31', importo: 7531, versato: 7530, mancano: 1, versata: false, scaduta: true },
            { numero: 3, data: '2026-09-30', importo: 7530, versato: 0, mancano: 7530, versata: false, scaduta: true },
        ],
        segnali: {
            rate: { il: '2026-10-01', rate: [{ numero: 2, data: '2026-08-31', importo: 7531, versato: 7530 }, { numero: 3, data: '2026-09-30', importo: 7530, versato: 0 }] },
            giorni: { il: '2026-09-16', rate: [{ numero: 2, data: '2026-08-31', importo: 7531, versato: 0 }] },
        } };

    test('le rate versate o no, la regola, i due segnali dal più vecchio; «Registra la decadenza» con la data e la nota', async () => {
        axiosGet.mockResolvedValue(risposta({ data: '2026-10-02', oggi: '2026-10-02', accordi: [accordo] }));
        monta('2026-10-02');
        await flushPromises();
        const card = corpo().querySelector('[data-testid="accordo"]')!;
        expect(Array.from(card.querySelectorAll('[data-testid="rata-calendario"]')).map((r) => Array.from(r.querySelectorAll('td')).map((c) => pulito(c.textContent)).join(' ').trim())).toEqual([
            '1 31/07/2026 € 75,31 versata', '2 31/08/2026 € 75,31 versati € 75,30 di € 75,31: mancano € 0,01', '3 30/09/2026 € 75,30 non versata',
        ]);
        expect(pulito(card.querySelector('[data-testid="regola-accordo"]')?.textContent))
            .toBe('Regola di decadenza: 2 rate del calendario non versate, anche non consecutive; oppure 15 giorni dopo una rata non versata.');
        const segnali = Array.from(card.querySelectorAll('[data-testid="segnale-decadenza"]')).map((s) => pulito(s.textContent));
        expect(segnali[0]).toContain('Il 16/09/2026 la condizione di decadenza');
        expect(segnali[1]).toContain('Il 01/10/2026 la condizione di decadenza scritta nell\'accordo («2 rate del calendario non versate, anche non consecutive») si è verificata: '
            + 'quel giorno la rata 2 del 31/08/2026: versati € 75,30 di € 75,31, mancavano € 0,01');
        expect(pulito(card.textContent)).not.toMatch(/decadut|dovut|esigibil|moros/i);
        // I pulsanti dell'accordo, una volta ciascuno.
        const nomi = Array.from(card.querySelectorAll('button')).map((b) => pulito(b.textContent));
        expect(nomi.filter((n) => n === 'Registra la decadenza')).toHaveLength(1);
        expect(nomi.filter((n) => n === 'Chiudi l\'accordo')).toHaveLength(1);
        expect(card.querySelector('[data-testid="stampa-accordo"]')!.getAttribute('href')).toContain('gestionale.accordi-rientro.stampa');

        card.querySelector<HTMLButtonElement>('[data-testid="apri-decadenza"]')!.click();
        await flushPromises();
        expect(pulito(card.querySelector('[data-testid="modulo-decadenza"]')?.textContent)).toContain('La decadenza chiude l\'accordo il giorno che scrivi');
        expect(card.querySelector('[data-testid="apri-chiusura"]')).toBeNull();
        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Decadenza registrata: da oggi le rate dell\'accordo tornano nelle lettere proposte.' } });
        axiosGet.mockResolvedValue(risposta({ data: '2026-10-02', oggi: '2026-10-02',
            accordi: [{ ...accordo, chiuso_il: '2026-10-02', decadenza_registrata_il: '2026-10-02', nota_chiusura: 'Raccomandata del 02/10', in_corso: false }] }));
        const nota = card.querySelectorAll<HTMLInputElement>('[data-testid="modulo-decadenza"] input')[1];
        nota.value = 'Raccomandata del 02/10';
        nota.dispatchEvent(new Event('input'));
        await flushPromises();
        card.querySelector<HTMLButtonElement>('[data-testid="conferma-decadenza"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toContain('gestionale.accordi-rientro.decadenza');
        expect(axiosPost.mock.calls[0][1]).toEqual({ data: '2026-10-02', nota: 'Raccomandata del 02/10' });
        expect(pulito(corpo().querySelector('[data-testid="decadenza-registrata"]')?.textContent)).toBe('Decadenza registrata il 02/10/2026: Raccomandata del 02/10');
        expect(pulito(corpo().querySelector('[data-testid="esito"]')?.textContent)).toBe('Decadenza registrata: da oggi le rate dell\'accordo tornano nelle lettere proposte.');
    });
});
