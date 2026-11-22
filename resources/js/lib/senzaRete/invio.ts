// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import axios from 'axios';

/**
 * L'invio di un lotto di gesti al server: una richiesta JSON (axios), non una visita di Inertia, perché una visita non si mette in coda né si ripete con lo
 * stesso numero. Il server risponde gesto per gesto; ogni altro esito diventa un {@link ErroreDellInvio} con il suo motivo, e **la coda non perde niente**:
 *
 * - `rete`: nessuna risposta (la rete è caduta, il server è spento);
 * - `sessione`: 401 o 419, la sessione è scaduta (dura due ore, un'assemblea può durarne di più): si rientra, e i gesti partono da soli;
 * - `permesso`: 403, chi è collegato non gestisce le assemblee;
 * - `forma`: 422, un gesto non ha la forma giusta (con gli errori per gesto, `gesti.3.dati`);
 * - `server`: un 5xx, o una risposta che non è quella dei gesti.
 */
export type MotivoDellErrore = 'rete' | 'sessione' | 'permesso' | 'forma' | 'server';

export class ErroreDellInvio extends Error {
    constructor(
        public readonly motivo: MotivoDellErrore,
        public readonly stato: number | null = null,
        public readonly errori: Record<string, string[]> = {},
    ) {
        super('Invio dei gesti: ' + motivo + (stato !== null ? ' (' + stato + ')' : ''));
    }
}

export interface GestoDaMandare {
    gesto: string;
    tipo: string;
    dati: Record<string, unknown>;
    base: number | null;
    orologio: string;
    segnato_il: string;
}

export interface LottoDaMandare {
    dispositivo: string;
    gesti: GestoDaMandare[];
}

export interface RispostaDelServer {
    ora_del_server: string;
    risposte: { gesto: string; tipo: string; esito: string; messaggio: string | null; [altro: string]: unknown }[];
}

/** Il motivo di un errore dallo stato HTTP (null: nessuna risposta). */
export function motivoDallo(stato: number | null): MotivoDellErrore {
    if (stato === null || stato === 0) return 'rete';
    if (stato === 401 || stato === 419) return 'sessione';
    if (stato === 403) return 'permesso';
    if (stato === 422) return 'forma';

    return 'server';
}

type Postino = { post: (indirizzo: string, corpo: unknown, opzioni: Record<string, unknown>) => Promise<{ status: number; data: unknown }> };

/** La funzione che manda un lotto all'indirizzo dei gesti della sala (`sala.rotte.gesti`). */
export function inviaA(indirizzo: string, postino: Postino = axios as unknown as Postino, attesaMs = 20000): (lotto: LottoDaMandare) => Promise<RispostaDelServer> {
    return async (lotto) => {
        let risposta: { status: number; data: unknown };
        try {
            risposta = await postino.post(indirizzo, lotto, { headers: { Accept: 'application/json' }, timeout: attesaMs });
        } catch (e: any) {
            const stato: number | null = e?.response?.status ?? null;
            throw new ErroreDellInvio(motivoDallo(stato), stato, stato === 422 ? (e.response.data?.errors ?? {}) : {});
        }
        const dati = risposta.data as Partial<RispostaDelServer> | undefined;
        if (!dati || !Array.isArray(dati.risposte) || typeof dati.ora_del_server !== 'string') {
            throw new ErroreDellInvio('server', risposta.status);
        }

        return dati as RispostaDelServer;
    };
}
