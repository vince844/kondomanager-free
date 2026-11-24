// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La scheda «Errori» della pagina «Registri» (1.12.0-beta.14, decisione 698; bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scene
 * 7–9): le parole e i colori dello stato, la riga «dove» (la pagina o il lavoro), quante volte, la data breve, il codice cercato. Il server manda gli errori
 * già ripuliti (`LogsController`): qui niente si calcola sui dati, solo come si leggono.
 */

export type StatoErrore = 'nuovo' | 'avvisato' | 'visto';

export interface RigaErrore {
    id: number;
    stato: StatoErrore;
    classe: string;
    messaggio: string;
    codice: string | null;
    metodo: string | null;
    pagina: string | null;
    azione: string | null;
    volte: number;
    ultima_volta_il: string | null;
    avvisato_da: string | null;
}

export interface FiltriErrori {
    cerca: string;
    stato: 'tutti' | StatoErrore;
    dal: string;
    al: string;
    per_page: number;
}

export interface DettaglioErrore {
    id: number;
    stato: StatoErrore;
    classe: string;
    messaggio: string;
    volte_in_tutto: number;
    prima_volta: string;
    ultima_volta: string;
    impronta: string;
    volta: {
        id: number;
        codice: string;
        quando: string;
        metodo: string | null;
        pagina: string | null;
        azione: string | null;
        file: string | null;
        riga: number | null;
        causa: string | null;
        programma: string;
        chi: string;
    } | null;
    volte: Array<{ id: number; codice: string; quando: string; chi: string; avvisato: boolean }>;
    avvisi: Array<{ codice: string; nome: string; chi: string; condominio: string | null; quando: string; nota: string | null }>;
    traccia: string[];
    rapporto: string;
    rapporto_url: string | null;
}

/** I menu «Stato» della scheda: la voce e la sua etichetta. */
export const STATI: Array<{ valore: FiltriErrori['stato']; etichetta: string }> = [
    { valore: 'tutti', etichetta: 'Tutti gli stati' },
    { valore: 'avvisato', etichetta: 'Avvisati' },
    { valore: 'nuovo', etichetta: 'Nuovi' },
    { valore: 'visto', etichetta: 'Visti' },
];

export const RIGHE_PER_PAGINA = [10, 25, 50, 100].map((n) => ({ valore: n, etichetta: String(n) }));

/** Lo stato si dice anche con la scritta; il colore ha un significato: rosso nuovo, arancio avvisato, grigio visto. */
export function badgeDelloStato(stato: StatoErrore): { testo: string; classi: string } {
    switch (stato) {
        case 'avvisato':
            return { testo: 'Avvisato', classi: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300' };
        case 'nuovo':
            return { testo: 'Nuovo', classi: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300' };
        default:
            return { testo: 'Visto', classi: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300' };
    }
}

/** Dove è successo: la pagina con il metodo, o il lavoro in background. */
export function doveESuccesso(r: Pick<RigaErrore, 'metodo' | 'pagina' | 'azione'>): { metodo: string | null; testo: string } {
    if (r.pagina) return { metodo: r.metodo, testo: r.pagina };

    return { metodo: null, testo: r.azione || 'Pagina non nota' };
}

export function testoVolte(n: number): string {
    // Il punto delle migliaia a mano: l'italiano di Intl non lo mette sotto le cinque cifre (1234), e le cifre si leggono «1.234».
    return n === 1 ? '1 volta' : `${String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')} volte`;
}

/** «22/11 10:42»: il giorno e l'ora di Roma, come si leggono nella tabella. */
export function dataBreve(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    const parti = new Intl.DateTimeFormat('it-IT', {
        timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
    }).formatToParts(d);
    const p = (t: string) => parti.find((x) => x.type === t)?.value ?? '';

    return `${p('day')}/${p('month')} ${p('hour')}:${p('minute')}`;
}

/** Il codice intero com'è scritto da chi lo cerca (minuscole, spazi, un trattino), o null: lo stesso alfabeto del server (niente 0, O, 1, I, L). */
export function codiceDaTesto(testo: string): string | null {
    const pulito = testo.replace(/[\s-]+/g, '').toUpperCase();

    return /^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/.test(pulito) ? pulito : null;
}

/** Il numero sul cartellino della scheda: «2 nuovi», «1 nuovo»; nessun cartellino rosso con zero. */
export function etichettaNuovi(n: number): string | null {
    if (n <= 0) return null;

    return n === 1 ? '1 nuovo' : `${n} nuovi`;
}
