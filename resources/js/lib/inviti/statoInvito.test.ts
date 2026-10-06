/**
 * Lo stato di un invito nell'elenco degli inviti.
 *
 * Fino alla 1.11.0-beta.44 la colonna guardava prima la scadenza e poi l'accettazione: un invito
 * accettato dopo dieci minuti diventava «Scaduto» un'ora dopo l'invio, e l'amministratore rischiava
 * di cancellarlo e reinvitare una persona già registrata.
 */

import { describe, expect, test } from 'vitest';
import { statoInvito } from './statoInvito';

const adesso = new Date('2026-10-06T12:00:00Z');

describe('statoInvito', () => {
    test('un invito accettato resta accettato anche dopo la sua scadenza', () => {
        expect(statoInvito('2026-10-03T09:10:00Z', '2026-10-03T10:00:00Z', adesso)).toBe('accettato');
    });

    test('un invito non accettato e con la scadenza passata è scaduto', () => {
        expect(statoInvito(null, '2026-10-06T11:59:00Z', adesso)).toBe('scaduto');
    });

    test('un invito non accettato e ancora valido è in attesa', () => {
        expect(statoInvito(null, '2026-10-09T12:00:00Z', adesso)).toBe('in_attesa');
    });

    test('senza una scadenza un invito non accettato è in attesa', () => {
        expect(statoInvito(undefined, null, adesso)).toBe('in_attesa');
    });
});
