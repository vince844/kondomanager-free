/**
 * Come si riassumono in una cella le categorie di un documento — 1.11.0-beta.10.
 *
 * ## Perché è un modulo e non tre righe dentro `columns.ts`
 *
 * Dalla beta.10 un documento può stare in **più categorie**, e la cella deve deciderne tre cose:
 * quante mostrarne, in che ordine, e cosa scrivere quando non ce ne sono. Sono tre decisioni, non
 * una formattazione — e finché vivevano dentro la funzione `cell` di una colonna di TanStack non
 * c'era modo di provarle senza montare la tabella intera.
 *
 * ⚠️ **È la stessa correzione già fatta due volte in questo progetto**, e per lo stesso motivo:
 * `useReidratazioneFiltri` (beta.62) e `useConfermaEliminazione` (beta.9) sono nati così, dopo che
 * un difetto vissuto solo nel template era passato sotto una suite verde. La regola che ne è uscita
 * sta nella Fase 1 del flusso di lavoro: *la logica che decide cosa si vede si prova, e per provarla
 * deve stare dove un test può chiamarla*.
 *
 * ## Cosa questo modulo NON decide
 *
 * Non disegna niente: non conosce i `Badge`, le classi, né i colori. Restituisce **cosa** mostrare,
 * e la colonna decide **come**. Il confine è voluto — un test che dovesse conoscere le classi CSS
 * diventerebbe rosso a ogni ritocco grafico, e allora lo si smette di guardare.
 */

/** La sola parte di una categoria che serve a questa cella. */
export type CategoriaEtichetta = { id: number; name: string };

export type CellaCategorie = {
  /** Nessuna categoria: la colonna scrive lo stato «senza categoria», non una cella vuota. */
  vuoto: boolean;
  /** Le etichette da mostrare per esteso, già ordinate. */
  visibili: CategoriaEtichetta[];
  /** Quante restano fuori: `0` quando ci stanno tutte. */
  restanti: number;
  /** L'elenco completo, per il `title` che si legge passandoci sopra. */
  titolo: string;
};

/**
 * Riassume le categorie di un documento per la cella dell'elenco.
 *
 * ⚠️ **Due etichette e poi «+N», perché la cella non deve crescere.** Con quattro categorie la
 * colonna diventa una siepe e le righe della tabella si alzano una diversa dall'altra, il che rende
 * illeggibile *tutta* la tabella per colpa di una riga sola. Il resto diventa un contatore, con
 * l'elenco intero nel `title`.
 *
 * ⚠️ **L'ordine è alfabetico, non quello di inserimento.** Il legame è una tabella ponte e l'ordine
 * naturale è quello in cui le righe sono state scritte: la stessa coppia di categorie comparirebbe
 * come «Bilanci, Verbali» su una riga e «Verbali, Bilanci» su un'altra, e chi legge lo prende per un
 * errore. L'ordinamento serve a rendere la colonna **confrontabile riga per riga**, non a mettere
 * prima la più importante — quale sia la più importante non lo sa nessuno.
 *
 * ⚠️ **L'elenco in ingresso non viene toccato**: si ordina una copia. `sort()` ordina sul posto, e
 * qui l'array arriva dai `props` di Inertia — riordinarlo significherebbe cambiare il dato sotto ad
 * altri pezzi della pagina che leggono lo stesso oggetto.
 *
 * @param categorie Le categorie del documento; `null`/`undefined` valgono come nessuna — un
 *                  documento caricato su un fornitore o un'unità **non ha categoria di proposito**,
 *                  e la risorsa può quindi non mandare affatto il campo.
 * @param massimo   Quante mostrarne per esteso prima del contatore.
 */
export function cellaCategorie(
  categorie: CategoriaEtichetta[] | null | undefined,
  massimo = 2,
): CellaCategorie {
  const ordinate = [...(categorie ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'it'));

  if (ordinate.length === 0) {
    return { vuoto: true, visibili: [], restanti: 0, titolo: '' };
  }

  // ⚠️ Un `massimo` a zero o negativo non deve produrre «+N» su una cella senza niente scritto:
  // in quel caso non si nasconde nulla e si mostra tutto, che è il comportamento meno sorprendente.
  const quante = massimo > 0 ? massimo : ordinate.length;
  const visibili = ordinate.slice(0, quante);

  return {
    vuoto: false,
    visibili,
    restanti: ordinate.length - visibili.length,
    // ⚠️ **Tutte, comprese le due già visibili.** Il `title` risponde a «quali sono?», non a «quali
    // mancano»: un elenco che parte dalla terza costringe chi legge a tenere a mente le prime due.
    titolo: ordinate.map((c) => c.name).join(', '),
  };
}
