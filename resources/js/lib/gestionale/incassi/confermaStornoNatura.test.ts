import { describe, expect, test } from 'vitest';
import { AVVISO_DATA_NON_VERIFICATA, avvisoErroreFuoriEsercizio, SCELTE_NATURA, SCELTE_NATURA_COMPENSAZIONE, scelteDellaNatura, valeDalDellaNatura, type AnteprimaStorno } from './confermaStorno';

/**
 * La natura dello storno di un incasso (1.12.0-beta.3, decisione 217, D1): la sceglie chi storna, senza una risposta già scelta, fra
 * due frasi; accanto a ognuna da quale data vale lo storno, come la dice il server (il giorno dell'amministratore). Se l'esercizio che
 * contiene la data del versamento è chiuso, o non c'è, un errore di registrazione vale da oggi, e la conferma lo dice prima.
 */

const anteprima = (natura: AnteprimaStorno['natura']): AnteprimaStorno => ({ voci: [], frase: null, ricevuta: null, natura });

describe('le due nature', () => {
    test('due frasi, nell\'ordine, senza parole di effetto legale', () => {
        expect(SCELTE_NATURA.map((s) => s.valore)).toEqual(['errore_registrazione', 'versamento_tornato']);
        // Le parole della decisione 219: la prima comprende il versamento vero registrato male, che si storna per registrarlo di nuovo.
        expect(SCELTE_NATURA[0].titolo).toBe('Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo. Lo storno vale dal giorno del versamento.');
        expect(SCELTE_NATURA[1].titolo).toBe('Il versamento c\'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato). Lo storno vale da oggi.');
        for (const s of [...SCELTE_NATURA, ...SCELTE_NATURA_COMPENSAZIONE]) {
            expect(s.titolo).not.toMatch(/moros|esigibil|diffid|ingiung|quietanza/i);
        }
    });

    test('nessuna frase dice soltanto «il versamento non c\'è mai stato»', () => {
        for (const s of SCELTE_NATURA) {
            expect(s.titolo).not.toMatch(/^Il versamento non c'è mai stato/);
        }
        expect(SCELTE_NATURA[0].titolo).toContain('va registrato di nuovo');
    });

    test('un incasso fatto solo di credito: le parole della compensazione, con le stesse due nature', () => {
        expect(SCELTE_NATURA_COMPENSAZIONE.map((s) => s.valore)).toEqual(['errore_registrazione', 'versamento_tornato']);
        expect(SCELTE_NATURA_COMPENSAZIONE.map((s) => s.titolo)).toEqual([
            'La compensazione è sbagliata: si toglie dal giorno in cui è stata fatta.',
            'La compensazione era giusta e si annulla da oggi.',
        ]);
        expect(scelteDellaNatura({ importo_totale_cents: 0 }, null)).toEqual(SCELTE_NATURA_COMPENSAZIONE);
        expect(scelteDellaNatura({ importo_totale_cents: 30000 }, null)).toEqual(SCELTE_NATURA);
    });

    test('l\'esercizio della data chiuso: la frase dell\'errore non promette la data del versamento', () => {
        const chiuso = anteprima({ errore_registrazione: { vale_dal: '2027-01-15', esercizio_chiuso: true }, versamento_tornato: { vale_dal: '2027-01-15' } });

        expect(scelteDellaNatura({ importo_totale_cents: 30000 }, chiuso).map((s) => s.titolo)).toEqual([
            'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo. Lo storno vale da oggi, perché l\'esercizio del versamento è chiuso, o non c\'è.',
            SCELTE_NATURA[1].titolo,
        ]);
        expect(scelteDellaNatura({ importo_totale_cents: 0 }, chiuso).map((s) => s.titolo)).toEqual([
            'La compensazione è sbagliata: si toglie da oggi, perché l\'esercizio in cui è stata fatta è chiuso, o non c\'è.',
            SCELTE_NATURA_COMPENSAZIONE[1].titolo,
        ]);
    });
});

describe('da quando vale', () => {
    const date = { errore_registrazione: { vale_dal: '2026-12-10', esercizio_chiuso: false }, versamento_tornato: { vale_dal: '2027-01-15' } };

    test('l\'errore dalla data del versamento, il versamento tornato da oggi, scritte gg/mm/aaaa', () => {
        expect(valeDalDellaNatura(anteprima(date), 'errore_registrazione')).toBe('Vale dal 10/12/2026.');
        expect(valeDalDellaNatura(anteprima(date), 'versamento_tornato')).toBe('Vale dal 15/01/2027.');
    });

    test('senza l\'anteprima (in attesa o fallita) nessuna data inventata', () => {
        expect(valeDalDellaNatura(null, 'errore_registrazione')).toBeNull();
        expect(valeDalDellaNatura(anteprima(undefined), 'versamento_tornato')).toBeNull();
    });

    test('l\'esercizio della data chiuso, o assente: la conferma lo dice, con la data da cui vale', () => {
        const chiuso = { errore_registrazione: { vale_dal: '2027-01-15', esercizio_chiuso: true }, versamento_tornato: { vale_dal: '2027-01-15' } };

        expect(avvisoErroreFuoriEsercizio(anteprima(chiuso))).toBe('L\'esercizio che contiene la data del versamento è chiuso, o non c\'è: se è una registrazione sbagliata, lo storno vale da oggi, 15/01/2027.');
        expect(avvisoErroreFuoriEsercizio(anteprima(date))).toBeNull();
        expect(avvisoErroreFuoriEsercizio(null)).toBeNull();
        expect(avvisoErroreFuoriEsercizio(anteprima(chiuso), true)).toBe('L\'esercizio che contiene la data della compensazione è chiuso, o non c\'è: se è sbagliata, si toglie da oggi, 15/01/2027.');
    });

    test('senza l\'anteprima la conferma dice che la data non si è potuta sapere, e dove la si legge dopo', () => {
        expect(AVVISO_DATA_NON_VERIFICATA).toBe('Non si è potuto sapere da quale data vale lo storno: se l\'esercizio che contiene la data del versamento è chiuso, o non c\'è, vale da oggi anche come registrazione sbagliata. Dopo lo storno la data è scritta nella pagina dell\'incasso.');
    });
});
