/**
 * Lo scaduto da incassare della pagina del piano: la somma dei residui delle rate **emesse** che il server dice **scadute**.
 * Il browser non ricalcola la scadenza (il giorno della scadenza non è scaduto, nel giorno dell'amministratore: lo decide il
 * server) e non conta le bozze. Il caso è quello di Rossi alle 11:00 del 15/12: rata del 14/12 emessa, del 15/12 e del 16/12
 * emesse, del 10/12 in bozza, la rata 0 a credito. Centesimi, come li manda il server.
 */
import { describe, expect, test } from 'vitest';
import { rataScaduta, scadutoDaIncassare } from './scaduto';

const ROSSI = [
    { numero: 0, scadenza: '2026-01-05', emessa: false, scaduta: true, stato: 'credito', importo: -3000, importo_pagato: 0, residuo: 0 },
    { numero: 1, scadenza: '2026-12-14', emessa: true, scaduta: true, stato: 'da_pagare', importo: 10000, importo_pagato: 0, residuo: 10000 },
    { numero: 2, scadenza: '2026-12-15', emessa: true, scaduta: false, stato: 'da_pagare', importo: 10000, importo_pagato: 0, residuo: 10000 },
    { numero: 3, scadenza: '2026-12-16', emessa: true, scaduta: false, stato: 'da_pagare', importo: 10000, importo_pagato: 0, residuo: 10000 },
    { numero: 4, scadenza: '2026-12-10', emessa: false, scaduta: true, stato: 'da_pagare', importo: 10000, importo_pagato: 0, residuo: 10000 },
];

describe('scadutoDaIncassare', () => {
    test('Rossi alle 11:00 del 15/12: € 100,00, non € 300,00', () => {
        expect(scadutoDaIncassare(ROSSI)).toBe(10000);
    });

    test('una rata emessa, scaduta e pagata in parte conta per quello che resta', () => {
        expect(scadutoDaIncassare([{ ...ROSSI[1], stato: 'parzialmente_pagata', importo_pagato: 4000, residuo: 6000 }])).toBe(6000);
    });

    test('una rata pagata, o fatta di un credito, non conta', () => {
        expect(scadutoDaIncassare([{ ...ROSSI[1], stato: 'pagata', importo_pagato: 10000, residuo: 0 }, ROSSI[0]])).toBe(0);
    });
});

describe('rataScaduta: il badge «Scaduta» della cella', () => {
    test('solo una rata emessa, scaduta e da pagare', () => {
        expect(ROSSI.map(rataScaduta)).toEqual([false, true, false, false, false]);
    });
});
