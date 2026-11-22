// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le frasi di «Crea il piano straordinario»: il riepilogo del piede (che cosa nasce, la differenza dalla delibera, la gestione già legata) e l'anno nel nome
 * della gestione che segue l'esercizio scelto. I centesimi dal campo in euro, solo per dirlo.
 */
import { describe, expect, test } from 'vitest';
import { nomeConLAnno, nomeDelPianoPreparato, riepilogoDelPianoStraordinario } from './pianoStraordinario';
import { campiDellUrgenza, fraseDelGiornoDeiLavori } from '@/lib/gestionale/pianiRate/urgenza';

describe('il riepilogo e il nome', () => {
    test('che cosa nasce, la differenza in più o in meno, la gestione già legata, l\'importo vuoto', () => {
        expect(riepilogoDelPianoStraordinario('14.500,00', 1450000, null)).toBe('Nascono la gestione, il suo piano dei conti e 1 voce da € 14.500,00.');
        expect(riepilogoDelPianoStraordinario('15.000,00', 1450000, null)).toBe('Nascono la gestione, il suo piano dei conti e 1 voce da € 15.000,00. € 500,00 in più della delibera (€ 14.500,00).');
        expect(riepilogoDelPianoStraordinario('', 1450000, null)).toBe('Scrivi l\'importo della voce.');
        expect(riepilogoDelPianoStraordinario('1', 1450000, 'Portone 2027')).toBe('Si apre il piano della gestione «Portone 2027», già legata alla delibera: niente nasce di nuovo.');
    });
    test('l\'anno del nome segue l\'esercizio, un nome scritto a mano resta com\'è', () => {
        expect(nomeConLAnno('Portone 2026', '2026', '2027')).toBe('Portone 2027');
        expect(nomeConLAnno('Portone d\'ingresso', '2026', '2027')).toBe('Portone d\'ingresso');
    });
});

describe('l\'urgenza sulle voci (Coda 688)', () => {
    test('la frase del giorno e il giorno che viaggia con «Urgenza» su tutte e due le schede', () => {
        expect(fraseDelGiornoDeiLavori('2027-03-02')).toBe('Chi era titolare il 02/03/2027 paga tutto: il giorno decide chi paga, non quanto.');
        expect(fraseDelGiornoDeiLavori('')).toBe('Scegli il giorno: decide chi paga, non quanto.');
        expect(campiDellUrgenza('urgenza', 'voci', '2027-03-02')).toEqual({ giorno_dei_lavori: '2027-03-02' });
        // Dalla decisione sulla scheda delle fatture: il giorno anche lì, e la fattura senza periodo lo prende.
        expect(campiDellUrgenza('urgenza', 'fatture', '2027-03-02')).toEqual({ giorno_dei_lavori: '2027-03-02' });
        expect(campiDellUrgenza('delibera', 'voci', '2027-03-02')).toEqual({ giorno_dei_lavori: null });
    });
});

describe('il nome del piano preparato dalla delibera (M6)', () => {
    test('il nome vuoto prende quello della gestione («Portone 2026»); un nome già scritto non si tocca; senza gestione resta vuoto', () => {
        expect(nomeDelPianoPreparato('', 'Sostituzione del portone d\'ingresso 2026')).toBe('Sostituzione del portone d\'ingresso 2026');
        expect(nomeDelPianoPreparato('   ', 'Portone 2026')).toBe('Portone 2026');
        expect(nomeDelPianoPreparato('Il mio piano', 'Portone 2026')).toBe('Il mio piano');
        expect(nomeDelPianoPreparato('', null)).toBe('');
        expect(nomeDelPianoPreparato('', undefined)).toBe('');
    });
});
