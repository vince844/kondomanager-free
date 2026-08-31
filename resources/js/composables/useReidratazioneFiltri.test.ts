/**
 * beta.62 — La barra dei filtri dichiara i filtri che il server ha già applicato.
 *
 * ## Il difetto, e perché era latente
 *
 * L'elenco documenti accetta `category_id` dall'indirizzo e filtra correttamente lato server,
 * ma la barra nasceva **vuota**: nessuna pillola accesa, nessun pulsante «azzera», e al primo
 * tocco su un qualsiasi filtro la richiesta ripartiva senza `category_id`, allargando l'elenco
 * senza spiegare perché.
 *
 * Finché nessuno costruiva a mano quell'indirizzo il difetto non si vedeva. La beta.62 rende il
 * nome di una categoria un link che porta esattamente lì — quindi lo rende quotidiano. È la
 * domanda del perimetro di raggiungibilità della beta.46, *cosa diventa raggiungibile che prima
 * non lo era*, e stavolta ha trovato qualcosa: la correzione è in due pezzi, e questo è il
 * secondo.
 *
 * ## Perché il test non monta il componente intero
 *
 * La barra dipende da Ziggy, da Inertia, da tre composable che fanno richieste e da
 * `vue-select`: montarla per intero proverebbe l'impalcatura del montaggio, non la
 * reidratazione. Quello che deve essere vero è più stretto — **i valori che arrivano dal server
 * finiscono nello stato dei filtri della tabella** — e si verifica passando alla funzione due
 * colonne finte che espongono `setFilterValue`, cioè la sola superficie che la barra usa.
 *
 * ⚠️ **E chiama la funzione vera.** La prima stesura di questo file ne riproduceva la logica: con
 * quella forma si potevano cancellare le tre righe dal componente e la suite restava verde — la
 * lezione più cara della beta.60, ripetuta un giorno dopo averla scritta. L'ha trovata la
 * revisione avversariale, e la correzione è stata estrarre `reidratraFiltri()` in un composable.
 *
 * **Cosa questo file NON copre.** Non copre la resa a schermo della pillola accesa né del pulsante
 * «azzera», che dipendono dai componenti figli e si guardano a video. Non copre il **giunto**: che
 * `DataTableToolbar.vue` chiami davvero questa funzione, e prima del `watchDebounced`. Quella
 * proprietà è provata a video (`?category_id[]=2&name=duis` alla prima battitura nella casella di
 * ricerca, 20/08/2026) e sta nel commento accanto alla chiamata. L'altra metà del contratto — che
 * il server rimandi indietro `filters` — è fissata da
 * `tests/Feature/Documenti/CategoriaMostraISuoiDocumentiTest.php`.
 */

import { describe, expect, it, vi } from 'vitest';
import { reidratraFiltri } from './useReidratazioneFiltri';

const colonnaFinta = () => ({ setFilterValue: vi.fn() });

describe('la barra dei filtri si reidrata da quello che il server ha applicato', () => {
  it('arrivando dal nome di una categoria, quella categoria risulta selezionata', () => {
    // È il caso della beta.62: si clicca «Verbali» nell'elenco categorie e si atterra
    // sull'archivio filtrato. Senza questa riga la pagina è filtrata e non lo dice.
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();

    reidratraFiltri({ category_id: [3] }, nameFilter, categoria, condominio);

    expect(categoria.setFilterValue).toHaveBeenCalledWith([3]);
    expect(condominio.setFilterValue).not.toHaveBeenCalled();
    expect(nameFilter.value).toBe('');
  });

  it('vale anche per la ricerca per nome e per il filtro sui condomìni', () => {
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();

    reidratraFiltri({ name: 'verbale', condominio_id: [1, 2] }, nameFilter, categoria, condominio);

    expect(nameFilter.value).toBe('verbale');
    expect(condominio.setFilterValue).toHaveBeenCalledWith([1, 2]);
    expect(categoria.setFilterValue).not.toHaveBeenCalled();
  });

  it('senza filtri non tocca niente: un elenco completo non deve presentarsi come filtrato', () => {
    // Controprova, ed è la metà che conta quanto l'altra: reidratare a vuoto accenderebbe una
    // pillola su un elenco che non è filtrato — cioè lo stesso difetto, all'incontrario.
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();

    reidratraFiltri(undefined, nameFilter, categoria, condominio);

    expect(nameFilter.value).toBe('');
    expect(categoria.setFilterValue).not.toHaveBeenCalled();
    expect(condominio.setFilterValue).not.toHaveBeenCalled();
  });

  it('una lista vuota vale come nessun filtro, non come «filtra su niente»', () => {
    // Il server manda `null` quando il filtro non c'è, ma una lista vuota è la forma che la barra
    // stessa produce azzerando: trattarla come un filtro attivo darebbe un elenco vuoto e una
    // pillola accesa su zero categorie.
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();

    reidratraFiltri({ name: '', category_id: [], condominio_id: [] }, nameFilter, categoria, condominio);

    expect(nameFilter.value).toBe('');
    expect(categoria.setFilterValue).not.toHaveBeenCalled();
    expect(condominio.setFilterValue).not.toHaveBeenCalled();
  });

  it('⚠️ lo stato torna come STRINGA, perché è così che sono fatte le opzioni', () => {
    // 1.11.0-beta.10. Il server manda booleani veri (`is_published: [false]`), ma le opzioni del
    // filtro sfaccettato sono costruite con `String(s.value)`: reidratando con `false` la pillola
    // si accenderebbe senza trovare l'opzione corrispondente, e resterebbe senza etichetta.
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();
    const stato = colonnaFinta();

    reidratraFiltri({ is_published: [false] }, nameFilter, categoria, condominio, stato);

    expect(stato.setFilterValue).toHaveBeenCalledWith(['false']);
    expect(categoria.setFilterValue).not.toHaveBeenCalled();
  });

  it('i due stati insieme si reidratano tutti e due', () => {
    const nameFilter = { value: '' };
    const stato = colonnaFinta();

    reidratraFiltri({ is_published: [true, false] }, nameFilter, undefined, undefined, stato);

    expect(stato.setFilterValue).toHaveBeenCalledWith(['true', 'false']);
  });

  it('la colonna dello stato è facoltativa: le altre barre non devono cambiare per averla', () => {
    // Il quinto parametro è stato aggiunto dopo, e la firma vecchia deve continuare a valere: una
    // barra senza filtro di stato chiama con quattro argomenti e non deve esplodere.
    const nameFilter = { value: '' };
    const categoria = colonnaFinta();
    const condominio = colonnaFinta();

    expect(() => reidratraFiltri({ is_published: [true] }, nameFilter, categoria, condominio))
      .not.toThrow();
  });
});
