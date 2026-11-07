/**
 * Le parole dell'accordo di rientro: il segno con le due cifre (riconosciuti, e quanto resta oggi), «residuo invariato» quando sono uguali,
 * «fino al» passato senza aggettivi, la parte versata oltre il capitale riconosciuto; la parte di una rata in accordo; l'anteprima delle lettere.
 * Le cifre sono quelle del caso di Giulia: € 1.500,00 riconosciuti il 26/06/2026, € 994,10 al 04/11, € 17,67 versati oltre.
 */
import { describe, expect, test } from 'vitest';
import { FRASE_AUTORIZZAZIONE, testoInAccordoNellaLettera, testoParteInAccordo, testoSegnoAccordo, testoSoloInAccordo } from './accordiRientro';

const accordo = { id: 1, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 99410, invariato: false, fino_al_passato: false, versati_oltre: 0,
    autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null };

describe('il segno dell\'accordo', () => {
    test('le due cifre che il programma sa', () => {
        expect(testoSegnoAccordo(accordo)).toBe('Accordo di rientro del 26/06/2026, fino al 15/12/2026: riconosciuti € 1.500,00, oggi € 994,10.');
    });
    test('«residuo invariato» quando il residuo è lo stesso, non «nessun versamento»', () => {
        expect(testoSegnoAccordo({ ...accordo, oggi: 150000, invariato: true })).toBe('Accordo di rientro del 26/06/2026, fino al 15/12/2026: riconosciuti € 1.500,00, residuo invariato dal 26/06/2026.');
    });
    test('«fino al» passato: la data, senza aggettivi', () => {
        expect(testoSegnoAccordo({ ...accordo, fino_al_passato: true })).toBe('Accordo di rientro del 26/06/2026, fino al 15/12/2026 (data passata): riconosciuti € 1.500,00, oggi € 994,10.');
    });
    test('la parte versata oltre il capitale riconosciuto: un credito della persona, che il programma non sposta', () => {
        expect(testoSegnoAccordo({ ...accordo, oggi: 0, versati_oltre: 1767 })).toBe('Accordo di rientro del 26/06/2026, fino al 15/12/2026: riconosciuti € 1.500,00, oggi € 0,00. '
            + 'Versati € 17,67 oltre il capitale riconosciuto: sono andati a credito della persona; interessi e spese il programma non li registra ancora.');
    });
    test('la parte di una rata', () => {
        expect(testoParteInAccordo({ riconosciuto: 75000, in_accordo: 24410, data_accordo: '2026-06-26', fuori_dalle_lettere: true }))
            .toBe('Nell\'accordo del 26/06/2026: € 244,10 (riconosciuti € 750,00); non nelle lettere proposte.');
        expect(testoParteInAccordo({ riconosciuto: 75000, in_accordo: 75000, data_accordo: '2026-06-26', fuori_dalle_lettere: false }))
            .toBe('Nell\'accordo del 26/06/2026: € 750,00 (riconosciuti € 750,00).');
    });
});

describe('l\'anteprima delle lettere', () => {
    test('quanto è in accordo, e chi ha solo rate in accordo', () => {
        expect(testoInAccordoNellaLettera(99410)).toBe('€ 994,10 in accordo non sono nella lettera.');
        expect(testoInAccordoNellaLettera(0)).toBeNull();
        expect(testoSoloInAccordo(['Giulia'])).toBe('Solo rate in accordo di rientro, non nella lettera: Giulia.');
        expect(testoSoloInAccordo([])).toBeNull();
    });
    test('l\'autorizzazione non ha una risposta già scelta, e il «no» si spiega', () => {
        expect(FRASE_AUTORIZZAZIONE).toContain('è «no», con una nota');
    });
});
