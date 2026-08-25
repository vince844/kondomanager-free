import { describe, it, expect } from 'vitest';
import { creditoDisponibileCents, haCreditoDisponibile, versatoRataCents } from './credito';

/**
 * Coda 69: PianiRateShow.vue riconosceva il credito solo su `importo < 0` (saldo iniziale /
 * anticipo), ignorando lo strapagamento (`importo_pagato > importo`) — la seconda forma che
 * `RataQuote::getCreditoDisponibileAttribute()` (app/Models/Gestionale/RataQuote.php) riconosce
 * dalla beta.9. Questi test mirano quella stessa formula, in centesimi come il payload che arriva
 * dal server (`fromCents: true` è il default di `useCurrencyFormatter`).
 *
 * Misurato sui dati veri il 23/08/2026 (condominio 31, piano rate 208): Bianchi Anna, importo
 * € 100,00 pagato € 200,00 — credito reale € 100,00, che la pagina mostrava a zero.
 */

describe('creditoDisponibileCents — la stessa formula di RataQuote::credito_disponibile', () => {
    it('importo positivo pagato esattamente: nessun credito', () => {
        expect(creditoDisponibileCents(10000, 10000)).toBe(0);
    });

    it('importo positivo pagato in parte: nessun credito (è un residuo da pagare, non un credito)', () => {
        expect(creditoDisponibileCents(10000, 4000)).toBe(0);
    });

    it('⚠️ strapagamento — il caso di Bianchi Anna: importo 100,00 pagato 200,00 → credito 100,00', () => {
        expect(creditoDisponibileCents(10000, 20000)).toBe(10000);
    });

    it('importo negativo (saldo iniziale/anticipo) non consumato: credito pari al suo valore assoluto', () => {
        expect(creditoDisponibileCents(-8000, 0)).toBe(8000);
    });

    it('importo negativo parzialmente consumato: credito pari al residuo', () => {
        expect(creditoDisponibileCents(-8000, -3000)).toBe(5000);
    });

    it('importo negativo interamente consumato: nessun credito residuo', () => {
        expect(creditoDisponibileCents(-8000, -8000)).toBe(0);
    });

    it('non scende mai sotto zero, anche con dati inattesi', () => {
        expect(creditoDisponibileCents(-8000, -9000)).toBe(0);
        expect(creditoDisponibileCents(0, 0)).toBe(0);
    });
});

describe('haCreditoDisponibile', () => {
    it('vero solo quando creditoDisponibileCents è positivo', () => {
        expect(haCreditoDisponibile({ importo: 10000, importo_pagato: 20000 })).toBe(true);
        expect(haCreditoDisponibile({ importo: -8000, importo_pagato: 0 })).toBe(true);
        expect(haCreditoDisponibile({ importo: 10000, importo_pagato: 10000 })).toBe(false);
        expect(haCreditoDisponibile({ importo: 10000, importo_pagato: 4000 })).toBe(false);
    });

    it('importo_pagato assente conta come zero', () => {
        expect(haCreditoDisponibile({ importo: -8000 })).toBe(true);
        expect(haCreditoDisponibile({ importo: 10000 })).toBe(false);
    });
});

describe('versatoRataCents — cosa è davvero entrato in cassa per questa rata', () => {
    it('rata pagata esattamente: il versato è l\'importo teorico (i due coincidono)', () => {
        expect(versatoRataCents({ stato: 'pagata', importo: 10000, importo_pagato: 10000 })).toBe(10000);
    });

    it('⚠️ rata pagata IN ECCEDENZA: il versato è importo_pagato, non l\'importo teorico', () => {
        // Il bug: la pagina sommava `importo` (10000) invece di `importo_pagato` (20000),
        // sottostimando l'incassato di esattamente l'eccedenza — 500,00 misurati sul piano 208.
        expect(versatoRataCents({ stato: 'pagata', importo: 10000, importo_pagato: 20000 })).toBe(20000);
    });

    it('rata parzialmente pagata: il versato è importo_pagato', () => {
        expect(versatoRataCents({ stato: 'parzialmente_pagata', importo: 10000, importo_pagato: 4000 })).toBe(4000);
    });

    it('rata da pagare: nessun versato', () => {
        expect(versatoRataCents({ stato: 'da_pagare', importo: 10000, importo_pagato: 0 })).toBe(0);
    });

    it('rata annullata: nessun versato, anche se importo_pagato non è zero', () => {
        expect(versatoRataCents({ stato: 'annullata', importo: 10000, importo_pagato: 10000 })).toBe(0);
    });

    it('rata credito (saldo iniziale negativo): il consumo del credito non è cassa versata', () => {
        expect(versatoRataCents({ stato: 'credito', importo: -8000, importo_pagato: -3000 })).toBe(0);
    });
});
