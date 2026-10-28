// @vitest-environment jsdom

/**
 * L'elenco utenti con le sue tre card (Coda 398, decisione 141.3), montato con i dati che il server gli manda.
 *
 * Le card mostrano i numeri del server (l'archivio intero) e un clic **sostituisce** i filtri dell'elenco con il
 * suo: «da verificare» chiede i non verificati, «sospesi» lo stato sospeso, «totale» toglie ogni filtro. A risposta
 * arrivata la tabella si ricrea e la barra mostra ciò che l'elenco mostra davvero, senza mandare una richiesta di
 * rimbalzo. Aperta senza toccare niente, la pagina non manda niente.
 *
 * Il layout è finto (porta il contenuto e basta); le card, la tabella e la barra sono quelle vere.
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));
const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    router: { put: vi.fn(), get: inertia.get, post: vi.fn(), delete: vi.fn() },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ElencoUtenti = (await import('./ElencoUtenti.vue')).default;

const meta = { current_page: 1, per_page: 10, last_page: 1, total: 0 };
const stats = { totale: 7, da_verificare: 3, sospesi: 2 };
const layoutFinto = { template: '<div><slot /></div>' };

/** La pagina come la apre il server, con i filtri dati nell'indirizzo. */
function apri(filters: Record<string, unknown> = {}) {
    pagina.props = reactive({
        filters, meta, sort: null, direction: null, stats,
        ruoliDisponibili: [{ value: 'collaboratore', label: 'Collaboratore' }],
        auth: { user: { roles: [], permissions: [] } },
        flash: {},
    });

    return mount(ElencoUtenti, {
        props: { users: [], meta, stats },
        global: { stubs: { AppLayout: layoutFinto, UtentiLayout: layoutFinto, DataTablePagination: true } },
    });
}

const card = (elenco: ReturnType<typeof apri>, chiave: string) => elenco.get(`[data-card="${chiave}"]`);
const dopoLAttesa = () => vi.advanceTimersByTimeAsync(1000);
const parametri = () => inertia.get.mock.calls.at(-1)![1] as Record<string, unknown>;

/** Il server risponde alla richiesta: i filtri che rimanda sono quelli della richiesta, validati. */
function ilServerRisponde() {
    inertia.get.mockImplementation((_url: string, richiesta: Record<string, unknown>, opzioni?: { onSuccess?: () => void; onFinish?: () => void }) => {
        const filtri = Object.fromEntries(
            Object.entries(richiesta).filter(([chiave]) => ['name', 'roles', 'stato', 'verifica'].includes(chiave)),
        );
        (pagina.props as Record<string, unknown>).filters = filtri;
        opzioni?.onSuccess?.();
        opzioni?.onFinish?.();
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    inertia.get.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('ElencoUtenti — le card e la tabella (Coda 398)', () => {
    test('mostra le tre card con i numeri del server', () => {
        const elenco = apri();

        expect(elenco.findAll('[data-card]')).toHaveLength(3);
        expect(card(elenco, 'totale').find('span').text()).toBe('7');
        expect(card(elenco, 'da_verificare').find('span').text()).toBe('3');
        expect(card(elenco, 'sospesi').find('span').text()).toBe('2');
    });

    test('aperta senza toccare niente non manda nessuna richiesta, con i filtri accesi nell\'indirizzo', async () => {
        const elenco = apri({ name: "D'Àngelo", roles: ['collaboratore'], stato: ['sospeso'], verifica: ['non_verificato'] });

        await dopoLAttesa();

        expect(inertia.get).not.toHaveBeenCalled();
        // I numeri sono quelli del server, non quelli dell'elenco filtrato.
        expect(card(elenco, 'totale').find('span').text()).toBe('7');
    });

    test('le card stanno sopra la tabella, nello stesso contenitore', () => {
        const elenco = apri();
        const html = elenco.html();

        expect(html.indexOf('data-card="totale"')).toBeGreaterThan(-1);
        expect(html.indexOf('data-card="totale"')).toBeLessThan(html.indexOf('<table'));
    });
});

describe('ElencoUtenti — il clic su una card applica il suo filtro (Coda 398)', () => {
    test('«da verificare» chiede i non verificati e toglie nome, ruolo e stato accesi prima', async () => {
        ilServerRisponde();
        const elenco = apri({ name: 'An', roles: ['collaboratore'], stato: ['attivo'] });

        await card(elenco, 'da_verificare').trigger('click');
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.calls[0][0]).toBe('/utenti.index');
        expect(parametri()).toEqual({ verifica: ['non_verificato'], per_page: 10, page: 1 });
    });

    test('«sospesi» chiede lo stato sospeso e toglie «Verifica» acceso prima', async () => {
        ilServerRisponde();
        const elenco = apri({ verifica: ['verificato'], name: 'An' });

        await card(elenco, 'sospesi').trigger('click');
        await dopoLAttesa();

        expect(parametri()).toEqual({ stato: ['sospeso'], per_page: 10, page: 1 });
    });

    test('«totale» toglie ogni filtro', async () => {
        ilServerRisponde();
        const elenco = apri({ name: 'An', roles: ['collaboratore'], stato: ['sospeso'], verifica: ['non_verificato'] });

        await card(elenco, 'totale').trigger('click');
        await dopoLAttesa();

        expect(parametri()).toEqual({ per_page: 10, page: 1 });
    });

    test('il clic tiene le righe per pagina e l\'ordinamento dell\'elenco, e torna a pagina 1', async () => {
        ilServerRisponde();
        const elenco = apri({ stato: ['attivo'] });
        Object.assign(pagina.props, { sort: 'last_login_at', direction: 'desc', meta: { ...meta, per_page: 25, current_page: 3 } });

        await card(elenco, 'sospesi').trigger('click');
        await dopoLAttesa();

        expect(parametri()).toEqual({ stato: ['sospeso'], sort: 'last_login_at', direction: 'desc', per_page: 25, page: 1 });
    });

    test('a risposta arrivata la barra mostra il filtro della card e il nome cercato prima sparisce', async () => {
        ilServerRisponde();
        const elenco = apri({ name: 'An', stato: ['attivo'] });
        expect((elenco.get('input').element as HTMLInputElement).value).toBe('An');

        await card(elenco, 'da_verificare').trigger('click');
        await dopoLAttesa();

        expect((elenco.get('input').element as HTMLInputElement).value).toBe('');
        const badge = elenco.findAll('button').filter((b) => b.text().includes('users.table.unverified'));
        expect(badge.length).toBeGreaterThan(0);
        expect(elenco.findAll('button').some((b) => b.text().includes('users.table.active'))).toBe(false);
        expect(elenco.text()).toContain('users.table.clear_all_filters');
    });

    test('dopo «totale» la barra è vuota e non manda una richiesta di rimbalzo', async () => {
        ilServerRisponde();
        const elenco = apri({ name: 'An', roles: ['collaboratore'], stato: ['sospeso'], verifica: ['non_verificato'] });

        await card(elenco, 'totale').trigger('click');
        await dopoLAttesa();

        // Una sola richiesta: quella del clic. La barra ricreata non ne manda un'altra, e non offre più «Rimuovi filtri».
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect((elenco.get('input').element as HTMLInputElement).value).toBe('');
        expect(elenco.text()).not.toContain('users.table.clear_all_filters');
    });

    test('dopo il clic su una card, toccare un altro filtro tiene quello della card', async () => {
        ilServerRisponde();
        const elenco = apri({ name: 'An' });

        await card(elenco, 'sospesi').trigger('click');
        await dopoLAttesa();
        inertia.get.mockClear();

        await elenco.get('input').setValue('Elena');
        await dopoLAttesa();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(parametri()).toEqual({ name: 'Elena', stato: ['sospeso'], per_page: 10, page: 1 });
    });

    test('finché il server non risponde la tabella non si ricrea: la barra tiene ciò che si è scritto', async () => {
        // Nessuna risposta: né `onSuccess` né `onFinish`. Chi ha scritto un nome e non ha ancora aspettato la ricerca
        // non lo perde per un clic su una card che il server non ha ancora servito.
        inertia.get.mockImplementation(() => undefined);
        const elenco = apri({ name: 'An' });
        await elenco.get('input').setValue('Anna');

        await card(elenco, 'sospesi').trigger('click');

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(parametri()).toEqual({ stato: ['sospeso'], per_page: 10, page: 1 });
        expect((elenco.get('input').element as HTMLInputElement).value).toBe('Anna');
    });
});
