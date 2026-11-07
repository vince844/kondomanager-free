import { describe, expect, test } from 'vitest';
import { usePaymentDistribution } from './usePaymentDistribution';
import type { Rata } from '@/types/gestionale/rata';

/**
 * Il riparto automatico del modulo dell'incasso riempie le righe nell'ordine in cui le manda il server (1.12.0-beta.3, decisione 218):
 * per scadenza, emesse o no; a pari scadenza la rata 0, poi le emesse, poi le bozze; poi l'id della rata. Il browser non riordina: prima
 * ordinava per data e, a pari data, per l'id della **quota**, e a pari scadenza pagava per prima la riga con l'id più basso, che poteva
 * essere una bozza davanti alla rata 0. Il filtro per gestione toglie righe e lascia l'ordine.
 */

const riga = (id: number, gestione: number, residuo: number, scadenza: string, extra: Partial<Rata> = {}) => ({
    id, gestione_id: gestione, residuo, da_pagare: 0, selezionata: false, descrizione: `Quota ${id}`,
    scadenza_human: scadenza.split('-').reverse().join('/'), data_scadenza: null, scaduta: false, ...extra,
}) as unknown as Rata;

// Tre righe del 05/02 nell'ordine del server: la rata 0 (quota 30), la rata emessa (quota 10), la bozza (quota 20); poi una del 05/01 di un'altra
// gestione che il server, per ipotesi, manda in fondo: il browser non la sposta.
const dalServer = () => [
    riga(30, 1, 50, '2026-02-05', { pregresso: true, ordine: 1 }),
    riga(10, 1, 100, '2026-02-05', { is_emitted: true, ordine: 2 }),
    riga(20, 1, 100, '2026-02-05', { is_emitted: false, ordine: 3 }),
    riga(40, 2, 80, '2026-01-05', { ordine: 4 }),
];

describe('l\'ordine del server', () => {
    test('la lista resta nell\'ordine ricevuto, anche con date e id che direbbero altro', () => {
        const { rawRateList, getRateListByGestione } = usePaymentDistribution();
        rawRateList.value = dalServer();

        expect(getRateListByGestione(null).map((r) => r.id)).toEqual([30, 10, 20, 40]);
        expect(getRateListByGestione(1).map((r) => r.id)).toEqual([30, 10, 20]);
    });

    test('il riparto automatico paga per prima la rata 0, poi l\'emessa, poi la bozza', () => {
        const { rawRateList, getRateListByGestione, distributeGreedy } = usePaymentDistribution();
        rawRateList.value = dalServer();
        const lista = getRateListByGestione(1);

        const avanzo = distributeGreedy(lista, 120);

        expect(lista.map((r) => [r.id, r.da_pagare])).toEqual([[30, 50], [10, 70], [20, 0]]);
        expect(avanzo).toBe(0);
    });

    test('la lista è una copia: riempirla non tocca le righe ricevute', () => {
        const { rawRateList, getRateListByGestione, distributeGreedy } = usePaymentDistribution();
        rawRateList.value = dalServer();

        distributeGreedy(getRateListByGestione(null), 1000);

        expect(rawRateList.value.every((r) => r.da_pagare === 0)).toBe(true);
    });
});
