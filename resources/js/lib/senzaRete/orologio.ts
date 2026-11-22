// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'orologio del dispositivo e quello del server. La pagina riceve l'ora del server (con lo stato della sala e con ogni risposta ai gesti) e tiene lo
 * **scarto**: ogni gesto porta l'ora corretta (`segnato_il`, quella che conta) e quella del dispositivo com'era (`orologio`). Senza rete lo scarto resta
 * l'ultimo noto: un portatile con l'ora sbagliata di dieci minuti segna lo stesso le ore giuste. Il server rifiuta un'ora più avanti della sua di oltre due
 * minuti, e scrive sempre l'ora di arrivo.
 */
export class Orologio {
    /** Millisecondi da aggiungere all'ora del dispositivo per avere quella del server. */
    scarto = 0;

    constructor(private readonly adesso: () => number = () => Date.now()) {}

    /**
     * Rilegge lo scarto dall'ora del server. Con l'ora in cui la richiesta è partita, conta metà del viaggio: la risposta è stata scritta a metà strada.
     */
    aggiorna(oraDelServer: string, partitaAlle?: number): void {
        const server = Date.parse(oraDelServer);
        if (Number.isNaN(server)) return;
        const arrivata = this.adesso();
        const meta = partitaAlle === undefined ? arrivata : Math.round((partitaAlle + arrivata) / 2);
        this.scarto = server - meta;
    }

    /** L'ora corretta di adesso. */
    ora(): Date {
        return new Date(this.adesso() + this.scarto);
    }

    /** Le due ore di un gesto segnato adesso. */
    segna(): { segnato_il: string; orologio: string } {
        return { segnato_il: this.ora().toISOString(), orologio: new Date(this.adesso()).toISOString() };
    }
}
