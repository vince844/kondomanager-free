/**
 * `useFattureRettificabili` (Coda 165, 1.11.0-beta.36). Il test che conta è la corsa: si cambia fornitore mentre la
 * prima richiesta è in volo, e la risposta vecchia non deve mostrare le fatture dell'altro fornitore.
 */
import { expect, test, vi } from 'vitest';

const server = vi.hoisted(() => ({
    risposte: [] as Array<{ data: any[]; freno: null | Promise<void> }>,
    chiamate: [] as any[],
}));

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (_url: string, opzioni: any) => {
            server.chiamate.push(opzioni?.params);
            const r = server.risposte.shift();
            if (!r) return { data: [] };
            if (r.freno) await r.freno;
            return { data: r.data };
        }),
    },
}));

(globalThis as any).route = (name: string, params: Record<string, unknown>) => `/${name}/${params?.condominio ?? ''}`;

import { useFattureRettificabili } from './useFattureRettificabili';

const fattura = (id: number, numero: string) => ({
    id, numero_documento: numero, data_documento: '2026-01-10', totale_documento: 100000, esercizio_nome: null,
    is_pregresso: false, gia_rettificato: 0, motivo_blocco_nota: null, avviso_nota: null,
});

test('carica le candidate e passa importo e modo al server', async () => {
    server.chiamate = [];
    server.risposte.push({ freno: null, data: [fattura(1, 'FT-1')] });
    const { candidate, carica } = useFattureRettificabili();

    await carica({ condominioId: 1, fornitoreId: 3, importoCents: -30000, perCollegare: true });

    expect(candidate.value).toHaveLength(1);
    expect(server.chiamate[0]).toEqual({
        fornitore_id: 3, importo_cents: 30000, per_collegare: 1,
        nota_id: undefined, righe: undefined, numero_dichiarato: undefined, data_dichiarata: undefined,
    });
});

test('senza importo il server non riceve il parametro', async () => {
    server.chiamate = [];
    const { carica } = useFattureRettificabili();
    await carica({ condominioId: 1, fornitoreId: 3, importoCents: 0 });
    expect(server.chiamate[0].importo_cents).toBeUndefined();
});

test('senza fornitore non chiama il server e svuota', async () => {
    server.chiamate = [];
    const { candidate, carica } = useFattureRettificabili();
    candidate.value = [fattura(1, 'X')];
    await carica({ condominioId: 1, fornitoreId: null });
    expect(candidate.value).toEqual([]);
    expect(server.chiamate).toHaveLength(0);
});

test('un errore di rete lo dice, invece di sembrare «nessuna fattura»', async () => {
    const axios = (await import('axios')).default as any;
    axios.get.mockImplementationOnce(() => Promise.reject(new Error('rete giù')));
    const silenzioso = vi.spyOn(console, 'error').mockImplementation(() => {});
    const { candidate, errore, isLoading, carica } = useFattureRettificabili();

    await carica({ condominioId: 1, fornitoreId: 3 });

    expect(candidate.value).toEqual([]);
    expect(errore.value).toBe(true);
    expect(isLoading.value).toBe(false);
    silenzioso.mockRestore();
});

test('⚠️ CONTROPROVA: la risposta del fornitore di prima, arrivata tardi, non sovrascrive quella nuova', async () => {
    let sblocca: () => void = () => {};
    const freno = new Promise<void>((resolve) => { sblocca = resolve; });
    server.risposte.push({ freno, data: [fattura(1, 'DEL-FORNITORE-A')] });
    server.risposte.push({ freno: null, data: [fattura(2, 'DEL-FORNITORE-B')] });
    const { candidate, isLoading, carica } = useFattureRettificabili();

    const prima = carica({ condominioId: 1, fornitoreId: 1 });
    await carica({ condominioId: 1, fornitoreId: 2 });
    expect(candidate.value[0].numero_documento).toBe('DEL-FORNITORE-B');

    sblocca();
    await prima;
    expect(candidate.value[0].numero_documento).toBe('DEL-FORNITORE-B');
    expect(isLoading.value).toBe(false);
});

test('righe, nota e fattura dichiarata arrivano al server (R4, R8 della Fase 1-bis)', async () => {
    server.chiamate = [];
    const { carica } = useFattureRettificabili();
    await carica({
        condominioId: 1, fornitoreId: 3, notaId: 9,
        righe: [{ conto_id: 5, immobile_id: null, riduzione: 20000 }],
        numeroDichiarato: 'FT-28', dataDichiarata: '2025-12-22',
    });
    expect(server.chiamate[0]).toMatchObject({
        nota_id: 9, righe: '[{"conto_id":5,"immobile_id":null,"riduzione":20000}]',
        numero_dichiarato: 'FT-28', data_dichiarata: '2025-12-22',
    });
});
