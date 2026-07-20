/**
 * `Math.round` arrotonda .5 verso +∞ (`Math.round(-27.5) === -27`), `round()` di PHP lo fa
 * lontano da zero (`round(-27.5) === -28.0`). Sugli importi negativi — le note di credito, che
 * `MoneyInput` permette di digitare — i due divergerebbero di un centesimo.
 */
const arrotonda = (n: number): number => Math.sign(n) * Math.round(Math.abs(n));

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
    const imponibileCents = arrotonda((Number(imponibileEuro) || 0) * 100);
    const ivaCents = arrotonda((imponibileCents * (Number(aliquotaIva) || 0)) / 100);

    return imponibileCents + ivaCents;
};
