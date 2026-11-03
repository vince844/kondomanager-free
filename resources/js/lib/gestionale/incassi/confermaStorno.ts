/**
 * La conferma dello storno di un incasso (`resources/js/components/gestionale/movimenti/incassi/DataTableRowActions.vue`),
 * 1.11.0-beta.49, voce P8 del verbale (Coda 209, decisione 75.5).
 */

/** Una voce dell'avviso: la rata la cui parte in più è già stata usata, e quanto tornerà da pagare. Importi in centesimi. */
export interface VoceAvvisoStorno {
    rata: number;
    persona: string;
    importo_rata: number;
    da_pagare_dopo: number;
    gia_usato: number;
    usi: Array<{ tipo: string; protocollo: string | null; data: string | null }>;
}

/** La risposta della rotta `movimenti-rate.storno.anteprima` (`AvvisoStornoIncasso`). */
export interface AnteprimaStorno {
    voci: VoceAvvisoStorno[];
    frase: string | null;
}

/**
 * L'importo dell'incasso, in centesimi interi, come lo manda il server (`importo_totale_cents`). Fino alla beta.48 la pagina
 * riceveva gli euro (`importo_totale_raw`, già divisi per 100 dal server) e li rimoltiplicava per 100: una seconda conversione
 * a valle del confine, in virgola mobile (€ 19,99 diventava 1998.9999999999998).
 */
export function importoDaStornare(incasso: { importo_totale_cents: number; importo_totale_raw?: number }): number {
    return incasso.importo_totale_cents;
}

/**
 * La frase dell'avviso, solo quando c'è qualcosa da dire: decidono le voci, non la frase. Senza l'anteprima (non ancora arrivata,
 * o fallita) niente avviso: la conferma non inventa.
 */
export function avvisoDelloStorno(anteprima: AnteprimaStorno | null | undefined): string | null {
    if (!anteprima || anteprima.voci.length === 0 || !anteprima.frase) {
        return null;
    }

    return anteprima.frase;
}

/** I tre momenti dell'anteprima dello storno: chiesta e non ancora arrivata, arrivata, fallita (419, 500, la rete). */
export type StatoAnteprimaStorno = 'in_attesa' | 'arrivata' | 'fallita';

/**
 * La frase della conferma quando l'anteprima non è arrivata: lo storno si può fare, ma senza la verifica della Coda 209. Dice il
 * rischio, non ripete la frase sopra («Le rate torneranno da pagare»), e dove guardare prima di confermare (giro stretto sulle
 * correzioni della Fase 1-bis, LT-7).
 */
export const AVVISO_ANTEPRIMA_FALLITA =
    'Non è stato possibile verificare se la parte in più di questo incasso è già stata usata o rimborsata. Se lo è, lo storno non annulla quell\'uso, e una rata può tornare da pagare per più del suo importo: prima di confermare, guarda l\'estratto conto del condòmino.';

/** Quanto si aspetta l'anteprima prima di darla per fallita (LT-7): senza un limite, la conferma restava ferma su «Si verifica…». */
export const ATTESA_ANTEPRIMA_MS = 15000;

/**
 * Che cosa la conferma dello storno può fare in ognuno dei tre momenti dell'anteprima (rilievo S3 della Fase 1-bis della
 * 1.11.0-beta.49). Finché l'anteprima non arriva la conferma non si può dare: lo storno partiva prima di sapere se la parte in più
 * era già stata usata. Se l'anteprima fallisce si può confermare, ma la conferma dice che la verifica non c'è stata, invece di
 * tacere. Arrivata, l'avviso è quello del server (`avvisoDelloStorno`).
 */
export function confermaDelloStorno(stato: StatoAnteprimaStorno, anteprima: AnteprimaStorno | null | undefined): { confermabile: boolean; avviso: string | null } {
    if (stato === 'in_attesa') {
        return { confermabile: false, avviso: null };
    }
    if (stato === 'fallita') {
        return { confermabile: true, avviso: AVVISO_ANTEPRIMA_FALLITA };
    }

    return { confermabile: true, avviso: avvisoDelloStorno(anteprima) };
}
