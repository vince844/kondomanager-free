// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il registro delle modifiche a video (1.12.0-beta.13, §8 e §14 del progetto della tappa 1, scene 1, 2 e 5 della bozza): le forme delle righe che il server
 * manda (`LetturaDelRegistro::perLaPagina`, solo `prima` e `dopo` dei campi), le parole e i colori delle azioni, le frasi di «Mostra altre» della «Storia».
 * La stampa con la finestra e le parole delle pagine della tabella stanno in `stampa.ts`.
 */

export interface CampoDellaRiga { chiave: string | null; etichetta: string | null; prima: string | null; dopo: string | null; segreto: boolean }

export interface RigaDelRegistro {
    id: number;
    avvenuta_il: string;
    autore: { tipo: 'persona' | 'programma' | string; nome: string; ruolo: string | null; origine: string; dettaglio: string | null };
    area: string;
    azione: string;
    oggetto: { tipo: string; id: number | null; nome: string };
    scheda: { tipo: string | null; id: number | null };
    riepilogo: string | null;
    perche: string | null;
    campi: CampoDellaRiga[];
    rimando: { tipo: string; id: number | null; etichetta: string | null } | null;
    oscurata: boolean;
    annullata_dal_ripristino_del: string | null;
    /** La riga non ha la firma del programma (aggiunta o cambiata con il database): il badge rosso «Aggiunta fuori dal programma». */
    fuori_dal_programma?: boolean;
}

/** Una riga della «Storia»: in più, per ogni campo, se «Ripristina» si può, la frase di un'area senza ripristino, e la pagina da aprire. */
export interface RigaDellaStoria extends RigaDelRegistro {
    ripristina: Record<string, boolean>;
    nota: string | null;
    apri?: string | null;
}

/** Una riga della pagina del registro: in più «Storia» o «Apri», le parole e la frase di «Che cosa è cambiato». */
export interface RigaDellaPagina extends RigaDelRegistro {
    storia: { tipo: string; id: number | null } | null;
    apri: string | null;
    azione_parola: string;
    area_parola: string;
    condomini: string[];
    nota: string | null;
    cambiato: string;
}

/** I filtri della pagina del registro, come li rimanda il server (`RegistroPerLaPagina::filtri`). */
export interface FiltriDelRegistro { chi: string | null; area: string | null; azione: string | null; mese: string; cerca: string | null; condominio: number | null }

/** Una scelta dei menu dei filtri. */
export interface SceltaDelFiltro { valore: string; nome: string }

/** Le scelte dei menu dei filtri (`RegistroPerLaPagina::scelte`). */
export interface ScelteDelRegistro { chi: SceltaDelFiltro[]; aree: SceltaDelFiltro[]; azioni: SceltaDelFiltro[]; mesi: SceltaDelFiltro[]; condomini: SceltaDelFiltro[] }

/** Le righe di una pagina della tabella, con le pagine delle altre tabelle: la pagina, quante pagine, quante righe per pagina, quante in tutto. */
export interface PaginaDelleRighe { data: RigaDellaPagina[]; current_page: number; last_page: number; per_page: number; total: number }

export interface StoriaDellaScheda {
    titolo: string;
    sottotitolo: string;
    totale: number;
    pagina: number;
    altre: number;
    righe: RigaDellaStoria[];
    oscuramento: SoggettoDaOscurare | null;
}

/** L'oscuramento: l'evento `oscura` del cassetto porta la persona o il fornitore. */
export interface SoggettoDaOscurare { soggetto_tipo: 'anagrafica' | 'fornitore'; soggetto_id: number; nome: string }

export interface AnteprimaDelRipristino {
    riga_id: number; campo: string; etichetta: string; titolo: string; descrizione: string; adesso: string | null; torna_a: string | null;
    gia_cosi: boolean; invii: string | null; pulsante: string;
}

/**
 * Le «Storie» dello studio (P18): utenti, ruoli, impostazioni dello studio, modelli e carte delle stampe. Solo l'amministratore le apre: il pulsante non c'è
 * per gli altri (il server le rifiuta comunque, `SchedeDellaStoria::delloStudio`).
 */
export const STORIE_DELLO_STUDIO = ['utente', 'ruolo', 'impostazioni_generali', 'impostazioni_posta', 'impostazioni_stampe', 'impostazioni_backup',
    'impostazioni_tasso_legale', 'impostazioni_assemblee', 'modello_testo', 'carta_stampa'];

/** Le parole delle azioni, come la bozza («Modificata», «Collegata», «Creata»). */
export const PAROLE_DELLE_AZIONI: Record<string, string> = {
    creata: 'Creata', modificata: 'Modificata', eliminata: 'Eliminata', collegata: 'Collegata', scollegata: 'Scollegata', ripristinata: 'Ripristinata',
    gesto: 'Operazione', atto_utenza: 'Utenze', oscuramento: 'Oscuramento', ripristino_copia: 'Ripristino di una copia', conservazione: 'Conservazione',
};

/** Il colore del badge dell'azione (badge `rounded-md`, standard 3), con il tema scuro: lo stato è detto anche a parole (standard 12). */
export const COLORI_DELLE_AZIONI: Record<string, string> = {
    creata: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    modificata: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
    ripristinata: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    collegata: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300',
    scollegata: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300',
    eliminata: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
};
export const COLORE_NEUTRO = 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300';

export function paroleDellAzione(azione: string): string {
    return PAROLE_DELLE_AZIONI[azione] ?? azione;
}

export function coloreDellAzione(azione: string): string {
    return COLORI_DELLE_AZIONI[azione] ?? COLORE_NEUTRO;
}

/** Chi, come lo dice la riga: la persona con il ruolo; «Il programma» con il lavoro che l'ha fatto; l'importazione «avviata da …». */
export function dettaglioDiChi(r: RigaDelRegistro): string | null {
    if (r.autore.tipo === 'programma') {
        return r.autore.dettaglio;
    }
    if (r.autore.origine === 'importazione') {
        return r.autore.dettaglio ?? 'importazione';
    }

    return r.autore.ruolo;
}

/** Il valore vuoto si scrive «vuoto», come le righe del registro. */
export function valore(v: string | null): string {
    return v === null || v === '' ? 'vuoto' : v;
}

/** «e altre 304 righe · Mostra altre» (la bozza). */
export function testoAltre(altre: number): string {
    if (altre <= 0) {
        return '';
    }

    return altre === 1 ? 'e un\'altra riga' : `e altre ${numero(altre)} righe`;
}

/** Le cifre all'italiana: «4.812». Con il separatore anche a quattro cifre: di serie l'italiano scrive «4812». */
export function numero(n: number): string {
    return new Intl.NumberFormat('it-IT', { useGrouping: 'always' } as Intl.NumberFormatOptions).format(n);
}

/** «312 modifiche», «1 modifica». */
export function testoModifiche(n: number): string {
    return n === 1 ? '1 modifica' : `${numero(n)} modifiche`;
}

/** Il primo messaggio di un errore della richiesta: quello della regola della pagina (422), o il messaggio, o la frase di riserva. */
export function messaggioDellErrore(e: unknown, riserva: string): string {
    const risposta = (e as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } } | null)?.response;
    if (risposta?.status === 403) {
        return 'Non hai il permesso di modificare questa scheda.';
    }
    const errori = risposta?.data?.errors;
    if (errori) {
        const primo = Object.values(errori)[0]?.[0];
        if (primo) {
            return `La scheda non passa i controlli della sua pagina: ${primo}`;
        }
    }

    return risposta?.data?.message || riserva;
}
