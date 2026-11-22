// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa del registro delle modifiche con la finestra «Stampa il registro delle modifiche», e le parole delle pagine della tabella.
 *
 * - **Il periodo dal … al …** (giorni `aaaa-mm-gg`, come li vuole il server: `RegistroPerLaPagina::filtriDellaStampa`) con le scorciatoie «Questo mese»,
 *   «L'esercizio» (le date dell'esercizio, solo nel registro del condominio), «Dall'inizio» (dal primo mese con una riga alla fine dell'ultimo) e «Scegli
 *   le date». La finestra parte dal mese della pagina.
 * - I parametri della stampa e del suo conto: i filtri scelti e il periodo, senza i vuoti e senza il mese (con il periodo il server non lo guarda).
 * - Il riepilogo del piede («Stamperai 134 righe, dal 01/01/2026 al 31/12/2026, sulle assemblee.») e la frase oltre il limite di una stampa sola.
 */
import { giornoItaliano } from '@/lib/giorno';
import { numero, type FiltriDelRegistro, type ScelteDelRegistro } from '@/lib/registroModifiche/registro';

export type Scorciatoia = 'mese' | 'esercizio' | 'inizio' | 'date';

/** Un periodo di giorni, `aaaa-mm-gg`, tutti e due compresi. */
export interface Periodo { dal: string; al: string }

/**
 * Ciò che la pagina dà alla stampa: l'indirizzo del PDF e quello del conto, i filtri della pagina e le scelte dei menu, l'esercizio (solo nel registro
 * del condominio), se è il registro dello studio, e il luogo che la finestra dice sotto il titolo («Via Roma 12», «Tutti i condomini e lo studio»).
 */
export interface StampaDelRegistro {
    indirizzo: string;
    conto: string;
    filtri: FiltriDelRegistro;
    scelte: ScelteDelRegistro;
    esercizio: Periodo | null;
    /** Gli esercizi del condominio, dal più recente (vuoti nello studio): il menu «L'esercizio». */
    esercizi?: EsercizioDellaStampa[];
    delloStudio: boolean;
    luogo: string;
}

/** Un esercizio del condominio come lo manda la pagina: le date `aaaa-mm-gg` e lo stato («aperto», «chiuso»). */
export interface EsercizioDellaStampa { id: number; nome: string; dal: string; al: string; stato: string }

/** La voce del menu «L'esercizio»: «Esercizio 2026 · aperto». */
export const nomeDelMenuEsercizio = (e: EsercizioDellaStampa): string => `${e.nome} · ${e.stato}`;

/** La frase sotto il conto quando non ci sono righe: la stessa che il server dà se si prova a stampare lo stesso. */
export const FRASE_NESSUNA_RIGA = 'Nessuna riga con questi filtri: non c\'è niente da stampare.';

/** Le date di un esercizio come le manda il server (`2026-01-01T00:00:00.000000Z`, o `2026-01-01`) in un periodo; nessuno senza le due date. */
export function periodoDellEsercizio(esercizio: { data_inizio?: string | null; data_fine?: string | null } | null | undefined): Periodo | null {
    const dal = esercizio?.data_inizio ? String(esercizio.data_inizio).slice(0, 10) : '';
    const al = esercizio?.data_fine ? String(esercizio.data_fine).slice(0, 10) : '';

    return /^\d{4}-\d{2}-\d{2}$/.test(dal) && /^\d{4}-\d{2}-\d{2}$/.test(al) && dal <= al ? { dal, al } : null;
}

/** Il contesto delle scorciatoie: oggi (a Roma), l'esercizio del condominio (o nessuno, nello studio), i mesi del filtro della pagina dal più recente. */
export interface ContestoDelPeriodo { oggi: string; esercizio: Periodo | null; mesi: string[] }

/** Le righe per pagina offerte dalla tabella, come `RegistroPerLaPagina::RIGHE_PER_PAGINA`: la prima è quella di serie. */
export const RIGHE_PER_PAGINA = [20, 50, 100];

/** La frase sotto «Al» quando il giorno viene prima di quello di «Dal»: la stessa del server. */
export const ERRORE_AL_PRIMA_DI_DAL = '«Al» viene prima di «Dal»: scegli un giorno uguale o successivo.';

const due = (n: number) => String(n).padStart(2, '0');

/** Il mese intero di un `aaaa-mm`: dal primo all'ultimo giorno (anche il 29 febbraio). */
export function meseIntero(mese: string): Periodo {
    const [anno, m] = mese.split('-').map(Number);
    const ultimo = new Date(Date.UTC(anno, m, 0)).getUTCDate();

    return { dal: `${anno}-${due(m)}-01`, al: `${anno}-${due(m)}-${due(ultimo)}` };
}

/** «L'esercizio 2026», o «L'esercizio 2025/2026» per un esercizio a cavallo di due anni. */
export function etichettaDellEsercizio(esercizio: Periodo): string {
    const [da, a] = [esercizio.dal.slice(0, 4), esercizio.al.slice(0, 4)];

    return `L'esercizio ${da === a ? da : `${da}/${a}`}`;
}

/** Le scorciatoie del periodo, nell'ordine della bozza: «L'esercizio» solo con un esercizio (il registro del condominio). */
export function scorciatoie(esercizio: Periodo | null): { id: Scorciatoia; nome: string }[] {
    return [
        { id: 'mese', nome: 'Questo mese' },
        ...(esercizio ? [{ id: 'esercizio' as const, nome: etichettaDellEsercizio(esercizio) }] : []),
        { id: 'inizio', nome: 'Dall\'inizio' },
        { id: 'date', nome: 'Scegli le date' },
    ];
}

/** Il nome di una scorciatoia, per la frase oltre il limite. */
export function nomeDellaScorciatoia(s: Scorciatoia, esercizio: Periodo | null): string {
    return scorciatoie(esercizio).find((x) => x.id === s)?.nome ?? '';
}

/**
 * Il periodo di una scorciatoia: il mese di oggi; le date dell'esercizio; dal primo mese con una riga alla fine dell'ultimo (i mesi del filtro della
 * pagina, che arrivano almeno al mese di oggi). «Scegli le date» non cambia le date: `null`.
 */
export function periodoDi(s: Scorciatoia, ctx: ContestoDelPeriodo): Periodo | null {
    const meseDiOggi = ctx.oggi.slice(0, 7);
    if (s === 'mese') return meseIntero(meseDiOggi);
    if (s === 'esercizio') return ctx.esercizio ? { dal: ctx.esercizio.dal.slice(0, 10), al: ctx.esercizio.al.slice(0, 10) } : null;
    if (s === 'inizio') {
        const mesi = ctx.mesi.length ? ctx.mesi : [meseDiOggi];

        return { dal: meseIntero(mesi[mesi.length - 1]).dal, al: meseIntero(mesi[0]).al };
    }

    return null;
}

/** Il periodo da cui parte la finestra, dal mese della pagina: il mese di oggi è «Questo mese», «Tutti i mesi» è «Dall'inizio», un altro mese le sue date. */
export function periodoIniziale(mese: string, ctx: ContestoDelPeriodo): { scorciatoia: Scorciatoia; periodo: Periodo } {
    if (mese === 'tutti') return { scorciatoia: 'inizio', periodo: periodoDi('inizio', ctx)! };
    if (!/^\d{4}-\d{2}$/.test(mese) || mese === ctx.oggi.slice(0, 7)) return { scorciatoia: 'mese', periodo: periodoDi('mese', ctx)! };

    return { scorciatoia: 'date', periodo: meseIntero(mese) };
}

/** «Al» prima di «Dal»: la frase sotto «Al». Lo stesso giorno va bene. */
export function erroreDelPeriodo(p: Periodo): string | null {
    return p.dal && p.al && p.al < p.dal ? ERRORE_AL_PRIMA_DI_DAL : null;
}

/** Ciò che si sceglie nella finestra. */
export interface SceltaDellaStampa { chi: string; area: string; azione: string; cerca: string; condominio: string; periodo: Periodo }

/** I parametri della stampa e del conto: i filtri scelti e il periodo, senza i vuoti; il testo da cercare senza gli spazi in più. */
export function parametriDellaStampa(s: SceltaDellaStampa): Record<string, string> {
    return Object.fromEntries(Object.entries({
        chi: s.chi, area: s.area, azione: s.azione, cerca: s.cerca.trim(), condominio: s.condominio, dal: s.periodo.dal, al: s.periodo.al,
    }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

/** L'indirizzo con i parametri, come lo apre la scheda nuova del PDF. */
export function indirizzoConParametri(base: string, parametri: Record<string, string>): string {
    const testo = new URLSearchParams(parametri).toString();

    return testo === '' ? base : `${base}${base.includes('?') ? '&' : '?'}${testo}`;
}

/** «su che cosa» detto come in una frase, dopo il periodo: «sulle assemblee». */
const SU_CHE_COSA: Record<string, string> = {
    persone: 'su persone e fornitori', condominio: 'sul condominio e le unità', millesimi: 'sulle tabelle e i millesimi', piani_rate: 'sui piani rate e le gestioni',
    contabilita: 'sulla contabilità', comunicazioni: 'sulle comunicazioni', segnalazioni: 'sulle segnalazioni', documenti: 'sui documenti',
    impostazioni: 'sulle impostazioni', utenti: 'sugli utenti e i ruoli', agenda: 'sull\'agenda e i compiti', assemblee: 'sulle assemblee',
};

/** «134 righe», «1 riga», con le cifre all'italiana. */
export function testoRighe(n: number): string {
    return n === 1 ? '1 riga' : `${numero(n)} righe`;
}

/** «Pagina 2 di 7». */
export function testoPagina(pagina: number, pagine: number): string {
    return `Pagina ${numero(pagina)} di ${numero(Math.max(1, pagine))}`;
}

/** «dal 01/01/2026 al 31/12/2026». */
export function testoDelPeriodo(p: Periodo): string {
    return `dal ${giornoItaliano(p.dal)} al ${giornoItaliano(p.al)}`;
}

/**
 * Il riepilogo nel piede della finestra, in tre pezzi (il secondo in grassetto): «Stamperai **134 righe**, dal 01/01/2026 al 31/12/2026, sulle assemblee.».
 * Oltre il limite: «**6.240 righe**, dal … al …: troppe per una stampa sola.».
 */
export function riepilogoDellaStampa(r: {
    righe: number; periodo: Periodo; area?: string | null; condominio?: string | null; chi?: string | null; cerca?: string | null; oltre?: boolean;
}): { prima: string; forte: string; dopo: string } {
    const periodo = testoDelPeriodo(r.periodo);
    if (r.oltre) return { prima: '', forte: testoRighe(r.righe), dopo: `, ${periodo}: troppe per una stampa sola.` };
    if (r.righe === 0) return { prima: 'Stamperai ', forte: testoRighe(0), dopo: `, ${periodo}.` };
    const pezzi = [
        periodo,
        r.area ? SU_CHE_COSA[r.area] ?? `su «${r.area}»` : null,
        r.condominio ? `nel condominio ${r.condominio}` : null,
        r.chi ? (r.chi === 'Il programma' ? 'del programma' : `di ${r.chi}`) : null,
        r.cerca?.trim() ? `con «${r.cerca.trim()}»` : null,
    ].filter((p): p is string => !!p);

    return { prima: 'Stamperai ', forte: testoRighe(r.righe), dopo: `, ${pezzi.join(', ')}.` };
}

/** La frase della riga arancio oltre il limite: quante righe, quante al più, e un periodo più breve (un esercizio alla volta, o un anno nello studio). */
export function fraseOltreIlLimite(r: { righe: number; limite: number; scorciatoia: Scorciatoia; nome?: string; conEsercizio: boolean }): string {
    const nomi: Record<Scorciatoia, string> = { mese: 'Questo mese', esercizio: 'L\'esercizio', inizio: 'Dall\'inizio', date: '' };
    const con = r.scorciatoia === 'date' ? 'Con il periodo scelto' : `Con «${r.nome || nomi[r.scorciatoia]}»`;
    const proposta = r.conEsercizio ? 'Scegli un periodo più breve, o stampa un esercizio alla volta.' : 'Scegli un periodo più breve, per esempio un anno alla volta.';

    return `${con} le righe sarebbero ${numero(r.righe)}: troppe per una stampa sola (al più ${numero(r.limite)}). ${proposta}`;
}
