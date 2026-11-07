// @vitest-environment jsdom

/**
 * La finestra dell'accordo di rientro: «autorizzato dall'assemblea» senza una risposta già scelta (il pulsante resta spento finché non si
 * sceglie), le rate proposte con il residuo del giorno dell'accordo, la richiesta con le rate scelte e gli importi come li scrive chi lavora,
 * la lettura arrivata dopo un altro cambio della data che si scarta, la chiusura con la data e la nota.
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

const possibile = (n: number, residuo: number) => ({ rata_id: n, immobile_id: 1, numero_rata: n, piano: 'Preventivo 2026', unita: 'Interno 7', scadenza: '2026-01-31', residuo });
const risposta = (extra: Record<string, unknown> = {}) => ({ data: { persona: { id: 1, nome: 'Giulia' }, data: '2026-06-26', oggi: '2026-06-26',
    rate: [possibile(1, 75000), possibile(2, 75000)], accordi: [], ...extra } });

let w: VueWrapper | null = null;
const monta = () => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persona: { id: 1, nome: 'Giulia' }, oggi: '2026-06-26' } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();
const corpo = () => document.body;

describe('la finestra dell\'accordo', () => {
    test('«autorizzato dall\'assemblea» non ha una risposta già scelta: senza la scelta non si registra', async () => {
        axiosGet.mockResolvedValue(risposta());
        monta();
        await flushPromises();
        const radio = Array.from(corpo().querySelectorAll<HTMLInputElement>('input[name="autorizzato"]'));
        expect(radio).toHaveLength(2);
        expect(radio.some((r) => r.checked)).toBe(false);
        expect(pulito(corpo().textContent ?? '')).toContain('Se l\'assemblea non ha ancora deliberato è «no», con una nota');

        // Le rate proposte, con il residuo di quel giorno come importo.
        const rate = corpo().querySelectorAll('[data-testid="rata-possibile"]');
        expect(rate).toHaveLength(2);
        expect(pulito(rate[0].textContent ?? '')).toContain('residuo € 750,00');
        (rate[0].querySelector('button[role="checkbox"]') as HTMLElement).click();
        const fino = corpo().querySelector<HTMLInputElement>('[data-testid="fino-al"]')!;
        fino.value = '2026-12-15';
        fino.dispatchEvent(new Event('input'));
        await flushPromises();
        const registra = corpo().querySelector<HTMLButtonElement>('[data-testid="registra-accordo"]')!;
        expect(registra.disabled).toBe(true);

        radio[1].click();
        await flushPromises();
        expect(registra.disabled).toBe(false);
        axiosPost.mockResolvedValueOnce({ data: { id: 9, messaggio: 'Accordo registrato.' } });
        axiosGet.mockResolvedValue(risposta());
        registra.click();
        await flushPromises();
        const [indirizzo, dati] = axiosPost.mock.calls[0] as [string, FormData];
        expect(indirizzo).toContain('gestionale.accordi-rientro.store');
        expect(dati.get('autorizzato_assemblea')).toBe('0');
        expect(dati.get('rate[0][rata_id]')).toBe('1');
        expect(dati.get('rate[0][importo]')).toBe('750,00');
        expect(dati.get('rate[1][rata_id]')).toBeNull();
        expect(dati.get('fino_al')).toBe('2026-12-15');
        expect(pulito(corpo().textContent ?? '')).toContain('Accordo registrato.');
    });

    test('una lettura arrivata dopo un altro cambio della data dell\'accordo si scarta', async () => {
        let rispondiPrima: (v: unknown) => void = () => {};
        axiosGet.mockResolvedValueOnce(risposta());
        monta();
        await flushPromises();
        axiosGet.mockImplementationOnce(() => new Promise((r) => { rispondiPrima = r; }));
        axiosGet.mockResolvedValueOnce(risposta({ data: '2026-05-10', rate: [possibile(1, 75000)] }));
        const data = corpo().querySelector<HTMLInputElement>('[data-testid="data-accordo"]')!;
        data.value = '2026-06-01';
        data.dispatchEvent(new Event('input'));
        await flushPromises();
        data.value = '2026-05-10';
        data.dispatchEvent(new Event('input'));
        await flushPromises();
        rispondiPrima(risposta({ data: '2026-06-01', rate: [possibile(1, 75000), possibile(2, 75000), possibile(3, 75000)] }));
        await flushPromises();
        expect(corpo().querySelectorAll('[data-testid="rata-possibile"]')).toHaveLength(1);
    });

    test('un accordo in corso si chiude con la data e la nota; uno chiuso lo dice', async () => {
        const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 150000, invariato: true, fino_al_passato: false, versati_oltre: 0,
            autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: 'Assemblea convocata', chiuso_il: null, nota_chiusura: null,
            in_corso: true, documento: null, rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 75000, unita: 'Interno 7', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-01-31' }] };
        axiosGet.mockResolvedValue(risposta({ accordi: [accordo] }));
        monta();
        await flushPromises();
        const testo = pulito(corpo().querySelector('[data-testid="accordo"]')?.textContent ?? '');
        expect(testo).toContain('residuo invariato dal 26/06/2026');
        expect(testo).toContain('Non autorizzato dall\'assemblea.');
        corpo().querySelector<HTMLButtonElement>('[data-testid="apri-chiusura"]')!.click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Accordo chiuso.' } });
        axiosGet.mockResolvedValue(risposta({ accordi: [{ ...accordo, chiuso_il: '2026-06-26', nota_chiusura: 'Rate non versate', in_corso: false }] }));
        corpo().querySelector<HTMLButtonElement>('[data-testid="chiudi"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toEqual({ chiuso_il: '2026-06-26', nota_chiusura: null });
        expect(pulito(corpo().querySelector('[data-testid="chiuso"]')?.textContent ?? '')).toBe('Chiuso il 26/06/2026: Rate non versate');
    });
});
