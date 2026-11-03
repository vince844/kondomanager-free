import { euroToCents, ivaRigaCents } from './money';

/**
 * Impatto di una riga fattura sul budget di un capitolo di spesa.
 *
 * Il residuo esposto dal backend (`conti[].residuo_budget`) è già decurtato del LORDO di quanto
 * è stato speso sul capitolo. Dalla beta.30 quella sottrazione non passa più da `righe_fattura`:
 * `FatturaPassivaController::prepareContestoBudget()` delega a `SpesaPerVoceService`, che somma
 * dare meno avere sul libro giornale — dove finiscono anche le regolazioni immediate, che una
 * fattura non la creano. La base resta lorda perché le righe scritte a giornale portano
 * l'importo lordo (`FatturaPassivaService`, `$importoLordoRiga`).
 *
 * Il form deve quindi confrontare il lordo della fattura corrente, non il solo imponibile:
 * altrimenti mette a confronto due grandezze diverse e sottostima lo sforo esattamente dell'IVA
 * delle righe che si stanno scrivendo. Non è un errore che si accumula — il residuo viene
 * ricalcolato dal database a ogni apertura del form — ma può far mancare del tutto la finestra
 * di motivazione, e quello sì che si accumula, una fattura oltre budget alla volta.
 *
 * Gli arrotondamenti ricalcano operazione per operazione quelli della registrazione: stanno,
 * spiegati, in `./money.ts`, e qui non si ripetono per non ricreare le due copie libere di
 * divergere che la beta.35 ha appena eliminato.
 */
export const lordoRigaCents = (imponibileEuro: unknown, aliquotaIva: unknown): number => {
    const imponibileCents = euroToCents(imponibileEuro);

    return imponibileCents + ivaRigaCents(imponibileCents, aliquotaIva);
};

/**
 * «Questo documento provoca uno sforo?» — la domanda che accende il badge sulla riga, il semaforo in testa e la
 * finestra della motivazione (`ModalOverrideBudget`). Tre regole, nell'ordine:
 *
 * - una nota di credito non sfora mai: libera budget, non lo consuma (Coda 122);
 * - una spesa a zero non sfora niente: su un capitolo già oltre il preventivo (residuo negativo, il backend non lo
 *   clampa) il confronto «0 <= −28.200» diceva sforo prima ancora che l'importo fosse battuto — segnalato da
 *   Vincenzo a video il 20/09/2026;
 * - altrimenti sfora ciò che supera il residuo (lordo contro residuo lordo, vedi `lordoRigaCents`).
 *
 * Lo stato pregresso del capitolo resta visibile nel residuo accanto: qui si giudica solo il documento.
 */
export const sforaBudget = (spesoCents: number, residuoCents: number, notaCredito = false): boolean => {
    if (notaCredito) return false;
    if (spesoCents <= 0) return false;

    return spesoCents > residuoCents;
};

/**
 * Come si mostra il margine che resta sul capitolo dopo questo documento (`residuo − speso`), nella «Simulazione
 * impatto finanziario» — Coda 157 (1.11.0-beta.35). Il segno e il tono seguono il **numero**: «+» e verde solo sopra
 * zero, rosso sotto, neutro a zero. Prima prefisso e colore seguivano `isOk` («questo documento sfora?»), e su un
 * capitolo già oltre il preventivo con il documento ancora a zero usciva «+€ -282,00» in verde. Il giudizio sul
 * documento resta dove sta, nel semaforo; la tendina dei capitoli colora già il residuo col suo segno.
 *
 * Il segno lo scrive il formattatore del progetto, con `forcePlus` («€ +282,00», «€ -282,00»): un «+» messo davanti al
 * simbolo stava in un posto diverso dal «−» del formattatore, nello stesso riquadro (R19 della Fase 1-bis).
 */
export const descriviMargine = (deltaCents: number): { tono: 'positivo' | 'negativo' | 'neutro'; opzioni: { forcePlus: true } } => ({
    tono: deltaCents > 0 ? 'positivo' : deltaCents < 0 ? 'negativo' : 'neutro',
    opzioni: { forcePlus: true },
});
