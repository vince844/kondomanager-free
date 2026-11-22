// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa del registro delle modifiche con la finestra, e le pagine della tabella: le funzioni senza la pagina.
 *
 * - Il mese intero (anche febbraio di un anno bisestile), le scorciatoie del periodo («Questo mese», «L'esercizio», «Dall'inizio», «Scegli le date») e il
 *   periodo da cui parte la finestra, dal mese della pagina.
 * - «Al» prima di «Dal» è un errore; i parametri della stampa e del conto, senza i vuoti e senza il mese.
 * - Il riepilogo del piede («Stamperai 134 righe, dal 01/01/2026 al 31/12/2026, sulle assemblee.») e la frase oltre il limite, con le cifre all'italiana.
 * - Le parole delle pagine: «134 righe», «Pagina 2 di 7».
 *
 * Che cosa non copre: la finestra montata (`components/registroModifiche/FinestraDellaStampa.test.ts`); il conto del server (`StampaDelRegistroTest`).
 */
import { describe, expect, test } from 'vitest';
import {
    ERRORE_AL_PRIMA_DI_DAL, erroreDelPeriodo, etichettaDellEsercizio, fraseOltreIlLimite, indirizzoConParametri, meseIntero, parametriDellaStampa, periodoDi,
    periodoIniziale, riepilogoDellaStampa, scorciatoie, testoPagina, testoRighe,
} from './stampa';
import { numero } from './registro';

const contesto = { oggi: '2026-11-21', esercizio: { dal: '2026-01-01', al: '2026-12-31' }, mesi: ['2026-11', '2026-10', '2024-03'] };

describe('il periodo della stampa', () => {
    test('il mese intero, anche febbraio di un anno bisestile', () => {
        expect(meseIntero('2026-11')).toEqual({ dal: '2026-11-01', al: '2026-11-30' });
        expect(meseIntero('2028-02')).toEqual({ dal: '2028-02-01', al: '2028-02-29' });
        expect(meseIntero('2026-12')).toEqual({ dal: '2026-12-01', al: '2026-12-31' });
    });

    test('le scorciatoie: «L\'esercizio» solo con un esercizio (il registro del condominio), con gli anni dell\'esercizio', () => {
        expect(scorciatoie(contesto.esercizio).map((s) => s.nome)).toEqual(['Questo mese', 'L\'esercizio 2026', 'Dall\'inizio', 'Scegli le date']);
        expect(scorciatoie(null).map((s) => s.nome)).toEqual(['Questo mese', 'Dall\'inizio', 'Scegli le date']);
        expect(etichettaDellEsercizio({ dal: '2025-07-01', al: '2026-06-30' })).toBe('L\'esercizio 2025/2026');
    });

    test('il periodo di ogni scorciatoia: il mese di oggi, le date dell\'esercizio, dal primo mese con una riga alla fine dell\'ultimo', () => {
        expect(periodoDi('mese', contesto)).toEqual({ dal: '2026-11-01', al: '2026-11-30' });
        expect(periodoDi('esercizio', contesto)).toEqual({ dal: '2026-01-01', al: '2026-12-31' });
        expect(periodoDi('inizio', contesto)).toEqual({ dal: '2024-03-01', al: '2026-11-30' });
        expect(periodoDi('date', contesto)).toBeNull();
        expect(periodoDi('inizio', { ...contesto, mesi: [] })).toEqual({ dal: '2026-11-01', al: '2026-11-30' });
    });

    test('la finestra parte dal mese della pagina: il mese di oggi è «Questo mese», «Tutti i mesi» è «Dall\'inizio», un altro mese sono le sue date', () => {
        expect(periodoIniziale('2026-11', contesto)).toEqual({ scorciatoia: 'mese', periodo: { dal: '2026-11-01', al: '2026-11-30' } });
        expect(periodoIniziale('tutti', contesto)).toEqual({ scorciatoia: 'inizio', periodo: { dal: '2024-03-01', al: '2026-11-30' } });
        expect(periodoIniziale('2026-10', contesto)).toEqual({ scorciatoia: 'date', periodo: { dal: '2026-10-01', al: '2026-10-31' } });
    });

    test('«Al» prima di «Dal» è un errore, detto sotto «Al»; lo stesso giorno va bene', () => {
        expect(erroreDelPeriodo({ dal: '2026-06-30', al: '2026-01-01' })).toBe(ERRORE_AL_PRIMA_DI_DAL);
        expect(ERRORE_AL_PRIMA_DI_DAL).toBe('«Al» viene prima di «Dal»: scegli un giorno uguale o successivo.');
        expect(erroreDelPeriodo({ dal: '2026-06-30', al: '2026-06-30' })).toBeNull();
    });
});

describe('i parametri, il riepilogo e la frase oltre il limite', () => {
    const scelta = { chi: '', area: 'assemblee', azione: '', cerca: '  delega ', condominio: '', periodo: { dal: '2026-01-01', al: '2026-12-31' } };

    test('i parametri della stampa e del conto: i filtri scelti e il periodo, senza i vuoti; l\'indirizzo con i parametri', () => {
        expect(parametriDellaStampa(scelta)).toEqual({ area: 'assemblee', cerca: 'delega', dal: '2026-01-01', al: '2026-12-31' });
        expect(indirizzoConParametri('/stampa', { area: 'assemblee', dal: '2026-01-01' })).toBe('/stampa?area=assemblee&dal=2026-01-01');
        expect(indirizzoConParametri('/stampa?x=1', { dal: '2026-01-01' })).toBe('/stampa?x=1&dal=2026-01-01');
    });

    test('il riepilogo del piede, come la bozza', () => {
        const r = riepilogoDellaStampa({ righe: 134, periodo: scelta.periodo, area: 'assemblee' });
        expect(`${r.prima}${r.forte}${r.dopo}`).toBe('Stamperai 134 righe, dal 01/01/2026 al 31/12/2026, sulle assemblee.');
        expect(r.forte).toBe('134 righe');
        const una = riepilogoDellaStampa({ righe: 1, periodo: { dal: '2026-11-01', al: '2026-11-30' }, chi: 'Marco Galli', cerca: 'delega' });
        expect(`${una.prima}${una.forte}${una.dopo}`).toBe('Stamperai 1 riga, dal 01/11/2026 al 30/11/2026, di Marco Galli, con «delega».');
        const programma = riepilogoDellaStampa({ righe: 2, periodo: { dal: '2026-11-01', al: '2026-11-30' }, chi: 'Il programma', condominio: 'Via Roma 12' });
        expect(`${programma.prima}${programma.forte}${programma.dopo}`).toBe('Stamperai 2 righe, dal 01/11/2026 al 30/11/2026, nel condominio Via Roma 12, del programma.');
        const nessuna = riepilogoDellaStampa({ righe: 0, periodo: { dal: '2026-11-01', al: '2026-11-30' } });
        expect(`${nessuna.prima}${nessuna.forte}${nessuna.dopo}`).toBe('Stamperai 0 righe, dal 01/11/2026 al 30/11/2026.');
        const troppe = riepilogoDellaStampa({ righe: 6240, periodo: { dal: '2024-03-01', al: '2026-11-30' }, oltre: true });
        expect(`${troppe.prima}${troppe.forte}${troppe.dopo}`).toBe('6.240 righe, dal 01/03/2024 al 30/11/2026: troppe per una stampa sola.');
    });

    test('oltre il limite la frase dice quante righe e quante al più, e propone un periodo più breve', () => {
        expect(fraseOltreIlLimite({ righe: 6240, limite: 5000, scorciatoia: 'inizio', conEsercizio: true }))
            .toBe('Con «Dall\'inizio» le righe sarebbero 6.240: troppe per una stampa sola (al più 5.000). Scegli un periodo più breve, o stampa un esercizio alla volta.');
        expect(fraseOltreIlLimite({ righe: 5400, limite: 5000, scorciatoia: 'date', conEsercizio: false }))
            .toBe('Con il periodo scelto le righe sarebbero 5.400: troppe per una stampa sola (al più 5.000). Scegli un periodo più breve, per esempio un anno alla volta.');
    });
});

describe('le parole delle pagine', () => {
    test('«134 righe», «1 riga», «Pagina 2 di 7», le cifre all\'italiana anche con quattro cifre', () => {
        expect(testoRighe(134)).toBe('134 righe');
        expect(testoRighe(1)).toBe('1 riga');
        expect(testoRighe(4812)).toBe('4.812 righe');
        expect(testoPagina(2, 7)).toBe('Pagina 2 di 7');
        expect(numero(4812)).toBe('4.812');
    });
});
