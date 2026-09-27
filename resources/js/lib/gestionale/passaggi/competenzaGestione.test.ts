import { describe, expect, test } from 'vitest';
import { competenzaDellaGestione } from './competenzaGestione';

const GRADINI = { dichiarata: 'competenza dichiarata', delibera: 'data della delibera', capitolo: 'competenza del capitolo' };

describe('competenzaDellaGestione — la colonna della competenza nell\'anteprima del passaggio (R5 della Fase 1-bis)', () => {
    test('sull\'ordinaria con voci miste: «voce per voce», nessun intervallo inventato', () => {
        // Pregressa dichiarata 2025, imprevisto 1/3–31/8/2026, e una fattura senza periodo sulla base 2026.
        expect(competenzaDellaGestione({
            natura: 'ordinaria', gradino: ['dichiarata'], voce_per_voce: true,
            periodo: [{ dal: '2025-01-01', al: '2025-12-31' }, { dal: '2026-01-01', al: '2026-12-31' }, { dal: '2026-03-01', al: '2026-08-31' }],
        }, GRADINI)).toEqual({ etichetta: 'competenza voce per voce', intervallo: null, giorno: null });
    });

    test('una voce sola dichiarata: la sua competenza e le sue date', () => {
        expect(competenzaDellaGestione({ natura: 'ordinaria', gradino: ['dichiarata'], periodo: [{ dal: '2025-01-01', al: '2025-12-31' }] }, GRADINI))
            .toEqual({ etichetta: 'competenza dichiarata', intervallo: '01/01/2025–31/12/2025', giorno: null });
    });

    test('più periodi: dal primo inizio all\'ultima fine, non alla fine dell\'ultimo in ordine', () => {
        const c = competenzaDellaGestione({
            natura: 'straordinaria', gradino: ['dichiarata'],
            periodo: [{ dal: '2026-01-01', al: '2026-12-31' }, { dal: '2026-03-01', al: '2026-08-31' }],
        }, GRADINI);
        expect(c.intervallo).toBe('01/01/2026–31/12/2026');
    });

    test('lo straordinario con la delibera: l\'etichetta e il giorno', () => {
        expect(competenzaDellaGestione({ natura: 'straordinaria', gradino: ['delibera'], periodo: [{ dal: '2026-06-15', al: '2026-06-15' }] }, GRADINI))
            .toEqual({ etichetta: 'data della delibera', intervallo: null, giorno: '15/06/2026' });
    });
});
