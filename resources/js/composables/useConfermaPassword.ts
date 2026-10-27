import { router } from '@inertiajs/vue3';

/**
 * Coda 261 — le azioni sensibili chiedono la password ridigitata da non più di quindici minuti.
 *
 * Le pagine Inertia non ne sanno niente: il server le rimanda da solo alla pagina della conferma.
 * Le chiamate fatte con `fetch` o `axios` (le quattro della 2FA, la prova della posta) ricevono invece
 * un 423 in JSON, e senza questo passaggio il pulsante non faceva niente, senza messaggio. Qui il 423
 * porta alla pagina della conferma; dove tornare dopo l'ha già ricordato il server
 * (`App\Http\Middleware\ConfermaPasswordRecente`, con il nome della rotta scritto sulla rotta).
 */
export const STATO_CONFERMA_PASSWORD = 423;

/**
 * Se la risposta chiede la password, porta alla conferma e restituisce `true`: chi chiama si ferma lì.
 */
export function portaAllaConfermaSeServe(stato: number | undefined | null): boolean {
    if (stato !== STATO_CONFERMA_PASSWORD) {
        return false;
    }

    router.visit(route('password.confirm'));

    return true;
}
