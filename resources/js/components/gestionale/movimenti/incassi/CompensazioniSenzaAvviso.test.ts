// @vitest-environment jsdom

/**
 * La sezione «Compensazioni senza avviso» della pagina delle ricevute: che cosa dice di ogni compensazione (di chi era il credito, quanto, perché la
 * ricevuta non basta), di serie solo quelle senza avviso, la scelta, il bollo a scelta dell'amministratore senza una risposta già fatta, l'invio
 * per email a chi ha l'indirizzo. Le cifre sono quelle dello scenario della ricevuta: € 20,00 del credito di Anna Bruni sulla sua rata 2.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Sezione = (await import('./CompensazioniSenzaAvviso.vue')).default;

const compensazione = (extra: Record<string, unknown> = {}) => ({
    chiave: '12:3', incasso_id: 12, numero: 'INC-2026-00002', data: '2026-03-01', persona: { id: 3, nome: 'Anna Bruni' }, importo: 2000,
    quote: [{ rata: 'Rata 2', piano: 'Preventivo 2026', unita: 'Int. 4', scadenza: '2026-03-31', importo: 2000 }],
    credito_di: ['Anna Bruni'], caso: 'senza_denaro', ricevuta_di: null, ha_email: true, avvisata: false, ultimo: null, ...extra,
});
const proprieta = (righe: unknown[]) => ({ condominioId: 7, compensazioni: { righe, senza_avviso: righe.filter((r: any) => !r.avvisata).length,
    frase_bollo: 'Se l\'avviso sconta il bollo lo decidi tu, con il tuo commercialista: scegli lo stato. Il programma non lo calcola e non lo assolve.' } });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le compensazioni senza avviso', () => {
    test('che cosa dice di una compensazione: di chi era il credito, quanto, e perché la ricevuta non basta', () => {
        w = mount(Sezione, { props: proprieta([compensazione(),
            compensazione({ chiave: '13:3', incasso_id: 13, numero: 'INC-2026-00003', caso: 'fuori_dalla_ricevuta', ricevuta_di: 'Carlo Bruni' }),
            compensazione({ chiave: '14:5', incasso_id: 14, persona: { id: 5, nome: 'Ugo Neri' }, credito_di: ['Anna Bruni'], ha_email: false })]) as any });
        const righe = w.findAll('[data-testid="compensazione"]').map((r) => pulito(r.text()));
        expect(righe[0]).toContain('01/03/2026, n. INC-2026-00002: il suo credito ha coperto € 20,00 delle rate di Anna Bruni (un incasso senza denaro, che non ha una ricevuta).');
        expect(righe[0]).toContain('Nessun avviso');
        expect(righe[1]).toContain('(la ricevuta di quell\'incasso va a Carlo Bruni)');
        expect(righe[2]).toContain('il credito di Anna Bruni ha coperto € 20,00 delle rate di Ugo Neri');
        expect(righe[2]).toContain('Senza email: stampalo per la posta.');
        expect(pulito(w.text())).toContain('Compensazioni senza avviso: 3');
    });

    test('di serie solo quelle senza avviso; quelle avvisate si mostrano a richiesta, con l\'ultimo avviso', async () => {
        w = mount(Sezione, { attachTo: document.body, props: proprieta([compensazione(), compensazione({ chiave: '13:3', avvisata: true,
            ultimo: { canale: 'email', stato: 'inviata', quando: '2026-03-02 10:30', destinatario: 'anna@x.it', errore: null } })]) as any });
        expect(w.findAll('[data-testid="compensazione"]')).toHaveLength(1);
        await w.get('[data-testid="anche-avvisate"] button').trigger('click');
        const righe = w.findAll('[data-testid="compensazione"]');
        expect(righe).toHaveLength(2);
        expect(pulito(righe[1].text())).toContain('Avviso inviato il 02/03/2026 alle 10:30 a anna@x.it, consegna non provata');
    });

    test('il bollo non ha una scelta già fatta; l\'invio porta le compensazioni scelte e il bollo, e conta solo chi ha l\'email', async () => {
        w = mount(Sezione, { attachTo: document.body, props: proprieta([compensazione(), compensazione({ chiave: '14:5', persona: { id: 5, nome: 'Ugo Neri' }, ha_email: false })]) as any });
        expect((w.get('[data-testid="bollo-avvisi"]').element as HTMLSelectElement).value).toBe('');
        expect(w.get('[data-testid="invia-avvisi"]').attributes('disabled')).toBeDefined();
        await w.get('[aria-label="Scegli tutte"]').trigger('click');
        expect(pulito(w.get('[data-testid="invia-avvisi"]').text())).toBe('Invia 1 avviso per email');
        expect(pulito(w.get('[data-testid="stampa-avvisi"]').text())).toBe('Stampa 2 avvisi');
        await w.get('[data-testid="bollo-avvisi"]').setValue('non_dovuta');
        axiosPost.mockResolvedValueOnce({ data: { messaggi: 2, avvisi: 1, senza_indirizzo: ['Ugo Neri'], restanti: null } });
        await w.get('[data-testid="invia-avvisi"]').trigger('click');
        await flushPromises();
        expect(axiosPost.mock.calls[0]).toEqual(['/gestionale.ricevute.avvisi-compensazione.invia', { avvisi: ['12:3', '14:5'], bollo: 'non_dovuta' }]);
        expect(pulito(w.get('[data-testid="esito-avvisi"]').text())).toBe('1 avviso per email (2 messaggi). Senza un indirizzo email: Ugo Neri. Stampali per la posta.');
        expect(w.emitted('fatto')).toHaveLength(1);
    });

    test('senza compensazioni la sezione non c\'è', () => {
        w = mount(Sezione, { props: proprieta([]) as any });
        expect(w.find('[data-testid="compensazioni"]').exists()).toBe(false);
    });
});
