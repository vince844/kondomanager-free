/**
 * Da dove parte il calendario delle rate.
 *
 * La regola è una riga sola, e sarebbe stato più rapido scriverla dentro il componente. Vive
 * qui perché **esiste già in PHP** — `PianoRate::dataPartenzaCalendario()` — e le due devono
 * rispondere allo stesso modo: se l'interfaccia dice all'amministratore che si parte da una
 * data e il server ne usa un'altra, nessuna delle due è sbagliata da sola. È lo schema che
 * nella beta.35 è costato un centesimo di divergenza sul netto da pagare.
 *
 * La controparte PHP è autoritativa: qui non si decide niente, si **anticipa** ciò che il
 * server farà, per poterlo mostrare prima di salvare.
 */

/**
 * La data effettiva di partenza, in forma `YYYY-MM-DD`.
 *
 * `dataPrimaScadenza` vuota non è un dato mancante: **è la scelta di seguire la gestione**.
 * Un piano senza data propria si sposta se l'inizio della gestione si sposta; chi lo vuole
 * fermo mette una data. Per questo la stringa vuota, `null` e `undefined` sono trattate allo
 * stesso modo — l'interfaccia produce tutte e tre a seconda di come si svuota il campo, e
 * distinguerle qui vorrebbe dire far dipendere una regola di dominio da un dettaglio del
 * date picker.
 *
 * Restituisce `null` quando non c'è nemmeno l'inizio gestione: è un caso reale — il servizio
 * di creazione lo rifiuta esplicitamente — e va reso visibile, non mascherato con una data
 * inventata come «oggi».
 */
export function partenzaCalendario(
    dataPrimaScadenza: string | null | undefined,
    dataInizioGestione: string | null | undefined,
): string | null {
    const scelta = soloData(dataPrimaScadenza);

    if (scelta) {
        return scelta;
    }

    return soloData(dataInizioGestione);
}

/**
 * Se la partenza è quella scelta dall'amministratore o quella ereditata dalla gestione.
 *
 * Serve all'interfaccia per la differenza fra **scrivere una data nel campo** e **mostrarla
 * nel segnaposto**: un valore ereditato va suggerito, non compilato, o al primo salvataggio
 * diventerebbe una scelta esplicita che nessuno ha fatto — e il piano smetterebbe di seguire
 * la gestione senza che l'amministratore l'abbia deciso.
 */
export function partenzaEreditata(dataPrimaScadenza: string | null | undefined): boolean {
    return soloData(dataPrimaScadenza) === null;
}

/** La data in forma `YYYY-MM-DD`, o `null` se non è una data utilizzabile. */
function soloData(valore: string | null | undefined): string | null {
    if (valore === null || valore === undefined) {
        return null;
    }

    const testo = String(valore).trim();

    if (testo === '') {
        return null;
    }

    // Le date arrivano dal server come `YYYY-MM-DD` o come ISO completo; dal date picker
    // sempre come `YYYY-MM-DD`. Si taglia la parte oraria invece di costruire un `Date`,
    // che reinterpreterebbe la stringa nel fuso del browser e potrebbe spostare il giorno.
    const data = testo.slice(0, 10);

    return /^\d{4}-\d{2}-\d{2}$/.test(data) ? data : null;
}
