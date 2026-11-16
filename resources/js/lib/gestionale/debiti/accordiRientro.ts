/**
 * Le parole dell'accordo di rientro, nell'elenco «Rate scadute», nella sua finestra, nell'estratto conto e nell'anteprima delle lettere. Il segno dice
 * le due cifre che il programma sa (i riconosciuti, e quanto resta oggi della parte riconosciuta), e mai che la persona è «in regola» o che l'accordo è
 * finito: «fino al» passato è una data passata, senza aggettivi. Il calendario dice per ogni rata quanto è versato, al centesimo; la regola di
 * decadenza segnala il giorno in cui si verifica, e la decadenza la registra l'amministratore: il programma non scrive mai che l'accordo è decaduto.
 * Le date arrivano dal server come `Y-m-d`, le cifre in centesimi.
 */
import { euroTesto, giorno } from './rateScadute';

/** Una rata del calendario come la legge il server: il versato, quanto manca, se è versata e se è scaduta. */
export interface RataDelCalendario { numero: number; data: string; importo: number; versato: number; mancano: number; versata: boolean; scaduta: boolean }
/** Una rata come la conserva un segnale: il versato di quel giorno. */
export interface RataDelSegnale { numero: number; data: string; importo: number; versato: number }
/** Un segnale conservato: il giorno, le rate di quel giorno con i loro versati, e se il giornale di oggi lo smentisce. */
export interface Segnale { il: string; rate: RataDelSegnale[]; smentito?: boolean }
export interface RegolaDiDecadenza { rate: number | null; giorni: number | null }

export interface AccordoRientro {
    id: number;
    data_accordo: string;
    fino_al: string;
    riconosciuti: number;
    oggi: number;
    invariato: boolean;
    fino_al_passato: boolean;
    versati_oltre: number;
    autorizzato_assemblea: boolean;
    data_autorizzazione: string | null;
    verbale_autorizzazione: string | null;
    nota: string | null;
    chiuso_il: string | null;
    nota_chiusura: string | null;
    /** Il calendario, se l'accordo ne ha uno; gli accordi registrati come solo registro no. */
    calendario?: RataDelCalendario[] | null;
    rate_non_versate?: number | null;
    prossima?: { numero: number; data: string; importo: number; mancano: number } | null;
    segnali?: { rate: Segnale | null; giorni: Segnale | null };
    regola?: RegolaDiDecadenza;
    decadenza_registrata_il?: string | null;
}

export interface ParteInAccordo { riconosciuto: number; in_accordo: number; data_accordo: string; fuori_dalle_lettere: boolean; in_parte?: boolean }

export const FRASE_AUTORIZZAZIONE = 'Se l\'assemblea non ha ancora deliberato è «no», con una nota: diventa «sì» quando registri la delibera. '
    + 'L\'autorizzazione dell\'accordo non è la dispensa dei sei mesi.';

export const FRASE_REGISTRO = 'Le rate coperte restano fra le scadute, con il segno dell\'accordo, e le lettere proposte lasciano fuori la parte riconosciuta '
    + 'fino all\'ultima rata del calendario. Gli interessi e le spese entrano nell\'accordo dopo che li hai registrati, come rate loro.';

/** Sotto il calendario: chi decide le cifre. */
export const FRASE_CALENDARIO = 'Rate uguali, l\'ultima con i centesimi che avanzano: cambiale come dice l\'accordo firmato. La somma deve fare il debito riconosciuto.';

/** Il calendario con i giorni fuori ordine o una rata a zero: la stessa frase del server. */
export const FRASE_CALENDARIO_IN_ORDINE = 'Le rate del calendario vanno in ordine di giorno, una per giorno, dal giorno dell\'accordo in poi; ogni rata più di zero.';

/** Sotto la regola di decadenza: che cosa fa la regola, e chi registra la decadenza. */
export const FRASE_REGOLA = 'Quando la condizione si verifica l\'accordo lo dice, con il giorno e le cifre; la decadenza la registri tu, se la vuoi. '
    + 'Lasciala vuota se l\'accordo non la prevede.';

/** Accanto alla regola proposta: da dove viene. */
export const FRASE_REGOLA_DI_SERIE = 'Proposta la regola dell\'ultimo accordo di questo condominio.';

/** Sopra il modulo della decadenza. */
export const FRASE_DECADENZA = 'La decadenza chiude l\'accordo il giorno che scrivi: da quel giorno le sue rate tornano nelle lettere proposte.';

export const FRASE_IMPORTO = 'Di serie il residuo della rata il giorno dell\'accordo: lo puoi abbassare, non alzare.';

/** Una rata coperta solo in parte: come conta un versamento su quella rata. */
export const FRASE_IN_PARTE = 'Un versamento su questa rata conta prima per la parte fuori dall\'accordo.';

/** La finestra offre solo le rate scadute: una rata che scade dopo entra con un accordo nuovo. */
export const FRASE_NON_SCADUTE = 'Le rate non ancora scadute non entrano. Quando scadono, chiudi questo accordo e registrane uno nuovo con tutte le rate, con la data '
    + 'della chiusura.';

/** Il segno di un accordo: le due cifre, o «residuo invariato» quando sono uguali; e la parte versata oltre il capitale, se c'è. */
export function testoSegnoAccordo(a: AccordoRientro): string {
    const quando = `Accordo di rientro del ${giorno(a.data_accordo)}, fino al ${giorno(a.fino_al)}${a.fino_al_passato ? ' (data passata)' : ''}`;
    const cifre = a.invariato
        ? `riconosciuti ${euroTesto(a.riconosciuti)}, residuo invariato dal ${giorno(a.data_accordo)}`
        : `riconosciuti ${euroTesto(a.riconosciuti)}, oggi ${euroTesto(a.oggi)}`;
    const oltre = a.versati_oltre > 0
        ? ` Versati ${euroTesto(a.versati_oltre)} oltre il capitale riconosciuto: sono andati a credito della persona.`
        : '';
    const calendario = testoCalendario({ calendario: a.calendario ?? null, rate_non_versate: a.rate_non_versate ?? null });

    return `${quando}: ${cifre}.${calendario ? ` Calendario: ${calendario}.` : ''}${oltre}`;
}

/** «€ 225,92» per un importo in centesimi, come lo scrive il server nel controllo del calendario. */
const euro = (centesimi: number) => euroTesto(centesimi);

/**
 * Il giorno della rata `mesi` mesi dopo la prima: lo stesso giorno del mese; se il mese è più corto, il suo ultimo giorno; se la prima rata è l'ultimo
 * giorno del suo mese, l'ultimo giorno di ogni mese (31/07, 31/08, 30/09).
 */
export function giornoOgniMese(prima: string, mesi: number): string {
    const [a, m, g] = prima.slice(0, 10).split('-').map(Number);
    const giorniDel = (anno: number, mese: number) => new Date(Date.UTC(anno, mese, 0)).getUTCDate();
    const ultimo = g === giorniDel(a, m);
    const indice = (m - 1) + mesi;
    const anno = a + Math.floor(indice / 12);
    const mese = (indice % 12) + 1;
    const giorniMese = giorniDel(anno, mese);
    const d = ultimo ? giorniMese : Math.min(g, giorniMese);

    return `${anno}-${String(mese).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/**
 * Il calendario che la finestra propone: `numero` rate uguali, ognuna il totale diviso per il numero al centesimo più vicino (il mezzo in su), l'ultima con
 * quello che resta, così la somma torna al centesimo (225,92 in tre: 75,31 + 75,31 + 75,30); i giorni ogni mese dalla prima. Se l'ultima restasse senza
 * niente (un totale di pochi centesimi in molte rate), le prime al centesimo in meno. Nessuna proposta con meno centesimi che rate.
 */
export function proponiCalendario(totale: number, numero: number, prima: string): Array<{ data: string; importo: number }> {
    if (!Number.isInteger(numero) || numero < 1 || totale < numero || !prima) {
        return [];
    }
    let rata = Math.round(totale / numero);
    if (totale - rata * (numero - 1) <= 0) {
        rata = Math.floor(totale / numero);
    }

    return Array.from({ length: numero }, (_, i) => ({ data: giornoOgniMese(prima, i), importo: i === numero - 1 ? totale - rata * (numero - 1) : rata }));
}

/** Il controllo del calendario, con la stessa frase del server: null quando la somma è il debito riconosciuto. */
export function sommaCheNonTorna(importi: number[], totale: number): string | null {
    const somma = importi.reduce((s, i) => s + i, 0);
    if (somma === totale) {
        return null;
    }

    return `La somma delle rate del calendario è ${euro(somma)} e il debito riconosciuto è ${euro(totale)}: correggi una rata, la differenza è ${euro(Math.abs(somma - totale))}.`;
}

/** Lo stato di una rata del calendario, accanto alla sua riga: versata, versati € … di € …, non versata; niente per una rata non scaduta e non toccata. */
export function testoRataDelCalendario(r: RataDelCalendario): string {
    if (r.versata) {
        return 'versata';
    }
    if (r.versato > 0) {
        return `versati ${euroTesto(r.versato)} di ${euroTesto(r.importo)}${r.scaduta ? `: mancano ${euroTesto(r.mancano)}` : ''}`;
    }

    return r.scaduta ? 'non versata' : '';
}

/** A che punto è il calendario, in poche parole; null senza calendario. Le stesse parole della tesoreria (`AccordiRientro::fraseCalendario`). */
export function testoCalendario(a: { calendario: RataDelCalendario[] | null; rate_non_versate: number | null }): string | null {
    if (!a.calendario) {
        return null;
    }
    const n = a.rate_non_versate ?? 0;
    if (!a.calendario.some((r) => r.scaduta)) {
        return 'nessuna rata ancora scaduta';
    }

    return n === 0 ? 'le rate scadute sono versate' : n === 1 ? 'una rata non versata' : `${n} rate non versate`;
}

/** La regola di decadenza scritta come nell'accordo. */
export function testoRegola(r: RegolaDiDecadenza | null | undefined): string {
    const parti = [
        r?.rate != null ? `${r.rate} ${r.rate === 1 ? 'rata del calendario non versata' : 'rate del calendario non versate, anche non consecutive'}` : null,
        r?.giorni != null ? `${r.giorni} ${r.giorni === 1 ? 'giorno' : 'giorni'} dopo una rata non versata` : null,
    ].filter(Boolean);

    return parti.length ? `Regola di decadenza: ${parti.join('; oppure ')}.` : 'L\'accordo non ha una regola di decadenza.';
}

/** In una riga, per l'elenco: il primo giorno in cui la condizione di decadenza si è verificata, e se il giornale di oggi lo smentisce; null senza segnali. */
export function testoSegnaleBreve(a: Pick<AccordoRientro, 'segnali'>): string | null {
    const segnali = [a.segnali?.giorni, a.segnali?.rate].filter((s): s is Segnale => !!s).sort((x, y) => x.il.localeCompare(y.il));
    if (!segnali.length) {
        return null;
    }
    const primo = segnali[0];

    return `La condizione di decadenza scritta nell'accordo si è verificata il ${giorno(primo.il)}${primo.smentito ? '; nel giornale di oggi quel giorno non c\'era' : ''}.`;
}

/** Sotto un segnale che il giornale di oggi smentisce. */
export const FRASE_SEGNALE_SMENTITO = 'Nel giornale di oggi quel giorno la condizione non c\'era (un versamento registrato dopo con una data di prima): controlla prima di registrare la decadenza.';

/**
 * Il segnale di una forma della regola, al passato: il giorno in cui si è verificata, e per ogni rata che la faceva quanto mancava quel giorno, al
 * centesimo. Il segnale è conservato com'era; se il giornale di oggi lo smentisce, la frase lo dice. Il programma segnala; la decadenza la registra
 * l'amministratore.
 */
export function testoSegnale(forma: 'rate' | 'giorni', s: Segnale, regola: RegolaDiDecadenza | null | undefined): string {
    const quale = forma === 'rate'
        ? `${regola?.rate ?? s.rate.length} ${(regola?.rate ?? s.rate.length) === 1 ? 'rata del calendario non versata' : 'rate del calendario non versate, anche non consecutive'}`
        : `${regola?.giorni ?? 0} ${(regola?.giorni ?? 0) === 1 ? 'giorno' : 'giorni'} dopo una rata non versata`;
    const rate = s.rate.map((r) => r.versato > 0
        ? `la rata ${r.numero} del ${giorno(r.data)}: versati ${euroTesto(r.versato)} di ${euroTesto(r.importo)}, mancavano ${euroTesto(r.importo - r.versato)}`
        : `la rata ${r.numero} del ${giorno(r.data)} (${euroTesto(r.importo)}) non risultava versata`);

    return `Il ${giorno(s.il)} la condizione di decadenza scritta nell'accordo («${quale}») si è verificata: quel giorno ${rate.join('; ')}.`
        + (s.smentito ? ` ${FRASE_SEGNALE_SMENTITO}` : '');
}

/** La parte di una rata in un accordo, nella riga aperta dell'elenco; per una rata coperta solo in parte, come conta un versamento. */
export function testoParteInAccordo(p: ParteInAccordo): string {
    return `Nell'accordo del ${giorno(p.data_accordo)}: ${euroTesto(p.in_accordo)} (riconosciuti ${euroTesto(p.riconosciuto)})${p.fuori_dalle_lettere ? '; non nelle lettere proposte' : ''}.`
        + (p.in_parte ? ` ${FRASE_IN_PARTE}` : '');
}

/** Nell'anteprima delle lettere, per una persona: quanto delle sue rate scadute è in accordo e non è nella lettera. */
export function testoInAccordoNellaLettera(centesimi: number): string | null {
    return centesimi > 0 ? `${euroTesto(centesimi)} in accordo non sono nella lettera.` : null;
}

/** Nell'anteprima: chi ha solo rate in accordo, e non riceve la lettera se l'amministratore non le rimette. */
export function testoSoloInAccordo(nomi: string[]): string | null {
    return nomi.length ? `Solo rate in accordo di rientro, non nella lettera: ${nomi.join(', ')}.` : null;
}

/** L'autorizzazione dell'assemblea, come la dice la finestra. */
export function testoAutorizzazione(a: Pick<AccordoRientro, 'autorizzato_assemblea' | 'data_autorizzazione' | 'verbale_autorizzazione'>): string {
    if (!a.autorizzato_assemblea) {
        return 'Non autorizzato dall\'assemblea.';
    }
    const verbale = a.verbale_autorizzazione ? ` (verbale n. ${a.verbale_autorizzazione})` : '';

    return `Autorizzato dall'assemblea del ${giorno(a.data_autorizzazione)}${verbale}.`;
}
