/**
 * Il vocabolario dei ruoli di un soggetto su un'unità immobiliare: colore, etichetta e **ordine**.
 *
 * ## Perché in un posto solo
 *
 * La stessa mappa colore-ruolo era scritta a mano in tre punti dell'interfaccia, e **nessuno dei
 * tre era d'accordo con gli altri**: `anagrafiche/columns.ts` e `ScopertoWarning.vue` avevano tre
 * casi e mandavano `nuda_proprietario` nel grigio dei ruoli sconosciuti, `AnagraficheStack.vue` ne
 * aveva quattro. Il ruolo è registrabile dalla beta.43: da allora ha viaggiato per metà
 * dell'interfaccia con il colore «non so cosa sei», che è ciò che un ruolo legittimo non deve mai
 * avere.
 *
 * Il lato server ha già la sua fonte unica in `App\Enums\RuoloAnagraficaImmobile`, dove vivono le
 * catene di ripiego e le motivazioni giuridiche. Questo file è il suo corrispettivo a schermo, e
 * si limita a **come si mostrano**: nessuna regola di dominio, che sta di là.
 */

/**
 * L'ordine in cui i ruoli vanno elencati: **dal diritto reale pieno verso il godimento**.
 *
 * ⚠️ Non serve solo all'interfaccia. Le due stampe del riparto li elencano in ordini diversi —
 * `RipartoCapitoliService` non ha `nuda_proprietario` nella sua mappa e lo manda in fondo, dopo
 * l'inquilino, mentre `RipartoTabelleService` lo mette accanto al proprietario — così che due
 * documenti della stessa assemblea presentano gli stessi soggetti in sequenze diverse. È un
 * reperto della revisione avversariale della beta.52, ancora aperto lato PHP: quando si allineerà,
 * questa è la sequenza giusta.
 */
export const ORDINE_RUOLI: Record<string, number> = {
    proprietario: 0,
    nuda_proprietario: 1,
    usufruttuario: 2,
    inquilino: 3,
};

/**
 * I quattro colori.
 *
 * Blu il proprietario, che è il caso normale. **Ambra il nudo proprietario**, perché è il ruolo
 * che l'amministratore deve notare: su un'unità con usufrutto le spese si dividono per legge —
 * ordinaria all'usufruttuario (art. 1004 c.c.), straordinaria al nudo proprietario (art. 1005) — e
 * vederlo a colpo d'occhio evita di addebitare alla persona sbagliata. Viola l'usufruttuario e
 * verde l'inquilino erano già la convenzione della scheda dell'unità.
 */
const BASE: Record<string, string> = {
    proprietario: 'bg-blue-100 text-blue-700',
    nuda_proprietario: 'bg-amber-100 text-amber-700',
    usufruttuario: 'bg-purple-100 text-purple-700',
    inquilino: 'bg-emerald-100 text-emerald-700',
};

const SCURO: Record<string, string> = {
    proprietario: 'dark:bg-blue-900/30 dark:text-blue-400',
    nuda_proprietario: 'dark:bg-amber-900/30 dark:text-amber-400',
    usufruttuario: 'dark:bg-purple-900/30 dark:text-purple-400',
    inquilino: 'dark:bg-emerald-900/30 dark:text-emerald-400',
};

export const COLORI_RUOLO: Record<string, string> = Object.fromEntries(
    Object.keys(BASE).map((r) => [r, `${BASE[r]} ${SCURO[r]}`]),
);

/** Il grigio di chi non è in catalogo: un dato sporco si vede, non si traveste da ruolo vero. */
const IGNOTO_BASE = 'bg-slate-100 text-slate-700';
const IGNOTO_SCURO = 'dark:bg-slate-800 dark:text-slate-300';
export const COLORE_RUOLO_IGNOTO = `${IGNOTO_BASE} ${IGNOTO_SCURO}`;

/** `nuda_proprietario` è un valore di colonna, non una parola: a schermo si legge diversamente. */
const ETICHETTE: Record<string, string> = {
    nuda_proprietario: 'nudo proprietario',
};

/**
 * ⚠️ **`tema: 'chiaro'` serve ai pannelli che non invertono, e non è un capriccio.**
 *
 * `ScopertoWarning.vue` è **interamente a tema chiaro** — zero classi `dark:` nel file, fondo
 * `bg-amber-50` e righe `bg-white/50` che restano tali in entrambi i temi. Mettendoci dentro un
 * badge con le varianti scure, un amministratore con macOS in tema scuro — che è il valore
 * predefinito, non una scelta esplicita — leggeva testo verde chiaro su fondo quasi bianco:
 * contrasto intorno a 1,1:1, cioè illeggibile. Prima della beta.53 quel badge aveva colori propri
 * senza varianti scure, e il problema non esisteva.
 *
 * La fonte unica resta una: cambia solo quali metà delle classi si emettono.
 */
export const coloreRuolo = (ruolo?: string | null, tema: 'auto' | 'chiaro' = 'auto'): string => {
    const chiave = ruolo ?? '';

    if (tema === 'chiaro') {
        return BASE[chiave] ?? IGNOTO_BASE;
    }

    return COLORI_RUOLO[chiave] ?? COLORE_RUOLO_IGNOTO;
};

export const etichettaRuolo = (ruolo?: string | null): string =>
    ETICHETTE[ruolo ?? ''] ?? ruolo ?? '—';

/** Ordina in posto una lista di soggetti che portano il ruolo nella pivot. */
export const perOrdineRuolo = <T extends { pivot?: { tipologia?: string | null } | null }>(a: T, b: T): number =>
    (ORDINE_RUOLI[a.pivot?.tipologia ?? ''] ?? 9) - (ORDINE_RUOLI[b.pivot?.tipologia ?? ''] ?? 9);
