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
