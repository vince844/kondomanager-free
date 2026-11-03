/**
 * Le quote degli eredi nella successione (1.11.0-beta.44, decisione 65), come le controlla il server
 * (`AnteprimaPassaggioRequest::controllaSuccessione`): in centesimi di punto interi, senza somme in virgola mobile.
 */

/** Una quota digitata («33,33», «50») in centesimi di punto; null se non è un numero. */
export function centesimiDiPunto(quota: number | string | null | undefined): number | null {
    if (quota === null || quota === undefined || String(quota).trim() === '') return null;
    const n = Number(String(quota).trim().replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) : null;
}

/**
 * La quota del defunto divisa in parti uguali fra `n` eredi, in centesimi di punto con i resti maggiori: il centesimo che avanza va
 * ai primi, nell'ordine del modulo (la stessa regola del server per gli importi). Restituisce le quote come testo italiano («33,34»).
 */
export function partiUguali(quotaDefunto: number | string, n: number): string[] {
    const totale = centesimiDiPunto(quotaDefunto);
    if (totale === null || n <= 0) return [];
    const base = Math.floor(totale / n);
    const resto = totale - base * n;

    return Array.from({ length: n }, (_, i) => quotaIt((base + (i < resto ? 1 : 0)) / 100));
}

/** La somma delle quote degli eredi, in centesimi di punto; null se una quota non è un numero. */
export function sommaQuote(quote: (number | string | null | undefined)[]): number | null {
    let somma = 0;
    for (const q of quote) {
        const c = centesimiDiPunto(q);
        if (c === null) return null;
        somma += c;
    }

    return somma;
}

/**
 * Che cosa manca perché le quote tornino: null se sommano proprio la quota del defunto; altrimenti una frase, come la dice il
 * server («sommano 90 %, quella di Ugo è 100 %»). Prima della somma, ogni quota come la vuole il server
 * (`AnteprimaPassaggioRequest::controllaSuccessione`, rilievo X12 della Fase 1-bis): più di zero, al massimo due decimali, almeno
 * 0,01 % — arrotondata, «33,333» sommerebbe giusto, e il server la rifiuterebbe.
 */
export function quoteCheNonTornano(quote: (number | string | null | undefined)[], quotaDefunto: number | string): string | null {
    if (quote.some(q => q === null || q === undefined || String(q).trim() === '')) return 'scrivi la quota di ogni erede';
    const numeri = quote.map(q => Number(String(q).trim().replace(',', '.')));
    if (numeri.some(n => !Number.isFinite(n))) return 'la quota di ogni erede si scrive in cifre, per esempio 33,33';
    if (numeri.some(n => n <= 0)) return 'la quota di ogni erede dev\'essere più di zero';
    if (numeri.some(n => Math.round(n * 100) < 1 || Math.abs(n * 100 - Math.round(n * 100)) > 1e-6)) {
        return 'la quota di ogni erede può avere al massimo due decimali e dev\'essere almeno 0,01 %';
    }
    const somma = sommaQuote(quote);
    const atteso = centesimiDiPunto(quotaDefunto);
    if (somma === null || atteso === null) return 'scrivi la quota di ogni erede';
    if (somma === atteso) return null;

    return `le quote sommano ${quotaIt(somma / 100)} %, quella del defunto è ${quotaIt(atteso / 100)} %: devono coincidere`;
}

function quotaIt(q: number): string {
    return q.toLocaleString('it-IT', { maximumFractionDigits: 2 });
}
