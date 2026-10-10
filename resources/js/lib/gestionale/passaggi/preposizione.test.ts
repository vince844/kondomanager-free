/**
 * 1.11.0-beta.48 — «a» o «ad» davanti al nome di una persona: la regola della pagina (rilievo R5 della lente testi della Fase 1-bis
 * di `docs/piano_esecutivo_beta48.md`).
 *
 * Nasce dalla successione di Fresco: l'erede di riferimento si chiama Anna, e il pannello «Cosa cambierà» scrive «€ 268,55 a Anna»,
 * «vanno a Anna», «passano a Anna». La decisione 73 (4) aveva corretto due frasi del server con l'aiuto `FrasiObbligati::a()`, ma le
 * altre frasi del blocco, le tabelle delle coppie e dell'arretrato nel pannello (`a {{ c.entrante_nome }}`, `a {{ e.nome }}`) restano
 * senza la «d». Il server usa la regola di `FrasiObbligati.php` (`/^[aàAÀ]/u`: davanti a un nome che comincia per A, anche con
 * l'accento o in minuscolo, «ad»; davanti a tutti gli altri «a»); la pagina ha bisogno della stessa regola, in una funzione pura
 * sua, `aNome(nome)` in `preposizione.ts`, perché le tabelle sono composte dal template e non dal server.
 *
 * Presidia: `aNome` — «ad» davanti alla A (maiuscola, minuscola, con l'accento), «a» davanti a ogni altra iniziale (anche alle
 * altre vocali: la regola del progetto è solo la A, come il server), il nome lasciato com'è (maiuscole, spazi, cognomi) e un solo
 * spazio fra la preposizione e il nome. Il file è nuovo e `preposizione.ts` oggi non esiste: l'import non si risolve e il file
 * intero è rosso («Failed to resolve import»), che è la ragione del difetto — la pagina non ha ancora la sua regola.
 *
 * Verdi oggi: nessuno, per la ragione detta sopra; devono restare tutti verdi quando il file c'è.
 *
 * Cosa NON copre: le frasi del server (le prova `SuccessioneTestiTest`, lato PHP); dove la pagina usa la funzione (le tabelle del
 * pannello: `AnteprimaPassaggio.test.ts`); il nome vuoto o fatto di soli spazi, che la pagina non produce (un'anagrafica ha sempre
 * un nome) e che il progetto non fissa.
 */
import { describe, expect, test } from 'vitest';
import { aNome } from './preposizione';

describe('preposizione — «a» o «ad» davanti al nome (1.11.0-beta.48, R5)', () => {
    test('davanti a un nome che comincia per A si scrive «ad»: «ad Anna», come «ad Acquirente Elsa» del server', () => {
        expect(aNome('Anna')).toBe('ad Anna');
        expect(aNome('Ada')).toBe('ad Ada');
        expect(aNome('Acquirente Elsa')).toBe('ad Acquirente Elsa');
    });

    test('davanti a una A accentata si scrive «ad», come davanti alla A: «ad Àlex»', () => {
        expect(aNome('Àlex')).toBe('ad Àlex');
        expect(aNome('àlex')).toBe('ad àlex');
    });

    test('la regola del server guarda la lettera, non le maiuscole: «ad alberto» come «ad Alberto»', () => {
        expect(aNome('Alberto')).toBe('ad Alberto');
        expect(aNome('alberto')).toBe('ad alberto');
    });

    test('davanti a ogni altra iniziale si scrive «a»: «a Bruno», «a Carla», «a Venditore Ugo»', () => {
        expect(aNome('Bruno')).toBe('a Bruno');
        expect(aNome('Carla')).toBe('a Carla');
        expect(aNome('Venditore Ugo')).toBe('a Venditore Ugo');
        expect(aNome('Leo')).toBe('a Leo');
    });

    test('anche davanti alle altre vocali si scrive «a»: la regola del progetto è solo la A («a Elsa», «a Ugo», «a Èlia»)', () => {
        expect(aNome('Elsa')).toBe('a Elsa');
        expect(aNome('Ugo')).toBe('a Ugo');
        expect(aNome('Ottavia')).toBe('a Ottavia');
        expect(aNome('Ivo')).toBe('a Ivo');
        // L'accento sulla E non fa scattare la «d»: solo la À conta, come nell'espressione del server.
        expect(aNome('Èlia')).toBe('a Èlia');
    });

    test('conta solo la prima lettera: una A in mezzo al nome non cambia la preposizione', () => {
        expect(aNome('Bruno Antonelli')).toBe('a Bruno Antonelli');
        expect(aNome('Carla Alberti')).toBe('a Carla Alberti');
        expect(aNome('Anna Maria Rossi')).toBe('ad Anna Maria Rossi');
    });

    test('il nome resta com\'è e fra la preposizione e il nome c\'è un solo spazio', () => {
        expect(aNome('Anna')).not.toContain('  ');
        expect(aNome('Bruno')).not.toContain('  ');
        expect(aNome('ANNA')).toBe('ad ANNA');
        expect(aNome('de Rossi')).toBe('a de Rossi');
        expect(aNome('Anna').endsWith('Anna')).toBe(true);
    });

    // Le frasi che il pannello compone con la funzione: la cifra e il nome, dalla successione di Fresco.
    test('le righe del caso di Fresco: «ad Anna» (erede di riferimento), «a Bruno», «a Carla»', () => {
        const eredi = ['Anna', 'Bruno', 'Carla'];
        expect(eredi.map(aNome)).toEqual(['ad Anna', 'a Bruno', 'a Carla']);
        expect(`€ 268,55 ${aNome('Anna')} (33,34 %)`).toBe('€ 268,55 ad Anna (33,34 %)');
        expect(`le rate in bozza passano ${aNome('Anna')}`).toBe('le rate in bozza passano ad Anna');
    });
});
