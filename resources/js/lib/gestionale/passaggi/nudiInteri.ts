/**
 * Decisione 62 (1.11.0-beta.43): all'estinzione, con un altro usufrutto in corso, l'amministratore sceglie i nudi che tornano
 * pieni — nudi interi che insieme valgono quanto l'usufrutto che finisce, oppure tutti, ciascuno per la sua quota. Se nessun
 * insieme di nudi interi vale proprio l'usufrutto (nudi al 75 % e al 25 % sotto un usufrutto del 50 %), le caselle non servono:
 * il modulo non promette una scelta impossibile. Lo stesso conto lo fa il server (`NudiDellEstinzione::combinazioneEsiste`).
 *
 * Le quote sono percentuali con due decimali: si contano in centesimi di punto, interi, per non sbagliare per un arrotondamento.
 */
export function nudiInteriPossibili(quoteNudi: Array<number | string>, quotaUsufrutto: number | string): boolean {
    const obiettivo = Math.round(Number(quotaUsufrutto) * 100);
    const raggiungibili = new Set<number>([0]);
    for (const quota of quoteNudi) {
        const q = Math.round(Number(quota) * 100);
        for (const somma of [...raggiungibili]) {
            if (somma + q <= obiettivo) raggiungibili.add(somma + q);
        }
    }

    return raggiungibili.has(obiettivo);
}
