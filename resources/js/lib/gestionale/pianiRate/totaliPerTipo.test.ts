/**
 * Cosa resta scoperto: il capitolo padre (la pagina offre solo foglie: il padre è disabilitato nel selettore); il disegno del badge «Entrata»
 * nell'opzione del selettore. La somma sta in queste funzioni; il collegamento con la pagina — la pagina formatta con
 * `euro(centesimi, { fromCents: true })` perché il suo `euro` legge euro, e passa il tipo di ogni voce — lo prova `PianiRateNewTotali.test.ts`,
 * che monta la pagina vera.
 *
 * Il gemello TypeScript di `Conto::segno()`: i totali delle schermate che non passano dal server (il «Totale richiesto» della creazione del
 * piano) contano le voci di entrata con il segno meno, come fa il motore (`CalcoloQuoteService::processaConti`, il ramo con l'importo del piano e quello con l'importo della voce: una voce che non
 * è di spesa né di uscita si distribuisce con −abs(importo)).
 *
 * Il modulo espone: `segnoDelTipo(tipo)`, `totaliPerTipo(voci)` (centesimi interi), `centesimiDaImporto(valore)` e `totaliDaImportiInEuro(voci)`
 * (il campo della pagina, in euro, in una delle due notazioni: la conversione in centesimi avviene qui, una volta sola), `fraseDaRipartire(totali,
 * euro)` e `etichettaFabbisogno(entrate)`.
 *
 * La conversione euro → centesimi sta in una funzione sola, provata: la pagina non moltiplica per cento da nessuna parte (il ×100 «difensivo» a
 * valle è il difetto che una versione precedente ha già avuto).
 */

import { describe, expect, it } from 'vitest';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import {
    centesimiDaImporto,
    etichettaFabbisogno,
    fraseDaRipartire,
    segnoDelTipo,
    totaliDaImportiInEuro,
    totaliPerTipo,
} from '@/lib/gestionale/pianiRate/totaliPerTipo';

const { euro } = useCurrencyFormatter({ spacing: 'normal' });

describe('segnoDelTipo — la stessa clausola del motore', () => {
    it('spesa e uscita si addebitano, tutto il resto si accredita', () => {
        expect(segnoDelTipo('spesa')).toBe(1);
        expect(segnoDelTipo('uscita')).toBe(1);
        expect(segnoDelTipo('entrata')).toBe(-1);
        expect(segnoDelTipo('qualcosa-d-altro')).toBe(-1); // il motore tratta come entrata ogni valore che non è spesa o uscita
    });

    it('un tipo che non arriva non si indovina: errore, non un segno', () => {
        expect(() => segnoDelTipo(undefined)).toThrow();
        expect(() => segnoDelTipo(null)).toThrow();
        expect(() => segnoDelTipo('')).toThrow();
    });
});

describe('totaliPerTipo', () => {
    it('spese € 1.200,00 ed entrata € 240,00: da ripartire € 960,00', () => {
        expect(totaliPerTipo([{ tipo: 'spesa', importo: 120000 }, { tipo: 'entrata', importo: 24000 }])).toEqual({ spese: 120000, entrate: 24000, daRipartire: 96000 });
    });

    it('senza entrate il netto è la somma delle spese, come prima', () => {
        expect(totaliPerTipo([{ tipo: 'spesa', importo: 120000 }, { tipo: 'spesa', importo: 5000 }])).toEqual({ spese: 125000, entrate: 0, daRipartire: 125000 });
    });

    it('la sola entrata dà un netto negativo, mai azzerato: −€ 240,00', () => {
        expect(totaliPerTipo([{ tipo: 'entrata', importo: 24000 }])).toEqual({ spese: 0, entrate: 24000, daRipartire: -24000 });
    });

    it('un importo negativo su una voce di spesa conta come il motore lo addebita: in valore assoluto', () => {
        expect(totaliPerTipo([{ tipo: 'spesa', importo: -24000 }])).toEqual({ spese: 24000, entrate: 0, daRipartire: 24000 });
    });

    it('nessuna voce, nessun totale', () => {
        expect(totaliPerTipo([])).toEqual({ spese: 0, entrate: 0, daRipartire: 0 });
    });

    it('una voce senza tipo ferma il conto invece di finire fra le spese', () => {
        expect(() => totaliPerTipo([{ importo: 1000 }])).toThrow();
    });
});

describe('centesimiDaImporto — il confine euro → centesimi, una volta sola', () => {
    it('la notazione italiana del campo: «1.200,00» sono 120000 centesimi', () => {
        expect(centesimiDaImporto('1.200,00')).toBe(120000);
        expect(centesimiDaImporto('240,00')).toBe(24000);
        expect(centesimiDaImporto('1.234,5')).toBe(123450);
    });

    it('la notazione pura del valore iniziale del campo: «1250.50» sono 125050 centesimi', () => {
        expect(centesimiDaImporto('1250.50')).toBe(125050);
        expect(centesimiDaImporto('1200.00')).toBe(120000);
    });

    it('un numero in euro e un campo vuoto', () => {
        expect(centesimiDaImporto(1200)).toBe(120000);
        expect(centesimiDaImporto('')).toBe(0);
        expect(centesimiDaImporto(null)).toBe(0);
        expect(centesimiDaImporto(undefined)).toBe(0);
        expect(centesimiDaImporto('abc')).toBe(0);
    });

    it('arrotonda al centesimo: 0,29 × 100 non diventa 28 (il prodotto in virgola mobile dà 28,999…)', () => {
        expect(centesimiDaImporto('0,29')).toBe(29);
        expect(centesimiDaImporto('0.29')).toBe(29);
        expect(centesimiDaImporto('1.005,10')).toBe(100510);
    });
});

describe('totaliDaImportiInEuro — i totali della creazione del piano, dai campi della pagina', () => {
    it('spese € 1.200,00 ed entrata € 240,00 scritte nei campi: 120000 / 24000 / 96000', () => {
        const voci = [{ tipo: 'spesa', importo_da_usare: '1.200,00' }, { tipo: 'entrata', importo_da_usare: '240,00' }];

        expect(totaliDaImportiInEuro(voci)).toEqual({ spese: 120000, entrate: 24000, daRipartire: 96000 });
    });

    it('con i valori iniziali dei campi, in notazione pura: lo stesso risultato', () => {
        const voci = [{ tipo: 'spesa', importo_da_usare: '1200.00' }, { tipo: 'entrata', importo_da_usare: '240.00' }];

        expect(totaliDaImportiInEuro(voci)).toEqual({ spese: 120000, entrate: 24000, daRipartire: 96000 });
    });

    it('un campo vuoto o un importo scelto parziale: contano per quello che c\'è', () => {
        const voci = [{ tipo: 'spesa', importo_da_usare: '600,00' }, { tipo: 'entrata', importo_da_usare: '' }];

        expect(totaliDaImportiInEuro(voci)).toEqual({ spese: 60000, entrate: 0, daRipartire: 60000 });
    });

    it('una voce senza tipo ferma il conto: il selettore deve mandarlo', () => {
        expect(() => totaliDaImportiInEuro([{ importo_da_usare: '1.200,00' }])).toThrow();
    });
});

describe('le parole del totale (decisione 87.3)', () => {
    it('senza entrate la frase è quella di sempre: «Totale richiesto»', () => {
        expect(fraseDaRipartire({ spese: 120000, entrate: 0, daRipartire: 120000 }, euro)).toBe('Totale richiesto: € 1.200,00');
    });

    it('con un\'entrata il netto si chiama «da ripartire» e la frase dice da dove viene', () => {
        expect(fraseDaRipartire({ spese: 120000, entrate: 24000, daRipartire: 96000 }, euro)).toBe('Da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)');
    });

    it('il netto negativo si legge col meno, e il simbolo resta prima della cifra', () => {
        expect(fraseDaRipartire({ spese: 0, entrate: 24000, daRipartire: -24000 }, euro)).toBe('Da ripartire: € -240,00 (spese € 0,00, entrate € 240,00)');
    });

    it('dal campo alla frase, senza il ×100 di troppo né il ÷100 mancante: «1.200,00» e «240,00» dicono € 960,00, non € 96.000,00 né € 9,60', () => {
        const frase = fraseDaRipartire(totaliDaImportiInEuro([{ tipo: 'spesa', importo_da_usare: '1.200,00' }, { tipo: 'entrata', importo_da_usare: '240,00' }]), euro);

        expect(frase).toBe('Da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)');
        expect(fraseDaRipartire(totaliDaImportiInEuro([{ tipo: 'spesa', importo_da_usare: '1.200,00' }]), euro)).toBe('Totale richiesto: € 1.200,00');
    });

    it('il riquadro del cruscotto si chiama come prima senza entrate, e «Da ripartire» con le entrate', () => {
        expect(etichettaFabbisogno(0)).toBe('Fabbisogno Condiviso');
        expect(etichettaFabbisogno(24000)).toBe('Da ripartire');
    });
});
