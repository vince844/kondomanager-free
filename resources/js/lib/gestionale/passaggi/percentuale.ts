/**
 * La percentuale senza articolo, come la scrive il server (`RegoleDelRecupero::percentuale`): «100 %», «50 %», «33,33 %». La virgola, i decimali
 * solo se servono (al più due, arrotondati), lo spazio che non va a capo prima di «%». La quota di un'unità arriva come «100.00».
 */
export function percentuale(quota: number | string): string {
    const q = Math.round(Number(quota) * 100) / 100;

    // Lo spazio prima di «%» non si spezza: in una riga che va a capo il simbolo non resta da solo.
    return `${q.toLocaleString('it-IT', { maximumFractionDigits: 2 })}\u00a0%`;
}

/**
 * La percentuale con l'articolo, come la scrive il server (`NudiDellEstinzione::percentuale`): «lo 0,5 %», «l'8 %», «l'80 %»,
 * «il 25 %». L'articolo segue la parte intera letta ad alta voce (zero, uno, otto, undici, ottanta…). Prima si arrotonda a due
 * decimali, così numero e articolo vengono dallo stesso valore (una somma in virgola mobile può dare 79,9999…).
 */
export function percentualeIt(quota: number | string): string {
    const q = Math.round(Number(quota) * 100) / 100;
    const intero = Math.floor(q);
    const articolo = intero === 0 ? 'lo ' : [1, 8, 11].includes(intero) || (intero >= 80 && intero <= 89) ? "l'" : 'il ';

    return `${articolo}${percentuale(q)}`;
}
