// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La finestra «Emetti le rate» del cassetto della tesoreria, fuori dal componente: le rate spuntate di serie, il riepilogo, i testi e l'esito della risposta.
 * I dati arrivano dal server (`FinestraDiEmissione`), le cifre in centesimi; l'emissione è quella della pagina del piano (`EmissioneRateController::store`).
 *
 * - **Spuntate di serie** le rate già scadute e quelle dei prossimi 30 giorni: la scadenza fino a `fino`, il giorno che manda il server (oggi più 30 giorni, la
 *   finestra della tesoreria). Sono le rate dell'azione, e il riepilogo all'apertura dice la stessa cifra.
 * - **Il riepilogo**: quante rate, quanti condòmini (le persone delle rate spuntate, ognuna una volta), la cifra (la somma delle cifre delle rate: per ogni
 *   persona la sua parte da pagare, mai sotto zero).
 * - **L'esito**: un rifiuto del server (il piano non approvato, il piano degli addebiti, i passaggi da seguire, la delibera cambiata) arriva come messaggio
 *   d'errore e si legge nella finestra; nessuna rata andata a giornale è un avviso, e si legge nella finestra; altrimenti le rate sono emesse.
 */
import { euroSegno, giornoIntero } from './saldoPrevisto';

export interface RataDellaFinestra {
    rataId: number;
    numero: number;
    scadenza: string;
    /** Per persona la sua parte da pagare (le sue quote meno il già pagato), mai sotto zero, sommata: in centesimi. */
    cifra: number;
    /** Quante persone hanno una quota della rata. */
    persone: number;
    /** Le persone una per una, solo quando la rata non ha tutte quelle del piano; null quando le ha tutte. */
    chi: number[] | null;
}

export interface PianoDellaFinestra {
    pianoId: number;
    piano: string;
    esercizioId: number | null;
    /** Le persone di tutte le rate non emesse del piano, ognuna una volta. */
    persone: number;
    rate: RataDellaFinestra[];
}

export interface DatiDellaFinestra {
    oggi: string;
    /** L'ultimo giorno delle rate spuntate di serie: oggi più 30 giorni. */
    fino: string;
    piani: PianoDellaFinestra[];
}

export interface Riepilogo {
    rate: number;
    condomini: number;
    cifra: number;
}

/**
 * Le rate spuntate all'apertura e a ogni cambio di piano: le già scadute e quelle fino a `fino`, con una cifra da chiedere. Una rata senza denaro (già
 * versata da tutti, o fatta solo di crediti) resta nell'elenco e si spunta a mano: l'azione «Emetti le rate» non la conta, e la finestra apre con le stesse
 * rate dell'azione.
 */
export function spuntateDiSerie(piano: PianoDellaFinestra | null | undefined, fino: string): number[] {
    return (piano?.rate ?? []).filter((r) => r.scadenza.slice(0, 10) <= fino && r.cifra > 0).map((r) => r.rataId);
}

/** Il riepilogo delle rate spuntate: le persone si contano una volta sola, anche se hanno una quota in più rate. */
export function riepilogo(piano: PianoDellaFinestra | null | undefined, spuntate: number[]): Riepilogo {
    const scelte = (piano?.rate ?? []).filter((r) => spuntate.includes(r.rataId));
    if (!piano || scelte.length === 0) return { rate: 0, condomini: 0, cifra: 0 };
    // Una rata con tutte le persone del piano le porta tutte; altrimenti si uniscono quelle delle rate scelte.
    const condomini = scelte.some((r) => r.chi === null) ? piano.persone : new Set(scelte.flatMap((r) => r.chi ?? [])).size;

    return { rate: scelte.length, condomini, cifra: scelte.reduce((s, r) => s + r.cifra, 0) };
}

const rate = (n: number) => (n === 1 ? '1 rata' : `${n} rate`);
const condomini = (n: number) => (n === 1 ? '1 condòmino' : `${n} condòmini`);

/** «3 rate, 4 condòmini, € 3.000,00». */
export function testoDelRiepilogo(r: Riepilogo): string {
    return `${rate(r.rate)}, ${condomini(r.condomini)}, ${euroSegno(r.cifra)}`;
}

/** «Emetti 3 rate», «Emetti 1 rata»; senza rate spuntate «Emetti le rate» (il pulsante è spento). */
export function testoDelPulsante(n: number): string {
    return n > 0 ? `Emetti ${rate(n)}` : 'Emetti le rate';
}

/** Il nome del piano nel menu, con quante rate ha da emettere. */
export function etichettaDelPiano(p: PianoDellaFinestra): string {
    return `${p.piano} (${p.rate.length === 1 ? '1 rata da emettere' : `${p.rate.length} rate da emettere`})`;
}

/** I giorni fra due date «AAAA-MM-GG», contati sul calendario. */
function giorniFra(da: string, a: string): number {
    const giorno = (d: string) => Date.UTC(Number(d.slice(0, 4)), Number(d.slice(5, 7)) - 1, Number(d.slice(8, 10)));

    return Math.round((giorno(a) - giorno(da)) / 86_400_000);
}

/** La scadenza di una rata rispetto a oggi, con il tono: «scaduta da 45 giorni» rossa, «scade oggi» e i 30 giorni ambra, più avanti grigia. */
export function quandoScade(scadenza: string, oggi: string, fino: string): { testo: string; tono: 'scaduta' | 'presto' | 'avanti' } {
    const g = giorniFra(oggi, scadenza);
    if (g < 0) return { testo: g === -1 ? 'scaduta ieri' : `scaduta da ${-g} giorni`, tono: 'scaduta' };
    const testo = g === 0 ? 'scade oggi' : g === 1 ? 'scade domani' : `fra ${g} giorni`;

    return { testo, tono: scadenza.slice(0, 10) <= fino ? 'presto' : 'avanti' };
}

/** «05/04/2026». */
export const dataDellaRata = (r: RataDellaFinestra): string => giornoIntero(r.scadenza);

/** Il pezzo di `flash` che la risposta dell'emissione porta. */
export interface FlashDellEmissione {
    message?: { type?: string; message?: string } | null;
    esito_emissione?: { titolo?: string; testo?: string } | null;
    suggerimento_crediti?: string | null;
}

export type EsitoDellaRisposta =
    | { tipo: 'rifiuto'; testo: string }
    | { tipo: 'nessuna'; titolo: string; testo: string }
    | { tipo: 'emesse'; titolo: string; testo: string };

/**
 * Che cosa ha detto il server dopo «Emetti»: un errore è un rifiuto (nessuna rata emessa, la finestra resta); un avviso vuol dire che nessuna rata è andata a
 * giornale (la finestra resta, con le frasi dell'esito); altrimenti le rate sono emesse, con l'esito e il suggerimento dei crediti se c'è.
 */
export function esitoDellaRisposta(flash: FlashDellEmissione | null | undefined, scelte: number): EsitoDellaRisposta {
    const messaggio = flash?.message ?? null;
    if (messaggio?.type === 'error') {
        return { tipo: 'rifiuto', testo: messaggio.message ?? 'Le rate non sono state emesse.' };
    }
    const esito = flash?.esito_emissione ?? null;
    if (messaggio?.type === 'warning') {
        return { tipo: 'nessuna', titolo: esito?.titolo ?? 'Nessuna rata emessa', testo: esito?.testo ?? messaggio.message ?? '' };
    }
    const base = esito?.testo ?? (scelte === 1 ? 'È stata emessa 1 rata.' : `Sono state emesse ${scelte} rate.`);
    const suggerimento = flash?.suggerimento_crediti ?? null;

    return { tipo: 'emesse', titolo: esito?.titolo ?? 'Emissione completata', testo: suggerimento ? `${base}\n\n${suggerimento}` : base };
}

/** Le frasi degli errori della richiesta, una volta ognuna (due rate di un altro piano danno la stessa frase). */
export function frasiDegliErrori(errori: Record<string, string | string[]> | null | undefined): string[] {
    return [...new Set(Object.values(errori ?? {}).flatMap((m) => (Array.isArray(m) ? m : [m])).filter((m): m is string => typeof m === 'string' && m !== ''))];
}

/**
 * L'interruttore «Avvisa i condòmini»: l'esempio della «i», la spiegazione di acceso e spento, e la riga «Adesso: …». È l'interruttore della pagina del piano
 * («Rendi visibile e invia notifiche», `invia_notifiche`), acceso di serie: acceso, la scadenza della rata compare nell'area dei condòmini; spento, la rata va a
 * giornale nascosta, e si pubblica dopo dalla pagina del piano.
 */
export const AVVISA = {
    titolo: 'Avvisa i condòmini',
    esempio: 'Per esempio: spegnilo se prima devi registrare i versamenti già arrivati, così nessuno vede da pagare una rata che ha già versato.',
    spiegazione: 'Acceso: la scadenza delle rate compare subito nell\'area dei condòmini. Spento: le rate vanno a giornale, ma i condòmini non le vedono finché non le pubblichi.',
    acceso: 'Adesso: i condòmini delle rate spuntate vedono la scadenza nella loro area. Di serie, come nella pagina del piano.',
    spento: 'Adesso: le rate vanno a giornale e restano nascoste ai condòmini; le pubblichi con «Pubblica nascoste» nella pagina del piano.',
} as const;
