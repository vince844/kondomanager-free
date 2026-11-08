/**
 * Il consuntivo di una voce nel piano dei conti, con le voci di entrata.
 *
 * Una voce di spesa ha il consuntivo (quanto si è speso), confrontato con il preventivo: sopra il preventivo è uno sforo. Una voce di entrata
 * ha l'incassato (l'affitto dell'antenna, gli interessi): il server lo manda positivo in `incassato_raw`, e la pagina lo chiama «Incassato».
 * Non è mai uno speso negativo, e non è mai uno sforo, nemmeno quando si incassa più del previsto.
 *
 * Fonte dell'atteso: l'incasso da terzi va in diminuzione della voce di entrata; nel piano dei conti la voce dice «incassato € 240,00», mai uno
 * speso di −€ 240,00.
 */
import { describe, expect, test } from 'vitest';
import { consuntivoDellaVoce } from './consuntivoDellaVoce';

describe('una voce di entrata', () => {
    const antenna = { tipo: 'entrata', budget_originale_raw: 24000, speso_raw: 0, incassato_raw: 24000 };

    test('dice «Incassato» e l\'importo positivo', () => {
        const c = consuntivoDellaVoce(antenna);

        expect(c.etichetta).toBe('Incassato');
        expect(c.importo).toBe(24000);
        expect(c.entrata).toBe(true);
        expect(c.haMovimenti).toBe(true);
    });

    test('non è mai uno sforo, nemmeno incassando più del previsto', () => {
        expect(consuntivoDellaVoce({ ...antenna, incassato_raw: 30000 }).isSforo).toBe(false);
    });

    test('senza incassi non ha movimenti', () => {
        expect(consuntivoDellaVoce({ ...antenna, incassato_raw: 0 }).haMovimenti).toBe(false);
    });

    test('con i dati di prima (una fattura sulla voce di entrata) si legge come prima: «Consuntivo», lo speso, lo sforo sul preventivo', () => {
        const c = consuntivoDellaVoce({ ...antenna, speso_raw: 50000, incassato_raw: 0 });

        expect(c.etichetta).toBe('Consuntivo');
        expect(c.importo).toBe(50000);
        expect(c.isSforo).toBe(true);
    });

    test('con i dati di prima e un incasso, conta lo speso di prima: la voce non si legge in due modi', () => {
        const c = consuntivoDellaVoce({ ...antenna, speso_raw: 50000, incassato_raw: 24000 });

        expect(c.etichetta).toBe('Consuntivo');
        expect(c.importo).toBe(50000);
    });
});

describe('una voce di spesa', () => {
    const pulizie = { tipo: 'spesa', budget_originale_raw: 300000, speso_raw: 290000, incassato_raw: 0 };

    test('dice «Consuntivo» e lo speso', () => {
        const c = consuntivoDellaVoce(pulizie);

        expect(c.etichetta).toBe('Consuntivo');
        expect(c.importo).toBe(290000);
        expect(c.isSforo).toBe(false);
        expect(c.differenza).toBe(10000);
    });

    test('sopra il preventivo è uno sforo, con la percentuale', () => {
        const c = consuntivoDellaVoce({ ...pulizie, speso_raw: 330000 });

        expect(c.isSforo).toBe(true);
        expect(c.differenza).toBe(-30000);
        expect(c.percentuale).toBe(10);
    });

    test('senza preventivo non sfora', () => {
        expect(consuntivoDellaVoce({ ...pulizie, budget_originale_raw: 0, speso_raw: 5000 }).isSforo).toBe(false);
    });

    test('una voce di uscita conta come una spesa', () => {
        expect(consuntivoDellaVoce({ ...pulizie, tipo: 'uscita' }).etichetta).toBe('Consuntivo');
    });
});
