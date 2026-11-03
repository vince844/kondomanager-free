/**
 * Gli errori dei singoli indirizzi nella pagina «Nuovo invito»: arrivano su `emails.0`, `emails.1`…
 * e fino alla 1.11.0-beta.44 non comparivano da nessuna parte.
 */

import { describe, expect, test } from 'vitest';
import { erroriDegliIndirizzi } from './erroriDegliIndirizzi';

describe('erroriDegliIndirizzi', () => {
    test('raccoglie gli errori di ogni indirizzo, nell\'ordine degli indirizzi', () => {
        expect(erroriDegliIndirizzi({
            'emails.10': 'decimo',
            'emails.1': 'secondo',
            'emails.0': 'primo',
        })).toEqual(['primo', 'secondo', 'decimo']);
    });

    test('lascia fuori l\'errore dell\'elenco intero e quelli degli altri campi', () => {
        expect(erroriDegliIndirizzi({
            emails: 'elenco vuoto',
            buildings: 'nessun condominio',
            'emails.0': 'già invitato',
        })).toEqual(['già invitato']);
    });

    test('senza errori non restituisce niente', () => {
        expect(erroriDegliIndirizzi({})).toEqual([]);
    });
});
