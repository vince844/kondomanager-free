// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole del riquadro «Sei mesi per agire» e del suo compito nell'Inbox. Le date, le cifre, i passi e le frasi della regola arrivano dal server già
 * decise (`SeiMesiPerAgire`, `PassoProposto`): qui si formattano e basta. Mai «in ritardo» per l'amministratore, né che qualcuno «deve»: il programma dice a
 * che punto è e propone.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export type Passo = 'sollecito' | 'diffida' | 'spedizione' | 'fascicolo' | 'accordo' | 'compensa' | 'agito';
export type Colore = 'passata' | 'vicina' | 'lontana' | 'fermato' | 'approvato';
export type Situazione = 'senza_lettera' | 'promemoria' | 'sollecito' | 'diffida' | 'accordo';

export interface PassoDellaPersona {
    caso: string;
    passo: Passo | null;
    frase: string;
    note: string[];
    possibili: Passo[];
    rata_compensa: number | null;
    termine: string | null;
}
export interface PersonaLeggera { anagrafica_id: number; nome: string; cifra: number; situazione: Situazione | string }
export interface PersonaDellaRiga extends PersonaLeggera {
    unita: string[];
    credito: number;
    passo: PassoDellaPersona;
    torna: string | null;
    lettere_da_spedire: number[];
}
export interface Cartellini { senza_lettera: number; promemoria?: number; sollecito: number; diffida_spedita: number; diffida_da_spedire: number; accordo: number; dispensata: number }
export interface Risposta { stato: string | null; frase: string | null; nota?: string | null; ricordami_il?: string | null; registro: Array<Record<string, unknown>> }
export interface RigaSeiMesi {
    chiave: string;
    esercizio: { id: number; nome: string };
    data: string;
    giorni: number;
    colore: Colore;
    aperta: boolean;
    piu_avanti: boolean;
    quante: number;
    cifra: number;
    cartellini: Cartellini;
    persone: PersonaDellaRiga[];
    oltre: { mancano: number; cifra: number } | null;
    tutte: PersonaLeggera[];
    chiuse: Array<{ anagrafica_id: number; nome: string; cifra: number; motivo: 'agito' | 'dispensa' | string; frase: string }>;
    in_blocco: Array<{ passo: Passo; persone: number[]; lettere: number[] }>;
    risposta: Risposta | null;
    compito: boolean;
    compito_tornato: string | null;
}
export interface RendicontoInAssemblea {
    esercizio: { id: number; nome: string };
    data: string;
    giorni: number;
    colore: Colore;
    stato: 'bozza' | 'presentata' | 'approvata' | string;
    frase: string;
    passo: 'apri' | 'approvazione' | null;
    url: string;
    agenda: { giorno: string } | null;
    risposta: Risposta | null;
    compito: boolean;
    ultimo: boolean;
}
export interface Compito {
    chiave: string;
    argomento: 'sei_mesi' | 'rendiconto';
    esercizio: { id: number; nome: string };
    data: string;
    giorni: number;
    colore: Colore;
    quante?: number;
    cifra?: number;
    cartellini?: Cartellini;
    frase?: string;
    url?: string;
    agenda?: { giorno: string } | null;
    tornato: string | null;
    ancora: string;
    tutte?: PersonaLeggera[];
}
/** Una persona con rate senza la data dei sei mesi: perché la data manca e la prossima azione che quel perché chiede. */
export interface MotivoSenzaData {
    caso: string;
    perche: string;
    spiegazione: string;
    azione: { tipo: 'origine' | 'esercizio' | 'piano' | 'estratto'; etichetta: string; piano?: { id: number; esercizio_id: number | null } };
}
export interface PersonaSenzaData { anagrafica_id: number; nome: string; unita: string[]; cifra: number; motivi: MotivoSenzaData[] }

export interface SeiMesi {
    oggi: string;
    passo_proposto: boolean;
    troppe: { quote: number; frase: string } | null;
    righe: RigaSeiMesi[];
    senza_data: { rate: number; persone: number; cifra: number; perche: string[]; per_persona?: Record<number, number>; righe?: PersonaSenzaData[] } | null;
    rendiconti: RendicontoInAssemblea[];
    compiti: Compito[];
}

/** Una risposta da dare: il riquadro e il compito la chiedono, la finestra delle risposte la manda. */
export interface RichiestaDiRisposta {
    tipo: 'agito' | 'fatto' | 'non_serve' | 'ricordamelo' | 'riapri' | 'in_agenda';
    argomento: 'sei_mesi' | 'rendiconto';
    esercizio: { id: number; nome: string };
    /** La data dei sei mesi, o quella dei 180 giorni. */
    data: string;
    persona?: PersonaLeggera | null;
    persone?: PersonaLeggera[];
}

/** Il nome di ogni passo, come sul pulsante (lo stesso di `PassoProposto::NOMI`). */
export const NOMI_DEI_PASSI: Record<Passo, string> = {
    sollecito: 'Manda un sollecito',
    diffida: 'Manda la diffida',
    spedizione: 'Registra la spedizione',
    fascicolo: 'Prepara il fascicolo per il legale',
    accordo: 'Vedi l\'accordo',
    compensa: 'Compensa',
    agito: 'Registra che hai agito',
};

/** I passi che aprono qualcosa: una finestra (le lettere, il fascicolo per il legale, l'accordo, le risposte) o una pagina. */
export const PASSI_CON_UNA_PORTA: Passo[] = ['sollecito', 'diffida', 'spedizione', 'fascicolo', 'accordo', 'compensa', 'agito'];

export const TITOLO = 'Sei mesi per agire';
export const FRASE_PIEDE = 'Le date si calcolano a ogni apertura. Il programma suggerisce la prossima azione per ogni persona: la scelta resta tua.';
export const FRASE_PIEDE_SCELGO_IO = 'Le date si calcolano a ogni apertura. Le azioni possibili sono nel menu di ogni persona: la scelta resta tua.';
export const FRASE_SUGGERIMENTO = 'Il programma calcola la data a ogni apertura e suggerisce la prossima azione per ogni persona: la scelta resta tua.';
/** La «i» della colonna «Non versato» della tabella delle persone. */
export const FRASE_NON_VERSATO = 'Le rate di questo esercizio non versate.';
/** La riga delle rate senza la data dei sei mesi, chiusa: sotto ci sono le persone. */
export const FRASE_SENZA_DATA = 'Il programma non sa da quale esercizio contare i sei mesi: qui sotto chi è e che cosa fare.';
/** Le colonne della tabella delle persone della riga di un esercizio, e di quella delle rate senza la data. */
export const COLONNE_PERSONE = ['Persona', 'Non versato', 'A che punto è', 'Prossima azione', 'Registra che hai agito'] as const;
export const COLONNE_SENZA_DATA = ['Persona', 'Non versato', 'Perché manca la data', 'Prossima azione'] as const;
export const FRASE_LETTERE = 'Le lettere chiedono tutto lo scaduto della persona, non solo la parte di questo esercizio.';
export const FRASE_VUOTO = 'Nessuna rata scaduta da seguire per i sei mesi.';
export const FRASE_RENDICONTO = 'La legge chiede di convocare l\'assemblea che approva il rendiconto entro 180 giorni dalla fine dell\'esercizio (art. 1130 n. 10 c.c.). '
    + 'Il programma conta dal giorno dopo la fine dell\'esercizio: è la data più presto. Il programma sa quando il rendiconto è fermato e quando è approvato, '
    + 'non quando l\'assemblea è convocata.';

/** «2025» da «Esercizio 2025». */
export const annoDi = (nome: string): string => nome.replace(/^(esercizio\s+)?(anno\s+)?/i, '');

const persone = (n: number) => (n === 1 ? '1 persona' : `${n} persone`);

/** «fra 41 giorni», «domani», «oggi», «passata ieri», «passata da 12 giorni». */
export function testoGiorni(g: number): string {
    if (g > 1) return `fra ${g} giorni`;
    if (g === 1) return 'domani';
    if (g === 0) return 'oggi';
    if (g === -1) return 'passata ieri';

    return `passata da ${-g} giorni`;
}

/** Il cartellino maiuscolo del compito, come quelli dei compiti di oggi. */
export function cartellinoDelCompito(g: number): string {
    if (g > 1) return `FRA ${g} GIORNI`;
    if (g === 1) return 'DOMANI';
    if (g === 0) return 'OGGI';
    if (g === -1) return 'DATA PASSATA IERI';

    return `DATA PASSATA DA ${-g} GIORNI`;
}

/** «4 persone · € 3.240,00 di questo esercizio». */
export const testoPersoneCifra = (quante: number, cifra: number): string => `${persone(quante)} · ${euroTesto(cifra)} di questo esercizio`;

/** I cartellini della riga, solo quelli che contano qualcosa. */
export function testiCartellini(c: Cartellini): string[] {
    return [
        c.senza_lettera ? `${c.senza_lettera} senza lettera` : null,
        c.promemoria ? `${c.promemoria} con il promemoria` : null,
        c.sollecito ? `${c.sollecito} con il sollecito` : null,
        c.diffida_spedita ? `${c.diffida_spedita} con la diffida spedita` : null,
        c.diffida_da_spedire ? `${c.diffida_da_spedire} con la diffida da spedire` : null,
        c.accordo ? `${c.accordo} con un accordo` : null,
        c.dispensata ? `${c.dispensata} ${c.dispensata === 1 ? 'dispensata' : 'dispensate'}` : null,
    ].filter((x): x is string => x !== null);
}

/** Il pulsante in blocco del fondo della riga. */
export function testoInBlocco(passo: Passo, n: number): string {
    if (passo === 'spedizione') return `Registra la spedizione di ${n === 1 ? '1 lettera' : `${n} lettere`}`;
    if (passo === 'sollecito') return `Manda un sollecito a ${persone(n)}`;

    return `Manda la diffida a ${persone(n)}`;
}

/** La riga di fondo oltre 8 persone. */
export const testoOltre = (o: { mancano: number; cifra: number }): string => `Qui le 5 con la cifra più alta; mancano ${persone(o.mancano)}, ${euroTesto(o.cifra)}`;

/** Le rate senza la data dei sei mesi. */
export function testoSenzaData(s: { rate: number; persone: number; cifra: number }): string {
    return `${s.rate === 1 ? '1 rata' : `${s.rate} rate`} di ${persone(s.persone)}, ${euroTesto(s.cifra)}, ${s.rate === 1 ? 'non ha' : 'non hanno'} la data dei sei mesi.`;
}

/** «Hai registrato che hai agito per 2 persone; 1 è dispensata». */
export function testoChiuse(chiuse: Array<{ motivo: string }>): string {
    const agito = chiuse.filter((c) => c.motivo === 'agito').length;
    const dispensate = chiuse.filter((c) => c.motivo === 'dispensa').length;
    const parti = [
        agito ? `Hai registrato che hai agito per ${persone(agito)}` : null,
        dispensate ? (dispensate === 1 ? '1 è dispensata' : `${dispensate} sono dispensate`) : null,
    ].filter(Boolean) as string[];

    return agito ? parti.join('; ') : `${dispensate === 1 ? '1 persona è dispensata' : `${dispensate} persone sono dispensate`}`;
}

/** La riga con tutte le persone chiuse: non «0 persone», ma chi è chiuso. */
export function testoRigaSenzaAperte(chiuse: Array<{ motivo: string }>): string {
    const chi = testoChiuse(chiuse);

    return `Nessuna persona da seguire: ${chi.charAt(0).toLowerCase()}${chi.slice(1)}`;
}

/**
 * Le classi del colore di una data: il testo della data e dei giorni, lo sfondo appena tinto. Niente bordo sinistro colorato: la riga ha il bordo sottile
 * di tutte le card, e il colore lo dicono la data, la scritta e lo sfondo.
 */
export function classiDelColore(c: Colore): { testo: string; sfondo: string } {
    switch (c) {
        case 'passata':
            return { testo: 'text-red-600 dark:text-red-400', sfondo: 'bg-red-50/40 dark:bg-red-900/10' };
        case 'vicina':
            return { testo: 'text-amber-700 dark:text-amber-400', sfondo: 'bg-amber-50/40 dark:bg-amber-900/10' };
        case 'approvato':
            return { testo: 'text-emerald-700 dark:text-emerald-400', sfondo: 'bg-emerald-50/40 dark:bg-emerald-900/10' };
        default:
            return { testo: 'text-slate-500 dark:text-slate-400', sfondo: 'bg-slate-50/50 dark:bg-slate-800/30' };
    }
}

/** Il titolo del compito. */
export function titoloDelCompito(c: Pick<Compito, 'argomento' | 'esercizio' | 'data'>): string {
    return c.argomento === 'rendiconto'
        ? `Rendiconto ${annoDi(c.esercizio.nome)}: convocare l'assemblea entro il ${giorno(c.data)}`
        : `${TITOLO}: esercizio ${annoDi(c.esercizio.nome)}`;
}

/** La descrizione del compito: la data, le persone, la cifra, i primi cartellini. */
export function descrizioneDelCompito(c: Compito): string {
    if (c.argomento === 'rendiconto') {
        // Il compito c'è solo con il rendiconto in bozza.
        return 'Il rendiconto è ancora una bozza: le cifre cambiano a ogni registrazione.';
    }
    const cartellini = c.cartellini ? testiCartellini({ ...c.cartellini, dispensata: 0 }).slice(0, 2) : [];

    return `Entro il ${giorno(c.data)}: ${persone(c.quante ?? 0)}, ${euroTesto(c.cifra ?? 0)} di rate di questo esercizio non versate.`
        + (cartellini.length ? ` ${cartellini.join(', ')}.` : '');
}

/** Le situazioni del filtro della finestra «Fatto». */
export const SITUAZIONI: Array<{ valore: Situazione | null; etichetta: string }> = [
    { valore: null, etichetta: 'Tutte le situazioni' },
    { valore: 'senza_lettera', etichetta: 'Senza lettera' },
    { valore: 'promemoria', etichetta: 'Con il promemoria' },
    { valore: 'sollecito', etichetta: 'Con il sollecito' },
    { valore: 'diffida', etichetta: 'Con la diffida' },
    { valore: 'accordo', etichetta: 'Con l\'accordo' },
];

/** La situazione di una persona, per la riga della finestra «Fatto». */
export const testoSituazione = (s: string): string => ({
    senza_lettera: 'senza lettera', promemoria: 'con il promemoria', sollecito: 'con il sollecito', diffida: 'con la diffida', accordo: 'con l\'accordo',
} as Record<string, string>)[s] ?? s;

/** Le persone che la ricerca e il filtro mostrano. */
export function personeDellaFinestra<T extends PersonaLeggera>(tutte: readonly T[], cerca: string, situazione: string | null): T[] {
    const testo = cerca.trim().toLowerCase();

    return tutte.filter((p) => (!testo || p.nome.toLowerCase().includes(testo)) && (!situazione || p.situazione === situazione));
}

/** «3 spuntate su 4». */
export const testoSpuntate = (spuntate: number, tutte: number): string => `${spuntate} ${spuntate === 1 ? 'spuntata' : 'spuntate'} su ${tutte}`;

/** «1 spuntata non si vede con il filtro». */
export const testoNascoste = (n: number): string => (n === 1 ? '1 spuntata non si vede con il filtro' : `${n} spuntate non si vedono con il filtro`);

/** «Registra per 3 persone». */
export const testoRegistraPer = (n: number): string => `Registra per ${persone(n)}`;

/** «Il 15/07/2026 viene dopo la data dei sei mesi, il 30/06/2026.»: lo dice, non lo vieta. */
export const testoDopoLaData = (scelto: string, data: string): string => `Il ${giorno(scelto)} viene dopo la data dei sei mesi, il ${giorno(data)}.`;

/** Il gesto di una riga del registro, a parole. */
export const testoDelGesto = (tipo: string): string => ({
    agito: 'Hai agito', fatto: 'Fatto', non_serve: 'Non serve', ricordamelo: 'Ricordamelo', riapri: 'Riaperto', in_agenda: 'Messo in agenda',
} as Record<string, string>)[tipo] ?? tipo;
