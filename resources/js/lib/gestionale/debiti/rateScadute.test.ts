/**
 * Le parole della pagina «Rate scadute»: il totale lordo, la riga delle rate non emesse, le celle delle due date dei sei mesi con i loro casi
 * (l'esercizio che manca a gennaio, due esercizi, il piano dedotto, la delibera che manca, la data che viene prima della scadenza, il
 * pregresso senza origine), la dispensa, il ritardo di una rata di pregresso. Le cifre e le date sono scritte a mano.
 */
import { describe, expect, test } from 'vitest';
import {
    RIGA_TESORERIA, giorno, testoData, testoDispensa, testoGiorniDellaRata, testoNonEmesse, testoSeiMesiDellaPersona, testoTotale, titoloVista,
    type DataSeiMesi,
} from './rateScadute';

const voce = (extra: Partial<DataSeiMesi> = {}): DataSeiMesi => ({
    data: '2027-06-30', esercizio: { id: 1, nome: 'Esercizio 2026', data_fine: '2026-12-31' }, stato: 'ok', doppio: false, dedotto: false,
    prima_della_scadenza: false, data_cercata: null, esercizio_da_chiudere: null, dispensa: null, ...extra,
});

describe('il totale e la Tesoreria', () => {
    test('il totale dice «senza togliere il credito»; la riga sotto il titolo spiega perché la Tesoreria dà una cifra più bassa', () => {
        expect(testoTotale({ persone: 3, totale: 174410, vista: 'scadute' })).toBe('3 persone con questi filtri: scaduto € 1.744,10, senza togliere il credito.');
        expect(testoTotale({ persone: 1, totale: 5000, vista: 'in_scadenza' })).toBe('1 persona con questi filtri: in scadenza € 50,00, senza togliere il credito.');
        expect(RIGA_TESORERIA).toContain('senza togliere il suo credito');
        expect(RIGA_TESORERIA).toContain('Tesoreria');
        expect(RIGA_TESORERIA).toContain('cifra più bassa');
    });

    test('la vista dei promemoria si chiama «In scadenza nei prossimi N giorni»', () => {
        expect(titoloVista('in_scadenza', 30)).toBe('In scadenza nei prossimi 30 giorni');
        expect(titoloVista('scadute', 30)).toBe('Rate scadute');
    });
});

describe('le rate non ancora emesse', () => {
    test('due persone con € 600,00 su rate scadute in bozza e € 50,00 di saldi non in un piano', () => {
        expect(testoNonEmesse({ persone: 2, importo: 60000, saldi: 5000 })).toBe(
            '2 persone hanno € 600,00 su rate con la scadenza passata e non ancora emesse (il pregresso compreso), e € 50,00 di saldi non ancora in un piano approvato: '
            + 'non sono in questo elenco. Le rate si emettono dal piano rate; i saldi si chiedono mettendoli in un piano.',
        );
    });

    test('niente da dire, niente riga; i soli saldi', () => {
        expect(testoNonEmesse({ persone: 0, importo: 0, saldi: 0 })).toBeNull();
        expect(testoNonEmesse(null)).toBeNull();
        expect(testoNonEmesse({ persone: 0, importo: 0, saldi: 5000 })).toBe('€ 50,00 di saldi non ancora in un piano approvato: non sono in questo elenco. Si chiedono con un piano rate.');
    });
});

describe('le celle delle due date', () => {
    test('la data, e i suoi segni: dedotto, due esercizi, la dispensa', () => {
        expect(testoData(voce())).toBe('30/06/2027');
        expect(testoData(voce({ dedotto: true }))).toBe('30/06/2027 — esercizio del piano dedotto');
        expect(testoData(voce({ doppio: true, data: '2028-06-30' }))).toBe('30/06/2028 — due esercizi contengono questa data: è la più vicina delle due');
        expect(testoData(voce({ dispensa: { id: 1, data_delibera: '2027-03-20', numero_verbale: '12', revocata_il: null } })))
            .toBe('30/06/2027 — dispensa dell\'assemblea del 20/03/2027 (verbale n. 12)');
    });

    test('l\'esercizio della scadenza che manca, a gennaio: si chiude il vecchio e si apre il successivo, mai «crea l\'esercizio»', () => {
        const t = testoData(voce({ data: null, esercizio: null, stato: 'manca_esercizio', data_cercata: '2027-01-05', esercizio_da_chiudere: 'Esercizio 2026' }));
        expect(t).toBe('L\'esercizio che contiene il 05/01/2027 non c\'è ancora: la data si calcola quando chiudi l\'esercizio 2026 e apri il successivo.');
        expect(t.toLowerCase()).not.toContain('crea');
    });

    test('una data prima del primo esercizio del programma: viene prima, non «non c\'è ancora»', () => {
        const t = testoData(voce({ data: null, esercizio: null, stato: 'prima_degli_esercizi', data_cercata: '2019-01-05' }));
        expect(t).toBe('Il 05/01/2019 viene prima del primo esercizio del programma: la data non si calcola.');
        expect(t).not.toContain('ancora');
    });

    test('la data dalla competenza che viene prima della scadenza della rata: si mostra quella dalla scadenza', () => {
        expect(testoData(voce({ prima_della_scadenza: true }), '2027-09-05', voce({ data: '2028-06-30' })))
            .toBe('La data dalla competenza (30/06/2027) viene prima della scadenza della rata (05/09/2027): si mostra quella dalla scadenza (30/06/2028).');
    });

    test('la delibera che manca, il pregresso senza origine o di prima del primo esercizio', () => {
        expect(testoData(voce({ data: null, esercizio: null, stato: 'senza_delibera' }))).toBe('Il piano straordinario non ha la data della delibera: la data dalla competenza non si calcola. Scrivila nel piano rate.');
        expect(testoData(voce({ data: null, esercizio: null, stato: 'origine_non_dichiarata' }))).toBe('Origine non dichiarata: le date non si calcolano.');
        expect(testoData(voce({ data: null, esercizio: null, stato: 'prima_del_primo' }))).toBe('Pregresso di prima del primo esercizio: le date non si calcolano.');
        expect(testoData(voce({ stato: 'da_origine', data: '2026-06-30', esercizio: { id: 2, nome: 'Esercizio 2025', data_fine: '2025-12-31' } })))
            .toBe('30/06/2026 — dall\'esercizio d\'origine 2025');
    });

    test('la dispensa revocata', () => {
        expect(testoDispensa({ id: 1, data_delibera: '2027-03-20', numero_verbale: null, revocata_il: '2027-06-01' })).toBe('dispensa revocata il 01/06/2027');
        expect(testoDispensa(null)).toBeNull();
    });
});

describe('il ritardo e la data della persona', () => {
    test('i giorni di una rata, o le parole del pregresso senza un numero', () => {
        expect(testoGiorniDellaRata({ giorni_ritardo: 12, pregresso: null })).toBe('12 giorni');
        expect(testoGiorniDellaRata({ giorni_ritardo: 1, pregresso: null })).toBe('1 giorno');
        expect(testoGiorniDellaRata({ giorni_ritardo: null, pregresso: { etichetta: 'pregresso dell\'esercizio 2025' } })).toBe('pregresso dell\'esercizio 2025');
        expect(testoGiorniDellaRata({ giorni_ritardo: null, pregresso: { etichetta: 'origine non dichiarata' } })).toBe('origine non dichiarata');
    });

    test('la data più vicina della persona, con la dispensa e le rate senza data', () => {
        expect(testoSeiMesiDellaPersona({ data: '2027-06-30', dispensa: null, mancano: 0 })).toBe('30/06/2027');
        expect(testoSeiMesiDellaPersona({ data: '2027-06-30', dispensa: null, mancano: 2 })).toBe('30/06/2027 — 2 rate senza data');
        expect(testoSeiMesiDellaPersona({ data: null, dispensa: null, mancano: 1 })).toBe('Non calcolata (1 rata senza data)');
        expect(giorno('2027-06-30')).toBe('30/06/2027');
    });
});
