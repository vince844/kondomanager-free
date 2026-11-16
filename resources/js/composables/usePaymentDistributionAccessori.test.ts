import { describe, expect, test } from 'vitest';
import { usePaymentDistribution } from './usePaymentDistribution';
import type { Rata } from '@/types/gestionale/rata';

/**
 * Gli interessi e le spese addebitati alla persona, scaduti o del giorno, vengono prima del capitale nell'ordine che il server propone (art. 1194 c.c.):
 * il server li segna `in_testa`, e «Paga scadute» li paga con le rate scadute, così il versamento non lascia aperta una riga che l'ordine mette davanti.
 * Uno che scade più avanti sta fra le rate, e «Paga scadute» non lo prende.
 */
const riga = (id: number, residuo: number, extra: Partial<Rata> = {}) => ({
    id, gestione_id: 1, residuo, da_pagare: 0, selezionata: false, descrizione: `Quota ${id}`, scadenza_human: '', data_scadenza: null, scaduta: false, ...extra,
}) as unknown as Rata;

describe('gli accessori nell\'incasso', () => {
    test('«Paga scadute» paga gli interessi registrati oggi, che non sono ancora scaduti, e le rate scadute; non le rate da scadere', () => {
        const { pagaScadute } = usePaymentDistribution();
        const lista = [
            riga(1, 1.18, { accessorio: true, in_testa: true, ordine: 1 }),
            riga(2, 25, { accessorio: true, in_testa: true, ordine: 2 }),
            riga(3, 300, { scaduta: true, ordine: 3 }),
            riga(4, 300, { ordine: 4 }),
        ];

        expect(pagaScadute(lista)).toBe(326.18);
        expect(lista.map((r) => r.selezionata)).toEqual([true, true, true, false]);
    });

    test('una spesa che scade il 30/09 non la prende: sta fra le rate da scadere', () => {
        const { pagaScadute } = usePaymentDistribution();
        const lista = [
            riga(1, 300, { scaduta: true, ordine: 1 }),
            riga(2, 300, { scaduta: true, ordine: 2 }),
            riga(3, 25, { accessorio: true, in_testa: false, scadenza_human: '30/09/2026', ordine: 3 }),
            riga(4, 300, { ordine: 4 }),
        ];

        expect(pagaScadute(lista)).toBe(600);
        expect(lista.map((r) => r.selezionata)).toEqual([true, true, false, false]);
    });
});
