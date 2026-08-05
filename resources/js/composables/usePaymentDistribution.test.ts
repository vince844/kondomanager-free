import { describe, expect, it } from 'vitest';
import { usePaymentDistribution } from './usePaymentDistribution';
import type { Rata } from '@/types/gestionale/rata';

/**
 * beta.43 — L'eccedenza contava il credito due volte, e l'errore usciva come pagina 500.
 *
 * `calculateExcess(rateList, importoTotale)` fa `importoTotale − somma(da_pagare)`. La somma è
 * **algebrica**: le righe di credito portano un `da_pagare` negativo, quindi il credito è già
 * scontato dentro l'allocato. Il chiamante in modalità manuale sommava però il credito anche
 * al primo argomento — `calculateExcess(lista, contante + credito)` — e l'eccedenza usciva
 * `contante + 2·credito − allocato`.
 *
 * Non era un numero storto a schermo e basta. Il server ricontrolla l'identità
 * `importo_totale === somma_algebrica + eccedenza` (`StoreIncassoRateAction`) e su
 * discordanza solleva; il controller non catturava, quindi l'amministratore si prendeva un
 * **500** e perdeva la compilazione.
 *
 * ## Ci si arrivava davvero? Sì, e vale la pena scrivere come
 *
 * In manuale il credito non viene spalmato (lo fa solo il ramo automatico), quindi con una
 * riga di credito appena selezionata `da_pagare` vale 0 e il difetto dorme. Si sveglia con
 * questa sequenza, che è quella di chi ci ripensa: **automatico** — il credito viene
 * spalmato e le righe restano con `da_pagare` negativo — poi passaggio a **manuale**, poi un
 * clic su «Usa credito» di un'altra riga, che richiama la distribuzione. Da lì il conto è
 * doppio. È la domanda «ci si arriva?» della beta.37, e la risposta sta qui invece che in
 * una conversazione.
 *
 * Il file fissa anche il **contratto** del primo argomento: è il **contante**, mai il
 * contante più il credito. È l'unica difesa contro il ritorno dello stesso errore, perché la
 * firma da sola non lo dice.
 */

let seq = 0;

function rata(residuo: number, daPagare = 0): Rata {
    seq++;

    return {
        id: seq,
        residuo,
        da_pagare: daPagare,
        selezionata: daPagare !== 0,
        descrizione: `Rata ${seq}`,
        data_scadenza: '2026-03-31',
    } as unknown as Rata;
}

describe('calculateExcess — il primo argomento è il contante, non contante+credito', () => {
    it('senza credito, l\'eccedenza è il contante che avanza dopo i debiti', () => {
        const { calculateExcess } = usePaymentDistribution();

        // 500 di contante su 300 di debiti allocati: 200 di anticipo.
        const lista = [rata(300, 300)];

        expect(calculateExcess(lista, 500)).toBe(200);
    });

    it('con il credito applicato, il contante da solo basta a dare l\'eccedenza giusta', () => {
        const { calculateExcess } = usePaymentDistribution();

        // Debito 300, coperto da 100 di contante e 200 di credito: nessun anticipo.
        const lista = [rata(300, 300), rata(-200, -200)];

        // allocato = 300 − 200 = 100, cioè il contante. Eccedenza 0.
        expect(calculateExcess(lista, 100)).toBe(0);
    });

    it('sommare il credito al contante lo conta due volte — è il difetto della beta.43', () => {
        const { calculateExcess } = usePaymentDistribution();

        const lista = [rata(300, 300), rata(-200, -200)];
        const contante = 100;
        const creditoApplicato = 200;

        // La chiamata sbagliata: 100 + 200 − (300 − 200) = 200 di anticipo inventato, su una
        // registrazione che salda esattamente il debito. È il numero che faceva scattare la
        // guardia del server e produceva il 500.
        expect(calculateExcess(lista, contante + creditoApplicato)).toBe(200);

        // La chiamata giusta, sugli stessi identici dati.
        expect(calculateExcess(lista, contante)).toBe(0);
    });

    it('l\'eccedenza non è mai negativa', () => {
        const { calculateExcess } = usePaymentDistribution();

        expect(calculateExcess([rata(300, 300)], 100)).toBe(0);
    });
});

describe('creditoNecessario — quanto credito serve davvero', () => {
    it('non impegna più credito di quanto il contante lasci scoperto', () => {
        const { creditoNecessario } = usePaymentDistribution();

        // 400 di debiti, 150 di contante: servono 250 di credito, non i 900 disponibili.
        const lista = [rata(300), rata(100), rata(-900)];

        expect(creditoNecessario(lista, 150, 900)).toBe(250);
    });

    it('con il contante che copre tutto non impegna credito', () => {
        const { creditoNecessario } = usePaymentDistribution();

        expect(creditoNecessario([rata(300), rata(-900)], 500, 900)).toBe(0);
    });

    it('non impegna più credito di quanto ne esista', () => {
        const { creditoNecessario } = usePaymentDistribution();

        expect(creditoNecessario([rata(1000), rata(-120)], 0, 120)).toBe(120);
    });

    it('è questo il numero che evita l\'anticipo inventato del ramo automatico', () => {
        // Il caso concreto: 300 di credito, zero contante, 100 di debito. Distribuendo un
        // budget di 300 il greedy ne allocava 100 e restituiva **200 di avanzo**, che
        // finivano nel campo eccedenza come anticipo — mentre quei 200 sono credito che
        // resta semplicemente dov'è. Con il credito impegnato prima, il budget è 100 e non
        // avanza niente.
        const { creditoNecessario, distributeGreedy } = usePaymentDistribution();

        const lista = [rata(100), rata(-300)];
        const impegnato = creditoNecessario(lista, 0, 300);

        expect(impegnato).toBe(100);
        expect(distributeGreedy(lista, 0 + impegnato)).toBe(0);
    });
});
