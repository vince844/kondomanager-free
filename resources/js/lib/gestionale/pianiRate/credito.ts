/**
 * Il credito su una quota rata ha due forme, non una — Coda 69.
 *
 * `PianiRateShow.vue` riconosceva solo il saldo iniziale/anticipo a importo negativo, ignorando lo
 * strapagamento (`importo_pagato > importo`): la seconda forma che
 * `RataQuote::getCreditoDisponibileAttribute()` (app/Models/Gestionale/RataQuote.php) riconosce dalla
 * beta.9. Le funzioni qui sotto ricalcano esattamente quella formula, così la pagina non ne tiene
 * più una copia propria destinata a scollarsi dal motore.
 *
 * Tutti gli importi sono in **centesimi interi**, come il payload che arriva dal server — vedi
 * `PianoRateQuoteService::quotePerAnagrafica()`/`quotePerImmobile()`, che non li divide mai per 100.
 */

export interface RataConCredito {
    importo: number;
    importo_pagato?: number | null;
}

export interface RataConStatoEImporto {
    stato: string;
    importo: number;
    importo_pagato?: number | null;
}

/**
 * Ricalca `RataQuote::getCreditoDisponibileAttribute()`:
 * - importo negativo (saldo iniziale/anticipo): credito = residuo non consumato del valore assoluto;
 * - importo positivo: credito = eccedenza versata oltre il dovuto (lo strapagamento).
 * Non scende mai sotto zero.
 */
export function creditoDisponibileCents(importoCents: number, importoPagatoCents: number): number {
    if (importoCents < 0) {
        return Math.max(0, Math.abs(importoCents) - Math.abs(importoPagatoCents));
    }
    return Math.max(0, importoPagatoCents - importoCents);
}

export function haCreditoDisponibile(rata: RataConCredito): boolean {
    return creditoDisponibileCents(rata.importo, rata.importo_pagato ?? 0) > 0;
}

/**
 * Quanto è davvero entrato in cassa per questa rata — non quanto valeva sulla carta.
 *
 * Il bug era qui: per una rata `'pagata'` la pagina sommava l'importo teorico invece di
 * `importo_pagato`. Per una rata pagata esattamente i due coincidono, quindi il caso comune non
 * cambiava; per una rata strapagata l'incassato risultava sotto di esattamente l'eccedenza.
 */
export function versatoRataCents(rata: RataConStatoEImporto): number {
    if (rata.stato === 'pagata' || rata.stato === 'parzialmente_pagata') {
        return rata.importo_pagato ?? 0;
    }
    return 0;
}
