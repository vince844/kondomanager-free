// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I pezzi comuni dei passi «Convocazione» e «Documenti»: i colori dei badge con il loro significato, l'indirizzo di un gesto su un invio, le parti
 * della pagina che un gesto ricarica, i giorni scritti con il nome («mercoledì 02/12») dai numeri della data, senza passare dal fuso.
 */
import { piuGiorni } from '@/lib/giorno';
import type { Colore } from './tipi';

/** Le classi di un badge: `rounded-md`, mai una pillola; il colore dice lo stato, e la parola lo dice anche a chi non vede il colore. */
export function classiBadge(colore: Colore | null | undefined): string {
    const base = 'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap';
    const colori: Record<Colore, string> = {
        em: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        am: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
        sk: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
        sl: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
        ro: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
        or: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300',
    };

    return `${base} ${colori[colore ?? 'sl']}`;
}

/** Le classi di una nota a tutta larghezza (un avviso, un esempio), con lo stesso significato dei colori. */
export function classiNota(colore: Colore): string {
    const colori: Record<Colore, string> = {
        em: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        am: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
        sk: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
        sl: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
        ro: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
        or: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300',
    };

    return `flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm ${colori[colore]}`;
}

/**
 * Le parti della pagina che un gesto della convocazione ricarica: il passo, la strada dei passi (con la riga «23 su 27 raggiunte»), il diario, e
 * l'assemblea con i proposti: la prima spedizione registrata la fa «convocata», e il badge, le date, l'ordine del giorno e il menu «⋯» lo devono dire
 * senza ricaricare la pagina.
 */
export const SOLO_CONVOCAZIONE = ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'];

/** Le parti della pagina che un gesto del fascicolo ricarica: il passo «Documenti», la strada, il diario. */
export const SOLO_FASCICOLO = ['fascicolo', 'passi', 'diario'];

/** L'indirizzo di un gesto su un invio: il server lo manda con `__INVIO__` al posto dell'id. */
export function conInvio(url: string, invioId: number): string {
    return url.replace('__INVIO__', String(invioId));
}

/** Le righe che la tabella mostra: le prime otto, o tutte con «Mostra tutti». */
export const RIGHE_DI_SERIE = 8;

export function righeVisibili<T>(righe: T[], tutte: boolean, quante = RIGHE_DI_SERIE): T[] {
    return tutte ? righe : righe.slice(0, quante);
}

const NOMI_DEI_GIORNI = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];

/** «02/12» da `aaaa-mm-gg`. */
export function giornoEMese(data: string): string {
    const [, mese, giorno] = data.slice(0, 10).split('-');

    return `${giorno}/${mese}`;
}

/** «mercoledì 02/12» da `aaaa-mm-gg`: il nome del giorno dai numeri della data, contati in UTC, dove non c'è l'ora legale. */
export function giornoConNome(data: string): string {
    const [anno, mese, giorno] = data.slice(0, 10).split('-').map(Number);
    const nome = NOMI_DEI_GIORNI[new Date(Date.UTC(anno, mese - 1, giorno)).getUTCDay()];

    return `${nome} ${giornoEMese(data)}`;
}

/** Il giorno prima di una data, per l'esempio della «i»: chi spedisce prima della proposta non riceve nessun avviso. */
export function giornoPrima(data: string): string {
    return piuGiorni(data, -1);
}

/** La riga dopo un PDF chiesto con un POST (le lettere, la prova, il foglio, il fascicolo): dove è andato il file. */
export function testoDelFile(arrivato: { modo: 'scheda' | 'scaricato' | 'chiusa'; nome: string }, dopo: string | null = null): string {
    const fine = dopo ? ` ${dopo}` : '';
    if (arrivato.modo === 'chiusa') return `Hai chiuso la scheda: il PDF non si è aperto.${fine}`;
    if (arrivato.modo === 'scheda') return `Il PDF è aperto in una scheda nuova.${fine}`;

    return `La scheda nuova non si è aperta: il PDF si scarica con il nome «${arrivato.nome}».${fine}`;
}

/** Un perché di un pulsante spento, col nome del pulsante (o dei pulsanti) a cui si riferisce e, se c'è, dove si rimedia. */
export interface PercheDelPulsante {
    /** `comune`, `entrambi`, `lettere` o `pec`: dà il `data-testid` della riga. */
    chiave: 'comune' | 'entrambi' | 'lettere' | 'pec';
    /** «Lettere e PEC», «Solo le lettere», «Solo le PEC». */
    chi: string;
    testo: string;
    dove: string | null;
    per: ('lettere' | 'pec')[];
}

/**
 * I perché dei pulsanti spenti, **ognuno col suo pulsante** (decisione 647): una ragione che spegne tutti e due (il luogo che manca, le date, l'assemblea non
 * più da convocare) si scrive una volta, «Lettere e PEC», con il link al passo «Date e ordine del giorno» quando si rimedia lì; una che spegne solo uno,
 * «Solo le PEC» (con il link alla casella) o «Solo le lettere».
 */
export function percheDeiPulsanti(c: {
    lettere: { attivo: boolean; perche: string | null };
    pec: { attivo: boolean; perche: string | null; dove?: string | null };
    comune?: { perche: string; lungo: string } | null;
    rotte: { date?: string };
}): PercheDelPulsante[] {
    const righe: PercheDelPulsante[] = [];
    const comune = c.comune ?? null;
    if (comune) {
        righe.push({ chiave: 'comune', chi: 'Lettere e PEC', testo: comune.perche, dove: c.rotte.date || null, per: ['lettere', 'pec'] });
    }
    const lettere = !c.lettere.attivo && c.lettere.perche && c.lettere.perche !== comune?.lungo ? c.lettere.perche : null;
    const pec = !c.pec.attivo && c.pec.perche && c.pec.perche !== comune?.lungo ? c.pec.perche : null;
    if (lettere !== null && lettere === pec) {
        righe.push({ chiave: 'entrambi', chi: 'Lettere e PEC', testo: lettere, dove: null, per: ['lettere', 'pec'] });
    } else {
        if (lettere !== null) righe.push({ chiave: 'lettere', chi: 'Solo le lettere', testo: lettere, dove: null, per: ['lettere'] });
        if (pec !== null) righe.push({ chiave: 'pec', chi: 'Solo le PEC', testo: pec, dove: c.pec.dove ?? null, per: ['pec'] });
    }

    return righe;
}

/** Il `title` di un pulsante spento: la stessa frase della riga dei perché che lo riguarda (con «Lettere e PEC» o «Solo le …» davanti). */
export function titoloDelPulsante(perche: PercheDelPulsante[], pulsante: 'lettere' | 'pec'): string | undefined {
    const riga = perche.find((r) => r.per.includes(pulsante));

    return riga ? `${riga.chi}: ${riga.testo}` : undefined;
}

/** Le righe che un pulsante di spedizione conta: quelle del suo mezzo e spuntate (di serie lo sono tutte; `nonScelte` tiene le chiavi di chi è stato tolto). */
export function righeScelte<T extends { chiave: string; per: { lettere: boolean; pec: boolean } }>(righe: T[], per: 'lettere' | 'pec', nonScelte: ReadonlySet<string>): { scelte: T[]; tutte: T[] } {
    const tutte = righe.filter((r) => r.per[per]);

    return { scelte: tutte.filter((r) => !nonScelte.has(r.chiave)), tutte };
}

/** Il numero del pulsante: «5» quando sono tutte, «1 scelta» o «3 scelte» quando sono solo alcune (decisione 647). */
export function numeroDelPulsante(scelte: number, tutte: number): string {
    if (scelte === tutte) return String(scelte);

    return scelte === 1 ? '1 scelta' : `${scelte} scelte`;
}
