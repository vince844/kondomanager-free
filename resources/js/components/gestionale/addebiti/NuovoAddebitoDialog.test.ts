// @vitest-environment jsdom

/**
 * La finestra dell'addebito a una persona sola, dalle tre porte. Dal piano: una riga per quota non assegnata dell'unità, con quanto resta come
 * importo; la persona proposta per prima, con il perché e «(la proposta)»; la richiesta con la chiave e il piano di ogni quota. Dall'estratto conto:
 * un contributo sull'unità della persona, o la spesa di una sola unità già fatturata con la sua riga. Dal conguaglio: quello che resta delle spese non
 * attribuite, con la gestione e l'esercizio di origine; scelta l'unità, le persone si rileggono. Gli importi partono come li scrive l'amministratore.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./NuovoAddebitoDialog.vue')).default;

const d2026 = { gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)', inizio: '2026-01-01', fine: '2026-12-31' };
const d2027 = { gestione_id: 6, esercizio_id: 5, nome: 'Ordinaria 2027 (Esercizio 2027)', inizio: '2027-01-01', fine: '2027-12-31' };
const dove = { proposta: d2026, scelte: [d2026] };
const verdi = { anagrafica_id: 4, nome: 'Verdi', perche: 'titolare fino al 30/11/2026, prima dei giorni senza titolare' };
const gialli = { anagrafica_id: 5, nome: 'Gialli', perche: 'titolare dal 16/12/2026, dopo i giorni senza titolare' };
const dalPiano = {
    unita: { id: 3, nome: 'Int. U3' }, persone: [verdi, gialli], scadenza: '2026-12-20', oggi: '2026-12-20', dove,
    voci: [
        { chiave: 'k-pulizie', piano_origine_id: 7, conto_id: 1, importo_cents: 5096, addebitato_cents: 0, resta_cents: 5096, causale: 'Quota non assegnata: Pulizie, Int. U3, 31 giorni senza titolare' },
        { chiave: 'k-manut', piano_origine_id: 7, conto_id: 2, importo_cents: 1699, addebitato_cents: 1000, resta_cents: 699, causale: 'Quota non assegnata: Manutenzioni, Int. U3, 31 giorni senza titolare' },
    ],
};

let w: VueWrapper | null = null;
const monta = (origine: unknown) => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, origine: origine as never } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const corpo = () => document.body;
/** Una voce di un menu del programma (`v-select`): il menu si apre e si fa clic sulla voce con quel testo. */
const menu = (testid: string) => corpo().querySelector(`[data-testid="${testid}"] .v-select`) as HTMLElement;
const vociDelMenu = async (testid: string) => {
    menu(testid).querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    return [...menu(testid).querySelectorAll('.vs__dropdown-option')].map((o) => pulito(o.textContent));
};
const nelMenu = async (testid: string, testo: string) => {
    await vociDelMenu(testid);
    ([...menu(testid).querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};
const sceltoNelMenu = (testid: string) => pulito(menu(testid).querySelector('.vs__selected')?.textContent);
const scrivi = async (sel: string, valore: string) => {
    const el = corpo().querySelector<HTMLInputElement>(sel)!;
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    el.dispatchEvent(new Event('change'));
    await flushPromises();
};

describe('dal riquadro del piano', () => {
    test('le quote dell\'unità con quanto resta, la persona proposta per prima con il perché; la richiesta porta la chiave e il piano di ogni quota', async () => {
        axiosGet.mockResolvedValue({ data: dalPiano });
        monta({ tipo: 'piano', pianoRateId: 7, chiavi: ['k-pulizie', 'k-manut'] });
        await flushPromises();
        expect(axiosGet.mock.calls[0][1]).toEqual({ params: { piano_rate_id: 7, chiavi: ['k-pulizie', 'k-manut'] } });

        const voci = corpo().querySelectorAll('[data-testid="voce"]');
        expect(voci).toHaveLength(2);
        expect(pulito(voci[1].textContent)).toContain('Quota non assegnata € 16,99, già addebitati € 10,00: si addebitano al più € 6,99.');
        expect([...corpo().querySelectorAll<HTMLInputElement>('[aria-label="Importo da addebitare"]')].map((i) => i.value)).toEqual(['50,96', '6,99']);
        const persone = [...corpo().querySelectorAll('[data-testid="persona"]')].map((p) => pulito(p.textContent));
        expect(persone).toEqual(['Verdi — titolare fino al 30/11/2026, prima dei giorni senza titolare (la proposta)', 'Gialli — titolare dal 16/12/2026, dopo i giorni senza titolare']);
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="persona"] input')!.checked).toBe(true);
        expect(pulito(corpo().textContent)).toContain('mai l\'inquilino');

        axiosPost.mockResolvedValueOnce({ data: { rate: [11, 12], messaggio: '2 addebiti registrati: € 57,95.' } });
        axiosGet.mockResolvedValue({ data: dalPiano });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toBe('/gestionale.addebiti.store');
        expect(axiosPost.mock.calls[0][1]).toEqual({ gestione_id: 2, esercizio_id: 1, data: '2026-12-20', voci: [
            { anagrafica_id: 4, scadenza: '2026-12-20', immobile_id: 3, importo: '50,96', causale: dalPiano.voci[0].causale, chiave: 'k-pulizie', piano_origine_id: 7, conto_id: 1 },
            { anagrafica_id: 4, scadenza: '2026-12-20', immobile_id: 3, importo: '6,99', causale: dalPiano.voci[1].causale, chiave: 'k-manut', piano_origine_id: 7, conto_id: 2 },
        ] });
        expect(pulito(corpo().querySelector('[data-testid="esito"]')?.textContent)).toBe('2 addebiti registrati: € 57,95.');
        expect(w!.emitted('fatto')).toHaveLength(1);
    });

    test('l\'unità senza titolari: la finestra lo dice e non registra', async () => {
        axiosGet.mockResolvedValue({ data: { ...dalPiano, persone: [] } });
        monta({ tipo: 'piano', pianoRateId: 7, chiavi: ['k-pulizie'] });
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="nessuna-persona"]')?.textContent)).toContain('aggiungi il titolare all\'unità');
        expect(corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.disabled).toBe(true);
    });

    test('il rifiuto del server si legge nella finestra', async () => {
        axiosGet.mockResolvedValue({ data: dalPiano });
        monta({ tipo: 'piano', pianoRateId: 7, chiavi: ['k-pulizie'] });
        await flushPromises();
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Della quota non assegnata si possono addebitare al più € 20,96.' } } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="errore"]')?.textContent)).toBe('Della quota non assegnata si possono addebitare al più € 20,96.');
    });
});

describe('dall\'estratto conto', () => {
    const dallaPersona = {
        persona: { id: 1, nome: 'Neri' }, unita: [{ id: 1, nome: 'Int. U1' }], scadenza: '2026-06-10', oggi: '2026-06-10', dove,
        spese: [{ riga_fattura_id: 9, immobile_id: 1, unita: 'Int. U1', descrizione: 'Sollecito', fattura: 'n. 12 del 01/06/2026', resta_cents: 2500, dove: d2026 }],
    };

    test('un contributo sull\'unità della persona: l\'importo e la causale come li scrive l\'amministratore', async () => {
        axiosGet.mockResolvedValue({ data: dallaPersona });
        monta({ tipo: 'persona', anagraficaId: 1 });
        await flushPromises();
        expect(pulito(corpo().textContent)).toContain('Nuovo addebito a Neri');
        expect(corpo().querySelector('[data-testid="persone"]')).toBeNull();
        await scrivi('[data-testid="importo"]', '40,00');
        await scrivi('[data-testid="causale"]', 'Pulizia straordinaria del box');
        axiosPost.mockResolvedValueOnce({ data: { rate: [3], messaggio: 'Addebito registrato: € 40,00.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toEqual({ gestione_id: 2, esercizio_id: 1, data: '2026-06-10', voci: [
            { anagrafica_id: 1, scadenza: '2026-06-10', immobile_id: 1, importo: '40,00', causale: 'Pulizia straordinaria del box' },
        ] });
        // Registrato, la finestra rilegge la proposta: le spese da chiedere sono cambiate.
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="importo"]')!.value).toBe('');
    });

    test('nell\'anno dopo, con l\'esercizio dell\'anno prima scelto, la data dell\'addebito di serie è la fine di quell\'esercizio', async () => {
        axiosGet.mockResolvedValue({ data: { ...dallaPersona, oggi: '2027-03-20', scadenza: '2027-03-20', dove: { proposta: d2027, scelte: [d2027, d2026] } } });
        monta({ tipo: 'persona', anagraficaId: 1 });
        await flushPromises();
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="data"]')!.value).toBe('2027-03-20');
        await nelMenu('dove', 'Ordinaria 2026 (Esercizio 2026)');
        await flushPromises();
        const data = corpo().querySelector<HTMLInputElement>('[data-testid="data"]')!;
        expect([data.value, data.min, data.max]).toEqual(['2026-12-31', '2026-01-01', '2026-12-31']);
        await scrivi('[data-testid="importo"]', '50,00');
        await scrivi('[data-testid="causale"]', 'Contributo cantina');
        axiosPost.mockResolvedValueOnce({ data: { rate: [3], messaggio: 'Addebito registrato: € 50,00.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toMatchObject({ gestione_id: 2, esercizio_id: 1, data: '2026-12-31' });
    });

    test('la spesa già fatturata dell\'anno prima si scrive nella gestione e nell\'esercizio della sua fattura, e il posto non si cambia', async () => {
        axiosGet.mockResolvedValue({ data: { ...dallaPersona, oggi: '2027-02-10', scadenza: '2027-02-10', dove: { proposta: d2027, scelte: [d2027, d2026] } } });
        monta({ tipo: 'persona', anagraficaId: 1 });
        await flushPromises();
        expect(sceltoNelMenu('dove')).toBe('Ordinaria 2027 (Esercizio 2027)');
        (corpo().querySelector('[data-testid="modo-spesa"] input') as HTMLInputElement).click();
        await flushPromises();
        await nelMenu('spesa', 'Int. U1 · Sollecito · fattura n. 12 del 01/06/2026 · da chiedere € 25,00');
        await flushPromises();
        expect([sceltoNelMenu('dove'), menu('dove').classList.contains('vs--disabled')]).toEqual(['Ordinaria 2026 (Esercizio 2026)', true]);
        expect(pulito(corpo().querySelector('[data-testid="dove-della-spesa"]')?.textContent))
            .toBe('La spesa è della fattura n. 12 del 01/06/2026: l\'addebito si scrive in Ordinaria 2026 (Esercizio 2026), l\'esercizio della fattura.');
        axiosPost.mockResolvedValueOnce({ data: { rate: [4], messaggio: 'Addebito registrato: € 25,00.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toMatchObject({ gestione_id: 2, esercizio_id: 1, data: '2026-12-31' });
    });

    test('la spesa già fatturata: la riga propone l\'importo e la causale; la natura «spesa per il ritardo» va nella richiesta con la riga', async () => {
        axiosGet.mockResolvedValue({ data: dallaPersona });
        monta({ tipo: 'persona', anagraficaId: 1 });
        await flushPromises();
        (corpo().querySelector('[data-testid="modo-spesa"] input') as HTMLInputElement).click();
        await flushPromises();
        expect(await vociDelMenu('spesa')).toEqual(['Int. U1 · Sollecito · fattura n. 12 del 01/06/2026 · da chiedere € 25,00']);
        await nelMenu('spesa', 'Int. U1 · Sollecito · fattura n. 12 del 01/06/2026 · da chiedere € 25,00');
        await flushPromises();
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="importo"]')!.value).toBe('25,00');
        (corpo().querySelector('[data-testid="natura-accessorio"] input') as HTMLInputElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { rate: [4], messaggio: 'Addebito registrato: € 25,00.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1].voci).toEqual([
            { anagrafica_id: 1, scadenza: '2026-06-10', immobile_id: 1, importo: '25,00', causale: 'Sollecito, Int. U1', riga_fattura_id: 9, natura: 'accessorio' },
        ]);
    });
});

describe('dal conguaglio', () => {
    test('quello che resta delle spese non attribuite, con la gestione e l\'esercizio di origine; l\'unità scelta rilegge le persone', async () => {
        const unita = [{ id: 1, nome: 'Int. U1' }, { id: 3, nome: 'Int. U3' }];
        axiosGet.mockResolvedValueOnce({ data: { unita, immobile_id: null, persone: [], causale: 'Spese dei giorni senza titolare, Ordinaria 2026', dove, scadenza: '2026-11-02', oggi: '2026-11-02' } });
        monta({ tipo: 'conguaglio', gestioneId: 2, esercizioId: 1, importo: 339 });
        await flushPromises();
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="importo"]')!.value).toBe('3,39');
        expect(corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.disabled).toBe(true);

        axiosGet.mockResolvedValueOnce({ data: { unita, immobile_id: 3, persone: [verdi], causale: 'Spese dei giorni senza titolare, Ordinaria 2026', dove, scadenza: '2026-11-02', oggi: '2026-11-02' } });
        await nelMenu('unita', 'Int. U3');
        await flushPromises();
        expect(axiosGet.mock.calls[1][1]).toEqual({ params: { gestione_id: 2, esercizio_id: 1, immobile_id: 3 } });
        expect(pulito(corpo().querySelector('[data-testid="persona"]')?.textContent)).toContain('Verdi');

        axiosPost.mockResolvedValueOnce({ data: { rate: [5], messaggio: 'Addebito registrato: € 3,39.' } });
        axiosGet.mockResolvedValue({ data: { unita, immobile_id: null, persone: [], dove, scadenza: '2026-11-02', oggi: '2026-11-02' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1].voci).toEqual([{ anagrafica_id: 4, scadenza: '2026-11-02', immobile_id: 3, importo: '3,39',
            causale: 'Spese dei giorni senza titolare, Ordinaria 2026', gestione_origine_id: 2, esercizio_origine_id: 1 }]);
    });
});

describe('la spesa per il ritardo fatturata, con il suo tipo e la sua base', () => {
    test('il tipo e la base scelti vanno nella richiesta con il riferimento; l\'avviso della coppia viene dalla tabella del server', async () => {
        const AVVISO = 'Alcune sentenze non ammettono che una delibera a maggioranza addebiti al solo condòmino le spese dell\'avvocato prima della causa: controlla la base.';
        axiosGet.mockResolvedValue({ data: {
            persona: { id: 1, nome: 'Neri' }, unita: [{ id: 1, nome: 'Int. U1' }], scadenza: '2026-06-10', oggi: '2026-06-10', dove,
            spese: [{ riga_fattura_id: 9, immobile_id: 1, unita: 'Int. U1', descrizione: 'Parcella', fattura: 'n. 12 del 01/06/2026', resta_cents: 15000, dove: d2026 }],
            tipi: [{ valore: 'sollecito', nome: 'Spese di sollecito' }, { valore: 'legali_stragiudiziali', nome: 'Spese legali prima della causa' }],
            basi: [{ valore: 'delibera', nome: 'una delibera dell\'assemblea' }, { valore: 'regolamento', nome: 'il regolamento' }],
            avvisi: { legali_stragiudiziali: { delibera: [AVVISO], regolamento: [] }, sollecito: { delibera: [], regolamento: [] } },
        } });
        monta({ tipo: 'persona', anagraficaId: 1 });
        await flushPromises();
        (corpo().querySelector('[data-testid="modo-spesa"] input') as HTMLInputElement).click();
        await flushPromises();
        await nelMenu('spesa', 'Int. U1 · Parcella · fattura n. 12 del 01/06/2026 · da chiedere € 150,00');
        await flushPromises();
        (corpo().querySelector('[data-testid="natura-accessorio"] input') as HTMLInputElement).click();
        await flushPromises();
        for (const v of ['legali_stragiudiziali', 'delibera']) {
            const r = corpo().querySelector<HTMLInputElement>(`[data-testid="base-della-spesa"] input[value="${v}"]`)!;
            r.checked = true;
            r.dispatchEvent(new Event('change'));
        }
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="avviso"]')?.textContent)).toBe(AVVISO);
        await scrivi('[data-testid="riferimento"]', 'del 14/03/2026, verbale n. 1');
        axiosPost.mockResolvedValueOnce({ data: { rate: [4], messaggio: 'Addebito registrato: € 150,00.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-addebito"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1].voci).toEqual([{ anagrafica_id: 1, scadenza: '2026-06-10', immobile_id: 1, importo: '150,00', causale: 'Parcella, Int. U1',
            riga_fattura_id: 9, natura: 'accessorio', tipo_accessorio: 'legali_stragiudiziali', base: 'delibera', riferimento: 'del 14/03/2026, verbale n. 1' }]);
    });
});
