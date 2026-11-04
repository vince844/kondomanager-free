// @vitest-environment jsdom

/**
 * Coda 349 (decisione 184) — il riquadro «Gestione saldi pregressi» della pagina dei piani rate porta ai saldi iniziali.
 *
 * Il terzo riquadro della guida in testa alla pagina ha un titolo che sembra un accesso («Gestione saldi pregressi») e non portava da nessuna
 * parte: un'utente che migrava da GeCoMAX, cercando dove si dichiarano i saldi, ci ha fatto doppio clic e non è successo niente. I saldi
 * pregressi si dichiarano in Struttura → «Saldi Iniziali» (`gestionale/:condominio/saldi`), che lavora sull'esercizio aperto del condominio e,
 * senza un esercizio aperto, rifiuta con «Nessun esercizio aperto trovato» (`SaldoInizialeController::index`): il rimando compare solo dove
 * la pagina dei saldi risponde.
 *
 * La pagina è quella vera, con la cornice, la tabella e la guida in testa: del rimando si legge il testo e dove porta.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        // Come il composable vero: il prefisso del ruolo e i segnaposto del percorso riempiti (`:condominio` diventa l'id).
        generatePath: (percorso: string, parametri: Record<string, number> = {}) => '/admin/' + percorso.replace(/:(\w+)/g, (_, nome: string) => String(parametri[nome])),
        generateRoute: (nome: string) => nome,
        hasPermission: () => true,
        hasRole: () => true,
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const PianiRateList = (await import('./PianiRateList.vue')).default;

let pagina: VueWrapper | null = null;

const CONDOMINIO = { id: 7, nome: 'Condominio Demo' };
const APERTO = { id: 1, nome: '2026', stato: 'aperto', data_inizio: '2026-01-01', data_fine: '2026-12-31', descrizione: '', note: '' };
const CHIUSO = { id: 2, nome: '2025', stato: 'chiuso', data_inizio: '2025-01-01', data_fine: '2025-12-31', descrizione: '', note: '' };

function apri(esercizio = APERTO, esercizi = [APERTO, CHIUSO]) {
    pagina = mount(PianiRateList, {
        props: {
            condominio: CONDOMINIO as never,
            condomini: [CONDOMINIO] as never,
            esercizio: esercizio as never,
            esercizi: esercizi as never,
            pianiRate: [],
            meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } as never,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, DataTable: true } },
    });

    return pagina;
}

/** Il riquadro della guida col suo titolo (il titolo è il primo testo del blocco). */
const riquadro = (titolo: string) => {
    const blocco = pagina!.findAll('div.space-y-3').find((d) => d.find('span.uppercase').exists() && d.find('span.uppercase').text().toLowerCase() === titolo.toLowerCase());
    if (!blocco) throw new Error(`nessun riquadro «${titolo}»`);
    return blocco;
};

afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('il riquadro «Gestione saldi pregressi» porta ai saldi iniziali (Coda 349)', () => {
    test('il riquadro ha il rimando «Vai ai saldi iniziali», verso la pagina dei saldi del condominio', () => {
        apri();
        const rimando = riquadro('Gestione Saldi Pregressi').find('a');
        expect(rimando.exists()).toBe(true);
        expect(rimando.text()).toBe('Vai ai saldi iniziali');
        expect(rimando.attributes('href')).toBe('/admin/gestionale/7/saldi');
    });

    test('il rimando è anche sulla pagina di un esercizio chiuso, finché un altro esercizio è aperto: i saldi lavorano su quello aperto', () => {
        apri(CHIUSO);
        expect(riquadro('Gestione Saldi Pregressi').find('a').attributes('href')).toBe('/admin/gestionale/7/saldi');
    });

    test('senza un esercizio aperto il rimando non c\'è: la pagina dei saldi rifiuterebbe', () => {
        apri(CHIUSO, [CHIUSO]);
        expect(riquadro('Gestione Saldi Pregressi').find('a').exists()).toBe(false);
    });

    test('gli altri due riquadri non cambiano: nessun rimando, lo stesso testo', () => {
        apri();
        expect(riquadro('Emissione Rate').find('a').exists()).toBe(false);
        expect(riquadro('Piani Integrativi').find('a').exists()).toBe(false);
        expect(riquadro('Emissione Rate').text()).toContain('Pianifica le scadenze per la riscossione delle quote.');
        expect(riquadro('Gestione Saldi Pregressi').text()).toContain('Consigliamo di isolare il "Saldo Iniziale" dell\'anno precedente in una rata separata.');
    });
});
