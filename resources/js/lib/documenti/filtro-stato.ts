/**
 * Il filtro «Stato» dell'archivio, e il cambio di tipo che avviene per strada — 1.11.0-beta.10.
 *
 * ## Il difetto da cui nasce questo file
 *
 * Il filtro è nato **rotto** ed è stato trovato in Fase 1-bis provandolo a video: selezionando
 * «Privato» su due documenti pubblici la tabella restava piena. Nessun errore a schermo, nessuna
 * riga nei log.
 *
 * La causa è che fra la tabella e il server ci sono **due rappresentazioni diverse**, e il passaggio
 * fra le due non aveva un posto suo:
 *
 * - **nella tabella** il valore è una **stringa** — le opzioni di un filtro sfaccettato si
 *   costruiscono con `String(s.value)`, perché un'opzione dev'essere confrontabile e stampabile;
 * - **verso il server** deve essere un **booleano vero**: `'true'` in un `whereIn` su una colonna
 *   `tinyint` non combacia con niente, e Inertia serializza i parametri nella query string, dove i
 *   tipi non esistono. La regola `boolean` di Laravel accetta `true`, `false`, `0`, `1`, `'0'`,
 *   `'1'` — e **rifiuta `'true'` e `'false'`**.
 *
 * ⚠️ **Le due direzioni devono restare d'accordo, e prima non lo erano.** La conversione in uscita
 * viveva come espressione dentro un `watchDebounced`, quella in entrata dentro un altro file: due
 * righe in due posti che nessun test guardava insieme. Il giro completo — tabella → server →
 * tabella — è provato in `filtro-stato.test.ts` chiamando le funzioni **vere** di tutte e due le
 * metà, che è la lezione più cara della beta.60: *una guardia provata su una copia prova la copia*.
 */

/**
 * Da come il filtro sta nella tabella a come deve viaggiare verso il server.
 *
 * ⚠️ **Il confronto è con la stringa `'true'`, e non è una conversione «permissiva».** Qualunque
 * altro valore diventa `false`: è voluto, perché l'unica sorgente di questi valori sono le opzioni
 * costruite da `publishedConstants`, che sono esattamente `'true'` e `'false'`. Se un giorno
 * arrivasse dell'altro, deve valere «privato» e non un terzo stato inventato qui.
 */
export function statoPerServer(valori: readonly string[]): boolean[] {
  return valori.map((v) => v === 'true');
}

/**
 * Il filtro come deve arrivare al server: `null` quando non c'è.
 *
 * ⚠️ **Un filtro svuotato viaggia come `null`, mai omesso.** La richiesta riparte da ciò che c'è
 * nell'indirizzo: un parametro omesso resterebbe quello di prima, e togliere il filtro lascerebbe
 * l'elenco filtrato com'era — con la pillola spenta sopra. È la regola già scritta per il nome e
 * per i filtri di categoria e condominio, e qui vale identica.
 */
export function parametroStato(valori: readonly string[]): boolean[] | null {
  return valori.length > 0 ? statoPerServer(valori) : null;
}
