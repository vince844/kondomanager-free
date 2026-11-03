/**
 * Il bollino di stato di una riga dell'estratto conto (`statoRigaEstratto.ts`, 1.11.0-beta.49): i casi limite che le mutazioni della
 * fase finale hanno trovato scoperti (una ricerca diretta nella tabella degli stati, il bollino condiviso fra le righe).
 */

import { describe, expect, test } from 'vitest';
import { statoRigaEstratto } from './statoRigaEstratto';

// Mutazioni sopravvissute (fase finale della 1.11.0-beta.49): i test che le prendono.
describe('statoRigaEstratto, i casi limite', () => {
    test('uno stato con il nome di una proprietà di ogni oggetto JavaScript non è uno stato conosciuto: niente bollino', () => {
        // Con una ricerca diretta in `STATI` «constructor» e «toString» troverebbero le funzioni del prototipo, e la pagina
        // disegnerebbe un bollino vuoto: proprio il difetto che il modulo toglie.
        expect(statoRigaEstratto('constructor')).toBeNull();
        expect(statoRigaEstratto('toString')).toBeNull();
        expect(statoRigaEstratto('__proto__')).toBeNull();
    });

    test('il bollino restituito è una copia: chi lo modifica non cambia quello delle righe dopo', () => {
        const primo = statoRigaEstratto('stornata');
        primo!.etichetta = 'ALTRO';
        primo!.tono = 'rosso';
        expect(statoRigaEstratto('stornata')).toEqual({ etichetta: 'STORNATA', tono: 'grigio' });
    });
});
