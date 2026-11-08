// @vitest-environment jsdom

/**
 * Lo stato patrimoniale porta all'elenco «Rate scadute» alla data della sua fotografia: per un esercizio chiuso la data di fine, non oggi.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./Show.vue')).default;

const vuoto = { gruppi: [], totale: 0 };
const pagina = (extra: Record<string, unknown> = {}) => ({
    data: '2026-12-31', dal: '2026-01-01', stato_esercizio: 'chiuso',
    situazione: { attivo: vuoto, passivo: vuoto, costi_voci: [], ricavi_voci: [], liquidita_non_contabilizzata: 0, costi: 0, ricavi: 0, risultato_motore: 0, sbilancio: 0, quadra: true },
    sintesi: { liquidita: 0, in_fondi: 0, crediti_condomini: 26000, debiti_fornitori: 0, debiti_erario: 0, spese_anticipate: 0 },
    risultato_gestione: { quote_emesse: 0, costi: 0, risultato: 0, cumulato: 0 },
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31', stato_esercizio: 'chiuso', righe: [], totale: { iniziale: 0, entrate: 0, uscite: 0, finale: 0 },
        giroconti: { entrate: 0, uscite: 0 }, stornate: { coppie: 0, importo: 0 }, piede_quadra: true, casse_reali_negative: [],
    },
    liquidita: {
        liquidita_totale: 0, libera: 0, in_fondi: 0, vincolata: 0, generica: 0, altra: 0, altra_voci: [], fondi: [], vincolo_registrato: 0, vincolo_su_fondi: 0,
        vincolo_non_accantonato: 0, scoperto_fondi: 0, avanzo_registrato: 0, gia_speso_acconto: 0, esito_r8: 'quadra',
    },
    raccordo: { righe: [], somma: 0, variazione_liquidita: 0, scarto: 0, quadra: true },
    debiti: { alla: '2026-12-31', fatture: [], totale_residui: 0, saldo_fornitori: 0, saldo_erario: 0, erario_su_fatture_non_pagate: 0, scarto: 0, scarto_voci: [] },
    per_gestione: {
        righe: [], senza_voce: 0, senza_gestione: { quote_emesse: 0, crediti: 0, debiti: 0 }, differenza_debiti: 0, totale: { costi: 0, quote_emesse: 0, crediti: 0, debiti: 0 },
        fatture_altra_gestione: [], nota_altra_gestione: null, nota_liquidita: 'La liquidità è del condominio.',
    },
    controlli: [],
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w?.unmount();
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 }, esercizi: [], pagina: pagina(extra) } as any,
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true,
                DropdownMenu: true, DropdownMenuTrigger: true, DropdownMenuContent: true, DropdownMenuItem: true, Dialog: true, DialogContent: true,
                DialogHeader: true, DialogTitle: true, DialogDescription: true,
            },
        },
    });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });

describe('il collegamento alle rate scadute', () => {
    test('da un esercizio chiuso apre l\'elenco alla data di fine, la data della fotografia', () => {
        const href = monta().get('[data-testid="collegamento-rate-scadute"]').attributes('href');
        expect(href).toBe('/gestionale/3/rate-scadute?alla=2026-12-31');
    });

    test('dall\'esercizio aperto, alla data della pagina (oggi)', () => {
        const href = monta({ data: '2027-01-17', stato_esercizio: 'aperto' }).get('[data-testid="collegamento-rate-scadute"]').attributes('href');
        expect(href).toBe('/gestionale/3/rate-scadute?alla=2027-01-17');
    });
});
