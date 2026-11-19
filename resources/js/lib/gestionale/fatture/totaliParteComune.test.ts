// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { describe, expect, it } from 'vitest';
import { calcolaTotali } from './totali';

/**
 * Coda 693 (e), decisione 532: la parte comune della spesa fuori preventivo, cioè il «Valore della parte comune da ripartire» della finestra «Configura nuova
 * voce di spesa» e lo sforo che la pagina registra con l'urgenza.
 *
 * Il servizio (`FatturaPassivaService::registraFattura`, «smistamento intelligente imprevisti») mette sulla voce tecnica che crea solo le righe «Fuori
 * preventivo» senza unità; una riga «Fuori preventivo» addebitata a un'unità resta sull'unità, senza voce. Prima la finestra sommava anche quella.
 * I valori sono fatti a mano: € 1.000,00 + IVA 22 % = € 1.220,00 comune; € 100,00 + IVA 22 % = € 122,00 sull'unità.
 *
 * Cosa non copre: la pagina che passa il valore alla finestra (`FatturaRegisterNewFuoriPreventivo.test.ts`); il totale «Di cui fuori preventivo», che resta
 * di tutte le righe con il segno.
 */
describe('calcolaTotali — la parte comune della spesa fuori preventivo', () => {
    const totali = calcolaTotali({
        is_pregresso: false,
        righe: [
            { importo_imponibile: 1000, aliquota_iva: 22, is_sopravvenienza: true },
            { importo_imponibile: 100, aliquota_iva: 22, is_sopravvenienza: true, immobile_id: 7 },
            { importo_imponibile: 50, aliquota_iva: 22, is_sopravvenienza: false },
        ],
        ritenuta: null,
    });

    it('la parte comune non somma la riga «Fuori preventivo» addebitata a un\'unità: € 1.000,00 + € 220,00', () => {
        expect(totali.imponibile_sopravvenienza_comune_cents).toBe(100_000);
        expect(totali.iva_sopravvenienza_comune_cents).toBe(22_000);
    });

    it('controprova — il «di cui fuori preventivo» resta di tutte le righe con il segno: € 1.100,00 + € 242,00', () => {
        expect(totali.imponibile_sopravvenienza_cents).toBe(110_000);
        expect(totali.iva_sopravvenienza_cents).toBe(24_200);
    });

    it('senza unità la parte comune è tutta la spesa fuori preventivo', () => {
        const soloComune = calcolaTotali({ is_pregresso: false, righe: [{ importo_imponibile: 1000, aliquota_iva: 22, is_sopravvenienza: true }], ritenuta: null });
        expect(soloComune.imponibile_sopravvenienza_comune_cents + soloComune.iva_sopravvenienza_comune_cents).toBe(122_000);
    });
});
