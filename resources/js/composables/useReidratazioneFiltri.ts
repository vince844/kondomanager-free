import type { Ref } from 'vue';

/**
 * I filtri che il server ha già applicato, come tornano dal controller.
 */
export type FiltriDalServer = {
  name?: string | null;
  category_id?: number[] | null;
  condominio_id?: number[] | null;
};

/**
 * La sola superficie di una colonna che serve alla reidratazione. Tipizzata così stretta di
 * proposito: il composable non deve poter fare altro con la tabella.
 */
export type ColonnaFiltrabile = { setFilterValue: (valore: unknown) => void };

/**
 * Porta nello stato della barra dei filtri quello che il server ha già applicato.
 *
 * ## Perché esiste
 *
 * L'elenco documenti accetta `category_id` dall'indirizzo e filtra correttamente lato server, ma
 * la barra nasceva **vuota**: nessuna pillola accesa, nessun pulsante «azzera», e al primo tocco
 * su un qualsiasi filtro la richiesta ripartiva senza `category_id`, allargando l'elenco senza
 * spiegare perché.
 *
 * Finché nessuno costruiva a mano quell'indirizzo il difetto era latente. La beta.62 rende il nome
 * di una categoria un link che porta esattamente lì — quindi lo rende quotidiano. È la domanda del
 * perimetro di raggiungibilità della beta.46: *cosa diventa raggiungibile che prima non lo era?*
 *
 * ## Perché è un file a sé e non tre righe nel componente
 *
 * ⚠️ **Prima stava dentro `<script setup>`, e il test ne provava una copia.** Cancellando le tre
 * righe dal componente la suite restava **verde**: il vitest chiamava la propria riproduzione
 * locale, e il test PHP verificava solo che il server rimandasse indietro `filters`. Il giunto fra
 * le due metà provate non era provato da nessuno. È esattamente la lezione più cara della beta.60
 * — *una guardia provata su una copia prova la copia* — e l'ha trovata la revisione avversariale
 * della beta.62.
 *
 * Estraendola, il test chiama **questa** funzione: svuotarla fa diventare rosso qualcosa.
 *
 * ## L'ordine conta, e non è un dettaglio di stile
 *
 * Va chiamata **prima** che il componente installi l'osservatore che manda i filtri al server.
 * Scrivendo lo stato prima, l'osservatore nasce già allineato e non vede nessun cambiamento:
 * nessuna richiesta di rimbalzo, nessun giro in più. Chiamandola dopo, partirebbe subito una
 * richiesta identica a quella appena servita — e sarebbe invisibile, perché il risultato è lo
 * stesso.
 *
 * ## Cosa NON fa
 *
 * Non azzera niente: un filtro assente o vuoto lascia lo stato com'è. Reidratare a vuoto
 * accenderebbe una pillola su un elenco che non è filtrato, cioè lo stesso difetto all'incontrario.
 */
export function reidratraFiltri(
  filtri: FiltriDalServer | undefined,
  nomeFiltro: Ref<string> | { value: string },
  colonnaCategoria: ColonnaFiltrabile | undefined,
  colonnaCondominio: ColonnaFiltrabile | undefined,
): void {
  if (filtri?.name) {
    nomeFiltro.value = filtri.name;
  }

  if (filtri?.category_id?.length) {
    colonnaCategoria?.setFilterValue(filtri.category_id);
  }

  if (filtri?.condominio_id?.length) {
    colonnaCondominio?.setFilterValue(filtri.condominio_id);
  }
}
