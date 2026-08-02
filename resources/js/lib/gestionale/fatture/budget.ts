import { euroToCents, ivaRigaCents } from './money';

/**
 * Impatto di una riga fattura sul budget di un capitolo di spesa.
 *
 * Il residuo esposto dal backend (`conti[].residuo_budget`) è già decurtato del LORDO delle
 * fatture registrate: `FatturaPassivaController::prepareContestoBudget()` somma
 * `importo_imponibile + importo_iva`. Il form deve quindi confrontare il lordo della fattura
 * corrente, non il solo imponibile — altrimenti sottostima lo sforo esattamente dell'IVA, e
 * l'errore si accumula fattura dopo fattura sullo stesso capitolo.
 *
 * L'arrotondamento ricalca quello di `FatturaPassivaService`, che lavora per riga:
 *   $impRiga = (int) round($importo_imponibile * 100);
 *   $ivaRiga = (int) round(($impRiga * $aliq) / 100);
 * così il numero mostrato a schermo coincide con quello che finirà a database.
 */
export const lordoRigaCents = (imponibileEuro: unknown, aliquotaIva: unknown): number => {
    const imponibileCents = euroToCents(imponibileEuro);

    return imponibileCents + ivaRigaCents(imponibileCents, aliquotaIva);
};
