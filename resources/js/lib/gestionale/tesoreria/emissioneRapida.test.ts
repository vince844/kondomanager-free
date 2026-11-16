// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le regole della finestra «Emetti le rate», fuori dal componente: le rate spuntate di serie (le già scadute e quelle fino al giorno che manda il server), il
 * riepilogo con le persone contate una volta, i testi, la scadenza rispetto a oggi, l'esito della risposta del server e le frasi degli errori.
 */
import { describe, expect, test } from 'vitest';
import {
    esitoDellaRisposta, etichettaDelPiano, frasiDegliErrori, quandoScade, riepilogo, spuntateDiSerie, testoDelPulsante, testoDelRiepilogo, type PianoDellaFinestra,
} from './emissioneRapida';

const preventivo: PianoDellaFinestra = {
    pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, persone: 4, rate: [
        { rataId: 91, numero: 9, scadenza: '2026-09-30', cifra: 120000, persone: 4, chi: null },
        { rataId: 92, numero: 10, scadenza: '2026-10-31', cifra: 120000, persone: 4, chi: null },
        { rataId: 93, numero: 11, scadenza: '2026-11-30', cifra: 118894, persone: 4, chi: null },
        { rataId: 94, numero: 12, scadenza: '2026-12-31', cifra: 120000, persone: 4, chi: null },
    ],
};
const facciata: PianoDellaFinestra = {
    pianoId: 12, piano: 'Facciata 2026', esercizioId: 25, persone: 3, rate: [
        { rataId: 121, numero: 1, scadenza: '2026-11-20', cifra: 50000, persone: 2, chi: [1, 2] },
        { rataId: 122, numero: 2, scadenza: '2026-12-10', cifra: 30000, persone: 2, chi: [2, 3] },
        { rataId: 123, numero: 3, scadenza: '2027-01-20', cifra: 0, persone: 1, chi: [3] },
    ],
};

describe('le rate spuntate e il riepilogo', () => {
    test('spuntate di serie le già scadute e quelle fino al giorno del server, l\'ultimo compreso; senza piano nessuna', () => {
        expect(spuntateDiSerie(preventivo, '2026-12-16')).toEqual([91, 92, 93]);
        expect(spuntateDiSerie(preventivo, '2026-11-30')).toEqual([91, 92, 93]);
        expect(spuntateDiSerie(preventivo, '2026-11-29')).toEqual([91, 92]);
        expect(spuntateDiSerie(null, '2026-12-16')).toEqual([]);
    });

    test('una rata senza denaro (già versata da tutti, o fatta solo di crediti) non è spuntata di serie: come l\'azione, che non la conta', () => {
        const conUnaGiaVersata: PianoDellaFinestra = { ...preventivo, rate: preventivo.rate.map((r) => (r.rataId === 92 ? { ...r, cifra: 0 } : r)) };
        expect(spuntateDiSerie(conUnaGiaVersata, '2026-12-16')).toEqual([91, 93]);
        // Si può sempre spuntare a mano: la rata resta nell'elenco della finestra.
        expect(conUnaGiaVersata.rate.map((r) => r.rataId)).toContain(92);
    });

    test('il riepilogo: quante rate, le persone una volta sola, la cifra in centesimi', () => {
        expect(riepilogo(preventivo, [91, 92, 93])).toEqual({ rate: 3, condomini: 4, cifra: 358894 });
        expect(testoDelRiepilogo(riepilogo(preventivo, [91, 92, 93]))).toBe('3 rate, 4 condòmini, € 3.588,94');
        // Rate con persone diverse: 1 e 2, poi 2 e 3: tre persone, non quattro.
        expect(riepilogo(facciata, [121, 122])).toEqual({ rate: 2, condomini: 3, cifra: 80000 });
        expect(riepilogo(facciata, [123])).toEqual({ rate: 1, condomini: 1, cifra: 0 });
        expect(testoDelRiepilogo(riepilogo(facciata, [123]))).toBe('1 rata, 1 condòmino, € 0,00');
        // Una rata con tutte le persone del piano le porta tutte.
        expect(riepilogo({ ...facciata, rate: [...facciata.rate, { rataId: 124, numero: 4, scadenza: '2027-02-20', cifra: 10000, persone: 3, chi: null }] }, [121, 124]).condomini).toBe(3);
        // Una spunta che non è del piano non conta.
        expect(riepilogo(preventivo, [91, 121])).toEqual({ rate: 1, condomini: 4, cifra: 120000 });
        expect(riepilogo(preventivo, [])).toEqual({ rate: 0, condomini: 0, cifra: 0 });
    });

    test('il pulsante dice quante rate, e senza spunte resta generico; il menu dice quante rate ha ogni piano', () => {
        expect(testoDelPulsante(3)).toBe('Emetti 3 rate');
        expect(testoDelPulsante(1)).toBe('Emetti 1 rata');
        expect(testoDelPulsante(0)).toBe('Emetti le rate');
        expect(etichettaDelPiano(preventivo)).toBe('Preventivo 2026 (4 rate da emettere)');
        expect(etichettaDelPiano({ ...facciata, rate: [facciata.rate[0]] })).toBe('Facciata 2026 (1 rata da emettere)');
    });
});

describe('la scadenza rispetto a oggi', () => {
    test('scaduta in rosso, nei 30 giorni in ambra, più avanti in grigio; i giorni sul calendario', () => {
        expect(quandoScade('2026-09-30', '2026-11-16', '2026-12-16')).toEqual({ testo: 'scaduta da 47 giorni', tono: 'scaduta' });
        expect(quandoScade('2026-11-15', '2026-11-16', '2026-12-16')).toEqual({ testo: 'scaduta ieri', tono: 'scaduta' });
        expect(quandoScade('2026-11-16', '2026-11-16', '2026-12-16')).toEqual({ testo: 'scade oggi', tono: 'presto' });
        expect(quandoScade('2026-11-17', '2026-11-16', '2026-12-16')).toEqual({ testo: 'scade domani', tono: 'presto' });
        expect(quandoScade('2026-12-16', '2026-11-16', '2026-12-16')).toEqual({ testo: 'fra 30 giorni', tono: 'presto' });
        expect(quandoScade('2026-12-31', '2026-11-16', '2026-12-16')).toEqual({ testo: 'fra 45 giorni', tono: 'avanti' });
        // L'ora legale non toglie un giorno: dal 20/03 al 30/03 sono 10 giorni.
        expect(quandoScade('2026-03-30', '2026-03-20', '2026-04-19').testo).toBe('fra 10 giorni');
    });
});

describe('la risposta del server', () => {
    test('un errore è un rifiuto, con la frase del server', () => {
        const frase = 'Le quote di questo piano sono state calcolate prima di questo passaggio:\n• Rossi → Elsa, Interno A, dal 1 febbraio 2026\n\nRicalcola il piano prima di emettere.';
        expect(esitoDellaRisposta({ message: { type: 'error', message: frase } }, 3)).toEqual({ tipo: 'rifiuto', testo: frase });
    });

    test('un avviso vuol dire nessuna rata a giornale, con le frasi dell\'esito', () => {
        expect(esitoDellaRisposta({ message: { type: 'warning', message: 'La rata 4 era già emessa.' }, esito_emissione: { titolo: 'Rate già emesse', testo: 'La rata 4 era già emessa.' } }, 1))
            .toEqual({ tipo: 'nessuna', titolo: 'Rate già emesse', testo: 'La rata 4 era già emessa.' });
    });

    test('le rate emesse: l\'esito del server, con il suggerimento dei crediti in un capoverso suo; senza esito, le rate chieste', () => {
        expect(esitoDellaRisposta({ message: { type: 'success', message: 'Rate emesse e notificate correttamente ai condòmini.' },
            esito_emissione: { titolo: 'Emissione completata', testo: 'Sono state emesse 3 rate.' }, suggerimento_crediti: 'Nota: Rossi ha € 50,00 di credito spendibile subito.' }, 3))
            .toEqual({ tipo: 'emesse', titolo: 'Emissione completata', testo: 'Sono state emesse 3 rate.\n\nNota: Rossi ha € 50,00 di credito spendibile subito.' });
        expect(esitoDellaRisposta({ message: { type: 'success', message: 'ok' } }, 1)).toEqual({ tipo: 'emesse', titolo: 'Emissione completata', testo: 'È stata emessa 1 rata.' });
        expect(esitoDellaRisposta(undefined, 2)).toEqual({ tipo: 'emesse', titolo: 'Emissione completata', testo: 'Sono state emesse 2 rate.' });
    });

    test('le frasi degli errori, una volta ognuna', () => {
        expect(frasiDegliErrori({ 'rate_ids.1': 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.', 'rate_ids.2': 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.',
            data_emissione: ['Scrivi la data di emissione.'] })).toEqual(['Una delle rate scelte non è di questo piano: scegli di nuovo le rate.', 'Scrivi la data di emissione.']);
        expect(frasiDegliErrori(null)).toEqual([]);
    });
});
