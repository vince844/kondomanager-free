// @vitest-environment jsdom

/**
 * La barra dei filtri dell'elenco utenti (Coda 398): il filtro «Verifica» accanto a «Stato».
 *
 * Quattro garanzie, tutte con la tabella vera (`DataTable.vue`, le colonne vere) e la barra vera, non una copia:
 *
 * 1. Il filtro «Verifica» ha le sue due scelte (verificati, non verificati) e manda `verifica` al server insieme agli
 *    altri filtri, senza perderne nessuno: la richiesta riparte da ciò che c'è e ogni filtro vuoto viaggia come `null`.
 * 2. La barra nasce **allineata a ciò che il server ha già applicato**: l'elenco arriva filtrato quando l'indirizzo
 *    porta i filtri (un segnalibro, la pagina ricaricata, una card), e una barra vuota sopra un elenco filtrato
 *    perderebbe gli altri filtri al primo tocco. Aperta senza toccare niente non manda nessuna richiesta.
 * 3. «Rimuovi filtri» toglie anche «Verifica».
 * 4. La colonna su cui vive il filtro esiste ma l'elenco non la mostra.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));
const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: vi.fn(), get: inertia.get, post: vi.fn(), delete: vi.fn() },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTable = (await import('./DataTable.vue')).default;
const DataTableToolbar = (await import('./DataTableToolbar.vue')).default;
const DataTableFacetedFilter = (await import('./DataTableFacetedFilter.vue')).default;
const { columns } = await import('./columns');

const meta = { current_page: 1, per_page: 10, last_page: 1, total: 0 };
const ruoliDisponibili = [
    { value: 'amministratore', label: 'Amministratore' },
    { value: 'collaboratore', label: 'Collaboratore' },
    { value: 'Contabile', label: 'Contabile' },
];

/** L'elenco utenti con la barra vera, come lo apre il server con i filtri dati. */
function monta(filters: Record<string, unknown> = {}, data: unknown[] = [], attachTo?: HTMLElement) {
    pagina.props = { filters, meta, sort: null, direction: null, ruoliDisponibili, auth: { user: { roles: [], permissions: [] } } };

    return mount(DataTable, {
        props: { columns: columns as never, data: data as never, meta },
        attachTo,
        global: { stubs: { DataTablePagination: true } },
    });
}

/** La tabella della barra: è quella su cui il filtro sfaccettato scrive quando si sceglie un'opzione. */
const tabellaDi = (elenco: ReturnType<typeof monta>) =>
    elenco.findComponent(DataTableToolbar).props('table') as unknown as import('@tanstack/vue-table').Table<unknown>;

/** Il filtro sfaccettato che ha quel titolo. */
const filtroConTitolo = (elenco: ReturnType<typeof monta>, titolo: string) =>
    elenco.findAllComponents(DataTableFacetedFilter).find((f) => f.props('title') === titolo)!;

/** Passa il tempo dell'attesa prima che la barra mandi la richiesta. */
const dopoLAttesa = () => vi.advanceTimersByTimeAsync(1000);

/** I parametri dell'ultima richiesta mandata. */
const ultimaRichiesta = () => inertia.get.mock.calls.at(-1)![1] as Record<string, unknown>;

beforeEach(() => {
    vi.useFakeTimers();
    inertia.get.mockReset();
    // Come il router vero: a risposta arrivata, finisce la richiesta.
    inertia.get.mockImplementation((_url: string, _parametri: unknown, opzioni?: { onSuccess?: () => void; onFinish?: () => void }) => {
        opzioni?.onSuccess?.();
        opzioni?.onFinish?.();
    });
});

afterEach(() => {
    vi.useRealTimers();
});

describe('DataTableToolbar — il filtro «Verifica» (Coda 398)', () => {
    test('offre i verificati e i non verificati, accanto a «Stato»', () => {
        const elenco = monta();

        const verifica = filtroConTitolo(elenco, 'users.table.verification');

        expect(verifica).toBeTruthy();
        expect((verifica.props('options') as { value: string; label: string }[]).map((o) => [o.value, o.label])).toEqual([
            ['verificato', 'users.table.verified'],
            ['non_verificato', 'users.table.unverified'],
        ]);
        // Accanto a «Stato»: l'ordine delle barre è ruolo, stato, verifica.
        expect(elenco.findAllComponents(DataTableFacetedFilter).map((f) => f.props('title'))).toEqual([
            'users.table.role', 'users.table.status', 'users.table.verification',
        ]);
    });

    test('scelto dalla tendina, «Non verificati» manda `verifica` al server e la tendina lo segna', async () => {
        // jsdom non conosce `scrollIntoView`, che la lista della tendina chiama quando si sceglie una voce.
        Element.prototype.scrollIntoView = vi.fn();
        const elenco = monta({}, [], document.body);

        await filtroConTitolo(elenco, 'users.table.verification').get('button').trigger('click');
        await vi.advanceTimersByTimeAsync(50);
        const voce = Array.from(document.body.querySelectorAll<HTMLElement>('[role="option"]'))
            .find((v) => v.textContent?.includes('users.table.unverified'))!;
        voce.click();
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(ultimaRichiesta()).toEqual({ verifica: ['non_verificato'], per_page: 10, page: 1 });
        expect(filtroConTitolo(elenco, 'users.table.verification').text()).toContain('users.table.unverified');

        elenco.unmount();
        document.body.innerHTML = '';
    });

    test('sceglierlo manda `verifica` al server, a pagina 1, e il resto dei filtri come vuoto', async () => {
        const elenco = monta();

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(['non_verificato']);
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.calls[0][0]).toBe('/utenti.index');
        expect(ultimaRichiesta()).toEqual({ verifica: ['non_verificato'], per_page: 10, page: 1 });
    });

    test('con «Stato» e il nome già accesi, sceglierlo li tiene', async () => {
        const elenco = monta({ name: "D'Àngelo", stato: ['sospeso'] });

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(['verificato']);
        await dopoLAttesa();

        expect(ultimaRichiesta()).toEqual({
            name: "D'Àngelo", stato: ['sospeso'], verifica: ['verificato'], per_page: 10, page: 1,
        });
    });

    test('con il ruolo e lo stato già accesi, scegliere «Non verificati» manda la richiesta con `verifica` e tiene gli altri due', async () => {
        const elenco = monta({ roles: ['amministratore'], stato: ['attivo'] });

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(['non_verificato']);
        await dopoLAttesa();

        // Garanzia 1 dell'intestazione: la scelta manda `verifica` insieme agli altri filtri, qualunque sia quello che cambia.
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(ultimaRichiesta()).toEqual({ roles: ['amministratore'], stato: ['attivo'], verifica: ['non_verificato'], per_page: 10, page: 1 });
    });

    test('con lo stato e «Verifica» già accesi, scegliere un ruolo manda la richiesta con `roles` e tiene gli altri due', async () => {
        const elenco = monta({ stato: ['attivo'], verifica: ['non_verificato'] });

        tabellaDi(elenco).getColumn('roles')!.setFilterValue(['Contabile']);
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(ultimaRichiesta()).toEqual({ roles: ['Contabile'], stato: ['attivo'], verifica: ['non_verificato'], per_page: 10, page: 1 });
    });

    test('con «Verifica» già acceso, toccare lo stato e il ruolo lo tiene', async () => {
        const elenco = monta({ verifica: ['non_verificato'] });

        tabellaDi(elenco).getColumn('suspended_at')!.setFilterValue(['attivo']);
        tabellaDi(elenco).getColumn('roles')!.setFilterValue(['Contabile']);
        await dopoLAttesa();

        expect(ultimaRichiesta()).toEqual({
            verifica: ['non_verificato'], stato: ['attivo'], roles: ['Contabile'], per_page: 10, page: 1,
        });
    });

    test('con «Verifica» già acceso, cercare per nome lo tiene', async () => {
        const elenco = monta({ verifica: ['non_verificato'] });

        await elenco.get('input').setValue('Elena');
        await dopoLAttesa();

        expect(ultimaRichiesta()).toEqual({ name: 'Elena', verifica: ['non_verificato'], per_page: 10, page: 1 });
    });

    test('svuotarlo manda `verifica` come vuoto: il filtro si toglie dall\'elenco', async () => {
        const elenco = monta({ verifica: ['non_verificato'] });

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(undefined);
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(ultimaRichiesta()).toEqual({ per_page: 10, page: 1 });
    });

    test('scegliere entrambi i valori li manda così come sono: «tutti» lo decide il server', async () => {
        const elenco = monta();

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(['verificato', 'non_verificato']);
        await dopoLAttesa();

        expect(ultimaRichiesta()).toEqual({ verifica: ['verificato', 'non_verificato'], per_page: 10, page: 1 });
    });
});

describe('DataTableToolbar — la barra nasce allineata all\'elenco che si vede (Coda 398)', () => {
    // I dati scomodi: un nome con l'apostrofo e le lettere accentate, un ruolo scritto con la maiuscola, tutti e
    // quattro i filtri accesi insieme.
    const filtriDelServer = {
        name: "D'Àngelo",
        roles: ['collaboratore', 'Contabile'],
        stato: ['sospeso'],
        verifica: ['non_verificato'],
    };

    test('aperta senza toccare niente non manda nessuna richiesta', async () => {
        monta(filtriDelServer);

        await dopoLAttesa();

        expect(inertia.get).not.toHaveBeenCalled();
    });

    test('aperta senza filtri non manda nessuna richiesta e non offre «Rimuovi filtri»', async () => {
        const elenco = monta();

        await dopoLAttesa();

        expect(inertia.get).not.toHaveBeenCalled();
        expect(elenco.text()).not.toContain('users.table.clear_all_filters');
    });

    test('mostra il nome cercato, i badge dei quattro filtri e «Rimuovi filtri»', () => {
        const elenco = monta(filtriDelServer);

        expect((elenco.get('input').element as HTMLInputElement).value).toBe("D'Àngelo");
        expect(filtroConTitolo(elenco, 'users.table.role').text()).toContain('Collaboratore');
        expect(filtroConTitolo(elenco, 'users.table.role').text()).toContain('Contabile');
        expect(filtroConTitolo(elenco, 'users.table.status').text()).toContain('users.table.suspended');
        expect(filtroConTitolo(elenco, 'users.table.verification').text()).toContain('users.table.unverified');
        expect(filtroConTitolo(elenco, 'users.table.verification').text()).not.toContain('users.table.verified');
        expect(elenco.text()).toContain('users.table.clear_all_filters');
    });

    test('un solo filtro nell\'indirizzo non accende gli altri', () => {
        const elenco = monta({ verifica: ['verificato'] });

        expect((elenco.get('input').element as HTMLInputElement).value).toBe('');
        expect(filtroConTitolo(elenco, 'users.table.verification').text()).toContain('users.table.verified');
        expect(filtroConTitolo(elenco, 'users.table.status').text()).not.toContain('users.table.suspended');
        expect(filtroConTitolo(elenco, 'users.table.status').text()).not.toContain('users.table.active');
        expect(filtroConTitolo(elenco, 'users.table.role').text()).not.toContain('Collaboratore');
    });

    test('il primo filtro toccato tiene gli altri che l\'elenco ha già applicato', async () => {
        const elenco = monta(filtriDelServer);

        tabellaDi(elenco).getColumn('suspended_at')!.setFilterValue(['attivo']);
        await dopoLAttesa();

        expect(ultimaRichiesta()).toEqual({
            name: "D'Àngelo", roles: ['collaboratore', 'Contabile'], stato: ['attivo'], verifica: ['non_verificato'],
            per_page: 10, page: 1,
        });
    });
});

describe('DataTableToolbar — «Rimuovi filtri» (Coda 398)', () => {
    test('toglie anche «Verifica», insieme agli altri filtri', async () => {
        const elenco = monta({ name: 'An', roles: ['collaboratore'], stato: ['sospeso'], verifica: ['non_verificato'] });

        await elenco.findAll('button').find((b) => b.text().includes('users.table.clear_all_filters'))!.trigger('click');
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(ultimaRichiesta()).toEqual({ per_page: 10, page: 1 });
        expect(elenco.text()).not.toContain('users.table.clear_all_filters');
    });

    test('compare anche quando l\'unico filtro acceso è «Verifica»', () => {
        const elenco = monta({ verifica: ['verificato'] });

        expect(elenco.text()).toContain('users.table.clear_all_filters');
    });
});

describe('DataTable — la colonna del filtro «Verifica» (Coda 398)', () => {
    test('esiste nella tabella, ma l\'elenco non la mostra', () => {
        const elenco = monta();
        const colonna = tabellaDi(elenco).getColumn('email_verified_at')!;

        expect(colonna).toBeTruthy();
        expect(colonna.getIsVisible()).toBe(false);
        // L'intestazione della tabella non la porta: ci sono solo le colonne vere.
        expect(elenco.get('thead').text()).not.toContain('users.table.verification');
        expect(elenco.get('thead').text()).toContain('users.table.name');
        expect(elenco.get('thead').text()).toContain('users.table.status');
    });

    test('la riga «nessun utente» occupa le colonne che si vedono, non quella nascosta', () => {
        const elenco = monta();
        const visibili = tabellaDi(elenco).getVisibleLeafColumns().length;

        expect(visibili).toBe(columns.length - 1);
        expect(elenco.get('tbody td').attributes('colspan')).toBe(String(visibili));
    });

    test('il filtro non filtra le righe a video: l\'elenco lo decide il server', async () => {
        const utente = (id: number, name: string, verificata: string | null) => ({
            id, name, email: `${name}@example.com`, suspended_at: null, email_verified_at: verificata,
            roles: [], permissions: [], anagrafica: null, gestibile: false,
        });
        const elenco = monta({}, [utente(1, 'Anna', '2026-10-01'), utente(2, 'Elena', null)]);

        tabellaDi(elenco).getColumn('email_verified_at')!.setFilterValue(['non_verificato']);
        await elenco.vm.$nextTick();

        expect(elenco.findAll('tbody tr')).toHaveLength(2);
    });
});
