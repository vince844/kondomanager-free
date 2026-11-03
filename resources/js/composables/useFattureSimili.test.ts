/**
 * `useFattureSimili` — il composable della decisione D4 (1.11.0-beta.13).
 *
 * ⚠️ Il test che conta è quello sulla corsa fra due richieste. È la stessa classe di difetto
 * misurata due volte in questa beta sul filtro data (Coda 111, guardia `inCorso` di
 * `useTabellaServer`): l'utente cambia fornitore o numero mentre la prima richiesta è ancora
 * in volo, ne parte una seconda, e se le risposte arrivano fuori ordine quella vecchia
 * sovrascrive quella nuova — il banner mostrerebbe un sospetto sbagliato o nessuno.
 */

import { describe, expect, test, vi } from 'vitest';

const server = vi.hoisted(() => ({
    // Ogni chiamata pesca la PRIMA voce e la consuma: permette a due richieste consecutive
    // di rispondere con payload diversi, per costruire lo scenario fuori ordine.
    risposte: [] as Array<{ data: any[]; freno: null | Promise<void> }>,
}));

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => {
            const r = server.risposte.shift();
            if (!r) return { data: [] };
            if (r.freno) await r.freno;
            return { data: r.data };
        }),
    },
}));

(globalThis as any).route = (name: string, params: Record<string, unknown>) =>
    `/${name}/${params?.condominio ?? ''}`;

import { useFattureSimili } from './useFattureSimili';

const parametriBase = {
    condominioId: 1,
    esercizioId: 1,
    fornitoreId: 1,
};

test('trova e mostra le fatture simili restituite dal server', async () => {
    server.risposte.push({
        freno: null,
        data: [{ id: 1, numero_documento: 'FT-1', data_documento: '2026-06-10', totale_documento: 1000, motivo: 'forte', is_pregresso: false }],
    });

    const { simili, cercaSimili } = useFattureSimili();
    await cercaSimili(parametriBase);

    expect(simili.value).toHaveLength(1);
    expect(simili.value[0].motivo).toBe('forte');
});

test('senza fornitore non chiama il server e svuota il risultato', async () => {
    const { simili, cercaSimili } = useFattureSimili();
    simili.value = [{ id: 1, numero_documento: 'X', data_documento: '', totale_documento: 0, motivo: 'forte', is_pregresso: false }];

    await cercaSimili({ ...parametriBase, fornitoreId: 0 });

    expect(simili.value).toHaveLength(0);
});

test('un errore di rete si comporta come "nessun sospetto", non come un blocco', async () => {
    const axios = (await import('axios')).default as any;
    axios.get.mockImplementationOnce(() => Promise.reject(new Error('rete giù')));
    const silenzioso = vi.spyOn(console, 'error').mockImplementation(() => {});

    const { simili, isLoading, cercaSimili } = useFattureSimili();
    await cercaSimili(parametriBase);

    expect(simili.value).toEqual([]);
    expect(isLoading.value).toBe(false);
    silenzioso.mockRestore();
});

test('⚠️ CONTROPROVA: una risposta vecchia arrivata in ritardo non sovrascrive quella nuova', async () => {
    let sbloccaLaPrima: () => void = () => {};
    const frenoPrima = new Promise<void>((resolve) => { sbloccaLaPrima = resolve; });

    // La PRIMA richiesta (fornitore A) resta in volo, frenata.
    server.risposte.push({
        freno: frenoPrima,
        data: [{ id: 999, numero_documento: 'VECCHIA-E-SBAGLIATA', data_documento: '', totale_documento: 0, motivo: 'forte', is_pregresso: false }],
    });
    // La SECONDA (fornitore B, l'utente ha già cambiato scelta) risponde subito.
    server.risposte.push({
        freno: null,
        data: [{ id: 1, numero_documento: 'NUOVA-E-GIUSTA', data_documento: '', totale_documento: 0, motivo: 'standard', is_pregresso: false }],
    });

    const { simili, cercaSimili } = useFattureSimili();

    const primaChiamata = cercaSimili({ ...parametriBase, fornitoreId: 10 }); // non attesa
    await cercaSimili({ ...parametriBase, fornitoreId: 20 }); // la seconda arriva e si applica

    expect(simili.value).toHaveLength(1);
    expect(simili.value[0].numero_documento).toBe('NUOVA-E-GIUSTA');

    // Ora la prima, tenuta in freno, arriva DOPO: non deve rimettere il risultato vecchio.
    sbloccaLaPrima();
    await primaChiamata;

    expect(simili.value).toHaveLength(1);
    expect(simili.value[0].numero_documento).toBe('NUOVA-E-GIUSTA');
});

test('reset() invalida anche una richiesta gia partita e in volo', async () => {
    let sblocca: () => void = () => {};
    const freno = new Promise<void>((resolve) => { sblocca = resolve; });
    server.risposte.push({
        freno,
        data: [{ id: 1, numero_documento: 'NON-DEVE-COMPARIRE', data_documento: '', totale_documento: 0, motivo: 'forte', is_pregresso: false }],
    });

    const { simili, cercaSimili, reset } = useFattureSimili();
    const chiamata = cercaSimili(parametriBase);

    reset();
    sblocca();
    await chiamata;

    expect(simili.value).toHaveLength(0);
});
