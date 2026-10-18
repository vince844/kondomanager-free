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

/** Il tipo di un capitolo con sottoconti di spesa e di entrata: il piano lo divide fra le due, e «Sposta spesa» non lo muove né lo riceve. */
export const TIPO_MISTO = 'misto';

/**
 * Le destinazioni che «Sposta spesa» offre a una sorgente: solo le voci dello stesso tipo, perché il totale del piano è la somma delle voci col segno
 * con cui il motore le distribuisce (spese con +, entrate con −) e spostare budget fra una spesa e un'entrata lo cambierebbe del doppio. Il tipo è
 * quello che manda il server, calcolato dal segno: un capitolo con i soli sottoconti di entrata è un'entrata anche se il suo tipo è «Spesa».
 *
 * Una sorgente «misto» (un capitolo con spese ed entrate insieme) non ha destinazioni, e una destinazione «misto» non si offre a nessuno.
 * Senza il tipo sulla sorgente (nei piani straordinari le sorgenti sono fatture) passa tutto; una destinazione senza tipo passa sempre.
 * Non cambia l'elenco che riceve.
 */
export function destinazioniAmmesse<T extends { tipo?: string | null }>(destinazioni: T[], sorgente?: { tipo?: string | null } | null): T[] {
    const tipo = sorgente?.tipo;
    if (!tipo) return destinazioni;
    if (tipo === TIPO_MISTO) return [];
    return destinazioni.filter((d) => d.tipo !== TIPO_MISTO && (!d.tipo || d.tipo === tipo));
}
