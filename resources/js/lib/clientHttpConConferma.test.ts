// @vitest-environment jsdom

/**
 * Coda 404 — il 423 «serve la password» si risolve nel client HTTP di Inertia, con il router vero.
 *
 * Le azioni sensibili rispondono 423 con `conferma_password: true` a conferma scaduta. Una richiesta Inertia (un
 * `useForm`, un `router.*`, un `Link`) ha i suoi callback solo nelle opzioni del chiamante, e `defaults.visitOptions`
 * non può aggiungerne: `router.visit` compone la richiesta come `{ ...visit, ...events }`, con gli eventi letti
 * dalle opzioni originali. Il client HTTP invece vede ogni richiesta: se risponde 423 con `conferma_password`,
 * aspetta la finestra della password e rifà la STESSA richiesta (la stessa `config`: stessi dati, intestazioni e
 * gettone), dentro la stessa visita. I callback del chiamante partono una volta sola, come se la richiesta fosse
 * riuscita al primo colpo; con «Annulla» la visita finisce senza successo né errore.
 *
 * Ogni test usa il router di Inertia vero (`router.init`) e un client finto che risponde come il server.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { HttpCancelledError, HttpResponseError, router, http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';

const { annullaConferma, confermaPasswordAperta, confermaRiuscita } = await import('@/composables/useConfermaPassword');
const { conConferma } = await import('./clientHttpConConferma');

const swapComponent = vi.fn<(cambio: { page: { component: string } }) => Promise<void>>(async () => {});
/** I componenti che Inertia ha mostrato, nell'ordine (il primo è la pagina iniziale). */
const mostrati = () => swapComponent.mock.calls.map(([cambio]) => cambio.page.component);
const richieste: HttpRequestConfig[] = [];
let risposte: Array<() => HttpResponse | HttpResponseError> = [];

const pagina = (componente: string, url: string) => ({ component: componente, props: { errors: {} }, url, version: '1', clearHistory: false, encryptHistory: false });
const giusta = (componente = 'ruoli/ElencoRuoli', url = '/ruoli') => (): HttpResponse => ({
    status: 200,
    headers: { 'x-inertia': 'true' },
    data: JSON.stringify(pagina(componente, url)),
});
const chiedeLaPassword = (corpo: unknown = { message: 'conferma', conferma_password: true }) => (): HttpResponseError =>
    new HttpResponseError('423', { status: 423, headers: {}, data: typeof corpo === 'string' ? corpo : JSON.stringify(corpo) } as HttpResponse);
// Un errore qualunque, anche con nel corpo lo stesso campo del 423: conta lo stato, non il corpo.
const errore = (stato: number) => (): HttpResponseError =>
    new HttpResponseError(String(stato), { status: stato, headers: {}, data: JSON.stringify({ conferma_password: true }) } as HttpResponse);

const attendi = () => new Promise((risolvi) => setTimeout(risolvi, 20));

const clientFinto = {
    request: async (config: HttpRequestConfig): Promise<HttpResponse> => {
        richieste.push(config);
        const risposta = risposte.shift()!();
        if (risposta instanceof HttpResponseError) throw risposta;
        return risposta;
    },
};

function callback() {
    return { onStart: vi.fn(), onSuccess: vi.fn(), onError: vi.fn(), onFinish: vi.fn(), onHttpException: vi.fn(() => false), onNetworkError: vi.fn(), onCancel: vi.fn() };
}

describe('conConferma — il client HTTP che chiede la password e rifà la richiesta', () => {
    beforeEach(async () => {
        richieste.length = 0;
        risposte = [];
        annullaConferma();
        (HTMLDialogElement.prototype as unknown as { showModal: () => void }).showModal = vi.fn();
        http.setClient(conConferma(clientFinto));
        await router.init({
            initialPage: pagina('ruoli/ModificaRuolo', '/ruoli/5/edit') as never,
            resolveComponent: async () => ({}),
            swapComponent,
        } as never);
    });

    test('Coda 404 — un 423 con conferma_password apre la finestra e la visita resta in corso: nessun callback di fine', async () => {
        risposte = [chiedeLaPassword(), giusta()];
        const cb = callback();

        router.put('/ruoli/5', { permessi: [1, 2, 3] }, cb);
        await attendi();

        expect(confermaPasswordAperta.value).toBe(true);
        expect(richieste).toHaveLength(1);
        expect(cb.onStart).toHaveBeenCalledTimes(1);
        expect(cb.onFinish).not.toHaveBeenCalled();
        expect(cb.onSuccess).not.toHaveBeenCalled();
        expect(cb.onHttpException).not.toHaveBeenCalled();
    });

    test('Coda 404 — dopo la conferma la STESSA richiesta riparte con gli stessi dati, e i callback partono una volta sola', async () => {
        risposte = [chiedeLaPassword(), giusta()];
        const cb = callback();
        router.put('/ruoli/5', { permessi: [1, 2, 3], nome: "Amministratore D'Àngelo" }, cb);
        await attendi();

        confermaRiuscita();
        await attendi();

        expect(richieste).toHaveLength(2);
        expect(richieste[1]).toBe(richieste[0]);
        expect(richieste[1].method).toBe('put');
        expect(richieste[1].data).toEqual({ permessi: [1, 2, 3], nome: "Amministratore D'Àngelo" });
        expect(cb.onStart).toHaveBeenCalledTimes(1);
        expect(cb.onSuccess).toHaveBeenCalledTimes(1);
        expect(cb.onFinish).toHaveBeenCalledTimes(1);
        expect(cb.onError).not.toHaveBeenCalled();
        expect(cb.onHttpException).not.toHaveBeenCalled();
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — le visite GET (la pagina della 2FA da un Link) si rifanno allo stesso modo, e la pagina cambia solo dopo', async () => {
        risposte = [chiedeLaPassword(), giusta('settings/TwoFactor', '/settings/two-factor')];
        const cb = callback();

        router.get('/settings/two-factor', {}, cb);
        await attendi();

        expect(confermaPasswordAperta.value).toBe(true);
        expect(mostrati()).not.toContain('settings/TwoFactor');

        confermaRiuscita();
        await attendi();

        expect(richieste).toHaveLength(2);
        expect(richieste[1]).toBe(richieste[0]);
        expect(richieste[1].method).toBe('get');
        expect(richieste[1].url).toContain('/settings/two-factor');
        expect(mostrati().filter((componente) => componente === 'settings/TwoFactor')).toHaveLength(1);
        expect(cb.onSuccess).toHaveBeenCalledTimes(1);
        expect(cb.onFinish).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — «Annulla» chiude la visita senza successo né errore, con onFinish, e non rifà la richiesta', async () => {
        risposte = [chiedeLaPassword(), giusta()];
        const cb = callback();
        router.put('/ruoli/5', { permessi: [1] }, cb);
        await attendi();

        annullaConferma();
        await attendi();

        expect(richieste).toHaveLength(1);
        expect(cb.onSuccess).not.toHaveBeenCalled();
        expect(cb.onError).not.toHaveBeenCalled();
        expect(cb.onHttpException).not.toHaveBeenCalled();
        expect(cb.onNetworkError).not.toHaveBeenCalled();
        expect(cb.onFinish).toHaveBeenCalledTimes(1);
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — se la richiesta rifatta riceve di nuovo il 423 la finestra si riapre, e la visita finisce una volta sola', async () => {
        risposte = [chiedeLaPassword(), chiedeLaPassword(), giusta()];
        const cb = callback();
        router.put('/ruoli/5', { permessi: [1] }, cb);
        await attendi();

        confermaRiuscita();
        await attendi();

        expect(confermaPasswordAperta.value).toBe(true);
        expect(richieste).toHaveLength(2);
        expect(cb.onFinish).not.toHaveBeenCalled();

        confermaRiuscita();
        await attendi();

        expect(richieste).toHaveLength(3);
        expect(cb.onSuccess).toHaveBeenCalledTimes(1);
        expect(cb.onFinish).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — se la visita è interrotta mentre la finestra è aperta (un\'altra visita parte), la finestra si chiude e la richiesta non riparte', async () => {
        risposte = [chiedeLaPassword(), giusta('ruoli/ElencoRuoli', '/ruoli')];
        const cb = callback();
        router.put('/ruoli/5', { permessi: [1] }, cb);
        await attendi();
        expect(confermaPasswordAperta.value).toBe(true);

        router.get('/ruoli');
        await attendi();

        expect(confermaPasswordAperta.value).toBe(false);
        expect(richieste).toHaveLength(2);
        expect(richieste[1].method).toBe('get');
        expect(cb.onSuccess).not.toHaveBeenCalled();
        expect(cb.onFinish).toHaveBeenCalledTimes(1);

        // Una conferma tardiva non rifà niente.
        confermaRiuscita();
        await attendi();
        expect(richieste).toHaveLength(2);
    });

    test('Coda 404 — un 423 senza conferma_password non apre la finestra: resta al chiamante', async () => {
        risposte = [chiedeLaPassword({ message: 'altro' })];
        const cb = callback();

        router.put('/ruoli/5', {}, cb);
        await attendi();

        expect(confermaPasswordAperta.value).toBe(false);
        expect(cb.onHttpException).toHaveBeenCalledTimes(1);
        expect(richieste).toHaveLength(1);
    });

    test('Coda 404 — un 423 con conferma_password falso, o con un corpo che non è JSON, resta al chiamante', async () => {
        for (const corpo of [{ conferma_password: false }, 'non è json']) {
            richieste.length = 0;
            risposte = [chiedeLaPassword(corpo)];
            const cb = callback();

            router.put('/ruoli/5', {}, cb);
            await attendi();

            expect(confermaPasswordAperta.value).toBe(false);
            expect(cb.onHttpException).toHaveBeenCalledTimes(1);
        }
    });

    test.each([401, 403, 404, 409, 419, 422, 429, 500])('Coda 404 — l\'errore %i resta al chiamante, e la finestra non si apre', async (stato) => {
        risposte = [errore(stato)];
        const cb = callback();

        router.put('/ruoli/5', {}, cb);
        await attendi();

        expect(confermaPasswordAperta.value).toBe(false);
        expect(cb.onHttpException).toHaveBeenCalledTimes(1);
        expect(richieste).toHaveLength(1);
    });

    test('Coda 404 — una richiesta prefetch (Purpose) con il 423 resta com\'è: né finestra né richiesta rifatta', async () => {
        risposte = [chiedeLaPassword()];
        const config = { method: 'get', url: '/settings/two-factor', headers: { Purpose: 'prefetch' } } as HttpRequestConfig;

        await expect(http.getClient().request(config)).rejects.toBeInstanceOf(HttpResponseError);

        expect(confermaPasswordAperta.value).toBe(false);
        expect(richieste).toHaveLength(1);
    });

    test('Coda 404 — una richiesta interrotta mentre la finestra è aperta finisce come annullata, e non resta in sospeso', async () => {
        risposte = [chiedeLaPassword(), giusta()];
        const controllo = new AbortController();
        const config = { method: 'post', url: '/ruoli', data: {}, headers: {}, signal: controllo.signal } as HttpRequestConfig;

        const esito = http.getClient().request(config);
        const rifiutata = expect(esito).rejects.toBeInstanceOf(HttpCancelledError);
        await attendi();
        expect(confermaPasswordAperta.value).toBe(true);

        controllo.abort();

        await rifiutata;
        expect(confermaPasswordAperta.value).toBe(false);
        expect(richieste).toHaveLength(1);
    });

    test('Coda 404 — una richiesta già annullata prima del 423 non apre la finestra', async () => {
        risposte = [chiedeLaPassword()];
        const controllo = new AbortController();
        const config = { method: 'post', url: '/ruoli', data: {}, headers: {}, signal: controllo.signal } as HttpRequestConfig;
        controllo.abort();

        await expect(http.getClient().request(config)).rejects.toBeInstanceOf(HttpCancelledError);

        expect(confermaPasswordAperta.value).toBe(false);
        expect(richieste).toHaveLength(1);
    });
});
