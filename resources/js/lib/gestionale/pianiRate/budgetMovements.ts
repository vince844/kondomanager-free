/**
 * Il saldo netto di una voce rispetto ai movimenti "Sposta spesa" — Coda 73.
 *
 * Fino alla beta.73 la pagina controllava solo se una voce era MAI comparsa in un movimento,
 * non quanto valeva il suo saldo. Il blocco restava per sempre, perché nessuno storno esisteva
 * per farlo tornare a zero. Ora esiste (`BudgetMovementController::reverse`), e questa funzione
 * ricalca esattamente la stessa formula usata lato server
 * (`PianoRateController::detachCapitolo`): net = ricevuto − ceduto.
 */

export interface MovimentoBudget {
    source_conto_id: number;
    destination_conto_id: number;
    amount: number;
}

/**
 * Positivo: la voce ha ricevuto più di quanto ha ceduto. Negativo: il contrario. Zero: nessun
 * vincolo residuo — un movimento e il suo storno completo si elidono a vicenda.
 */
export function saldoNettoMovimenti(movimenti: MovimentoBudget[], contoId: number): number {
    return movimenti.reduce((saldo, m) => {
        if (Number(m.destination_conto_id) === contoId) return saldo + Number(m.amount);
        if (Number(m.source_conto_id) === contoId) return saldo - Number(m.amount);
        return saldo;
    }, 0);
}

export function haStornoInAttesa(movimenti: MovimentoBudget[], contoId: number): boolean {
    return saldoNettoMovimenti(movimenti, contoId) !== 0;
}
