// @vitest-environment jsdom

/**
 * Coda 404 — la finestra della password è della pagina in cui è nata la richiesta ferma.
 *
 * Una visita di Inertia ferma sul 423 si ritira da sola quando la pagina cambia: il suo segnale viene interrotto
 * (`lib/clientHttpConConferma.ts`). Una chiamata `fetch` o `axios` (la 2FA, la prova della posta, lo scarico di un backup)
 * non ha un segnale: la ritira il cambio di pagina di Inertia, cioè il tasto «indietro» o una visita verso un altro
 * indirizzo. La finestra si chiude e la richiesta non riparte, perché la password darebbe il via a un'azione che
 * l'amministratore non vede più. Una visita che resta sulla stessa pagina (un ricaricamento) non cambia pagina: la
 * richiesta ferma resta, e riparte con la conferma.
 *
 * Il router di Inertia è quello vero (`history.back()` di jsdom), con la finestra vera montata accanto; il client HTTP è
 * finto e risponde come il server (`ConfermaPasswordRecente`).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { HttpResponseError, http, router, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';

const post = vi.fn();

vi.mock('axios', () => ({ default: { post } }));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, quanti: number) => `${chiave} ${quanti}`,
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { annullaConferma, confermaPasswordAperta, confermaPoiRiprova, confermaRiuscita, richiediConferma } = await import('@/composables/useConfermaPassword');
const { conConferma } = await import('./clientHttpConConferma');
const FinestraConfermaPassword = (await import('@/components/FinestraConfermaPassword.vue')).default;

const richieste: HttpRequestConfig[] = [];
let risposte: Array<() => HttpResponse | HttpResponseError> = [];

const pagina = (componente: string, url: string) => ({ component: componente, props: { errors: {} }, url, version: '1', clearHistory: false, encryptHistory: false });
const giusta = (componente: string, url: string) => (): HttpResponse => ({ status: 200, headers: { 'x-inertia': 'true' }, data: JSON.stringify(pagina(componente, url)) });
const chiedeLaPassword = (): HttpResponseError =>
    new HttpResponseError('423', { status: 423, headers: {}, data: JSON.stringify({ message: 'conferma', conferma_password: true }) } as HttpResponse);

const clientDelServer = {
    request: async (config: HttpRequestConfig): Promise<HttpResponse> => {
        richieste.push(config);
        const risposta = risposte.shift()!();
        if (risposta instanceof HttpResponseError) throw risposta;
        return risposta;
    },
};

const attendi = (ms = 30) => new Promise((risolvi) => setTimeout(risolvi, ms));
const dialogo = () => document.body.querySelector('[role="dialog"]') as HTMLElement | null;

let finestra: VueWrapper;

/** Apre la pagina della 2FA come una visita che riesce. */
async function vaiAllaPaginaDellaDueFattori() {
    risposte = [giusta('settings/TwoFactor', '/settings/two-factor')];
    router.get('/settings/two-factor');
    await attendi();
}

/** Il tasto «indietro» del browser. */
async function tornaIndietro() {
    window.history.back();
    await attendi(80);
    await flushPromises();
}

beforeEach(async () => {
    richieste.length = 0;
    risposte = [];
    post.mockReset();
    annullaConferma();
    (HTMLDialogElement.prototype as unknown as { showModal: () => void }).showModal = vi.fn();
    http.setClient(conConferma(clientDelServer));
    await router.init({
        initialPage: pagina('dashboard/Dashboard', '/admin/dashboard') as never,
        resolveComponent: async () => ({}),
        swapComponent: async () => {},
    } as never);
    // L'evento della pagina iniziale parte a caricamento finito: si lascia passare prima di fermare qualcosa.
    await attendi();
    finestra = mount(FinestraConfermaPassword, { attachTo: document.body });
});

afterEach(() => {
    finestra.unmount();
    document.body.innerHTML = '';
    annullaConferma();
});

describe('la conferma della password e il cambio di pagina', () => {
    test('Coda 404 — una chiamata ferma sul 423 si ritira col tasto «indietro»: la finestra si chiude, chi l\'ha chiesta è avvisata e la password non la fa ripartire', async () => {
        await vaiAllaPaginaDellaDueFattori();
        const disattiva = vi.fn();
        const avviso = vi.fn();

        expect(confermaPoiRiprova(423, disattiva, avviso)).toBe(true);
        await flushPromises();
        expect(confermaPasswordAperta.value).toBe(true);
        expect(dialogo()).not.toBeNull();

        await tornaIndietro();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(confermaPasswordAperta.value).toBe(false);
        expect(dialogo()).toBeNull();
        expect(avviso).toHaveBeenCalledTimes(1);

        confermaRiuscita();

        expect(disattiva).not.toHaveBeenCalled();
    });

    test('Coda 404 — lo scarico di un backup, fermo sulla finestra senza nessun avviso da dare, si ritira col tasto «indietro» e non parte', async () => {
        await vaiAllaPaginaDellaDueFattori();
        const scarica = vi.fn();

        richiediConferma(scarica);
        expect(confermaPasswordAperta.value).toBe(true);

        await tornaIndietro();

        expect(confermaPasswordAperta.value).toBe(false);
        confermaRiuscita();

        expect(scarica).not.toHaveBeenCalled();
    });

    test('Coda 404 — la visita ferma e la chiamata ferma insieme: col tasto «indietro» finiscono tutte e due, la visita non si rifà e la finestra si chiude', async () => {
        risposte = [giusta('ruoli/ModificaRuolo', '/ruoli/5/edit')];
        router.get('/ruoli/5/edit');
        await attendi();

        risposte = [chiedeLaPassword];
        const finita = vi.fn();
        router.put('/ruoli/5', { permessi: [1, 2, 3] }, { onFinish: finita });
        await attendi();
        const chiamata = vi.fn();
        confermaPoiRiprova(423, chiamata);
        expect(richieste).toHaveLength(2);
        expect(confermaPasswordAperta.value).toBe(true);

        await tornaIndietro();

        expect(confermaPasswordAperta.value).toBe(false);
        expect(finita).toHaveBeenCalledTimes(1);

        confermaRiuscita();

        expect(chiamata).not.toHaveBeenCalled();
        expect(richieste).toHaveLength(2);
    });

    test('Coda 404 — una visita verso un altro indirizzo ritira anche la chiamata ferma', async () => {
        await vaiAllaPaginaDellaDueFattori();
        const disattiva = vi.fn();
        confermaPoiRiprova(423, disattiva);
        expect(confermaPasswordAperta.value).toBe(true);

        risposte = [giusta('dashboard/Dashboard', '/admin/dashboard')];
        router.get('/admin/dashboard');
        await attendi();

        expect(window.location.pathname).toBe('/admin/dashboard');
        expect(confermaPasswordAperta.value).toBe(false);

        confermaRiuscita();

        expect(disattiva).not.toHaveBeenCalled();
    });

    test('Coda 404 — una visita che resta sulla stessa pagina (un ricaricamento) non ritira la chiamata ferma, che riparte con la conferma', async () => {
        await vaiAllaPaginaDellaDueFattori();
        const disattiva = vi.fn();
        confermaPoiRiprova(423, disattiva);

        risposte = [giusta('settings/TwoFactor', '/settings/two-factor')];
        router.reload();
        await attendi();

        expect(window.location.pathname).toBe('/settings/two-factor');
        expect(confermaPasswordAperta.value).toBe(true);
        expect(disattiva).not.toHaveBeenCalled();

        confermaRiuscita();

        expect(disattiva).toHaveBeenCalledTimes(1);
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — dopo un cambio di pagina una nuova richiesta ferma è trattata come sempre: riparte con la conferma, e un altro cambio di pagina la ritira', async () => {
        await vaiAllaPaginaDellaDueFattori();
        confermaPoiRiprova(423, vi.fn());
        await tornaIndietro();
        expect(confermaPasswordAperta.value).toBe(false);

        // Di nuovo sulla pagina: la richiesta che si ferma ora riparte con la conferma.
        await vaiAllaPaginaDellaDueFattori();
        const prima = vi.fn();
        confermaPoiRiprova(423, prima);
        expect(confermaPasswordAperta.value).toBe(true);
        confermaRiuscita();
        expect(prima).toHaveBeenCalledTimes(1);

        // E se la pagina cambia di nuovo, si ritira anche questa.
        const seconda = vi.fn();
        confermaPoiRiprova(423, seconda);
        await tornaIndietro();

        expect(confermaPasswordAperta.value).toBe(false);
        confermaRiuscita();
        expect(seconda).not.toHaveBeenCalled();
    });
});
