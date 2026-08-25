/**
 * Le due conversioni fra euro e centesimi, e l'arrotondamento che le tiene insieme.
 *
 * Sta qui, e non sotto `fatture/`, perché non è aritmetica di un documento: è il confine
 * di ingresso e di uscita del denaro per qualunque form del gestionale — è la controparte
 * TypeScript di `MoneyHelper` lato PHP, che infatti è un helper generale e non un metodo
 * di un Service.
 */

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
 * Centesimi interi che arrivano dal server → euro da mettere in un `MoneyInput`.
 *
 * È il confine di **uscita**, e va usato ogni volta che un form di modifica si precompila
 * con un importo già salvato. Finché questa funzione non esisteva, chi doveva riempire una
 * casella partendo dal DB se la scriveva a mano — e in `PagamentoEdit.vue` se l'era scritta
 * al contrario, moltiplicando per 100 dei centesimi: 2,50 € riaperti in modifica
 * diventavano 25.000,00 € a video e, al salvataggio, la cifra iniziale moltiplicata per
 * diecimila a DB.
 *
 * Non arrotonda: i centesimi sono già interi, e dividerli per 100 è esatto per ogni
 * importo che `MoneyInput` sa mostrare (due decimali).
 */
export const centsToEuro = (cents: unknown): number => (Number(cents) || 0) / 100;
