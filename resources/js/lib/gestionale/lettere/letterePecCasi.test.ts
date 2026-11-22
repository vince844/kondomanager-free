// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole delle lettere per PEC nei casi che contano (1.12.0-beta.13):
 *
 * - «Ultima lettera» dice lo stato della PEC: «PEC e carta» stampata con la PEC da mandare, o non partita, non dice «PEC partita»; la PEC con l'avviso di
 *   mancata consegna lo dice; la copia su carta spedita dice la spedizione.
 * - La conferma di «Manda N PEC» dice quante partono adesso, con il tetto per volta.
 * - L'avviso delle rate scadute conta le PEC da rimandare.
 * - «Pagata in parte per l'urgenza» fino al saldo.
 */
import { describe, expect, test } from 'vitest';
import { testoConfermaPec, testoPecDaRimandare, testoUltimaLettera, FRASE_PEC_SENZA_RISPOSTA } from '@/lib/gestionale/lettere/lettere';
import { parolaDellUrgenza } from '@/lib/assemblee/ratifica';

describe('«Ultima lettera» con la PEC', () => {
    test('«PEC e carta» stampata, la PEC da mandare o non partita: non dice «PEC partita»', () => {
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec_e_carta', stato: 'stampata', pec: 'da_mandare' }))
            .toBe('Diffida stampata il 20/03/2026, PEC da mandare');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec_e_carta', stato: 'stampata', pec: 'non_partita' }))
            .toBe('Diffida stampata il 20/03/2026, PEC non partita');
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-20', canale: 'pec_e_carta', stato: 'spedita', pec: 'da_mandare',
            spedizione: { canale: 'raccomandata', data: '2026-03-21', numero: '123' } })).toBe('Sollecito spedito il 21/03/2026 con raccomandata A/R n. 123, PEC da mandare');
    });

    test('la PEC partita con la copia su carta spedita dice la spedizione; la PEC in partenza; la PEC con l\'avviso di mancata consegna', () => {
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec_e_carta', stato: 'spedita', pec: 'partita',
            spedizione: { canale: 'raccomandata', data: '2026-03-21', numero: '123' } })).toBe('Diffida · PEC partita il 20/03/2026, e spedita il 21/03/2026 con raccomandata A/R n. 123');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec_e_carta', stato: 'inviata', pec: 'partita' }))
            .toBe('Diffida · PEC partita il 20/03/2026, e su carta');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec', stato: 'in_coda', pec: 'in_coda' })).toBe('Diffida · PEC in partenza dal 20/03/2026');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec', stato: 'non_partita', pec: 'non_consegnata', mancata_consegna_il: '2026-03-21' }))
            .toBe('Diffida · PEC non consegnata, avviso del gestore del 21/03/2026');
    });

    test('senza lo stato della PEC (le righe di prima), come prima', () => {
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-20', canale: 'pec', stato: 'inviata' })).toBe('Diffida · PEC partita il 20/03/2026');
    });
});

describe('le frasi della PEC', () => {
    test('la conferma dice quante partono adesso, oltre il tetto per volta', () => {
        const righe = testoConfermaPec(25, 'diffida', 'studio.costa@pec.it', 20);
        expect(righe[0]).toBe('Mandare 25 diffide per PEC?');
        expect(righe).toContain('Ne partono 20 adesso; le altre 5 con «Manda le altre», dopo.');
        expect(testoConfermaPec(3, 'diffida', 'studio.costa@pec.it', 20).join(' ')).not.toContain('Ne partono');
    });

    test('la pagina che non ha avuto la risposta dice di guardare la corrispondenza prima di rimandare', () => {
        expect(FRASE_PEC_SENZA_RISPOSTA).toBe('La pagina non ha avuto la risposta del programma: alcune PEC possono essere partite. Prima di rimandarle guarda la '
            + 'corrispondenza delle persone, o rifai l\'anteprima: a chi una PEC è già partita oggi non parte di nuovo.');
    });

    test('l\'avviso delle rate scadute conta le PEC da rimandare', () => {
        expect(testoPecDaRimandare(0)).toBeNull();
        expect(testoPecDaRimandare(1)).toBe('1 lettera per PEC non è partita, o ha l\'avviso di mancata consegna.');
        expect(testoPecDaRimandare(2)).toBe('2 lettere per PEC non sono partite, o hanno l\'avviso di mancata consegna.');
    });
});

describe('la spesa urgente pagata in parte', () => {
    test('«pagata in parte per l\'urgenza» fino al saldo, poi «pagata per l\'urgenza»', () => {
        expect(parolaDellUrgenza('parziale')).toBe('pagata in parte per l\'urgenza');
        expect(parolaDellUrgenza('pagata')).toBe('pagata per l\'urgenza');
        expect(parolaDellUrgenza('aperta')).toBe('sbloccata per l\'urgenza');
    });
});
