import { HttpCancelledError, HttpResponseError } from '@inertiajs/core';
import type { HttpClient, HttpRequestConfig, HttpResponse } from '@inertiajs/core';
import { richiediConferma, STATO_CONFERMA_PASSWORD } from '@/composables/useConfermaPassword';

/**
 * Coda 404 — il 423 «serve la password» si risolve nel client HTTP di Inertia.
 *
 * Perché qui e non nelle opzioni delle visite: i callback di una visita (`onSuccess`, `onFinish`, `onHttpException`…)
 * vengono solo dalle opzioni del chiamante; `defaults.visitOptions` non può aggiungerne, perché `router.visit` compone
 * la richiesta come `{ ...visit, ...events }` e gli eventi, letti dalle opzioni originali, coprono quelli aggiunti.
 * Il client HTTP vede invece ogni richiesta di ogni visita, con i suoi dati già pronti.
 *
 * Una richiesta che riceve 423 con `conferma_password: true` aspetta la finestra della password e poi si rifà con la
 * STESSA `config`: stessi dati, stesse intestazioni, stesso gettone. È la stessa visita: i suoi callback partono una
 * volta sola, come se la richiesta fosse riuscita al primo colpo, e `form.processing` resta vero finché la finestra è
 * aperta. Con «Annulla», o se la visita viene interrotta, la richiesta finisce come annullata: la visita chiude con
 * `onFinish`, senza `onSuccess` né `onError`, e il modulo ha ancora quello che era stato scritto.
 *
 * Restano al chiamante: un 423 senza `conferma_password`, ogni altro errore, e le richieste prefetch.
 */

function chiedeLaPassword(errore: unknown): boolean {
    if (!(errore instanceof HttpResponseError) || errore.response.status !== STATO_CONFERMA_PASSWORD) {
        return false;
    }

    let corpo: unknown = errore.response.data;

    if (typeof corpo === 'string') {
        try {
            corpo = JSON.parse(corpo);
        } catch {
            return false;
        }
    }

    return (corpo as { conferma_password?: unknown } | null)?.conferma_password === true;
}

function eUnPrefetch(config: HttpRequestConfig): boolean {
    return (config.headers as Record<string, unknown> | undefined)?.Purpose === 'prefetch';
}

/** Aspetta la finestra: si risolve alla conferma, si rifiuta come annullata con «Annulla» o se la richiesta viene interrotta. */
function attendiLaConferma(config: HttpRequestConfig): Promise<void> {
    return new Promise<void>((risolvi, rifiuta) => {
        const segnale = config.signal;
        const finita = () => segnale?.removeEventListener('abort', alleInterruzione);

        const confermata = () => {
            finita();
            risolvi();
        };
        const annullata = () => {
            finita();
            rifiuta(new HttpCancelledError('Request was cancelled', config.url));
        };

        const ritira = richiediConferma(confermata, annullata);
        // La visita è stata interrotta (un'altra visita è partita): la finestra non ha più niente da rifare.
        const alleInterruzione = () => {
            ritira();
            annullata();
        };

        segnale?.addEventListener('abort', alleInterruzione, { once: true });
    });
}

export function conConferma(base: HttpClient): HttpClient {
    return {
        async request(config: HttpRequestConfig): Promise<HttpResponse> {
            for (;;) {
                try {
                    return await base.request(config);
                } catch (errore) {
                    if (eUnPrefetch(config) || !chiedeLaPassword(errore)) {
                        throw errore;
                    }

                    if (config.signal?.aborted) {
                        throw new HttpCancelledError('Request was cancelled', config.url);
                    }
                }

                await attendiLaConferma(config);
            }
        },
    };
}
