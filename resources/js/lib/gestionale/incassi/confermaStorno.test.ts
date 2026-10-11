import { describe, expect, test } from 'vitest';
import { avvisoDelloStorno, importoDaStornare } from './confermaStorno';

/**
 * La conferma dello storno di un incasso (`resources/js/components/gestionale/movimenti/incassi/DataTableRowActions.vue`),
 * 1.11.0-beta.49, voce P8 del verbale (Coda 209, decisione 75.5).
 *
 * - L'importo. Oggi la pagina riceve gli euro (`importo_totale_raw`, `IncassoRateService`: `importo / 100`) e li rimoltiplica
 *   (`importoDecimale * 100`, :30): un «* 100» a valle del confine, il bug del ×100 costato la beta.32, e in virgola mobile
 *   € 19,99 diventano 1998.9999999999998. Il server passa i centesimi (`importo_totale_cents`, CreditiStornoTest) e la pagina
 *   li usa così come sono.
 * - L'avviso. Il server misura se la parte in più dell'incasso è già stata usata o rimborsata (rotta
 *   `movimenti-rate.storno.anteprima`, `{voci, frase}`); la conferma mostra la frase solo quando c'è qualcosa da dire, e non
 *   inventa niente quando l'anteprima non è arrivata.
 */

describe('importoDaStornare: i centesimi del server, senza moltiplicare', () => {
    test('€ 19,99 sono 1999 centesimi, non 19.99 × 100 = 1998.9999999999998', () => {
        expect(19.99 * 100).not.toBe(1999);
        expect(importoDaStornare({ importo_totale_cents: 1999, importo_totale_raw: 19.99 })).toBe(1999);
    });

    test('legge i centesimi, non gli euro: se i due campi non coincidono vince il dato intero', () => {
        expect(importoDaStornare({ importo_totale_cents: 1999, importo_totale_raw: 999 })).toBe(1999);
    });

    test('una compensazione senza denaro in cassa: 0', () => {
        expect(importoDaStornare({ importo_totale_cents: 0, importo_totale_raw: 0 })).toBe(0);
    });
});

describe('avvisoDelloStorno: la frase del server quando la parte in più è già stata usata, niente altrimenti', () => {
    const frase = 'Sulla rata n.1 di Ugo Venditore € 50,00 pagati in più sono già stati usati nell\'incasso prot. 7 del 10/03/2026: '
        + 'lo storno non annulla quell\'uso, e la rata n.1, da € 100,00, tornerà da pagare per € 150,00.';
    const voce = { rata: 1, persona: 'Ugo Venditore', importo_rata: 10000, da_pagare_dopo: 15000, gia_usato: 5000, usi: [{ tipo: 'compensazione', protocollo: '7', data: '10/03/2026' }] };

    test('con un uso della parte in più: la frase', () => {
        expect(avvisoDelloStorno({ voci: [voce], frase })).toBe(frase);
    });

    test('senza usi: nessun avviso', () => {
        expect(avvisoDelloStorno({ voci: [], frase: null })).toBeNull();
        // Le voci decidono, non la frase: una frase senza voci non diventa un avviso.
        expect(avvisoDelloStorno({ voci: [], frase: 'residuo' })).toBeNull();
    });

    test('anteprima non ancora arrivata, o fallita: nessun avviso inventato', () => {
        expect(avvisoDelloStorno(null)).toBeNull();
        expect(avvisoDelloStorno(undefined)).toBeNull();
    });
});

// ─── Rilievo S3 della Fase 1-bis (sicurezza): la conferma prima dell'anteprima, e l'anteprima fallita ────────────────────
//
// Oggi `DataTableRowActions.vue` apre la conferma e chiede l'anteprima in parallelo (`axios.get` senza uno stato di attesa): il
// pulsante «Sì, Annulla Incasso» si può premere prima che la risposta arrivi, e un errore della rotta (419, 500, la rete) mette
// l'anteprima a null, cioè nessun avviso, in silenzio. Lo storno di un incasso la cui parte in più è già stata usata parte così
// senza la frase della Coda 209, e la rata torna da pagare senza che sia stato detto. `confermaDelloStorno` dice alla conferma,
// per ognuno dei tre momenti dell'anteprima, se si può confermare e quale avviso mostrare: il componente passa `confermabile` al
// `:disabled` del ConfirmDialog e mostra `avviso`.
import { confermaDelloStorno } from './confermaStorno';

describe('confermaDelloStorno: la conferma aspetta l\'anteprima, e se l\'anteprima fallisce lo dice (rilievo S3)', () => {
    const frase = 'Sulla rata n.1 di Ugo Venditore € 50,00 pagati in più sono già stati usati nell\'incasso prot. 7 del 10/03/2026: '
        + 'lo storno non annulla quell\'uso, e la rata n.1, da € 100,00, tornerà da pagare per € 150,00.';
    const voce = { rata: 1, persona: 'Ugo Venditore', importo_rata: 10000, da_pagare_dopo: 15000, gia_usato: 5000, usi: [{ tipo: 'compensazione', protocollo: '7', data: '10/03/2026' }] };

    test('in attesa dell\'anteprima la conferma non si può dare: lo storno non parte prima di sapere se la parte in più è già stata usata', () => {
        expect(confermaDelloStorno('in_attesa', null)).toEqual({ confermabile: false, avviso: null });
    });

    test('anteprima fallita (419, 500, la rete): si può confermare, ma sapendo che la verifica non c\'è stata', () => {
        const stato = confermaDelloStorno('fallita', null);
        expect(stato.confermabile).toBe(true);
        expect(stato.avviso).toMatch(/non è stato possibile verificare/i);
    });

    test('anteprima arrivata con un uso della parte in più: si conferma, con la frase del server', () => {
        expect(confermaDelloStorno('arrivata', { voci: [voce], frase })).toEqual({ confermabile: true, avviso: frase });
    });

    test('anteprima arrivata senza usi: si conferma, nessun avviso', () => {
        expect(confermaDelloStorno('arrivata', { voci: [], frase: null })).toEqual({ confermabile: true, avviso: null });
    });
});
