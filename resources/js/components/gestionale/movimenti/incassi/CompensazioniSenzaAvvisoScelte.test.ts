// @vitest-environment jsdom

/**
 * La sezione «Compensazioni senza avviso»: il bollo parte dall'ultima scelta di chi lavora in questo condominio (e lo dice); prima dell'invio
 * si vedono gli indirizzi a cui vanno gli avvisi; oltre il tetto per volta l'esito dice quanti avvisi restano; le frasi dicono quello che il
 * programma sa (un avviso stampato non è un avviso arrivato).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Sezione = (await import('./CompensazioniSenzaAvviso.vue')).default;
const { FRASE_SEZIONE } = await import('@/lib/gestionale/incassi/compensazioni');

const compensazione = (extra: Record<string, unknown> = {}) => ({
    chiave: '12:3', incasso_id: 12, numero: 'INC-2026-00002', data: '2026-03-01', persona: { id: 3, nome: 'Anna Bruni' }, importo: 2000,
    quote: [{ rata: 'Rata 2', piano: 'Preventivo 2026', unita: 'Int. 4', scadenza: '2026-03-31', importo: 2000 }],
    credito_di: ['Anna Bruni'], caso: 'senza_denaro', ricevuta_di: null, ha_email: true, indirizzi: ['anna@x.it', 'anna.casa@x.it'], avvisata: false, ultimo: null, ...extra,
});
const proprieta = (righe: unknown[], extra: Record<string, unknown> = {}) => ({ condominioId: 7, compensazioni: { righe, senza_avviso: righe.filter((r: any) => !r.avvisata).length,
    frase_bollo: 'Se l\'avviso sconta il bollo lo decidi tu.', ...extra } });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le scelte della sezione', () => {
    test('il bollo parte dall\'ultima scelta in questo condominio, e la sezione lo dice', () => {
        w = mount(Sezione, { props: proprieta([compensazione()], { bollo_ricordato: 'non_dovuta' }) as any });
        expect((w.get('[data-testid="bollo-avvisi"]').element as HTMLSelectElement).value).toBe('non_dovuta');
        expect(pulito(w.text())).toContain('Lo stato del bollo è quello del tuo ultimo avviso in questo condominio: cambialo se per questi è diverso.');
    });

    test('prima dell\'invio si vedono gli indirizzi a cui vanno gli avvisi', async () => {
        w = mount(Sezione, { attachTo: document.body, props: proprieta([compensazione(), compensazione({ chiave: '14:5', persona: { id: 5, nome: 'Ugo Neri' }, ha_email: false, indirizzi: [] })]) as any });
        expect(w.find('[data-testid="indirizzi-avvisi"]').exists()).toBe(false);
        await w.get('[aria-label="Scegli tutte"]').trigger('click');
        expect(pulito(w.get('[data-testid="indirizzi-avvisi"]').text())).toBe('Per email: Anna Bruni (anna@x.it, anna.casa@x.it).');
    });

    test('oltre il tetto per volta l\'esito dice quanti avvisi restano', async () => {
        w = mount(Sezione, { attachTo: document.body, props: proprieta([compensazione()]) as any });
        await w.get('[aria-label="Scegli tutte"]').trigger('click');
        axiosPost.mockResolvedValueOnce({ data: { messaggi: 50, avvisi: 25, senza_indirizzo: [], restanti: ['20:1', '21:2'] } });
        await w.get('[data-testid="invia-avvisi"]').trigger('click');
        await flushPromises();
        expect(pulito(w.get('[data-testid="esito-avvisi"]').text())).toBe('25 avvisi per email (50 messaggi). Ne restano 2: sono ancora scelti, premi di nuovo per mandarli.');
        expect((w.vm as any).scelte).toEqual(['20:1', '21:2']);
    });

    test('le frasi dicono quello che il programma sa', () => {
        expect(FRASE_SEZIONE).toBe('Il credito ha coperto le rate di queste persone senza un versamento nuovo, e la ricevuta dell\'incasso non le riguarda: '
            + 'nessun documento del programma glielo ha mandato. L\'avviso di compensazione sì.');
        w = mount(Sezione, { attachTo: document.body, props: proprieta([compensazione({ avvisata: true })]) as any });
        expect(pulito(w.text())).toContain('Tutte le compensazioni del periodo hanno un avviso stampato o mandato.');
    });
});
