import { router } from '@inertiajs/core';
import { readonly, ref } from 'vue';

/**
 * Coda 261 e 404 — le azioni sensibili chiedono la password ridigitata da non più di quindici minuti.
 *
 * Le rotte sensibili rispondono 423 quando la conferma è scaduta (`App\Http\Middleware\ConfermaPasswordRecente`).
 * La pagina non lascia il suo posto: apre una sola finestra (`components/FinestraConfermaPassword.vue`, montata alla
 * radice dell'applicazione), che manda la password in JSON e, con il 200, fa ripartire le richieste che erano state
 * fermate, con gli stessi dati. «Annulla» le dimentica e le avvisa: il modulo sotto ha ancora quello che era stato scritto.
 *
 * Chi chiede la conferma è di due tipi:
 * - le visite di Inertia (moduli, collegamenti, `router.*`): le ferma il client HTTP (`lib/clientHttpConConferma.ts`),
 *   che aspetta la finestra e rifà la stessa richiesta dentro la stessa visita;
 * - le chiamate `fetch` e `axios` della pagina (la 2FA, la prova della posta): `confermaPoiRiprova`.
 *
 * Lo stato è uno solo per tutta l'applicazione. Con più richieste ferme insieme la finestra resta una e dopo la
 * conferma ripartono tutte, nell'ordine in cui si sono fermate.
 *
 * Le richieste ferme sono della pagina in cui sono nate: se la pagina cambia (il tasto «indietro», una visita verso un
 * altro indirizzo) finiscono come con «Annulla», e la finestra si chiude. Le visite lo sanno già dal loro segnale; le
 * chiamate `fetch` e `axios` non ne hanno uno, e le ritira l'evento di Inertia (`router.on('navigate')`, in fondo).
 * Una visita che resta sulla stessa pagina, come un ricaricamento, non è un cambio di pagina.
 */
export const STATO_CONFERMA_PASSWORD = 423;

interface RichiestaFerma {
    riprova: () => unknown;
    alAnnullamento?: () => void;
}

const aperta = ref(false);
let ferme: RichiestaFerma[] = [];

/** La finestra della conferma è aperta? */
export const confermaPasswordAperta = readonly(aperta);

/**
 * Apre la finestra e ricorda che cosa rifare dopo la conferma. `alAnnullamento` avvisa la richiesta se la finestra
 * viene annullata, perché possa finire. Restituisce la funzione con cui la richiesta si ritira da sola (per esempio
 * perché la visita è stata interrotta): non viene avvisata, e la finestra si chiude se era l'ultima.
 */
export function richiediConferma(riprova: () => unknown, alAnnullamento?: () => void): () => void {
    const richiesta: RichiestaFerma = { riprova, alAnnullamento };
    ferme.push(richiesta);
    aperta.value = true;

    return () => {
        ferme = ferme.filter((r) => r !== richiesta);
        if (ferme.length === 0) {
            aperta.value = false;
        }
    };
}

/**
 * Se la risposta chiede la password, apre la finestra e restituisce `true`: chi chiama si ferma lì, e la stessa
 * chiamata riparte dopo la conferma. Con ogni altro stato restituisce `false` e non fa niente. `alAnnullamento`,
 * se c'è, parte con «Annulla»: serve a chi ha aperto una propria finestra che da sola non ha più niente da mostrare.
 */
export function confermaPoiRiprova(
    stato: number | undefined | null,
    riprova: () => unknown,
    alAnnullamento?: () => void,
): boolean {
    if (stato !== STATO_CONFERMA_PASSWORD) {
        return false;
    }

    richiediConferma(riprova, alAnnullamento);

    return true;
}

/**
 * «Annulla»: chiude la finestra e dimentica le richieste ferme, avvisandole. Non riparte niente.
 */
export function annullaConferma(): void {
    const daAvvisare = ferme;
    ferme = [];
    aperta.value = false;

    daAvvisare.forEach((richiesta) => richiesta.alAnnullamento?.());
}

/**
 * La password è stata confermata dal server: chiude la finestra e rifà le richieste ferme, una volta sola ciascuna.
 * La finestra si chiude prima, così una richiesta rifatta che riceve di nuovo il 423 la riapre.
 */
export function confermaRiuscita(): void {
    const daRifare = ferme;
    ferme = [];
    aperta.value = false;

    daRifare.forEach((richiesta) => richiesta.riprova());
}

// La pagina è cambiata: le richieste ferme sono della pagina che si è lasciata, e la conferma non le fa ripartire.
router.on('navigate', () => annullaConferma());
