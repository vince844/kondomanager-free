import { ref, type Ref } from 'vue';

/**
 * Il ciclo di vita di una conferma di eliminazione: **cosa** si sta per eliminare, e **se** la
 * finestra è aperta.
 *
 * ## ⚠️ Il difetto che questo composable esiste per non far tornare
 *
 * `AlertDialogAction` — il pulsante di conferma di `ConfirmDialog` — **chiude il dialogo al clic**,
 * e la chiusura arriva *prima* dell'evento `confirm`. Il modo naturale di scrivere la cosa è:
 *
 * ```ts
 * const daEliminare = ref<T | null>(null)          // fa da dato E da interruttore
 * // apertura: :model-value="daEliminare !== null"
 * // chiusura: @update:model-value="v => { if (!v) daEliminare = null }"
 * ```
 *
 * e non funziona: al clic su «Continua» la finestra si chiude, l'azzeramento parte, e quando il
 * gestore di conferma legge `daEliminare` ci trova `null`. La guardia in cima esce, **non parte
 * nessuna richiesta**, e a schermo non succede assolutamente niente — nessun errore, nessun
 * messaggio, la riga resta dov'era.
 *
 * ⚠️ **È un difetto che i test del server non possono vedere**: chiamano la rotta e non passano
 * dall'interfaccia, quindi la cancellazione lato PHP risulta perfetta. Nella 1.11.0-beta.9 è
 * arrivato in due schermate scritte lo stesso giorno — le categorie di fornitore e i documenti
 * dell'anagrafica — ed è stato trovato solo provando a video.
 *
 * ## La regola, in una riga
 *
 * **Il dato non si azzera alla chiusura della finestra.** Sopravvive finché non lo sostituisce la
 * richiesta successiva o non lo pulisce l'esito. L'interruttore è una variabile a parte.
 */
export interface ConfermaEliminazione<T> {
    /** Ciò che si sta per eliminare. ⚠️ Non va azzerato alla chiusura della finestra. */
    daEliminare: Ref<T | null>;

    /** Se la finestra di conferma è aperta. È **solo** l'interruttore, non il dato. */
    confermaAperta: Ref<boolean>;

    /** Una richiesta è già partita: serve a non mandarne due con un doppio clic. */
    inCorso: Ref<boolean>;

    /**
     * Registra l'elemento e apre la finestra.
     *
     * `apri` esiste per i casi in cui la conferma **non** va mostrata — per esempio una categoria
     * usata da qualche fornitore, dove al suo posto si apre la finestra che spiega chi la usa.
     * L'elemento viene registrato lo stesso, perché serve a quell'altra finestra.
     */
    chiedi: (elemento: T, apri?: boolean) => void;

    /**
     * Esegue l'azione sull'elemento registrato, una volta sola.
     *
     * Legge il dato **prima** di qualunque altra cosa: è il punto in cui il difetto si manifestava.
     */
    conferma: (azione: (elemento: T) => void) => void;

    /** Da chiamare quando la richiesta è finita, riuscita o no. */
    conclusa: () => void;

    /**
     * Da collegare a `@update:model-value` della finestra.
     *
     * ⚠️ Cambia **solo** l'interruttore. Se un giorno qualcuno aggiunge qui l'azzeramento del dato,
     * il difetto torna: c'è un test che lo impedisce.
     */
    suCambioApertura: (aperta: boolean) => void;
}

export function useConfermaEliminazione<T>(): ConfermaEliminazione<T> {
    const daEliminare = ref<T | null>(null) as Ref<T | null>;
    const confermaAperta = ref(false);
    const inCorso = ref(false);

    function chiedi(elemento: T, apri = true): void {
        daEliminare.value = elemento;
        confermaAperta.value = apri;
    }

    function conferma(azione: (elemento: T) => void): void {
        const elemento = daEliminare.value;

        if (elemento === null || inCorso.value) {
            return;
        }

        inCorso.value = true;
        azione(elemento);
    }

    function conclusa(): void {
        inCorso.value = false;
        confermaAperta.value = false;
        daEliminare.value = null;
    }

    function suCambioApertura(aperta: boolean): void {
        confermaAperta.value = aperta;
    }

    return { daEliminare, confermaAperta, inCorso, chiedi, conferma, conclusa, suCambioApertura };
}
