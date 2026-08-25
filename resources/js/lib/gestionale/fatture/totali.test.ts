import { describe, expect, it } from 'vitest';
import { calcolaTotali, risolviRegimeRitenuta } from './totali';

/**
 * Il contratto di questo modulo è uno solo: **l'anteprima deve dire lo stesso numero che
 * il backend salverà**. Ogni caso qui sotto fissa un punto in cui le due aritmetiche
 * — centesimi interi lato PHP, float lato form — divergevano.
 *
 * I valori attesi non sono ricavati da questo codice: sono calcolati a mano seguendo
 * `FatturaPassivaService::registraFattura()` e `RitenutaService::calcola()`.
 */

const APPALTO_4 = { percBase: 100, percTratt: 4 };
const PROVVIGIONI_50 = { percBase: 50, percTratt: 23 };

describe('calcolaTotali — il caso segnalato dal forum', () => {
    // Imponibile 316,20 · IVA 22% · ritenuta d'appalto 4%.
    // PHP:  31620 + round(31620×22/100)=6956 − round(31620×4/100)=1265  =  37311
    // Il vecchio form sommava i grezzi: 316,20 + 69,564 − 12,648 = 373,116 → 373,12.
    const totali = calcolaTotali({
        is_pregresso: false,
        righe: [{ importo_imponibile: 316.2, aliquota_iva: 22 }],
        ritenuta: APPALTO_4,
    });

    it('mostra i componenti arrotondati al centesimo', () => {
        expect(totali.imponibile_cents).toBe(31620);
        expect(totali.iva_cents).toBe(6956);
        expect(totali.ritenuta_cents).toBe(1265);
    });

    it('dà 373,11 di netto, non 373,12', () => {
        expect(totali.netto_cents).toBe(37311);
    });

    it('quadra con i componenti che l\'utente legge sopra il totale', () => {
        expect(totali.netto_cents).toBe(
            totali.imponibile_cents + totali.iva_cents - totali.ritenuta_cents,
        );
    });
});

describe('calcolaTotali — arrotondamenti che il backend fa e il form non faceva', () => {
    it('arrotonda l\'IVA riga per riga, non sul totale', () => {
        // 1,15 × 22% = 25,3 centesimi per riga → 25 + 25 = 50.
        // Sommando i grezzi verrebbe 0,506 € → 51 centesimi.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1.15, aliquota_iva: 22 },
                { importo_imponibile: 1.15, aliquota_iva: 22 },
            ],
            ritenuta: null,
        });

        expect(totali.iva_cents).toBe(50);
        expect(totali.totale_documento_cents).toBe(280);
    });

    it('arrotonda la base ridotta della ritenuta prima di applicare l\'aliquota', () => {
        // Provvigioni con riduzione: base 50%, aliquota 23%.
        // PHP:  round(100013 × 50/100) = 50007  →  round(50007 × 23/100) = 11502
        // In un passo solo: round(100013 × 0,115) = 11501.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 1000.13, aliquota_iva: 22 }],
            ritenuta: PROVVIGIONI_50,
        });

        expect(totali.base_ritenuta_cents).toBe(50007);
        expect(totali.ritenuta_cents).toBe(11502);
        expect(totali.netto_cents).toBe(100013 + 22003 - 11502);
    });

    it('arrotonda lontano da zero sugli importi negativi, come round() di PHP', () => {
        // −0,25 × 22% = −5,5 centesimi. PHP dà −6; Math.round darebbe −5.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: -0.25, aliquota_iva: 22 }],
            ritenuta: null,
        });

        expect(totali.iva_cents).toBe(-6);
    });
});

describe('calcolaTotali — base della ritenuta', () => {
    it('esclude le righe che non concorrono, senza toccare l\'imponibile', () => {
        // Rimborso art. 15 / contributo cassa: entra nel documento, non nella base ritenuta.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1000, aliquota_iva: 22 },
                { importo_imponibile: 200, aliquota_iva: 22, concorre_base_ritenuta: false },
            ],
            ritenuta: APPALTO_4,
        });

        expect(totali.imponibile_cents).toBe(120000);
        expect(totali.base_ritenuta_cents).toBe(100000);
        expect(totali.ritenuta_cents).toBe(4000);
    });

    it('sulla fattura pregressa usa l\'imponibile intero come base', () => {
        // Nessuna riga di dettaglio: è il $imponibileTotaleFallback di RitenutaService.
        const totali = calcolaTotali({
            is_pregresso: true,
            imponibile_pregresso: 316.2,
            aliquota_iva_pregressa: 22,
            righe: [],
            ritenuta: APPALTO_4,
        });

        expect(totali.imponibile_cents).toBe(31620);
        expect(totali.iva_cents).toBe(6956);
        expect(totali.ritenuta_cents).toBe(1265);
        expect(totali.netto_cents).toBe(37311);
    });

    it('senza ritenuta il netto coincide col totale documento', () => {
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 316.2, aliquota_iva: 22 }],
            ritenuta: null,
        });

        expect(totali.ritenuta_cents).toBe(0);
        expect(totali.netto_cents).toBe(totali.totale_documento_cents);
    });
});

describe('calcolaTotali — sopravvenienze', () => {
    it('separa ordinario e imprevisto senza perdere centesimi', () => {
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1.15, aliquota_iva: 22 },
                { importo_imponibile: 1.15, aliquota_iva: 22, is_sopravvenienza: true },
            ],
            ritenuta: null,
        });

        expect(totali.ha_sopravvenienze).toBe(true);
        expect(totali.imponibile_ordinario_cents + totali.imponibile_sopravvenienza_cents)
            .toBe(totali.imponibile_cents);
        expect(totali.iva_ordinaria_cents + totali.iva_sopravvenienza_cents).toBe(totali.iva_cents);
    });
});

describe('risolviRegimeRitenuta', () => {
    it('preferisce il regime nuovo ai campi legacy del fornitore', () => {
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: 'provvigioni_base_50', perc_ritenuta: 4, perc_imponibile_ritenuta: 100 },
            true,
        );

        expect(regime).toEqual({ percBase: 50, percTratt: 23 });
    });

    it('ricade sui campi legacy quando il fornitore non ha ancora un regime', () => {
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: null, perc_ritenuta: 20, perc_imponibile_ritenuta: null },
            true,
        );

        expect(regime).toEqual({ percBase: 100, percTratt: 20 });
    });

    it('una base imponibile configurata a zero resta zero, non diventa il 100%', () => {
        // Il PHP legge `perc_imponibile_ritenuta ?? 100`: lo zero è un valore, non un vuoto.
        // Con `|| 100` l'anteprima annunciava una ritenuta che il salvataggio non faceva.
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: null, perc_ritenuta: 4, perc_imponibile_ritenuta: 0 },
            true,
        );

        expect(regime).toEqual({ percBase: 0, percTratt: 4 });
        expect(calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 1000, aliquota_iva: 22 }],
            ritenuta: regime,
        }).ritenuta_cents).toBe(0);
    });

    it('non applica nulla al forfetario, anche se marcato soggetto a ritenuta', () => {
        expect(risolviRegimeRitenuta(
            { soggetto_ritenuta: true, regime_forfetario: true, tipo_ritenuta: 'appalto_4' },
            true,
        )).toBeNull();
    });

    it('non applica nulla quando la ritenuta è esclusa sul documento', () => {
        expect(risolviRegimeRitenuta({ soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4' }, false)).toBeNull();
    });

    it('non applica nulla senza fornitore selezionato', () => {
        expect(risolviRegimeRitenuta(null, true)).toBeNull();
    });
});
