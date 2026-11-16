// @vitest-environment jsdom

/**
 * La proposta degli interessi con un tasso legale scritto a mano dallo studio: la frase dice l'anno, il tasso, chi l'ha scritto, quando e la fonte; il
 * tratto dice la sua fonte. Senza tassi scritti a mano la frase non c'è.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./ProponiInteressiDialog.vue')).default;

const FRASE = 'Il tasso legale del 2027, 2,25 %, è scritto a mano: da Mario Rossi il 16/12/2026, con la fonte «D.M. 10/12/2026, G.U. n. 290 del 14/12/2026».';
const tratto = (dal: string, al: string, giorni: number, tasso: string, fonte: string, esatti: number, causa: string) => ({ dal, al, giorni, capitale: 30000, tasso, fonte, divisore: 365, esatti, causa });
const proposta = (tassiAMano?: string[]) => ({
    alla: '2027-01-31',
    persone: [{ anagrafica_id: 1, nome: 'Neri', totale: 419, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0, righe: [
        { chiave: '1|10|5', rata_id: 10, immobile_id: 5, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
            scadenza: '2026-03-31', dal: '2026-04-01', gia_registrati_al: null, interessi: 419, fermo: null,
            tratti: [tratto('2026-04-01', '2026-12-31', 275, '1,60 %', 'tasso legale', 36164, 'scadenza'), tratto('2027-01-01', '2027-01-31', 31, '2,25 %', 'tasso legale scritto a mano', 5733, 'anno')] },
    ] }],
    totale: 419,
    gestioni: [{ gestione_id: 2, gestione: 'Ordinaria 2026', scrive: null, perche: 'Nessun esercizio aperto.', voci: [], voce_proposta: null }],
    regole: { righe: [], di_oggi: null, avviso: null },
    frase_domanda_giudiziale: 'Dopo la domanda giudiziale il tasso è un altro (art. 1284 c. 4 c.c.): gli interessi di una causa li quantifica il legale.',
    ...(tassiAMano === undefined ? {} : { tassi_a_mano: tassiAMano }),
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = () => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, oggi: '2027-01-31', persone: [1] } });

    return w;
};

describe('la proposta con un tasso legale scritto a mano', () => {
    test('la frase dice l\'anno, il tasso, chi l\'ha scritto, quando e la fonte; il tratto dice la sua fonte', async () => {
        axiosGet.mockResolvedValue({ data: proposta([FRASE]) });
        monta();
        await flushPromises();
        expect([...document.body.querySelectorAll('[data-testid="tasso-a-mano"]')].map((p) => pulito(p.textContent))).toEqual([FRASE]);
        document.body.querySelector<HTMLButtonElement>('[data-testid="apri-tratti"]')!.click();
        await flushPromises();
        expect(pulito(document.body.querySelectorAll('[data-testid="tratti"] li')[1]?.textContent))
            .toBe('dal 01/01/2027 al 31/01/2027, 31 giorni su € 300,00 al 2,25 % (tasso legale scritto a mano), su 365: € 0,5733 (anno nuovo, tasso dell\'anno)');
    });

    test('senza tassi scritti a mano, e con una risposta che non li porta, la frase non c\'è', async () => {
        axiosGet.mockResolvedValue({ data: proposta() });
        monta();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="persona-interessi"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="tasso-a-mano"]')).toBeNull();
    });
});
