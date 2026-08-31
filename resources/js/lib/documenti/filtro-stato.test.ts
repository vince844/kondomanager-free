import { describe, expect, it, vi } from 'vitest';
import { parametroStato, statoPerServer } from './filtro-stato';
import { reidratraFiltri } from '@/composables/useReidratazioneFiltri';

/**
 * 1.11.0-beta.10 — il filtro «Stato» dell'archivio, nelle due direzioni.
 *
 * ## Il difetto che questo file esiste per non far tornare
 *
 * Il filtro è nato rotto: selezionando «Privato» su due documenti pubblici la tabella restava
 * piena, senza un errore a schermo e senza una riga nei log. Fra la tabella e il server ci sono due
 * rappresentazioni diverse — stringhe di qua, booleani di là — e il passaggio non aveva un posto
 * suo: una riga dentro un `watchDebounced`, una dentro un altro file, e niente che le guardasse
 * insieme.
 *
 * ⚠️ **L'ultimo test è quello che conta**: fa il giro completo chiamando le funzioni vere di
 * entrambe le metà. Provare le due direzioni separatamente, ognuna con la propria idea di come è
 * fatto il valore, è esattamente il modo in cui un giunto rimane scoperto pur avendo test verdi da
 * tutte e due le parti.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il pezzo di server: che `DocumentoIndexRequest` accetti la stringa `'false'` che arriva
 * dalla query string sta in `tests/Feature/Documenti/FiltroStatoDocumentiTest.php`, ed è l'altra
 * metà della stessa correzione. Qui finisce dove finisce il browser.
 */
describe('il filtro «Stato» attraversa il confine senza cambiare senso', () => {
  it('le stringhe della tabella diventano booleani veri', () => {
    // `'true'` in un `whereIn` su una colonna `tinyint` non combacia con niente: il filtro si
    // accenderebbe senza filtrare, che è il difetto originale visto dall'altro lato.
    expect(statoPerServer(['true'])).toEqual([true]);
    expect(statoPerServer(['false'])).toEqual([false]);
    expect(statoPerServer(['true', 'false'])).toEqual([true, false]);
  });

  it('⚠️ i valori sono booleani, non stringhe che gli somigliano', () => {
    // La riga che il difetto avrebbe reso rossa: `'false'` è una stringa **vera** in JavaScript, e
    // una conversione fatta con `Boolean(v)` la trasformerebbe in `true`.
    const [privato] = statoPerServer(['false']);

    expect(privato).toBe(false);
    expect(typeof privato).toBe('boolean');
  });

  it('qualunque cosa non sia «true» vale privato, senza inventare un terzo stato', () => {
    expect(statoPerServer(['pippo', ''])).toEqual([false, false]);
  });

  it('⚠️ un filtro svuotato viaggia come null, non come lista vuota', () => {
    // La richiesta riparte da ciò che c'è nell'indirizzo: un parametro omesso resterebbe quello di
    // prima, e togliere il filtro lascerebbe l'elenco filtrato con la pillola spenta sopra.
    expect(parametroStato([])).toBeNull();
    expect(parametroStato(['false'])).toEqual([false]);
  });

  it('⚠️ il giro completo torna al punto di partenza — tabella → server → tabella', () => {
    // Le due metà sono scritte in file diversi e nessun test le guardava insieme. Qui si chiamano
    // tutte e due quelle **vere**: `parametroStato` per l'andata, `reidratraFiltri` per il ritorno.
    const colonnaStato = { setFilterValue: vi.fn() };

    for (const partenza of [['false'], ['true'], ['true', 'false']]) {
      colonnaStato.setFilterValue.mockClear();

      // Andata: è quello che finisce nell'indirizzo, ed è quello che il server valida.
      const versoIlServer = parametroStato(partenza);
      expect(versoIlServer).not.toBeNull();

      // Ritorno: il controller rimanda indietro i booleani in `filters`, e la barra si riaccende.
      reidratraFiltri(
        { is_published: versoIlServer },
        { value: '' },
        undefined,
        undefined,
        colonnaStato,
      );

      expect(colonnaStato.setFilterValue).toHaveBeenCalledWith(partenza);
    }
  });
});
