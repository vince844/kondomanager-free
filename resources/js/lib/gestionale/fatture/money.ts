/**
 * Aritmetica del denaro specifica delle fatture.
 *
 * Le due conversioni generiche euro↔centesimi vivono un livello più su, in
 * `@/lib/gestionale/money`: sono il confine di ingresso e di uscita di qualunque form del
 * gestionale, non solo di questo. Qui restano riesportate perché i moduli delle fatture le
 * usano come se fossero loro, e perché una seconda copia sarebbe esattamente il modo in cui
 * nasce un ×100 di troppo.
 */
export { arrotonda, euroToCents, centsToEuro } from '../money';

import { arrotonda } from '../money';

/**
 * IVA di UNA riga, arrotondata al centesimo prima di essere sommata alle altre.
 *
 * L'arrotondamento per riga non è un dettaglio: `FatturaPassivaService` lo fa così
 *   $ivaRiga = (int) round(($impRiga * $aliq) / 100);
 * e sommare invece le IVA grezze per arrotondare solo il totale dà un risultato diverso
 * già con due righe (2 × 0,253 € fa 0,50 € per riga e 0,51 € sul totale).
 */
export const ivaRigaCents = (imponibileCents: number, aliquotaIva: unknown): number =>
    arrotonda((imponibileCents * (Number(aliquotaIva) || 0)) / 100);
