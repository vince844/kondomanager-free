/**
 * Il giorno di oggi nel calendario di chi usa la pagina, come `aaaa-mm-gg`: dai numeri della data locale (anno, mese, giorno),
 * mai da `toISOString()`, che dà il giorno di Greenwich e fra le 00:00 e le 02:00 di Roma è ancora ieri.
 *
 * È il valore che un modulo propone come data di oggi (la data di emissione di una rata, che fa nascere il dovuto).
 */
export function oggiLocale(adesso: Date = new Date()): string {
    const mese = String(adesso.getMonth() + 1).padStart(2, '0');
    const giorno = String(adesso.getDate()).padStart(2, '0');

    return `${adesso.getFullYear()}-${mese}-${giorno}`;
}

/**
 * Una data senza ora (`aaaa-mm-gg`, come la manda il server) scritta come gg/mm/aaaa: solo i numeri, senza passare da un istante. Vuota se la
 * data non c'è.
 */
export function giornoItaliano(data: string | null | undefined): string {
    if (!data) return '';
    const [anno, mese, giorno] = data.slice(0, 10).split('-');

    return `${giorno}/${mese}/${anno}`;
}

const due = (n: number) => String(n).padStart(2, '0');

/**
 * Una data senza ora (`aaaa-mm-gg`) più N giorni (anche negativi), sui numeri della data: anno, mese e giorno contati in UTC, dove non c'è
 * l'ora legale. Con `new Date(data)` + `setDate` + `toISOString()` la somma partiva dalla mezzanotte UTC, passava dal fuso locale e, quando
 * attraversava il passaggio all'ora legale di marzo, tornava indietro di un giorno (la scadenza di una fattura del 10/03 a 30 giorni usciva
 * l'08/04).
 */
export function piuGiorni(data: string, giorni: number): string {
    const [anno, mese, giorno] = data.slice(0, 10).split('-').map(Number);
    const d = new Date(Date.UTC(anno, mese - 1, giorno + giorni));

    return `${d.getUTCFullYear()}-${due(d.getUTCMonth() + 1)}-${due(d.getUTCDate())}`;
}

/**
 * Il giorno scelto in un calendario, come `aaaa-mm-gg`: da una data del calendario (`CalendarDate`, con anno, mese da 1 e giorno) i suoi
 * numeri; da un `Date` il giorno locale; da una stringa i primi dieci caratteri. Il filtro dell'agenda passava dalla mezzanotte locale e da
 * `toISOString()`, e partiva sempre dal giorno prima. Undefined senza una data.
 */
export function giornoDelCalendario(data: unknown): string | undefined {
    if (!data) return undefined;
    if (typeof data === 'string') return data.slice(0, 10);
    if (data instanceof Date) return oggiLocale(data);
    const d = data as { year?: unknown; month?: unknown; day?: unknown };
    if (typeof d.year === 'number' && typeof d.month === 'number' && typeof d.day === 'number') return `${d.year}-${due(d.month)}-${due(d.day)}`;

    return undefined;
}
