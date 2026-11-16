// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * (D) I bonifici segnalati rimandati con «Ricordamelo» restano nell'azione, e la frase lo dice: quanti sono rimandati e a quando («di cui 1 rimandato al
 * 27/05»); con più giorni, il primo. La rata del condòmino resta in verifica: la tesoreria non fa sollecitare chi ha detto di aver pagato.
 */
import { describe, expect, test } from 'vitest';
import { azioniConsigliate, testoDiPezzi, type DatiDelleAzioni, type Recupero } from './azioniConsigliate';
import type { SaldoPrevisto } from './saldoPrevisto';

const giornoN = (i: number) => new Date(Date.UTC(2026, 4, 20 + i)).toISOString().slice(0, 10);
const SANO: SaldoPrevisto = {
    giorni: Array.from({ length: 31 }, (_, i) => ({ giorno: giornoN(i), saldo: 100000, con_entrate: 0, uscite: 0, quante_uscite: 0, voci_uscite: [], fatture: [], entrate: 0,
        quante_entrate: 0, rate_in_scadenza: 0, quante_rate_in_scadenza: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [] })),
    primoSottoZero: null, minimoCents: 0, finaleCents: 100000, finaleConEntrateCents: 0, rateScaduteCents: 0,
} as unknown as SaldoPrevisto;
const NESSUNO: Recupero = { persone: [], servono: 0, servonoCents: 0, quante: 0, cifra: 0 };
const dati = (bonifici: DatiDelleAzioni['bonifici']): DatiDelleAzioni => ({ rateDaEmettere: [], bonifici, ritenute: { scadenze: [] }, deficit: { quanti: 0, cifra: 0 },
    esercizioId: 9, conti: [], fondi: [] });
const azioneD = (b: DatiDelleAzioni['bonifici']) => azioniConsigliate(SANO, NESSUNO, dati(b)).find((a) => a.chiave === 'bonifici');

describe('(D) i bonifici rimandati con «Ricordamelo»', () => {
    test('uno solo, rimandato: c\'è, con il giorno a cui è rimandato', () => {
        const d = azioneD({ quanti: 1, cifra: 12345, rimandati: 1, rimandatiAl: '2026-05-27', rimandatiGiorni: 1 });
        expect(d?.titolo).toBe('1 bonifico segnalato, € 123,45, rimandato al 27/05: verificalo prima di sollecitare');
        expect(testoDiPezzi(d!.frase)).toContain('Rimandato con «Ricordamelo»: nell\'Action inbox torna il 27/05.');
    });

    test('tre, di cui uno rimandato: «di cui 1 rimandato al 27/05»', () => {
        expect(azioneD({ quanti: 3, cifra: 45000, rimandati: 1, rimandatiAl: '2026-05-27', rimandatiGiorni: 1 })?.titolo)
            .toBe('3 bonifici segnalati, € 450,00, di cui 1 rimandato al 27/05: verificali prima di sollecitare');
    });

    test('due rimandati allo stesso giorno, e a giorni diversi: il primo', () => {
        expect(azioneD({ quanti: 3, cifra: 45000, rimandati: 2, rimandatiAl: '2026-05-27', rimandatiGiorni: 1 })?.titolo)
            .toBe('3 bonifici segnalati, € 450,00, di cui 2 rimandati al 27/05: verificali prima di sollecitare');
        const d = azioneD({ quanti: 3, cifra: 45000, rimandati: 2, rimandatiAl: '2026-05-27', rimandatiGiorni: 2 });
        expect(d?.titolo).toBe('3 bonifici segnalati, € 450,00, di cui 2 rimandati, il primo al 27/05: verificali prima di sollecitare');
        expect(testoDiPezzi(d!.frase)).toContain('Rimandati con «Ricordamelo»: nell\'Action inbox tornano dal 27/05.');
    });

    test('il controllo: senza rimandati la frase di prima', () => {
        const d = azioneD({ quanti: 2, cifra: 35000, rimandati: 0, rimandatiAl: null, rimandatiGiorni: 0 });
        expect(d?.titolo).toBe('2 bonifici segnalati, € 350,00: verificali prima di sollecitare');
        expect(testoDiPezzi(d!.frase)).not.toContain('Ricordamelo');
        expect(azioneD({ quanti: 1, cifra: 15337 })?.titolo).toBe('1 bonifico segnalato, € 153,37: verificalo prima di sollecitare');
    });
});
