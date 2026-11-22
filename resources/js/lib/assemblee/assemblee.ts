// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole e i conti piccoli delle pagine delle assemblee (§3 del progetto della tappa 2): gli importi dai centesimi, le date all'italiana, i colori dei
 * badge con il loro significato, le frasi «Adesso: …». Gli importi arrivano in centesimi dal server; un importo scritto dall'amministratore si manda come
 * lo ha scritto, e il server lo converte una volta sola.
 */
import { giornoItaliano } from '@/lib/giorno';

export { giornoItaliano };

/** «€ 1.800,00» da 180000 centesimi. */
export function euro(centesimi: number | null | undefined): string {
    if (centesimi === null || centesimi === undefined) {
        return '';
    }
    const segno = centesimi < 0 ? '-' : '';
    const assoluto = Math.abs(Math.trunc(centesimi));
    const interi = Math.floor(assoluto / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const decimi = (assoluto % 100).toString().padStart(2, '0');
    return `€ ${segno}${interi},${decimi}`;
}

/** Un importo in centesimi come si scrive nel campo («8.200,00»), per riscriverlo. */
export function importoNelCampo(centesimi: number | null | undefined): string {
    return centesimi === null || centesimi === undefined ? '' : euro(centesimi).replace('€ ', '');
}

/** «15/12» da «2026-12-15». */
export function giornoBreve(data: string | null | undefined): string {
    return data ? giornoItaliano(data).slice(0, 5) : '';
}

export type ColoreDelBadge = 'neutro' | 'blu' | 'ambra' | 'verde' | 'rosso' | 'viola';

/** I badge, `rounded-md` (decisione 399): il colore dice qualcosa, e lo stato è scritto a parole. */
export const CLASSI_DEL_BADGE: Record<ColoreDelBadge, string> = {
    neutro: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    blu: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
    ambra: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    verde: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    rosso: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300',
    viola: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300',
};

export const BADGE = 'inline-flex items-center whitespace-nowrap rounded-md border px-2 py-px text-xs font-medium';

/** Il colore della maggioranza: «Senza voto» neutro, «Ordinaria» blu, le maggioranze qualificate ambra. */
export function coloreDellaMaggioranza(maggioranza: string | null | undefined): ColoreDelBadge {
    if (!maggioranza || maggioranza === 'senza_voto') {
        return 'neutro';
    }
    return maggioranza === 'ordinaria' ? 'blu' : 'ambra';
}

/** La regola di ogni maggioranza a parole, con l'articolo: la riga sotto il menu della maggioranza del punto e la riga del punto nella sala. */
const REGOLE_DELLE_MAGGIORANZE: Record<string, string> = {
    ordinaria: 'Maggioranza degli intervenuti e almeno metà del valore in prima convocazione, un terzo in seconda (art. 1136 c. 2 e 3).',
    meta_del_valore: 'Maggioranza degli intervenuti e almeno la metà del valore (art. 1136 c. 4).',
    due_terzi: 'Maggioranza degli intervenuti e almeno due terzi del valore (art. 1136 c. 5).',
    quattro_quinti: 'Quattro quinti dei condòmini e del valore (art. 1117-ter).',
    unanimita: 'Il voto favorevole di tutti i condòmini.',
    libera: 'Un\'altra maggioranza: decide il presidente.',
    senza_voto: 'Senza voto: il punto si tratta.',
};

/** «Maggioranza degli intervenuti e almeno la metà del valore (art. 1136 c. 4).»; senza maggioranza, decide il presidente. */
export function regolaDellaMaggioranza(maggioranza: string | null | undefined): string {
    return REGOLE_DELLE_MAGGIORANZE[maggioranza ?? ''] ?? 'La maggioranza del punto non è scelta: decide il presidente.';
}

/** Le materie di spesa: il punto può portare la cifra deliberata, che «Crea il piano straordinario» propone e il fondo speciale confronta (decisione 622). */
export const MATERIE_DI_SPESA = ['lavori', 'innovazioni', 'innovazioni_agevolate', 'videosorveglianza', 'progetti_territorio'];

/** Il colore dello stato di un'assemblea. */
export function coloreDelloStato(stato: string | null | undefined, tenutaFuori = false): ColoreDelBadge {
    if (stato === 'in_preparazione') return 'ambra';
    if (stato === 'convocata') return 'blu';
    if (stato === 'annullata') return 'rosso';
    if (stato === 'registrata_a_mano') return tenutaFuori ? 'viola' : 'neutro';
    // Gli stati della sala: in corso si vede, deserta chiede una nuova convocazione, chiusa è fatta.
    if (stato === 'in_corso') return 'verde';
    if (stato === 'deserta') return 'ambra';
    return 'neutro';
}

/** Il colore dell'esito di una delibera: approvata verde, respinta rossa, le altre neutre. */
export function coloreDellEsito(esito: string | null | undefined): ColoreDelBadge {
    if (esito === 'approvata' || esito === 'approvata_con_modifiche') return 'verde';
    if (esito === 'respinta') return 'rosso';
    return 'neutro';
}

/** «1 punto», «6 punti». */
export function quanti(n: number, una: string, tante: string): string {
    return n === 1 ? `1 ${una}` : `${n} ${tante}`;
}

/** Il messaggio di un errore di una richiesta JSON: la prima frase della validazione, o quella del server, o la riserva. */
export function messaggioDellErrore(e: unknown, riserva: string): string {
    const risposta = (e as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } } | null)?.response;
    if (risposta?.status === 403) {
        return 'Non hai il permesso per farlo: chiedilo all\'amministratore.';
    }
    const primo = risposta?.data?.errors ? Object.values(risposta.data.errors)[0]?.[0] : undefined;
    return primo || risposta?.data?.message || riserva;
}

/** «Adesso: dal 25/11/2026 nell'Action inbox e nell'agenda, a te.» — la riga di «Ricordamelo» mentre si sceglie (scena 2 della bozza del memo). */
export function adessoDelMemo(ricordami: boolean, giorno: string, aChi: string, collaboratore: string | null, condominio: string): string {
    if (!ricordami || !giorno) {
        return `Adesso: lo trovi proposto quando prepari la prossima assemblea di ${condominio}.`;
    }
    const chi = aChi === 'studio' ? 'a tutto lo studio' : aChi === 'collaboratore' ? `a ${collaboratore ?? 'un collaboratore'}` : 'a te';
    return `Adesso: dal ${giornoItaliano(giorno)} nell'Action inbox e nell'agenda, ${chi}.`;
}
