// @vitest-environment jsdom

/**
 * Il registro delle lettere nella pagina dell'estratto conto, montato con le lettere come le manda `RegistroLettere::perPersona()`: il tipo, il
 * giorno, chi; il totale di allora e il residuo di oggi; le righe con «consegna non provata»; il PDF conservato con l'impronta verificata;
 * «Registra la spedizione» solo su una lettera stampata non ancora spedita; «Togli» solo per una prova che si può togliere; gli eventi.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), post: vi.fn(), delete: vi.fn() }, usePage: () => ({ props: { errors: {} } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.invio ?? ''}/${p.documento ?? ''}`;

const RegistroLettere = (await import('./RegistroLettere.vue')).default;

const lettera = (extra: Record<string, unknown> = {}) => ({
    lettera: 'a', tipo: 'diffida', emessa_il: '2026-03-20', ora: '10:00', emesso_da: 'Anna Studio', totale: 18000, residuo_oggi: 12000, alla: '2026-03-20', termine: 15, stato: 'stampata',
    righe: [{ id: 9, canale: 'stampa', formato: null, destinatario: null, stato: 'stampata', quando: '2026-03-20 10:00', errore: null, spedizione: null, spedibile: true, rimandabile: false }],
    pdf: { invio_id: 9, nome: 'diffida.pdf', impronta: 'abc', verificato: true },
    prove: [{ id: 30, invio_id: 9, nome: 'avviso.pdf', aggiunta_il: '2026-03-22', rimovibile: false }],
    eventi: [{ evento: 'prova_allegata', il: '2026-03-22T10:00:00+00:00', da_nome: 'Anna Studio', nome_file: 'avviso.pdf' }],
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (lettere: unknown[]) => {
    w = mount(RegistroLettere, { props: { condominioId: 3, lettere: lettere as any, oggi: '2026-03-26' } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('il registro', () => {
    test('la lettera: il tipo, il giorno, chi, il totale di allora e il residuo di oggi, il PDF conservato verificato, le prove e gli eventi', () => {
        const t = pulito(monta([lettera()]).text());
        expect(t).toContain('Diffida del 20/03/2026 alle 10:00, da Anna Studio');
        expect(t).toContain('Nella lettera € 180,00 (situazione al 20/03/2026); sulle stesse rate oggi € 120,00.');
        expect(t).toContain('Stampata il 20/03/2026 alle 10:00; spedizione non registrata');
        expect(t).toContain('il file corrisponde all\'impronta della lettera');
        expect(t).toContain('prova allegata il 22/03/2026, da Anna Studio (avviso.pdf)');
        // La prova di un esercizio chiuso non si toglie: «Togli» non c'è.
        expect(w!.findAll('button').map((b) => b.text())).not.toContain('Togli');
        expect(w!.findAll('button').map((b) => b.text())).toContain('Registra la spedizione');
    });

    test('un\'email dice «consegna non provata» e si rimanda con le cifre di oggi; una lettera spedita non si «spedisce» di nuovo', async () => {
        monta([lettera({ tipo: 'sollecito', righe: [
            { id: 10, canale: 'email', formato: null, destinatario: 'ugo@x.it', stato: 'inviata', quando: '2026-03-20 10:00', errore: null, spedizione: null, spedibile: false, rimandabile: true },
            { id: 11, canale: 'stampa', formato: null, destinatario: null, stato: 'spedita', quando: '2026-03-20 10:00', errore: null, spedizione: { canale: 'raccomandata', data: '2026-03-21', numero: 'RA1' }, spedibile: false, rimandabile: false },
        ], pdf: { invio_id: 10, nome: 's.pdf', impronta: 'x', verificato: false }, prove: [{ id: 31, invio_id: 11, nome: 'a.pdf', aggiunta_il: '2026-03-22', rimovibile: true }] })]);
        const t = pulito(w!.text());
        expect(t).toContain('Inviata il 20/03/2026 alle 10:00 a ugo@x.it, consegna non provata');
        expect(t).toContain('spedita il 21/03/2026 con raccomandata A/R n. RA1');
        expect(t).toContain('il file non corrisponde all\'impronta della lettera');
        const testi = w!.findAll('button').map((b) => b.text());
        expect(testi).not.toContain('Registra la spedizione');
        expect(testi).toContain('Togli');
        axiosPost.mockResolvedValue({ data: { non_partite: 0 } });
        await w!.findAll('button').find((b) => b.text().startsWith('Invia di nuovo'))!.trigger('click');
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toBe('/gestionale.lettere.invia-di-nuovo/10/');
        expect(pulito(w!.text())).toContain('Lettera rifatta con le cifre di oggi e rimandata.');
    });

    test('senza lettere lo dice', () => {
        expect(pulito(monta([]).text())).toContain('Nessuna lettera a questa persona in questo condominio.');
    });
});

describe('il giorno degli eventi', () => {
    test('il giorno dell\'evento è quello che scrive il server, nel calendario dell\'amministratore, non i primi dieci caratteri dell\'istante in UTC', () => {
        const t = pulito(monta([lettera({ eventi: [{ evento: 'spedizione', il: '2026-03-21T23:30:00+00:00', giorno: '2026-03-22', da_nome: 'Anna Studio' }] })]).text());
        expect(t).toContain('spedizione registrata il 22/03/2026, da Anna Studio');
        expect(t).not.toContain('21/03/2026');
    });
});
