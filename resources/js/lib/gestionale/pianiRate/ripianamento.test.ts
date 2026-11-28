// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/** Coda 743: che cosa la pagina del nuovo piano mostra e manda del debito ereditato da ripianare (`ripianamento.ts`). Il massimo è lo scoperto, se è minore della fattura. */
import { describe, expect, test } from 'vitest';
import {
    cifraFuoriDaiLimiti, cifraIniziale, euroDaCentesimi, righeDelRipianamento, ripianamentoAltrove, ripianamentoDaInviare, ripianamentoVisibile, tabellaIniziale,
    type RipianamentoProposto,
} from './ripianamento';

const R: RipianamentoProposto = {
    compito_id: 501, fornitore: 'Idraulica Bianchi', voce: 'Ripianamento del debito verso Idraulica Bianchi', scoperto: 120000, ripianato: 0, massimo: 120000, proposto: 120000,
    limite: 'scoperto',
    gestione_id: 35, gestione: 'Ordinaria 2026', fatture: [{ id: 9, numero: 'IB-77', rata_0: 200000 }], tabelle: [{ id: 3, nome: 'Proprietà' }, { id: 4, nome: 'Scale' }],
};

describe('il debito ereditato da ripianare', () => {
    test('si vede solo sulla scheda delle fatture e sulla gestione della pregressa', () => {
        expect(ripianamentoVisibile(R, 'straordinario', 35)).toBe(true);
        expect(ripianamentoVisibile(R, 'ordinario', 35)).toBe(false);
        expect(ripianamentoVisibile(R, 'straordinario', 36)).toBe(false);
        expect(ripianamentoVisibile(null, 'straordinario', 35)).toBe(false);
    });

    test('la cifra parte dalla proposta; la tabella si propone solo se è una sola', () => {
        expect(cifraIniziale(R)).toBe('1200.00');
        expect(tabellaIniziale(R)).toBeNull();
        expect(tabellaIniziale({ ...R, tabelle: [{ id: 3, nome: 'Proprietà' }] })).toBe(3);
    });

    test('la cifra fra zero e il massimo, nelle due notazioni del campo: oltre lo scoperto i pregressi si chiederebbero due volte', () => {
        expect(cifraFuoriDaiLimiti(R, '1.200,00')).toBeNull();
        expect(cifraFuoriDaiLimiti(R, '1200.00')).toBeNull();
        expect(cifraFuoriDaiLimiti(R, '2.000,00')).toBe('Al massimo € 1.200,00: è lo scoperto di oggi, la parte dei debiti ereditati che i pregressi chiesti ai condòmini non coprono.');
        // Con la frase del server, quella: i numeri di oggi.
        expect(cifraFuoriDaiLimiti({ ...R, frase_del_massimo: 'Il debito ereditato si può mettere a rate al massimo per € 1.200,00: …' }, '2.000,00'))
            .toBe('Il debito ereditato si può mettere a rate al massimo per € 1.200,00: …');
        expect(cifraFuoriDaiLimiti(R, '')).toBe('Scrivi quanto del debito ereditato mettere a rate.');
    });

    test('lo scoperto sopra la fattura lo dice in una riga, e il massimo è la fattura', () => {
        const F = { ...R, scoperto: 250000, massimo: 200000, proposto: 200000, limite: 'fattura' as const };
        const righe = righeDelRipianamento(F);
        expect(righe[1]).toBe('Lo scoperto supera la fattura ancora a carico delle passate gestioni: si mette a rate al massimo € 2.000,00.');
        expect(righe).toHaveLength(4);
        expect(cifraFuoriDaiLimiti(F, '2.000,01')).toBe('Al massimo € 2.000,00: è la parte della fattura pregressa ancora a carico delle passate gestioni.');
        expect(euroDaCentesimi(123456789)).toBe('€ 1.234.567,89');
    });

    test('un ripianamento parziale fatto prima: la riga dice quanto è già a rate, e lo scoperto è il resto', () => {
        const righe = righeDelRipianamento({ ...R, scoperto: 60000, ripianato: 60000, massimo: 60000, proposto: 60000 });
        expect(righe[0]).toBe('Debito ereditato verso Idraulica Bianchi: lo scoperto di oggi è € 600,00; al massimo € 600,00 (fattura n. IB-77).');
        expect(righe[1]).toBe('Altri piani ne mettono già a rate € 600,00: lo scoperto è quello che resta.');
        expect(righe).toHaveLength(4);
    });

    test('lo scoperto di oggi si legge con i suoi numeri; con più debiti decide l\'assemblea', () => {
        const uno = righeDelRipianamento({ ...R, debiti: 200000, pregressi: 80000, coperto: 0, piu_debiti: false });
        expect(uno[1]).toBe('Lo scoperto di oggi: il debito (€ 2.000,00) meno i pregressi chiesti ai condòmini (€ 800,00). Il programma non vede il denaro ereditato in banca: '
            + 'se c\'è, lo scoperto vero è minore.');
        expect(uno).toHaveLength(4);
        const due = righeDelRipianamento({ ...R, debiti: 300000, pregressi: 80000, coperto: 100000, piu_debiti: true });
        expect(due[1]).toBe('Lo scoperto di oggi: i debiti ereditati (€ 3.000,00) meno i pregressi chiesti ai condòmini (€ 800,00), meno quanto è già a rate o '
            + 'coperto altrimenti (€ 1.000,00). Il programma non vede il denaro ereditato in banca: se c\'è, lo scoperto vero è minore.');
        expect(due[2]).toBe('Con più debiti ereditati il programma non sa quale coprono i pregressi: quanto mettere a rate su ogni debito lo decide l\'assemblea.');
    });

    test('in un altro esercizio il riquadro non c\'è, la pagina dice dove si prepara e il debito non viaggia', () => {
        const A = { ...R, altrove: 'Il debito ereditato verso Idraulica Bianchi è registrato nell\'esercizio «Esercizio 2026»: …' };
        expect(ripianamentoVisibile(A, 'straordinario', 35)).toBe(false);
        expect(ripianamentoAltrove(A, 'straordinario')).toBe(A.altrove);
        expect(ripianamentoAltrove(A, 'ordinario')).toBeNull();
        expect(ripianamentoAltrove(R, 'straordinario')).toBeNull();
        expect(ripianamentoDaInviare(A, true, true, '1.200,00', 3)).toBeNull();
    });

    test('viaggia con il piano solo se si vede e la spunta c\'è', () => {
        expect(ripianamentoDaInviare(R, true, true, '1.200,00', 3)).toEqual({ compito_id: 501, importo: '1.200,00', tabella_id: 3 });
        expect(ripianamentoDaInviare(R, true, false, '1.200,00', 3)).toBeNull();
        expect(ripianamentoDaInviare(R, false, true, '1.200,00', 3)).toBeNull();
    });
});
