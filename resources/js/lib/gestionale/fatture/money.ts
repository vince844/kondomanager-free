/**
 * Arrotondamento al centesimo con la stessa disciplina di PHP.
 *
 * `Math.round` arrotonda .5 verso +∞ (`Math.round(-27.5) === -27`), `round()` di PHP lo fa
 * lontano da zero (`round(-27.5) === -28.0`). Sugli importi negativi — le note di credito, che
 * `MoneyInput` permette di digitare — i due divergerebbero di un centesimo.
 */
export const arrotonda = (n: number): number => Math.sign(n) * Math.round(Math.abs(n));

/**
 * Euro digitati nel form → centesimi interi. È il confine di ingresso: da qui in poi il
 * denaro resta in centesimi e non si moltiplica più per 100 — un secondo `* 100`
 * "difensivo" a valle è il bug del ×100 costato la beta.32.
 *
 * Ricalca `(int) round($euro * 100)` di `FatturaPassivaService`.
 */
export const euroToCents = (euro: unknown): number => arrotonda((Number(euro) || 0) * 100);

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
