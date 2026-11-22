// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { OpzioniDelGesto } from './coda';
import type { VoceInCoda } from './deposito';

/**
 * Chi scrive il testo del verbale nella coda (decisioni 514 (4) e (5), 519 (2)): fra l'editor e la coda dei gesti, per la pagina del verbale e per la
 * discussione nella sala.
 *
 * - **N1 · Ogni lettera sul disco**: a ogni cambio dell'editor la parte (la struttura, la `base`, l'ora) si scrive nel deposito, **al più ogni
 *   {@link SUL_DISCO_OGNI_MS} millisecondi** per parte: la prima lettera subito, le altre al più tardi dopo quel tempo. Il gesto ha la chiave di fusione
 *   `testo:<parte>`, così sul disco c'è un solo gesto in attesa per parte, l'ultimo (N2). Spegnendo di colpo si perde al massimo l'ultimo istante.
 * - **N3 · Quando parte**: {@link PAUSA_MS} dopo l'ultima lettera, e **almeno ogni {@link ALMENO_OGNI_MS} millisecondi** mentre si scrive. Il ritorno della
 *   rete e la riapertura della pagina li fa partire chi usa lo scrittore (la coda li rimanda da sé). Prima di partire, ciò che è ancora in memoria va sul disco.
 * - **Chiudendo la pagina** ({@link ScrittoreDelTesto.sulDisco}) ciò che è in memoria va sul disco subito.
 *
 * Nessun timer del browser fuori da qui: chi lo usa lo ferma con {@link ScrittoreDelTesto.ferma}.
 */

/** Ogni quanto, al più, una parte si riscrive sul disco mentre si scrive. */
export const SUL_DISCO_OGNI_MS = 300;

/** Quanto aspetta dopo l'ultima lettera prima di partire: «pochi secondi». */
export const PAUSA_MS = 3000;

/** Mentre si scrive, il testo parte almeno così spesso. */
export const ALMENO_OGNI_MS = 30000;

export interface OpzioniDelloScrittore {
    /** Segna il gesto `testo` nella coda di chi usa lo scrittore, con la chiave di fusione e senza farlo partire. */
    segna: (dati: Record<string, unknown>, base: number, opzioni: OpzioniDelGesto) => Promise<VoceInCoda>;
    /** Fa partire ciò che è in coda. */
    manda: () => Promise<void> | void;
    /** I dati del gesto: `{verbale_id}` (o `{seduta_id}` prima che il verbale ci sia), la parte, il documento. */
    dati: (parte: string, documento: NodoDelVerbale) => Record<string, unknown>;
    /** La revisione da cui parte il testo della parte (0 la prima volta). */
    base: (parte: string) => number;
    /** Dopo che una parte è sul disco. */
    sulDiscoFatto?: (parte: string, voce: VoceInCoda) => void;
    /** Quando parte: le parti con un testo nuovo dalla partenza di prima, con l'ora in cui sono state scritte l'ultima volta. */
    parte?: (parti: { parte: string; il: string }[]) => void | Promise<void>;
    /**
     * La chiave di fusione della parte (di serie `testo:<parte>`). Con la scheda del browser dentro (`testo:<scheda>:<parte>`) due schede dello stesso browser
     * non si sostituiscono il testo in coda: partono tutte e due, e il server dice le due versioni.
     */
    fondi?: (parte: string) => string;
    adesso?: () => number;
    sulDiscoOgniMs?: number;
    pausaMs?: number;
    almenoOgniMs?: number;
}

type Timer = ReturnType<typeof setTimeout>;

export class ScrittoreDelTesto {
    /** Il testo in memoria non ancora sul disco, per parte. */
    private inMemoria = new Map<string, { documento: NodoDelVerbale; il: number }>();
    /** Quando ogni parte è andata sul disco l'ultima volta. */
    private ultimaSulDisco = new Map<string, number>();
    private timerDelDisco = new Map<string, Timer>();
    /** Le parti scritte dalla partenza di prima, con l'ora dell'ultima lettera. */
    private daMandare = new Map<string, number>();
    private pausa: Timer | null = null;
    private almeno: Timer | null = null;
    private scritture = new Map<string, Promise<void>>();
    private readonly adesso: () => number;

    constructor(private readonly o: OpzioniDelloScrittore) {
        this.adesso = o.adesso ?? (() => Date.now());
    }

    /** L'editor è cambiato: la parte va sul disco al più tardi fra {@link SUL_DISCO_OGNI_MS}, e parte dopo la pausa. */
    scrivi(parte: string, documento: NodoDelVerbale): void {
        const ora = this.adesso();
        this.inMemoria.set(parte, { documento, il: ora });
        this.daMandare.set(parte, ora);
        if (!this.timerDelDisco.has(parte)) {
            const passati = ora - (this.ultimaSulDisco.get(parte) ?? -Infinity);
            const tra = Math.max(0, (this.o.sulDiscoOgniMs ?? SUL_DISCO_OGNI_MS) - passati);
            if (tra === 0) {
                void this.scriviSulDisco(parte).catch(() => undefined);
            } else {
                this.timerDelDisco.set(parte, setTimeout(() => {
                    this.timerDelDisco.delete(parte);
                    void this.scriviSulDisco(parte).catch(() => undefined);
                }, tra));
            }
        }
        if (this.pausa) clearTimeout(this.pausa);
        this.pausa = setTimeout(() => void this.mandaOra(), this.o.pausaMs ?? PAUSA_MS);
        this.almeno ??= setTimeout(() => void this.mandaOra(), this.o.almenoOgniMs ?? ALMENO_OGNI_MS);
    }

    /** C'è un testo scritto che non è ancora partito (in memoria o sul disco). */
    get haDaMandare(): boolean {
        return this.daMandare.size > 0 || this.inMemoria.size > 0;
    }

    /** Il testo di una parte ancora in memoria (non sul disco), o null. */
    inScrittura(parte: string): NodoDelVerbale | null {
        return this.inMemoria.get(parte)?.documento ?? null;
    }

    /** Tutto ciò che è in memoria va sul disco adesso (la pagina che si chiude, la scheda nascosta, prima di partire). */
    async sulDisco(): Promise<void> {
        for (const [parte, t] of this.timerDelDisco) {
            clearTimeout(t);
            this.timerDelDisco.delete(parte);
        }
        // Un disco che non prende il testo non ferma niente: il testo resta in memoria, e ci si riprova.
        await Promise.all([...this.inMemoria.keys()].map((parte) => this.scriviSulDisco(parte).catch(() => undefined)));
        await Promise.all([...this.scritture.values()]);
    }

    /** Parte adesso: ciò che è in memoria va sul disco, poi la coda manda. */
    async mandaOra(): Promise<void> {
        if (this.pausa) clearTimeout(this.pausa);
        if (this.almeno) clearTimeout(this.almeno);
        this.pausa = null;
        this.almeno = null;
        await this.sulDisco();
        if (this.daMandare.size > 0) {
            const parti = [...this.daMandare].map(([parte, il]) => ({ parte, il: new Date(il).toISOString() }));
            this.daMandare.clear();
            await this.o.parte?.(parti);
        }
        await this.o.manda();
    }

    /** Ferma i timer (la pagina che si smonta): ciò che è in memoria va prima sul disco. */
    async ferma(): Promise<void> {
        if (this.pausa) clearTimeout(this.pausa);
        if (this.almeno) clearTimeout(this.almeno);
        this.pausa = null;
        this.almeno = null;
        await this.sulDisco();
    }

    /** Una parte sul disco: le scritture della stessa parte una dopo l'altra, l'ultima vince. */
    private scriviSulDisco(parte: string): Promise<void> {
        const prima = this.scritture.get(parte) ?? Promise.resolve();
        const questa = prima.then(async () => {
            const t = this.inMemoria.get(parte);
            if (!t) return;
            this.inMemoria.delete(parte);
            this.ultimaSulDisco.set(parte, this.adesso());
            let voce: VoceInCoda;
            try {
                voce = await this.o.segna({ ...this.o.dati(parte, t.documento) }, this.o.base(parte), { fondi: this.o.fondi?.(parte) ?? 'testo:' + parte, parti: false });
            } catch (e) {
                // Il disco non ha preso il testo: resta in memoria (se intanto non ce n'è uno più nuovo), e si riprova alla prossima lettera o alla partenza.
                if (!this.inMemoria.has(parte)) this.inMemoria.set(parte, t);
                throw e;
            }
            this.o.sulDiscoFatto?.(parte, voce);
        });
        const dopo = questa.catch(() => undefined);
        this.scritture.set(parte, dopo);
        void dopo.then(() => {
            if (this.scritture.get(parte) === dopo) this.scritture.delete(parte);
        });

        return questa;
    }
}
