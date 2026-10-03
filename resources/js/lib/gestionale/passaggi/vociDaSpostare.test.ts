import { describe, expect, test } from 'vitest';
import { cambiaSpunta, dividiVoci, nomiVoci, voceSpuntata } from './vociDaSpostare';

describe('vociDaSpostare — la spunta delle voci al passaggio (decisioni 31.6 e 31.8)', () => {
    test('di partenza ogni voce libera è spuntata', () => {
        expect(voceSpuntata({ id: 7, bloccata: false }, [])).toBe(true);
    });

    test('togliere la spunta mette la voce fra quelle da tenere, rimetterla la toglie', () => {
        const tolta = cambiaSpunta([], 7);
        expect(tolta).toEqual([7]);
        expect(voceSpuntata({ id: 7, bloccata: false }, tolta)).toBe(false);
        expect(cambiaSpunta(tolta, 7)).toEqual([]);
    });

    test('la spunta di una voce non tocca le altre, e l\'elenco dato non cambia', () => {
        const prima = [3];
        const dopo = cambiaSpunta(prima, 7);
        expect(dopo).toEqual([3, 7]);
        expect(prima).toEqual([3]);
        expect(voceSpuntata({ id: 3, bloccata: false }, dopo)).toBe(false);
    });

    test('una voce bloccata da un piano approvato non è mai spuntata', () => {
        expect(voceSpuntata({ id: 9, bloccata: true }, [])).toBe(false);
    });

    test('le voci si dividono in libere e bloccate, nell\'ordine in cui arrivano', () => {
        const voci = [{ id: 1, bloccata: false }, { id: 2, bloccata: true }, { id: 3, bloccata: false }];
        expect(dividiVoci(voci)).toEqual({ libere: [voci[0], voci[2]], bloccate: [voci[1]] });
    });

    test('i nomi ripetuti si dicono una volta, con quante sono (verifica a video: tre imprevisti dello stesso fornitore)', () => {
        expect(nomiVoci([{ conto: 'Compenso' }, { conto: 'Imprevisto' }, { conto: 'Giardino' }, { conto: 'Imprevisto' }, { conto: 'Imprevisto' }]))
            .toBe('Compenso, Imprevisto (3 voci), Giardino');
        expect(nomiVoci([{ conto: 'Compenso' }])).toBe('Compenso');
    });
});
