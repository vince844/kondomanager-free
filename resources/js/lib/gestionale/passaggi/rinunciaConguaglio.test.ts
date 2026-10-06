import { describe, expect, test } from 'vitest';
import { fraseArretrato, rinunciaEffettiva } from './rinunciaConguaglio';

describe('rinunciaConguaglio — la rinuncia al conguaglio nella successione (1.11.0-beta.44, rilievi X8 e X11)', () => {
    test('la rinuncia vale solo con una coppia proposta e senza l\'arretrato agli eredi', () => {
        // Con l'arretrato agli eredi coppia e arretrato fanno un conto solo: il server la rifiuta, e il modulo non la manda.
        expect(rinunciaEffettiva(2, true, 'eredi')).toBe(false);
        expect(rinunciaEffettiva(2, true, 'defunto')).toBe(true);
        expect(rinunciaEffettiva(0, true, 'defunto')).toBe(false);
        expect(rinunciaEffettiva(2, false, null)).toBe(false);
        // Fuori dalla successione l'arretrato non c'è, e vale la regola della vendita.
        expect(rinunciaEffettiva(1, true, undefined)).toBe(true);
    });

    test('con la rinuncia la frase dell\'arretrato a nome del defunto è quella senza la coppia, che non si scrive', () => {
        const arretrato = { scelta: 'defunto' as const, frase: '€ 595,07 resta a nome di Ugo', frase_senza_conguaglio: '€ 1.200,00 resta a nome di Ugo' };
        expect(fraseArretrato(arretrato, false)).toBe('€ 595,07 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, true)).toBe('€ 1.200,00 resta a nome di Ugo');
        expect(fraseArretrato({ scelta: 'eredi', frase: 'agli eredi' }, true)).toBe('agli eredi');
        expect(fraseArretrato(null, true)).toBeNull();
    });
});
