/**
 * Come si scrivono a video le misure di un'unità immobiliare: metri quadri e vani.
 *
 * ## Perché esiste
 *
 * Una colonna `decimal` esce da Eloquent come **stringa con tutti i decimali dichiarati**:
 * `superficie` è `decimal(8,2)` e arriva al frontend come `"456.00"`, che la cella dell'elenco
 * scriveva tale e quale — «456.00 m²», con due zeri che non dicono niente e un punto che in
 * italiano non è il separatore decimale.
 *
 * Fin qui era un difetto estetico preesistente e circoscritto alla superficie. La beta.62 rende
 * decimale anche `numero_vani`, e senza questa funzione l'avrebbe portato dentro pure lì: «6.00
 * vani» al posto di «6 vani» su tutte le unità già a database, che sarebbe stata una regressione
 * visibile introdotta da una correzione. È la domanda della beta.42 — *cosa dipendeva dal fatto
 * che questo dato fosse intero?* — e la risposta era: la cella che lo stampa.
 *
 * ## Cosa fa, in una riga
 *
 * Toglie gli zeri non significativi: `"6.00"` → `6`, `"6.50"` → `6.5`, `"6.25"` → `6.25`,
 * `"456.00"` → `456`.
 *
 * ## Il punto, non la virgola — deciso da Vincenzo il 20/08/2026
 *
 * La prima stesura rendeva il decimale con la virgola, che è il separatore italiano. È la scelta
 * sbagliata **qui**, per la stessa ragione già scritta e verificata a video ventiquattr'ore prima
 * sui millesimi (`resources/js/pages/gestionale/tabelle/quote/QuoteList.vue`): col punto **il
 * valore che si vede è quello che si salva**. Nessuna conversione al `submit`, nessun `transform`,
 * e nessuna ambiguità fra separatore decimale e separatore delle migliaia — che è esattamente da
 * dove nascono i guai di questa famiglia di funzioni.
 *
 * In **ingresso** la virgola resta accettata e viene raddrizzata in silenzio da
 * `App\Support\DecimaleItaliano::conIlPunto()` prima della validazione: chi la batte per
 * abitudine non deve essere corretto da un messaggio d'errore.
 *
 * Non arrotonda e non tronca — se un valore arrivasse con più decimali di quanti la colonna ne
 * dichiara, si vedrebbe per intero invece di essere accorciato in silenzio. È la stessa regola
 * che la beta.61 ha scritto per i millesimi: *i decimali governano come il valore si mostra, mai
 * cosa viene conservato*.
 *
 * ## Cosa NON fa
 *
 * Non mette il separatore delle migliaia: le misure in gioco — metri quadri e vani di una singola
 * unità — non ci arrivano, e con il punto già speso come separatore decimale usarlo anche per le
 * migliaia sarebbe l'ambiguità che questa funzione esiste per evitare.
 */

/**
 * Il numero pronto da scrivere, senza unità di misura.
 *
 * Un valore assente o non numerico restituisce `null`: chi chiama decide come si scrive il vuoto,
 * perché «—» e «-» e «0» sono tre cose diverse e la scelta è della cella, non di qui.
 */
export const misuraLeggibile = (valore: unknown): string | null => {
  if (valore === null || valore === undefined || valore === '') {
    return null;
  }

  const n = Number(valore);

  if (!Number.isFinite(n)) {
    return null;
  }

  // `String(n)` toglie da solo gli zeri di coda — `Number("6.50")` è `6.5` — e non introduce
  // notazione esponenziale nell'intervallo di queste misure (`decimal(5,2)` e `decimal(8,2)`).
  return String(n);
};
