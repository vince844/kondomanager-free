/**
 * Le frasi e le scelte della tesoreria a 30 giorni della dashboard del condominio, fuori dal componente: la regola delle 8, lo stato con la
 * sua scritta, le fatture, la barra delle fasce, le azioni di ogni persona e le righe di «Da dove viene». Le cifre e la
 * prossima azione arrivano dal server (`TreasuryGuardianService`): qui solo come si dicono.
 */
import { euroSegno, giornoIntero } from './saldoPrevisto';

export type Livello = 'verde' | 'giallo' | 'rosso';

export interface PassoDellaRiga {
    caso: string;
    /** L'azione che la regola suggerisce; null quando non ne suggerisce, o con «Scelgo io la prossima azione». */
    passo: string | null;
    frase: string;
    frase_dei_fatti: string;
    note: string[];
    rata_compensa: number | null;
}

export interface RigaDelDenaro {
    voce: string;
    tipo: 'banca' | 'contanti' | 'virtuale' | 'senza_cassa' | 'non_registrati' | 'fondo' | 'altro' | string;
    saldo?: number;
    cassa_id?: number | null;
    conto_contabile_id?: number | null;
    attiva: boolean;
    etichetta?: { testo: string; tono: string } | null;
    si_puo_usare?: boolean;
}

export interface MovimentiDopoOggi {
    quanti: number;
    cifra: number;
    primo: string | null;
    nel_flusso_quanti: number;
    nel_flusso_cifra: number;
}

export interface Disponibile {
    cifra: number;
    totale: number;
    solo_conti_correnti: boolean;
    righe: RigaDelDenaro[];
    tolte: Array<{ voce: string; saldo: number; cassa_id: number | null; etichetta: { testo: string; tono: string } | null }>;
    futuri: MovimentiDopoOggi & { nel_flusso?: Record<string, unknown> };
    banca: Array<{ cassa_id: number; cassa: string; data: string; saldo: number; esercizio: string }>;
    esercizio_id: number | null;
}

/** Fino a 8 righe si mostrano tutte; oltre, le prime 5 e la riga di fondo (lo decide il server). */
export const TUTTE_FINO_A = 8;

export const FRASE_ESTRATTO = 'Secondo le registrazioni fino a oggi: non è il saldo dell\'estratto conto della banca.';
export const FRASE_FONDI = 'I fondi sono parti del conto corrente: l\'estratto della banca li comprende.';
export const FRASE_PERSONE = 'Lo scaduto di ognuno, senza togliere il suo credito: il credito è scritto sotto il nome.';

const plurale = (n: number, uno: string, tanti: string) => (n === 1 ? `1 ${uno}` : `${n} ${tanti}`);

/** «Qui le 5 con la scadenza più vicina; mancano 4 fatture, € 2.300,00»; null quando ci sono tutte. */
export function testoMancanoFatture(mancano: { quante: number; cifra: number } | null, mostrate: number): string | null {
    if (!mancano || mancano.quante <= 0) return null;

    return `Qui le ${mostrate} con la scadenza più vicina; ${mancano.quante === 1 ? 'manca' : 'mancano'} ${plurale(mancano.quante, 'fattura', 'fatture')}, ${euroSegno(mancano.cifra)}`;
}

/** «Qui le 5 con lo scaduto più alto; mancano 4 persone, € 415,20»; null quando ci sono tutte. */
export function testoMancanoPersone(altri: number, cifra: number, mostrate: number): string | null {
    if (altri <= 0) return null;

    return `Qui le ${mostrate} con lo scaduto più alto; ${altri === 1 ? 'manca' : 'mancano'} ${plurale(altri, 'persona', 'persone')}, ${euroSegno(cifra)}`;
}

/** La testata di un cassetto: «Fatture in scadenza · 3 · € 2.300,00», «Rate scadute dei condòmini · 6 persone · € 1.240,00». */
export function testoCassetto(quale: 'fatture' | 'persone', quante: number, cifra: number): string {
    return quale === 'fatture'
        ? `Fatture in scadenza · ${quante} · ${euroSegno(cifra)}`
        : `Rate scadute dei condòmini · ${plurale(quante, 'persona', 'persone')} · ${euroSegno(cifra)}`;
}

/** Lo stato della tesoreria: la scritta del badge, la frase che lo spiega, il tono dei colori. */
export function statoDellaTesoreria(livello: Livello): { etichetta: string; frase: string; tono: Livello } {
    switch (livello) {
        case 'rosso':
            return { etichetta: 'Le uscite non sono coperte', frase: 'Nei prossimi 30 giorni il saldo va sotto zero anche se arrivano le entrate attese.', tono: 'rosso' };
        case 'giallo':
            return { etichetta: 'Coperte solo con le entrate attese', frase: 'Senza le entrate attese il saldo va sotto zero nei prossimi 30 giorni.', tono: 'giallo' };
        default:
            return { etichetta: 'Le uscite sono coperte', frase: 'Il disponibile di oggi copre le uscite previste dei prossimi 30 giorni, giorno per giorno.', tono: 'verde' };
    }
}

/** «scade il 20/11/2026», «scaduta il 02/11/2026». */
export function testoScadenzaFattura(f: { dataScadenza: string | null; scaduta?: boolean }): string {
    if (!f.dataScadenza) return '';

    return `${f.scaduta ? 'scaduta' : 'scade'} il ${giornoIntero(f.dataScadenza)}`;
}

export const FASCE: Array<{ chiave: '1_30' | '31_90' | 'oltre_90'; etichetta: string }> = [
    { chiave: '1_30', etichetta: '1–30 giorni' },
    { chiave: '31_90', etichetta: '31–90 giorni' },
    { chiave: 'oltre_90', etichetta: 'oltre 90 giorni' },
];

/** Le tre fasce dello scaduto in proporzione: la larghezza in percentuale, spente a zero, la più vecchia in rosso. */
export function fasceDellaBarra(fasce: Record<'1_30' | '31_90' | 'oltre_90', number>) {
    const totale = FASCE.reduce((s, f) => s + Math.max(0, fasce[f.chiave] ?? 0), 0);

    return FASCE.map((f) => {
        const cifra = fasce[f.chiave] ?? 0;
        const percentuale = totale > 0 ? Number(((Math.max(0, cifra) / totale) * 100).toFixed(2)) : 0;

        return { ...f, cifra, percentuale, spenta: cifra === 0, rossa: f.chiave === 'oltre_90' && cifra > 0 };
    });
}

/** Le azioni che la regola manda nei sei mesi: la diffida, la spedizione, il fascicolo, l'accordo, «hai agito». */
const NEI_SEI_MESI = ['diffida', 'spedizione', 'fascicolo', 'accordo', 'agito'];

/**
 * Le azioni di una persona: «Registra l'incasso» o, con il credito che copre tutto (R2), «Compensa»; la lettera («Manda un sollecito» solo quando la regola
 * lo suggerisce; con «Scelgo io la prossima azione» «Scrivi una lettera» per tutti); il rimando ai sei mesi quando la regola suggerisce un'azione di lì.
 */
export function azioniDellaPersona(m: { passo?: PassoDellaRiga | null }, suggerisce: boolean): { incasso: 'incasso' | 'compensa'; lettera: 'sollecito' | 'scrivi' | null; neiSeiMesi: boolean } {
    const passo = m.passo?.passo ?? null;
    if (!suggerisce) {
        return { incasso: 'incasso', lettera: 'scrivi', neiSeiMesi: false };
    }

    return {
        incasso: passo === 'compensa' ? 'compensa' : 'incasso',
        lettera: passo === 'sollecito' ? 'sollecito' : null,
        neiSeiMesi: passo !== null && NEI_SEI_MESI.includes(passo),
    };
}

/** Il nome di una riga di «Da dove viene», con il suo tipo. */
export function nomeDellaRiga(r: Pick<RigaDelDenaro, 'voce' | 'tipo' | 'attiva'>): string {
    const disattivata = r.attiva ? '' : ', disattivata';
    switch (r.tipo) {
        case 'banca':
            return `${r.voce} (conto corrente${disattivata})`;
        case 'contanti':
            return /contant/i.test(r.voce) && r.attiva ? r.voce : `${r.voce} (contanti${disattivata})`;
        case 'virtuale':
            return `${r.voce} (cassa virtuale${disattivata})`;
        case 'senza_cassa':
            return `${r.voce} (conto senza cassa)`;
        default:
            return r.attiva ? r.voce : `${r.voce} (disattivata)`;
    }
}

/** I movimenti con una data dopo oggi: non sono nella cifra; quelli nei 30 giorni entrano nel saldo previsto. Null senza. */
export function testoFuturi(f: MovimentiDopoOggi | null | undefined): string | null {
    if (!f || f.quanti <= 0 || !f.primo) return null;
    const uno = f.quanti === 1;
    const testa = `${plurale(f.quanti, 'movimento', 'movimenti')} con una data dopo oggi, ${euroSegno(f.cifra)} (${uno ? 'il' : 'il primo il'} ${giornoIntero(f.primo)}): `
        + `${uno ? 'non è' : 'non sono'} nella cifra di oggi`;
    if (f.nel_flusso_quanti === f.quanti) return `${testa}; ${uno ? 'entra nel saldo previsto al suo giorno' : 'entrano nel saldo previsto al loro giorno'}.`;
    if (f.nel_flusso_quanti === 0) return `${testa}, e ${uno ? 'cade' : 'cadono'} dopo i 30 giorni del saldo previsto.`;

    return `${testa}; ${f.nel_flusso_quanti}, ${euroSegno(f.nel_flusso_cifra)}, ${f.nel_flusso_quanti === 1 ? 'entra' : 'entrano'} nel saldo previsto al loro giorno.`;
}

/** Il saldo dell'estratto conto della banca scritto nella nota del rendiconto, con la sua data. */
export function testoNotaBanca(b: { cassa: string; data: string; saldo: number; esercizio: string }): string {
    return `Nella nota del rendiconto «${b.esercizio}» hai scritto il saldo dell'estratto conto di «${b.cassa}» al ${giornoIntero(b.data)}: ${euroSegno(b.saldo)}.`;
}
