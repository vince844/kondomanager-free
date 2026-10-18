/**
 * I totali delle voci con il segno del tipo: il gemello TypeScript di `Conto::segno()`.
 *
 * Il motore di ripartizione distribuisce una voce di spesa o di uscita con il segno più (`abs(importo)`) e ogni altra voce con il
 * meno (`-abs(importo)`): le entrate si sottraggono alle spese. Qui stanno le schermate che contano le voci senza passare dal server,
 * come il «Totale richiesto» della creazione del piano. Gli importi di `totaliPerTipo` sono in centesimi interi.
 *
 * La conversione da euro a centesimi avviene **una volta sola**, in `centesimiDaImporto` (che usa `euroToCents`): da lì in poi il
 * denaro resta in centesimi, e chi formatta li dichiara con `{ fromCents: true }`.
 */

import { euroToCents } from '@/lib/gestionale/money';

export interface VoceConTipo {
    tipo?: string | null;
    importo: number;
}

export interface TotaliPerTipo {
    spese: number;
    entrate: number;
    daRipartire: number;
}

/** Il segno con cui il motore distribuisce una voce di quel tipo. Un tipo che non arriva non si indovina. */
export function segnoDelTipo(tipo: string | null | undefined): 1 | -1 {
    if (tipo === undefined || tipo === null || tipo === '') {
        throw new Error('segnoDelTipo: la voce non porta il tipo, e il segno non si indovina');
    }

    return tipo === 'spesa' || tipo === 'uscita' ? 1 : -1;
}

/** Spese, entrate e netto da ripartire (spese meno entrate) delle voci, in valore assoluto e in centesimi. */
export function totaliPerTipo(voci: VoceConTipo[]): TotaliPerTipo {
    let spese = 0;
    let entrate = 0;

    for (const voce of voci) {
        if (segnoDelTipo(voce.tipo) === 1) {
            spese += Math.abs(voce.importo);
        } else {
            entrate += Math.abs(voce.importo);
        }
    }

    return { spese, entrate, daRipartire: spese - entrate };
}

/**
 * Un importo in euro, come lo tiene il campo della pagina, in centesimi interi.
 *
 * Il valore arriva in due notazioni: «1.250,50» (italiana, se l'utente ha digitato nel campo) e «1250.50» (pura, se il campo è
 * rimasto al valore iniziale). Si distinguono dalla virgola, come fa `MoneyHelper::toCents()` lato PHP. Un campo vuoto o che non
 * è un numero vale zero.
 */
export function centesimiDaImporto(valore: string | number | null | undefined): number {
    if (valore === null || valore === undefined || valore === '') {
        return 0;
    }
    if (typeof valore === 'number') {
        return euroToCents(valore);
    }

    const testo = valore.trim();
    const euro = testo.includes(',') ? Number(testo.replace(/\./g, '').replace(',', '.')) : Number(testo);

    return euroToCents(Number.isFinite(euro) ? euro : 0);
}

export interface VoceDiUnCampo {
    tipo?: string | null;
    importo_da_usare: string | number | null | undefined;
}

/** I totali delle voci della creazione del piano, dai campi della pagina (euro). */
export function totaliDaImportiInEuro(voci: VoceDiUnCampo[]): TotaliPerTipo {
    return totaliPerTipo(voci.map((voce) => ({ tipo: voce.tipo, importo: centesimiDaImporto(voce.importo_da_usare) })));
}

/** La frase del totale: «Totale richiesto» senza entrate, il netto «Da ripartire» con la sua origine quando ci sono. */
export function fraseDaRipartire(totali: TotaliPerTipo, euro: (centesimi: number) => string): string {
    if (totali.entrate === 0) {
        return `Totale richiesto: ${euro(totali.daRipartire)}`;
    }

    return `Da ripartire: ${euro(totali.daRipartire)} (spese ${euro(totali.spese)}, entrate ${euro(totali.entrate)})`;
}

/** Il nome del riquadro del cruscotto: «Da ripartire» dove ci sono entrate, «Fabbisogno Condiviso» come prima dove non ci sono. */
export function etichettaFabbisogno(entrate: number): string {
    return entrate > 0 ? 'Da ripartire' : 'Fabbisogno Condiviso';
}
