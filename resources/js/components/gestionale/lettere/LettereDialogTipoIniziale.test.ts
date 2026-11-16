// @vitest-environment jsdom

/**
 * La finestra delle lettere aperta dal riquadro dei sei mesi: «Manda la diffida» la apre con la diffida già scelta (`tipoIniziale`), una proposta visibile che
 * si cambia nella finestra; «Manda la diffida a 4 persone» con la selezione di quelle persone. Senza `tipoIniziale` il tipo di serie resta quello di oggi.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;

const opzioni = {
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false }, { tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true },
        { tipo: 'diffida', nome: 'Diffida', solo_carta: true, conservato: true }, { tipo: 'messa_in_mora', nome: 'Messa in mora', solo_carta: true, conservato: true },
    ],
    testi: { promemoria: 'Testo del promemoria', sollecito: 'Testo del sollecito', diffida: 'Testo della diffida', messa_in_mora: 'Testo della messa in mora' },
    conti: [{ id: 4, nome: 'Banca', iban: 'IT60X0542811101000000123456', intestatario: 'Condominio' }],
    scelta_ricordata: { termini: {}, cassa_id: 4, indirizzi: null }, studio_compilato: false, senza_server_posta: false, puo_salvare_il_testo: false,
};

let finestra: VueWrapper | null = null;
async function apri(props: Record<string, unknown>) {
    axiosGet.mockResolvedValue({ data: opzioni });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-05-20', ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
const tipoScelto = () => (document.body.querySelector('input[name="tipo-lettera"]:checked') as HTMLInputElement | null)?.value;

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; });

describe('il tipo iniziale', () => {
    test('«Manda la diffida» per una persona: la diffida già scelta, con il suo testo; si cambia nella finestra', async () => {
        await apri({ persona: { id: 1, nome: 'Rossi' }, tipoIniziale: 'diffida' });
        expect(tipoScelto()).toBe('diffida');
        expect((document.body.querySelector('[data-testid="testo"]') as HTMLTextAreaElement).value).toBe('Testo della diffida');
        (document.body.querySelector('[data-testid="tipo-sollecito"] input') as HTMLInputElement).click();
        await flushPromises();
        expect(tipoScelto()).toBe('sollecito');
    });

    test('«Manda la diffida a 4 persone»: la selezione di quelle persone, e l\'anteprima le chiede', async () => {
        await apri({ selezione: { tutte: false, ids: [1, 2, 3, 4] }, tipoIniziale: 'diffida' });
        expect(tipoScelto()).toBe('diffida');
        axiosPost.mockResolvedValueOnce({ data: { tipo: 'diffida', alla: '2026-05-20', persone: [], conteggi: { email: 0, stampa: 0, zip: 0 }, messaggi: 0, credito: [], senza_niente: [],
            solo_in_accordo: [], studio_compilato: false, senza_server_posta: false, tetto_messaggi: null, tetto: 200, oltre_tetto: false } });
        (document.body.querySelector('[data-testid="anteprima"]') as HTMLButtonElement).click();
        await flushPromises();
        const [, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, unknown>];
        expect(corpo).toMatchObject({ tipo: 'diffida', persone: [1, 2, 3, 4] });
    });

    test('senza tipo iniziale il tipo di serie è quello di oggi', async () => {
        await apri({ persona: { id: 1, nome: 'Rossi' } });
        expect(tipoScelto()).toBe('sollecito');
    });
});
