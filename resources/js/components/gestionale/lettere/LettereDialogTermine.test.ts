// @vitest-environment jsdom

/**
 * La finestra delle lettere: il termine per il versamento ha tre forme («N giorni dal ricevimento», «entro il [data]», «nessun termine»), si
 * ricorda per tipo di lettera (per il sollecito si propone l'ultimo, per diffida e messa in mora nessuna forma è già scelta se non c'è una lettera
 * di prima), e il promemoria non ne ha uno; per diffida e messa in mora la finestra dice che il testo è quello del programma e va controllato;
 * l'anteprima dice per la persona quando le rate di un accordo con «fino al» passato sono nella lettera.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;
const { FRASE_TESTO_DEL_PROGRAMMA } = await import('@/lib/gestionale/lettere/lettere');

const opzioni = (termini: Record<string, unknown> = { sollecito: { forma: 'giorni', giorni: 15 }, diffida: null, messa_in_mora: null }) => ({
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false }, { tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true },
        { tipo: 'diffida', nome: 'Diffida', solo_carta: true, conservato: true }, { tipo: 'messa_in_mora', nome: 'Messa in mora', solo_carta: true, conservato: true },
    ],
    testi: { promemoria: 'Testo del promemoria', sollecito: 'Testo del sollecito, [termine]', diffida: 'Testo della diffida, [termine]', messa_in_mora: 'Testo della messa in mora' },
    // La messa in mora parte da un modello dello studio, ricordato dall'ultima lettera: la riga del testo del programma non c'è.
    modelli: [{ id: 8, tipo: 'messa_in_mora', nome: 'Mora dello studio', testo: 'Testo della messa in mora', per_tutti: true }],
    modelli_ricordati: { promemoria: null, sollecito: null, diffida: null, messa_in_mora: { id: 8, nome: 'Mora dello studio', mancante: false } },
    conti: [{ id: 4, nome: 'Banca', iban: 'IT60X0542811101000000123456', intestatario: 'Condominio' }],
    scelta_ricordata: { termini, cassa_id: 4, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: true,
});
const persona = (id: number, nome: string, extra: Record<string, unknown> = {}) => ({
    id, nome, totale: 12000, righe: 2, credito: 0, canale: 'stampa', canali: ['email', 'stampa', 'zip'], indirizzi: [], senza_email: true, solo_pec: false,
    senza_indirizzo_postale: false, ...extra,
});

let finestra: VueWrapper | null = null;
async function apri(o = opzioni(), props: Record<string, unknown> = {}) {
    axiosGet.mockResolvedValue({ data: o });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: {}, ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
const el = <T extends HTMLElement>(id: string) => document.body.querySelector(`[data-testid="${id}"]`) as T | null;
const scegliTipo = async (t: string) => { el<HTMLElement>(`tipo-${t}`)!.querySelector('input')!.click(); await flushPromises(); };
const forma = () => (document.body.querySelector('input[name="termine-forma"]:checked') as HTMLInputElement | null)?.value ?? null;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
async function corpoDellAnteprima() {
    axiosPost.mockResolvedValueOnce({ data: { tipo: 'x', persone: [] } });
    el<HTMLButtonElement>('anteprima')!.click();
    await flushPromises();

    return axiosPost.mock.calls.at(-1)![1] as Record<string, unknown>;
}

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; });

describe('il termine per il versamento', () => {
    test('per il sollecito si propone quello dell\'ultimo sollecito; per la diffida nessuna forma è già scelta', async () => {
        await apri();
        expect(forma()).toBe('giorni');
        expect(el<HTMLInputElement>('termine')!.value).toBe('15');
        expect(await corpoDellAnteprima()).toMatchObject({ termine_forma: 'giorni', termine: '15' });

        await scegliTipo('diffida');
        expect(forma()).toBeNull();
        expect((await corpoDellAnteprima()).termine_forma).toBeNull();
    });

    test('per diffida e messa in mora la forma non si propone mai, nemmeno dopo una lettera di prima: si sceglie ogni volta', async () => {
        await apri(opzioni({ sollecito: null, diffida: { forma: 'data', giorni: null }, messa_in_mora: { forma: 'giorni', giorni: 10 } }));
        // Per il sollecito senza un sollecito di prima: la proposta dei giorni, con il numero da scrivere.
        expect(forma()).toBe('giorni');
        expect(el<HTMLInputElement>('termine')!.value).toBe('');
        await scegliTipo('messa_in_mora');
        expect(forma()).toBeNull();
        expect(el<HTMLInputElement>('termine')!.value).toBe('');
        await scegliTipo('diffida');
        expect(forma()).toBeNull();
        (document.body.querySelector('input[name="termine-forma"][value="data"]') as HTMLInputElement).click();
        await flushPromises();
        expect(forma()).toBe('data');
        const data = el<HTMLInputElement>('termine-data')!;
        data.value = '2026-04-30';
        data.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(await corpoDellAnteprima()).toMatchObject({ termine_forma: 'data', termine_data: '2026-04-30', termine: null });
    });

    test('«nessun termine nella lettera» si sceglie, e il promemoria non ha il termine', async () => {
        await apri();
        (document.body.querySelector('input[name="termine-forma"][value="nessuno"]') as HTMLInputElement).click();
        await flushPromises();
        expect(await corpoDellAnteprima()).toMatchObject({ termine_forma: 'nessuno', termine: null, termine_data: null });
        finestra!.unmount();
        document.body.innerHTML = '';
        await apri(opzioni(), { vista: 'in_scadenza' });
        expect(el('termine-forma')).toBeNull();
        const corpo = await corpoDellAnteprima();
        expect(corpo.tipo).toBe('promemoria');
        expect(corpo).not.toHaveProperty('termine_forma');
    });
});

describe('il testo di diffida e messa in mora', () => {
    test('la finestra dice che il testo è quello del programma e va controllato; non per un modello dello studio', async () => {
        await apri();
        expect(el('testo-del-programma')).toBeNull();
        await scegliTipo('diffida');
        expect(el<HTMLTextAreaElement>('testo')!.value).toBe('Testo della diffida, [termine]');
        expect(el('testo-del-programma')!.textContent).toContain(FRASE_TESTO_DEL_PROGRAMMA);
        await scegliTipo('messa_in_mora');
        expect(el('testo-del-programma')).toBeNull();
    });

    test('cambiato il testo, la riga «testo di partenza del programma» non c\'è più', async () => {
        await apri();
        await scegliTipo('diffida');
        expect(el('testo-del-programma')).not.toBeNull();
        const t = el<HTMLTextAreaElement>('testo')!;
        t.value = 'Il mio testo, [termine]';
        t.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(el('testo-del-programma')).toBeNull();
    });
});

describe('l\'accordo con «fino al» passato', () => {
    test('l\'anteprima dice per la persona che le rate dell\'accordo sono nella lettera, e si toglie', async () => {
        await apri();
        axiosPost.mockResolvedValueOnce({ data: { tipo: 'sollecito', alla: '2026-04-01', gestione: null, conteggi: { email: 0, stampa: 2, zip: 0 }, messaggi: 0, credito: [], senza_niente: [],
            studio_compilato: true, senza_server_posta: false, tetto: 200, oltre_tetto: false,
            persone: [persona(1, 'Ugo', { accordo_passato: { fino_al: '2026-03-31', importo: 6000 } }), persona(2, 'Bice')] } });
        el<HTMLButtonElement>('anteprima')!.click();
        await flushPromises();
        expect(testo()).toContain('Accordo di rientro fino al 31/03/2026 (data passata): € 60,00 delle rate dell\'accordo sono nella lettera.');
        expect(testo()).toContain('Le rate degli accordi con «fino al» passato sono nella lettera: Ugo. Togli chi non deve riceverla.');
        expect(document.body.querySelector('[aria-label="Togli Ugo"]')).not.toBeNull();
    });
});
