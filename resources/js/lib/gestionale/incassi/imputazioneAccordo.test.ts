// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'imputazione con un accordo di rientro in corso: il riquadro dell'accordo nel modulo, la frase quando il denaro va alle rate dell'accordo senza la
 * scelta (o fuori dall'accordo con la scelta), la firma della distribuzione che cambia con «Versamento dell'accordo».
 */
import { describe, expect, test } from 'vitest';
import { firmaOrdine, fraseFuoriOrdine, imputazioneMancante, testoAccordoIncasso, type RispostaOrdine } from './imputazione';

const euro = (c: number) => `€ ${(c / 100).toFixed(2).replace('.', ',')}`;
const rataAperta = { rata_id: 2, numero_rata: 2, descrizione: 'Rata 2', scadenza: '2026-06-30', emessa: true, gestione: 'Ordinaria 2026', unita: 'U1', resta: 30000 };

describe('l\'accordo di rientro nell\'incasso', () => {
    test('il riquadro: il giorno dell\'accordo, quanto è nell\'accordo, la prossima rata e quanto ne manca', () => {
        expect(testoAccordoIncasso({ data_accordo: '2026-07-15', in_accordo: 15061, prossima: { numero: 2, data: '2026-08-31', mancano: 1 } }))
            .toBe('Accordo di rientro del 15/07/2026: nell\'accordo € 150,61; la prossima rata del calendario è la 2 del 31/08/2026, mancano € 0,01.');
        expect(testoAccordoIncasso({ data_accordo: '2026-07-15', in_accordo: 0, prossima: null })).toBe('Accordo di rientro del 15/07/2026: nell\'accordo € 0,00.');
    });

    test('denaro sulle rate dell\'accordo senza la scelta, o fuori dall\'accordo con la scelta: la frase lo dice, e la scelta si chiede', () => {
        const nell: RispostaOrdine = { esito: 'fuori_ordine', rata_aperta: rataAperta, parte_in_piu: false, nell_accordo: true, fuori_dall_accordo: false };
        expect(fraseFuoriOrdine(nell, euro)).toBe('Questo versamento va anche a rate dell\'accordo di rientro: se è un versamento dell\'accordo, scegli «Versamento dell\'accordo»; se no, di\' chi ha scelto.');
        expect(imputazioneMancante(nell, null)).not.toBeNull();
        // Cercando l'unità la casella non c'è: la frase dice di cercare la persona.
        expect(fraseFuoriOrdine(nell, euro, undefined, null, false)).toBe('Questo versamento va anche a rate dell\'accordo di rientro: per un versamento dell\'accordo cerca la persona '
            + 'e scegli «Versamento dell\'accordo»; se no, di\' chi ha scelto.');
        const fuori: RispostaOrdine = { esito: 'fuori_ordine', rata_aperta: null, parte_in_piu: false, nell_accordo: false, fuori_dall_accordo: true };
        expect(fraseFuoriOrdine(fuori, euro)).toBe('Con «Versamento dell\'accordo» il versamento va prima alle rate che l\'accordo copre, e questo paga altre rate mentre l\'accordo ne ha '
            + 'ancora aperte: togli la scelta, o di\' chi ha scelto.');
        // Il versamento dell'accordo che segue il suo ordine non chiede niente.
        expect(fraseFuoriOrdine({ esito: 'accordo_rientro', rata_aperta: null, parte_in_piu: false }, euro)).toBeNull();
        expect(imputazioneMancante({ esito: 'accordo_rientro', rata_aperta: null, parte_in_piu: false }, null)).toBeNull();
    });

    test('la firma della distribuzione cambia con «Versamento dell\'accordo»: una risposta per l\'altra scelta non vale', () => {
        const base = { pagante_id: 5, versato_da_id: null, gestione_id: 4, importo_totale: 75.31, eccedenza: 0, credito_prima: null, credito_fra_gestioni: null,
            dettaglio_pagamenti: [{ rata_id: 1, importo: 75.31 }] };
        expect(firmaOrdine({ ...base, versamento_accordo: true })).not.toBe(firmaOrdine({ ...base, versamento_accordo: false }));
        expect(firmaOrdine({ ...base, versamento_accordo: false })).toBe(firmaOrdine(base));
    });
});
