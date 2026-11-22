// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I pezzi comuni delle caselle della pagina «Configurazione email» (decisione 572): i colori degli stati con il loro significato (proposta 6), il giorno e
 * l'ora di una prova fatta adesso, l'esito di una prova letto dalla risposta del server.
 */
import type { MotivoDellaProva, RispostaDellaProva, StatoDellaCasella } from './tipi';

/** Il colore di ogni stato: arancio da impostare, azzurro da provare, verde provata, rosso non andata, grigio spenta. */
const COLORI: Record<StatoDellaCasella, string> = {
    da_impostare: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    da_provare: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
    provata: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
    non_andata: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
    spenta: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
    gia_pronta: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
};

/** Il colore del valore nella card in alto: lo stesso significato, solo il testo. */
const TESTI: Record<StatoDellaCasella, string> = {
    da_impostare: 'text-amber-700 dark:text-amber-300',
    da_provare: 'text-sky-700 dark:text-sky-300',
    provata: 'text-emerald-700 dark:text-emerald-300',
    non_andata: 'text-rose-700 dark:text-rose-300',
    spenta: 'text-slate-600 dark:text-slate-300',
    gia_pronta: 'text-emerald-700 dark:text-emerald-300',
};

/** Le classi di un badge: `rounded-md`, mai una pillola; il colore dice lo stato, e la parola lo dice anche a chi non vede il colore. */
export function classiBadge(stato: StatoDellaCasella): string {
    return `inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap ${COLORI[stato]}`;
}

export function classiValore(stato: StatoDellaCasella): string {
    return TESTI[stato];
}

/** «il 19/11 alle 10:14», nell'ora dello studio: per la prova appena fatta, quando il server non manda il suo. */
export function adessoNellOraDelloStudio(quando: Date = new Date()): string {
    const parti = new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
        .formatToParts(quando);
    const v = (tipo: string) => parti.find((p) => p.type === tipo)?.value ?? '';

    return `il ${v('day')}/${v('month')} alle ${v('hour')}:${v('minute')}`;
}

/** L'esito di una prova fatta adesso, come lo mostra la nota sotto «Prova l'invio». */
export interface EsitoDellaProva {
    andata: boolean;
    quando: string;
    destinatario: string;
    motivo: MotivoDellaProva | null;
    /** Il testo del server, sotto il motivo; per un errore del modulo (la password da riscrivere, il limite) non c'è. */
    testo: string | null;
    /** La frase della porta rifiutata della posta (Coda 121), sotto il motivo. */
    suggerimento: string | null;
}

/** Il motivo di riserva per una risposta senza `motivo` (un errore del modulo, il limite delle prove): la frase del server è il titolo, senza testo sotto. */
export function esitoDallErrore(stato: number | undefined, dati: RispostaDellaProva | undefined, destinatario: string): EsitoDellaProva {
    const quando = dati?.prova?.quando ?? adessoNellOraDelloStudio();
    if (stato === 429) {
        return { andata: false, quando, destinatario, testo: null, suggerimento: null,
            motivo: { chiave: 'limite', titolo: 'Troppe prove in poco tempo.', fai: 'Attendi un minuto e riprova: si possono fare sei prove al minuto.', breve: '',
                azione: null } };
    }
    if (dati?.motivo) {
        return { andata: false, quando, destinatario, motivo: dati.motivo, testo: dati.message ?? null, suggerimento: dati.suggerimento ?? null };
    }
    const campo = dati?.campo === 'password' ? { campo: 'password' as const, etichetta: 'Riscrivi la password' } : null;

    return { andata: false, quando, destinatario, testo: null, suggerimento: null,
        motivo: { chiave: 'modulo', titolo: dati?.message || 'La prova non è partita: il server del programma non ha risposto.', fai: '', breve: '', azione: campo } };
}
