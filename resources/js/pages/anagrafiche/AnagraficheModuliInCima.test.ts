// @vitest-environment jsdom

/**
 * Dopo «Salva» il messaggio dell'esito è dove si guarda (decisione 139). «Salva» sta in fondo al modulo e il messaggio in testa:
 * dopo un salvataggio riuscito la finestra torna in cima, anche quando il server riporta chi non consulta l'elenco allo stesso
 * modulo (`AnagraficaController::dopoIlSalvataggio`); con un errore di validazione resta accanto ai campi. La visita è quella
 * vera del router di Inertia, con le opzioni che il modulo passa; il server finto risponde con la pagina dove porta il ritorno.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/anagrafiche/create',
        props: { flash: {}, auth: { user: { roles: ['Su misura'], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.id ? `/${parametri.id}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const AnagraficheNew = (await import('./AnagraficheNew.vue')).default;
const AnagraficheEdit = (await import('./AnagraficheEdit.vue')).default;

/** Il server: risponde alla visita con la pagina Inertia data (come dopo il ritorno del controller). */
class ServerFinto {
    static pagina: Record<string, unknown> = {};
    status = 200;
    responseText = '';
    upload = {};
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    onabort: (() => void) | null = null;
    open() {}
    setRequestHeader() {}
    getAllResponseHeaders() { return 'x-inertia: true\r\ncontent-type: application/json'; }
    send() {
        this.responseText = JSON.stringify(ServerFinto.pagina);
        setTimeout(() => this.onload?.());
    }
}

const passa = { template: '<div><slot /></div>' };
function monta(pagina: unknown, props: Record<string, unknown>) {
    return mount(pagina as never, {
        props: props as never,
        global: {
            mocks: { route },
            stubs: { AppLayout: passa, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, VueDatePicker: true, HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa },
        },
    });
}

/** Le opzioni con cui il modulo manda «Salva». */
function opzioniDelModulo(pagina: unknown, props: Record<string, unknown>, metodo: 'post' | 'put') {
    const spia = vi.spyOn(router, metodo).mockImplementation(() => undefined as never);
    const p = monta(pagina, props);
    (p.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
    const [url, , opzioni] = spia.mock.calls.at(-1)! as [string, unknown, Record<string, unknown>];
    spia.mockRestore();
    p.unmount();

    return { url, opzioni };
}

/** La visita vera, con le opzioni del modulo; il server porta alla pagina data. Torna se la finestra è andata in cima. */
async function visita(metodo: 'post' | 'put', url: string, opzioni: Record<string, unknown>, pagina: Record<string, unknown>) {
    ServerFinto.pagina = pagina;
    const inCima = vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    await new Promise<void>((fine) => {
        router[metodo](url, { nome: 'X' }, { ...opzioni, onSuccess: undefined, onError: undefined, onFinish: () => fine() } as never);
    });
    await new Promise((r) => setTimeout(r, 20));
    const andataInCima = inCima.mock.calls.length > 0;
    inCima.mockRestore();

    return andataInCima;
}

beforeEach(() => {
    (globalThis as Record<string, unknown>).XMLHttpRequest = ServerFinto;
    router.init({
        initialPage: { component: 'anagrafiche/AnagraficheNew', props: { errors: {} }, url: '/admin/anagrafiche/create', version: null } as never,
        resolveComponent: () => ({}) as never,
        swapComponent: async () => {},
    } as never);
});
afterEach(() => vi.restoreAllMocks());

describe('I moduli delle anagrafiche — dopo «Salva» il messaggio è dove si guarda (decisione 139)', () => {
    test('decisione 139 — solo «Crea»: dopo «Salva» riuscito, di nuovo sul modulo nuovo con il messaggio, la finestra torna in cima', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea anagrafiche'];
        const { url, opzioni } = opzioniDelModulo(AnagraficheNew, { condomini: [] }, 'post');

        expect(await visita('post', url, opzioni, {
            component: 'anagrafiche/AnagraficheNew', url: '/admin/anagrafiche/create', version: null,
            props: { errors: {}, flash: { message: { type: 'success', message: 'La nuova anagrafica è stata creata con successo.' } } },
        })).toBe(true);
    });

    test('decisione 139 — solo «Modifica»: dopo «Salva» riuscito, di nuovo sul modulo di modifica con il messaggio, la finestra torna in cima', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Modifica anagrafiche'];
        const { url, opzioni } = opzioniDelModulo(AnagraficheEdit, { anagrafica: { id: 7, nome: 'Mario', indirizzo: 'Via', condomini: [] }, condomini: [] }, 'put');

        expect(await visita('put', url, opzioni, {
            component: 'anagrafiche/AnagraficheEdit', url: '/admin/anagrafiche/7/edit', version: null,
            props: { errors: {}, flash: { message: { type: 'success', message: 'L\'anagrafica è stata aggiornata con successo.' } } },
        })).toBe(true);
    });

    test('decisione 139 — con un errore di validazione il modulo resta dov\'è, come prima', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea anagrafiche'];
        const { url, opzioni } = opzioniDelModulo(AnagraficheNew, { condomini: [] }, 'post');

        expect(await visita('post', url, opzioni, {
            component: 'anagrafiche/AnagraficheNew', url: '/admin/anagrafiche/create', version: null,
            props: { errors: { nome: 'Il campo nome è obbligatorio.' }, flash: {} },
        })).toBe(false);
    });
});
