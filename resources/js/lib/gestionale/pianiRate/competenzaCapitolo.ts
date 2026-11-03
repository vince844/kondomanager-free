/**
 * La competenza per voce del piano ordinario (1.11.0-beta.31, B2 S6 — decisione 20), lato interfaccia.
 *
 * Il motore legge i tratti di `competenze_capitolo` **nudi**, senza intersecarli con l'esercizio: quel che
 * si manda deve già stare dentro l'anno del piano. Per questo il preset non è «due tratti fissi» ma la
 * stagione di riscaldamento (15 ottobre – 15 aprile, DPR 74/2013 zona E) **tagliata sull'esercizio**: su
 * un esercizio solare fa due tratti (1/1–15/4 e 15/10–31/12), su uno 1/7–30/6 ne fa uno solo.
 *
 * Le date viaggiano come `YYYY-MM-DD` e si confrontano come stringhe: mai un `new Date`, che nel fuso del
 * browser può spostare il giorno (`soloData` taglia l'orario che il server attacca ai cast `date`).
 *
 * `verificaTratti` è il gemello di `CreatePianoRateRequest::withValidator`: stesse tre regole, qui per
 * dirle prima di salvare, là per rifiutarle davvero.
 */

import { soloData } from './calendario';

export interface Tratto {
    dal: string;
    al: string;
}

export type PresetCompetenza = 'gestione' | 'esercizio' | 'stagione' | 'manuale';

export interface PeriodoDate {
    data_inizio?: string | null;
    data_fine?: string | null;
}

/** Stagione di riscaldamento della zona climatica E (DPR 74/2013): mese-giorno di inizio e di fine. */
export const STAGIONE_RISCALDAMENTO = { inizio: '10-15', fine: '04-15' } as const;

export const ETICHETTE_PRESET: Record<PresetCompetenza, string> = {
    gestione: 'Periodo della gestione',
    esercizio: 'Tutto l’esercizio',
    stagione: 'Stagione di riscaldamento (15/10 – 15/04)',
    manuale: 'Date a mano',
};

/** Il periodo dell'esercizio come tratto, null se manca una data. */
export function periodoEsercizio(esercizio: PeriodoDate): Tratto | null {
    const dal = soloData(esercizio.data_inizio);
    const al = soloData(esercizio.data_fine);

    return dal && al && dal <= al ? { dal, al } : null;
}

/**
 * La base che il motore userebbe senza dichiarazioni: gestione ∩ esercizio se la gestione ha le sue date,
 * altrimenti l'esercizio. Null se l'intersezione è vuota o mancano le date.
 */
export function periodoGestione(gestione: PeriodoDate | null | undefined, esercizio: PeriodoDate): Tratto | null {
    const e = periodoEsercizio(esercizio);
    if (!e) {
        return null;
    }
    const gDal = soloData(gestione?.data_inizio);
    const gAl = soloData(gestione?.data_fine);
    if (!gDal || !gAl) {
        return e;
    }
    const dal = gDal > e.dal ? gDal : e.dal;
    const al = gAl < e.al ? gAl : e.al;

    return dal <= al ? { dal, al } : null;
}

/** La stagione di riscaldamento tagliata sull'esercizio: uno o due tratti, mai un giorno in comune. */
export function trattiStagione(esercizio: PeriodoDate, stagione = STAGIONE_RISCALDAMENTO): Tratto[] {
    const e = periodoEsercizio(esercizio);
    if (!e) {
        return [];
    }
    const primoAnno = Number(e.dal.slice(0, 4)) - 1;
    const ultimoAnno = Number(e.al.slice(0, 4));
    const tratti: Tratto[] = [];
    for (let anno = primoAnno; anno <= ultimoAnno; anno++) {
        const inizio = `${anno}-${stagione.inizio}`;
        const fine = `${anno + 1}-${stagione.fine}`;
        const dal = inizio > e.dal ? inizio : e.dal;
        const al = fine < e.al ? fine : e.al;
        if (dal <= al) {
            tratti.push({ dal, al });
        }
    }

    return tratti;
}

/** I tratti che un preset produce. `gestione` non ne produce: è la base, e non si scrive nulla. */
export function trattiDelPreset(preset: PresetCompetenza, esercizio: PeriodoDate, manuali: Tratto[] = []): Tratto[] {
    switch (preset) {
        case 'esercizio': {
            const e = periodoEsercizio(esercizio);
            return e ? [e] : [];
        }
        case 'stagione':
            return trattiStagione(esercizio);
        case 'manuale':
            return manuali;
        default:
            return [];
    }
}

/** Il preset che spiega dei tratti già scritti: serve a rileggerli, non a scriverli. */
export function presetDaiTratti(tratti: Tratto[], esercizio: PeriodoDate): PresetCompetenza {
    if (tratti.length === 0) {
        return 'gestione';
    }
    const uguali = (a: Tratto[], b: Tratto[]) => a.length === b.length && a.every((t, i) => t.dal === b[i].dal && t.al === b[i].al);
    const ordinati = [...tratti].sort((a, b) => a.dal.localeCompare(b.dal));
    if (uguali(ordinati, trattiDelPreset('esercizio', esercizio))) {
        return 'esercizio';
    }
    if (uguali(ordinati, trattiStagione(esercizio))) {
        return 'stagione';
    }

    return 'manuale';
}

/**
 * Le tre regole della Request, prima di salvare: fine ≥ inizio, dentro l'esercizio, nessun giorno in
 * comune fra due tratti. Torna il messaggio del primo difetto, o null.
 */
export function verificaTratti(tratti: Tratto[], esercizio: PeriodoDate): string | null {
    const e = periodoEsercizio(esercizio);
    const compilati = tratti.filter((t) => soloData(t.dal) && soloData(t.al));
    if (compilati.length !== tratti.length) {
        return 'Ogni tratto vuole tutte e due le date.';
    }
    const ordinati = [...compilati].sort((a, b) => a.dal.localeCompare(b.dal));
    let precedente: string | null = null;
    for (const t of ordinati) {
        if (t.al < t.dal) {
            return 'In un tratto la fine non può precedere l’inizio.';
        }
        if (e && (t.dal < e.dal || t.al > e.al)) {
            return `I tratti devono stare dentro l’esercizio (${formattaData(e.dal)} – ${formattaData(e.al)}).`;
        }
        if (precedente !== null && t.dal <= precedente) {
            return `Due tratti non possono avere un giorno in comune: chiudi il primo il giorno prima del ${formattaData(t.dal)}.`;
        }
        precedente = t.al;
    }

    return null;
}

/** «01/01 – 15/04 + 15/10 – 31/12», con l'anno solo se i tratti attraversano più anni. */
export function descriviTratti(tratti: Tratto[]): string {
    // I tratti a mano ancora vuoti non si descrivono: «—» finché non hanno tutte e due le date.
    const completi = tratti.filter((t) => soloData(t.dal) && soloData(t.al));
    if (completi.length === 0) {
        return '—';
    }
    const anni = new Set(completi.flatMap((t) => [t.dal.slice(0, 4), t.al.slice(0, 4)]));
    const conAnno = anni.size > 1;

    return completi.map((t) => `${formattaData(t.dal, conAnno)} – ${formattaData(t.al, conAnno)}`).join(' + ');
}

function formattaData(iso: string, conAnno = true): string {
    const [anno, mese, giorno] = iso.split('-');

    return conAnno ? `${giorno}/${mese}/${anno}` : `${giorno}/${mese}`;
}
