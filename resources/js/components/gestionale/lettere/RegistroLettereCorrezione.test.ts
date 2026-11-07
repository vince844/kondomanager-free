// @vitest-environment jsdom

/**
 * Il registro delle lettere di una persona: una spedizione registrata si corregge (il modulo parte dai dati di oggi, il registro tiene
 * l'evento), e «Invia di nuovo» dice quando la lettera rifatta porta un altro conto, o nessun IBAN.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), post: vi.fn(), delete: vi.fn() }, usePage: () => ({ props: {} }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Registro = (await import('./RegistroLettere.vue')).default;

const riga = (extra: Record<string, unknown> = {}) => ({ id: 11, canale: 'stampa', formato: 'stampa', destinatario: null, stato: 'spedita', quando: '2026-03-20 10:00', errore: null,
    spedizione: { canale: 'raccomandata', data: '2026-03-20', numero: 'RA123' }, spedibile: false, rimandabile: false, ...extra });
const lettera = (righe: unknown[], eventi: unknown[] = []) => ({ lettera: 'x', tipo: 'diffida', emessa_il: '2026-03-20', ora: '10:00', emesso_da: 'Anna Studio', totale: 18000,
    residuo_oggi: 18000, alla: '2026-03-20', termine: 15, stato: 'spedita', righe, pdf: null, prove: [], eventi });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('la correzione della spedizione', () => {
    test('«Correggi la spedizione» parte dai dati registrati e manda quelli nuovi alla riga', async () => {
        w = mount(Registro, { props: { condominioId: 3, oggi: '2026-03-22', lettere: [lettera([riga()])] } });
        await w.get('[data-testid="correggi-spedizione"]').trigger('click');
        const numero = w.get('[data-testid="spedizione-numero"]');
        expect((numero.element as HTMLInputElement).value).toBe('RA123');
        await numero.setValue('RA124');
        await w.get('[data-testid="spedizione-data"]').setValue('2026-03-21');
        axiosPost.mockResolvedValueOnce({ data: { corretta: true } });
        await w.get('[data-testid="registra-correzione"]').trigger('click');
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledWith('/gestionale.lettere.spedizione.correggi?condominio=3&invio=11', { canale: 'raccomandata', data: '2026-03-21', numero: 'RA124', nota: null });
        expect(pulito(w.text())).toContain('Spedizione corretta.');
    });

    test('il registro dice la correzione, con chi l\'ha fatta', () => {
        w = mount(Registro, { props: { condominioId: 3, oggi: '2026-03-22', lettere: [lettera([riga()], [
            { evento: 'spedizione', il: '2026-03-20T09:00:00+00:00', giorno: '2026-03-20', da_nome: 'Anna Studio' },
            { evento: 'spedizione_corretta', il: '2026-03-22T09:00:00+00:00', giorno: '2026-03-22', da_nome: 'Anna Studio' },
        ])] } });
        expect(pulito(w.text())).toContain('spedizione registrata il 20/03/2026, da Anna Studio; spedizione corretta il 22/03/2026, da Anna Studio');
    });

    test('una lettera stampata e non spedita non ha la correzione', () => {
        w = mount(Registro, { props: { condominioId: 3, oggi: '2026-03-22', lettere: [lettera([riga({ stato: 'stampata', spedizione: null, spedibile: true })])] } });
        expect(w.find('[data-testid="correggi-spedizione"]').exists()).toBe(false);
    });
});

describe('«Invia di nuovo» con il conto di prima chiuso', () => {
    test('la lettera rifatta con il conto di oggi lo dice', async () => {
        w = mount(Registro, { props: { condominioId: 3, oggi: '2026-03-22', lettere: [lettera([riga({ canale: 'email', stato: 'non_partita', spedizione: null, rimandabile: true })])] } });
        axiosPost.mockResolvedValueOnce({ data: { non_partite: 0, conti_cambiati: [{ prima: 'Banca principale', ora: 'Banca nuova' }] } });
        await w.get('[data-testid="invia-di-nuovo"]').trigger('click');
        await flushPromises();
        expect(pulito(w.text())).toContain('Lettera rifatta con le cifre di oggi e rimandata. Rifatta con il conto «Banca nuova»: il conto «Banca principale» non è più attivo o non ha più l\'IBAN.');
    });
});
