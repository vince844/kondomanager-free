/**
 * Coda 255 (1.11.0-beta.51): dove il pannello, il cancello e lo storico compongono una frase con una cifra, il simbolo e la cifra stanno
 * nello stesso elemento che non va a capo: «€» in fondo a una riga e «127,04» in cima alla seguente non si leggono.
 *
 * Il testo che il server manda non cambia (lo fissano centinaia di test, e le stampe usano lo stesso `MoneyHelper::format`): si divide
 * in pezzi, e `FraseConImporti.vue` fa di ogni importo uno span `whitespace-nowrap`. Una frase è testo, mai markup.
 */

/** Un pezzo di una frase: un tratto di testo, o un importo («€ 127,04», «€ 1.234,56») con il simbolo. */
export interface PezzoDiFrase {
    testo: string;
    importo: boolean;
}

/**
 * L'importo come lo scrive `MoneyHelper::format`: il simbolo, le migliaia con il punto e due decimali. Il meno è tollerato: finché la 251-c non ha
 * chiuso ogni punto, qualche frase dice ancora «€ -127,04». Il punto, la virgola o la parentesi che seguono la cifra restano fuori.
 */
const IMPORTO = /€\s?-?\d{1,3}(?:\.\d{3})*,\d{2}/g;

/** Divide una frase in pezzi di testo e importi; uniti, rifanno la frase di partenza parola per parola. */
export function spezzaPerImporti(testo: string): PezzoDiFrase[] {
    const pezzi: PezzoDiFrase[] = [];
    let da = 0;
    for (const trovato of testo.matchAll(IMPORTO)) {
        const inizio = trovato.index ?? 0;
        if (inizio > da) {
            pezzi.push({ testo: testo.slice(da, inizio), importo: false });
        }
        pezzi.push({ testo: trovato[0], importo: true });
        da = inizio + trovato[0].length;
    }
    if (da < testo.length) {
        pezzi.push({ testo: testo.slice(da), importo: false });
    }

    return pezzi;
}
