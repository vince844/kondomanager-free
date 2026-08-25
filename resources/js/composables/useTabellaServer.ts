import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import type { SortingState, Updater } from '@tanstack/vue-table';

/**
 * Un parametro d'indirizzo: un valore, un elenco di valori, oppure la sua assenza.
 *
 * ⚠️ **Gli array servono davvero.** Diversi filtri sono a selezione multipla — le priorità di una
 * comunicazione, le categorie di un evento — e viaggiano come `priority[]=alta&priority[]=media`.
 * Finché la firma ammetteva solo valori singoli, l'unico modo di passarli era forzare il tipo, che
 * è il modo in cui un difetto vero si traveste da dettaglio di TypeScript.
 *
 * ⚠️ Un array **vuoto** va passato come `null`, non come `[]`: `[]` non è né nullo né stringa
 * vuota, quindi supererebbe il filtro di `vai()` e resterebbe nell'indirizzo come filtro attivo su
 * niente.
 */
export type ValoreParametro = string | number | string[] | number[] | null | undefined;

/**
 * Il cablaggio condiviso delle tabelle: **una sola richiesta che porta tutto**.
 *
 * ## Il difetto che chiude
 *
 * Ogni azione di una tabella costruiva la propria richiesta da zero, ricordandosi solo di ciò che
 * le serviva. Ne uscivano due perdite simmetriche, entrambe segnalate dagli amministratori:
 *
 * - la **ricerca** non rimandava `per_page`, quindi chi aveva impostato 40 righe tornava a 10
 *   appena cercava qualcosa — otto punti nel progetto;
 * - la **paginazione** non rimandava i filtri, quindi alla seconda pagina tornava l'elenco intero
 *   mentre il selettore continuava a dichiarare il filtro attivo — ventidue punti.
 *
 * E l'ordinamento non arrivava affatto al server: `manualPagination: true` senza
 * `manualSorting: true` significa che la libreria ordina **le righe che ha**, cioè la pagina
 * visibile. Su cinquanta record mostrati a dieci, «il più alto» era il più alto dei primi dieci.
 *
 * ## Il principio
 *
 * Lo stato di una tabella — filtri, pagina, righe per pagina, ordinamento — vive **nell'URL**, e
 * ogni azione riparte da quello che c'è invece di ricostruirlo. Chi cambia una cosa cambia una
 * cosa sola; il resto viaggia perché non è mai stato tolto.
 *
 * La fonte dei filtri è quella che il controller rimanda indietro dopo averli validati, non uno
 * stato locale del componente: due copie dello stesso dato divergono, e la prima a mentire è
 * sempre quella che l'utente vede.
 */
export function useTabellaServer(url: () => string) {
  const inCorso = ref(false);

  const pagina = usePage<{
    filters?: Record<string, string | number | null>;
    meta?: { per_page?: number };
    sort?: string | null;
    direction?: 'asc' | 'desc' | null;
  }>();

  /**
   * Le righe per pagina con cui il server ha **davvero** paginato.
   *
   * ⚠️ Va riportata a ogni richiesta, ed è il difetto che questo composable dichiarava di aver
   * chiuso senza chiuderlo. `filters` contiene i filtri veri — `nome`, `stato` — e non `per_page`,
   * che viaggia in `meta`. Finché la si leggeva solo da lì, ogni azione che non fosse un cambio di
   * pagina la lasciava indietro: **cliccare un'intestazione riportava l'elenco a dieci righe**.
   *
   * È esattamente la segnalazione arrivata dal forum — *«ho impostato 40 elementi per pagina,
   * faccio una ricerca, me ne vengono fuori solo 10»* — e la ragione per cui va letta da `meta` e
   * rimessa dentro in `vai()` accanto ai filtri, non lasciata alle singole chiamate.
   */
  const righePerPagina = () => pagina.props.meta?.per_page ?? null;

  /**
   * L'ordinamento corrente, letto da ciò che il server ha applicato davvero.
   *
   * ⚠️ Non è uno stato locale che si tenta di tenere allineato: è **la risposta del server**. Così
   * la freccetta nell'intestazione non può dichiarare un ordinamento diverso da quello con cui le
   * righe sono state estratte — che è esattamente il difetto da cui nasce tutto questo.
   */
  const ordinamento = computed<SortingState>(() =>
    pagina.props.sort
      ? [{ id: pagina.props.sort, desc: pagina.props.direction === 'desc' }]
      : [],
  );

  /** I filtri attivi, senza i vuoti: un `nome=` nell'URL è rumore, non un filtro. */
  const filtriAttivi = () =>
    Object.fromEntries(
      Object.entries(pagina.props.filters ?? {}).filter(([, v]) => v !== null && v !== ''),
    );

  /**
   * Una richiesta che parte da ciò che c'è e cambia solo quello che l'utente ha toccato.
   */
  function vai(
    modifiche: Record<string, ValoreParametro>,
    dopo?: () => void,
  ) {
    if (inCorso.value) return;
    inCorso.value = true;

    const attuale = pagina.props;

    // ⚠️ `null` significa **togli**, e va distinto da «non lo cambio». Senza questa distinzione
    // il terzo clic su un'intestazione — quello che toglie l'ordinamento — non avrebbe effetto:
    // lo stato precedente verrebbe riscritto qui sopra e la modifica non lo raggiungerebbe.
    const parametri: Record<string, string | number | string[] | number[]> = Object.fromEntries(
      Object.entries({
        ...filtriAttivi(),
        ...(righePerPagina() ? { per_page: righePerPagina() as number } : {}),
        ...(attuale.sort ? { sort: attuale.sort, direction: attuale.direction ?? 'asc' } : {}),
        ...modifiche,
      }).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    ) as Record<string, string | number | string[] | number[]>;

    router.get(url(), parametri, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onSuccess: dopo,
      onFinish: () => { inCorso.value = false; },
    });
  }

  /**
   * Cambio di un filtro dalla barra sopra la tabella.
   *
   * ⚠️ **Un filtro svuotato va passato come `null`, non omesso.** Le barre costruivano i parametri
   * aggiungendo solo i filtri valorizzati (`if (nome) params.nome = nome`): omettere basta quando
   * si riparte da zero, ma qui si riparte da ciò che c'è, e un filtro omesso resterebbe quello di
   * prima. `null` significa **togli**, ed è la distinzione su cui è costruito `vai()`.
   *
   * Si torna sempre a pagina 1: restare a pagina 4 dopo aver filtrato porta quasi sempre su una
   * pagina che il nuovo insieme non ha.
   */
  function filtra(
    filtri: Record<string, ValoreParametro>,
    dopo?: () => void,
  ) {
    vai({ ...filtri, page: 1 }, dopo);
  }

  /**
   * Cambio di pagina o di righe per pagina.
   *
   * ⚠️ **Cambiando le righe per pagina si torna alla prima.** Chi passa da 10 a 50 stando a pagina
   * 4 chiederebbe le righe dalla 151 alla 200, che su un elenco di 60 non esistono: la tabella
   * risponderebbe vuota e sembrerebbe un difetto. È il motivo per cui `page` viene forzato a 1
   * quando la dimensione cambia.
   */
  function suPaginazione(prossimaPagina: number, righePerPagina: number, righeAttuali: number) {
    vai(
      righePerPagina !== righeAttuali
        ? { page: 1, per_page: righePerPagina }
        : { page: prossimaPagina, per_page: righePerPagina },
    );
  }

  /**
   * Clic su un'intestazione ordinabile.
   *
   * Il terzo clic **toglie** l'ordinamento invece di riportarlo a crescente: è il comportamento
   * della libreria, e toglierlo significherebbe non poter più tornare all'ordine naturale.
   */
  function suOrdinamento(updater: Updater<SortingState>) {
    const prossimo = typeof updater === 'function' ? updater(ordinamento.value) : updater;

    vai(
      prossimo.length === 0
        ? { sort: null, direction: null, page: 1 }
        : { sort: prossimo[0].id, direction: prossimo[0].desc ? 'desc' : 'asc', page: 1 },
    );
  }

  return { inCorso, ordinamento, filtriAttivi, righePerPagina, vai, filtra, suPaginazione, suOrdinamento };
}
