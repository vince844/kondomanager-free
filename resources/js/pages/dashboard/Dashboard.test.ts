// @vitest-environment jsdom

/**
 * La dashboard del pannello mostra la frase con cui il server ci rimanda chi ha il pannello e apre una
 * pagina del portale senza un'anagrafica collegata all'account (decisione 134.4, `CheckHasAnagrafica`).
 * Chi ci arriva dall'accesso, senza avviso, la vede come prima. Il server:
 * `tests/Feature/Portale/PortaleSenzaAnagraficaTest.php`.
 *
 * E il pulsante della casella delle attività compare a chi la può aprire, con «Visualizza scadenze in agenda» (Coda 402,
 * `tests/Feature/CasellaDelleAttivitaPermessiTest.php`).
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import Alert from '@/components/Alert.vue';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
// I permessi di chi apre la pagina: `null` = tutti (l'amministratore), altrimenti l'elenco dei concessi.
const permessi = vi.hoisted(() => ({ concessi: null as string[] | null }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    usePage: () => pagina,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/admin/${p}`, generateRoute: (n: string) => `admin.${n}`, hasRole: () => true,
        hasPermission: (chiesti: string[]) => permessi.concessi === null || chiesti.some((p) => permessi.concessi!.includes(p)),
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const Dashboard = (await import('./Dashboard.vue')).default;

beforeEach(() => {
    permessi.concessi = null;
});

function monta(flash: Record<string, unknown>) {
    pagina.props = { auth: { user: { id: 1, email: 'studio@example.com', roles: ['amministratore'], permissions: [] } }, inbox_count: 0, is_demo: true, flash };

    return shallowMount(Dashboard, {
        props: {
            stats: { total_condomini: 1, segnalazioni_aperte: 0, scadenze_imminenti: 0, storage: { used_bytes: 0, used_formatted: '0 B', total_files: 0 } },
            segnalazioni: [],
            comunicazioni: [],
            documenti: [],
            eventi: { total: 0, data: [] },
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('Dashboard del pannello — la frase di chi arriva dal portale senza anagrafica', () => {
    test('con l\'avviso del server, la pagina lo mostra com\'è', () => {
        const frase = "Il portale dei condòmini si apre solo con un'anagrafica collegata all'account, e il tuo non ne ha una: usa il pannello.";
        const avviso = monta({ message: { type: 'warning', message: frase } }).findComponent(Alert);

        expect(avviso.exists()).toBe(true);
        expect(avviso.props('message')).toBe(frase);
        expect(avviso.props('type')).toBe('warning');
    });

    test('senza avviso, come chi ci arriva dall\'accesso, la pagina non mostra niente in più', () => {
        expect(monta({}).findComponent(Alert).exists()).toBe(false);
        expect(monta({ message: null }).findComponent(Alert).exists()).toBe(false);
    });
});

/**
 * Il server manda un blocco (segnalazioni, comunicazioni, documenti, scadenze) solo a chi può aprire il suo elenco nel
 * pannello (decisione 137, `DashboardController`; il server: `tests/Feature/DashboardDelPannelloSoloCioCheSiApreTest.php`).
 * La pagina regge il blocco che manca anche quando il permesso che guarda per il riquadro c'è (per esempio i documenti, che
 * il server dà solo insieme all'accesso al pannello): dice che non si può vedere, non disegna un elenco vuoto né si rompe.
 */
describe('Dashboard del pannello — un blocco che il server non manda', () => {
    const blocchi = {
        segnalazioni: [] as unknown[],
        comunicazioni: [] as unknown[],
        documenti: [] as unknown[],
        eventi: { total: 0, data: [] as unknown[] },
    };

    async function montaCon(presenti: Partial<typeof blocchi>) {
        pagina.props = { auth: { user: { id: 1, email: 'studio@example.com', roles: ['amministratore'], permissions: [] } }, inbox_count: 0, is_demo: true, flash: {} };
        const { default: SegnalazioniList } = await import('@/components/segnalazioni/SegnalazioniList.vue');
        const { default: ComunicazioniList } = await import('@/components/comunicazioni/ComunicazioniList.vue');
        const { default: DocumentiList } = await import('@/components/documenti/DocumentiList.vue');
        const { default: EventiList } = await import('@/components/eventi/EventiList.vue');
        const pagine = shallowMount(Dashboard, {
            props: {
                stats: { total_condomini: 1, segnalazioni_aperte: 0, scadenze_imminenti: 0, storage: { used_bytes: 0, used_formatted: '0 B', total_files: 0 } },
                ...presenti,
            } as never,
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });

        return {
            elenchi: {
                segnalazioni: pagine.findComponent(SegnalazioniList).exists(),
                comunicazioni: pagine.findComponent(ComunicazioniList).exists(),
                documenti: pagine.findComponent(DocumentiList).exists(),
                eventi: pagine.findComponent(EventiList).exists(),
            },
            testo: pagine.text(),
        };
    }

    test('senza nessuno dei quattro blocchi la pagina si apre e dice, per ognuno, che non si può vedere', async () => {
        const { elenchi, testo } = await montaCon({});

        expect(elenchi).toEqual({ segnalazioni: false, comunicazioni: false, documenti: false, eventi: false });
        expect(testo).toContain('segnalazioni.dialogs.no_view_permission');
        expect(testo).toContain('comunicazioni.dialogs.no_view_permission');
        expect(testo).toContain('Non hai permessi sufficienti per visualizzare documenti in archivio!');
        expect(testo).toContain('Non hai permessi sufficienti per visualizzare le scadenze in agenda!');
    });

    test('con un blocco solo, la pagina disegna quell\'elenco e non gli altri', async () => {
        expect((await montaCon({ documenti: blocchi.documenti })).elenchi)
            .toEqual({ segnalazioni: false, comunicazioni: false, documenti: true, eventi: false });
        expect((await montaCon({ eventi: blocchi.eventi })).elenchi)
            .toEqual({ segnalazioni: false, comunicazioni: false, documenti: false, eventi: true });
    });

    test('con i quattro blocchi la pagina disegna i quattro elenchi, come prima', async () => {
        expect((await montaCon(blocchi)).elenchi).toEqual({ segnalazioni: true, comunicazioni: true, documenti: true, eventi: true });
    });
});

describe('Dashboard del pannello — il pulsante della casella delle attività (Coda 402)', () => {
    // `shallowMount` mette uno stub al posto del collegamento e ne disegna il contenuto: il pulsante c'è se c'è il suo testo.
    const pulsante = (w: ReturnType<typeof monta>) => w.text().split('Action inbox').length - 1;

    test('con «Visualizza scadenze in agenda», come i ruoli di serie, il pulsante porta alla casella', () => {
        permessi.concessi = null;
        expect(pulsante(monta({}))).toBe(1);

        permessi.concessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda'];
        expect(pulsante(monta({}))).toBe(1);
    });

    test('senza «Visualizza scadenze in agenda» il pulsante non c\'è: la casella non si aprirebbe', () => {
        permessi.concessi = ['Accesso pannello amministratore', 'Visualizza segnalazioni', 'Modifica scadenza in agenda'];

        expect(pulsante(monta({}))).toBe(0);
    });
});
