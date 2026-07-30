import { router } from '@inertiajs/vue3'

/**
 * Da dove veniamo, per un "Indietro" che torna davvero alla pagina di partenza.
 *
 * PERCHÉ ESISTE. Alcune pagine (dettaglio scrittura, estratto conto) sono
 * raggiungibili da molti punti diversi, quindi "Indietro" non può puntare a una
 * rotta fissa senza sbagliare per tutte le altre provenienze. Leggevano
 * `window.history.state.back`, che però è una convenzione di Vue Router: Inertia
 * v3 mette in `history.state` solo `page`, quindi quella lettura era sempre
 * `undefined` e l'Indietro cadeva sistematicamente sul fallback.
 *
 * COME FUNZIONA. L'evento `before` di Inertia scatta PRIMA che la navigazione
 * avvenga: è il momento esatto in cui `window.location` è ancora la pagina che
 * stiamo lasciando. La registriamo lì e la rileggiamo dalla pagina di arrivo.
 *
 * Su un caricamento diretto (link incollato, refresh) non c'è provenienza e il
 * valore resta `null`: chi lo usa deve sempre prevedere un fallback.
 */
let urlPrecedente: string | null = null
let registrato = false

function urlCorrente(): string {
    return window.location.pathname + window.location.search
}

/** Da chiamare una sola volta all'avvio dell'app, prima del mount. */
export function registraTracciamentoNavigazione(): void {
    if (registrato || typeof window === 'undefined') return

    registrato = true

    router.on('before', () => {
        urlPrecedente = urlCorrente()
    })
}

export function useUrlPrecedente() {
    return {
        /**
         * URL della pagina da cui si è arrivati, o `null` se non c'è (accesso
         * diretto). Non è reattivo di proposito: serve al momento del click su
         * "Indietro", non come stato osservabile.
         */
        urlPrecedente: (): string | null => urlPrecedente,
    }
}
