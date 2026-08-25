import { describe, it, expect } from 'vitest';
import { costruisciAllocazioni, quadratura, distribuisciNetting, type PendenzaSelezionata } from './netting';

/**
 * Il difetto della beta.66 e di prima: la schermata emetteva un record per documento, quindi la
 * partita doppia non quadrava mai e ogni compensazione veniva respinta con «Sbilancio rilevato».
 *
 * Queste prove esistono per una ragione precisa: **la quadratura si verifica qui, prima di spedire**.
 * Il motore la verifica anche lui e respinge — ma se ce ne accorgiamo solo lì, l'amministratore ha
 * già compilato tutto il modulo e riceve un errore che non gli dice cosa fare.
 */

const ft = (id: number, cents: number, extra: Partial<PendenzaSelezionata> = {}): PendenzaSelezionata => ({
    id, isNotaCredito: false, residuoCents: cents, importoAllocatoCents: cents, ...extra,
});

const nc = (id: number, cents: number, extra: Partial<PendenzaSelezionata> = {}): PendenzaSelezionata => ({
    id, isNotaCredito: true, residuoCents: cents, importoAllocatoCents: cents, ...extra,
});

describe('costruisciAllocazioni — la forma che il motore accetta', () => {
    it("l'esempio della specifica: fattura 1.000 + nota 200 → bonifico 800", () => {
        // È il caso scritto in docs/pagamenti_fatture.md, Decisione 1, dal 2025.
        const esito = costruisciAllocazioni([ft(42, 100000), nc(43, 20000)]);

        expect(esito.allocazioni).toEqual([
            { fattura_id: 42, tipo: 'pagamento', importo_allocato_cents: 80000 },
            { fattura_id: 42, tipo: 'compensazione', importo_allocato_cents: 20000 },
            { fattura_id: 43, tipo: 'compensazione', importo_allocato_cents: 20000 },
        ]);
        expect(esito.uscitaCassaCents).toBe(80000);
        expect(esito.compensatoCents).toBe(20000);
    });

    it('⚠️ la fattura compare due volte, ed è il punto: prima ne emetteva una sola', () => {
        const esito = costruisciAllocazioni([ft(42, 100000), nc(43, 20000)]);
        const righeSulla42 = esito.allocazioni.filter((a) => a.fattura_id === 42);

        expect(righeSulla42).toHaveLength(2);
        expect(righeSulla42.map((r) => r.tipo).sort()).toEqual(['compensazione', 'pagamento']);
    });

    it('compensazione pura: la nota copre tutto e non esce un euro', () => {
        const esito = costruisciAllocazioni([ft(42, 50000), nc(43, 50000)]);

        expect(esito.uscitaCassaCents).toBe(0);
        expect(esito.allocazioni.some((a) => a.tipo === 'pagamento')).toBe(false);
        expect(esito.allocazioni).toHaveLength(2);
    });

    it('senza note resta il pagamento semplice di sempre', () => {
        // La controprova che serve: il caso normale non deve cambiare di un centesimo.
        const esito = costruisciAllocazioni([ft(1, 30000), ft(2, 20000)]);

        expect(esito.allocazioni).toEqual([
            { fattura_id: 1, tipo: 'pagamento', importo_allocato_cents: 30000 },
            { fattura_id: 2, tipo: 'pagamento', importo_allocato_cents: 20000 },
        ]);
        expect(esito.compensatoCents).toBe(0);
    });

    it('il credito si consuma prima sulle fatture scadute', () => {
        const esito = costruisciAllocazioni([
            ft(1, 50000, { isScaduta: false, dataScadenza: '2026-12-01' }),
            ft(2, 50000, { isScaduta: true, dataScadenza: '2026-01-01' }),
            nc(9, 50000),
        ]);

        const compensata = esito.allocazioni.find((a) => a.tipo === 'compensazione' && a.fattura_id !== 9);
        expect(compensata?.fattura_id).toBe(2);
    });

    it('⚠️ una nota più grande delle fatture non genera un payload sbilanciato', () => {
        // È il caso che il pulsante produceva sempre: nota da € 800,00 su fatture per € 500,00.
        // Il credito in eccesso non si può usare qui, e resta sulla nota.
        const esito = costruisciAllocazioni([ft(1, 50000), nc(9, 80000)]);

        expect(esito.compensatoCents).toBe(50000);
        expect(esito.creditoNonUtilizzatoCents).toBe(30000);
        expect(esito.uscitaCassaCents).toBe(0);

        const suNota = esito.allocazioni.filter((a) => a.fattura_id === 9);
        expect(suNota).toHaveLength(1);
        expect(suNota[0].importo_allocato_cents).toBe(50000);
    });

    it('più note si consumano in ordine, senza spezzare l\'invariante', () => {
        const esito = costruisciAllocazioni([ft(1, 70000), nc(8, 30000), nc(9, 30000)]);

        expect(esito.compensatoCents).toBe(60000);
        expect(esito.uscitaCassaCents).toBe(10000);
        expect(esito.allocazioni.filter((a) => a.fattura_id === 8 || a.fattura_id === 9))
            .toHaveLength(2);
    });

    it('una pendenza selezionata a zero non produce righe', () => {
        const esito = costruisciAllocazioni([ft(1, 50000), ft(2, 0), nc(9, 0)]);

        expect(esito.allocazioni).toHaveLength(1);
        expect(esito.allocazioni[0].fattura_id).toBe(1);
    });
});

describe('quadratura — nessun payload parte sbilanciato', () => {
    /**
     * ⚠️ **Questa è la prova che sarebbe servita e non c'era.** La quadratura è ricalcolata con
     * l'aritmetica del motore, non con quella del modulo: se le due divergono, una è sbagliata.
     */
    const casi: Array<[string, PendenzaSelezionata[]]> = [
        ['fattura sola', [ft(1, 100000)]],
        ['fattura + nota parziale', [ft(1, 100000), nc(9, 20000)]],
        ['compensazione pura', [ft(1, 50000), nc(9, 50000)]],
        ['nota più grande delle fatture', [ft(1, 50000), nc(9, 80000)]],
        ['due fatture e due note', [ft(1, 40000), ft(2, 60000), nc(8, 30000), nc(9, 15000)]],
        ['importi dispari', [ft(1, 33333), nc(9, 11111)]],
    ];

    it.each(casi)('quadra: %s', (_nome, pendenze) => {
        const idNote = pendenze.filter((p) => p.isNotaCredito).map((p) => p.id);
        const esito = costruisciAllocazioni(pendenze);

        expect(quadratura(esito.allocazioni, idNote).sbilancio).toBe(0);
    });

    it('quadra anche con le commissioni bancarie', () => {
        const esito = costruisciAllocazioni([ft(1, 100000), nc(9, 20000)]);

        expect(quadratura(esito.allocazioni, [9], 500).sbilancio).toBe(0);
    });

    it('⚠️ e il payload vecchio NON quadrava: la prova che il difetto era reale', () => {
        // Un record per documento, la fattura tipizzata `pagamento` per intero. È esattamente ciò
        // che `syncAllocazioni()` emetteva fino alla beta.66.
        const payloadVecchio = [
            { fattura_id: 42, tipo: 'pagamento' as const, importo_allocato_cents: 100000 },
            { fattura_id: 43, tipo: 'compensazione' as const, importo_allocato_cents: 20000 },
        ];

        expect(quadratura(payloadVecchio, [43]).sbilancio).toBe(-20000);
    });
});

describe('distribuisciNetting — quanto allocare quando si preme il pulsante', () => {
    it('le fatture al residuo pieno, le note solo per quanto serve', () => {
        const importi = distribuisciNetting([ft(1, 50000), nc(9, 80000)]);

        expect(importi.get(1)).toBe(50000);
        expect(importi.get(9)).toBe(50000);
    });

    it('con credito minore del debito la nota si usa tutta', () => {
        const importi = distribuisciNetting([ft(1, 100000), nc(9, 20000)]);

        expect(importi.get(1)).toBe(100000);
        expect(importi.get(9)).toBe(20000);
    });

    it('e ciò che produce quadra sempre', () => {
        const pendenze = [ft(1, 40000), ft(2, 60000), nc(8, 70000), nc(9, 50000)];
        const importi = distribuisciNetting(pendenze);
        const conImporti = pendenze.map((p) => ({ ...p, importoAllocatoCents: importi.get(p.id) ?? 0 }));

        const esito = costruisciAllocazioni(conImporti);
        expect(quadratura(esito.allocazioni, [8, 9]).sbilancio).toBe(0);
        expect(esito.uscitaCassaCents).toBe(0); // il credito copre tutto
    });
});
