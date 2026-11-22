// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Ciò che la pagina della sala dà ai suoi pezzi (la barra, l'apertura, le presenze, il voto, le finestre): la sala vista da questo dispositivo (lo stato del
 * server più i gesti in coda, con le righe da segnare «Su questo computer» in `in_coda`), i gesti in coda, se il browser tiene il lavoro senza rete, il modo
 * di segnare un gesto (che dal foglio della sala porta l'ora scritta a mano), chi può gestire, i nomi delle persone. Ogni pezzo lo legge con `useSala()`.
 */
import { inject, type ComputedRef, type InjectionKey, type Ref } from 'vue';
import type { DatiDeiGesti, GestoDellaSala, TipoDiGesto } from '@/types/assemblee';
import type { SalaConLaCoda } from '@/lib/senzaRete/sala';
import type { TenutaDelBrowser } from '@/lib/senzaRete/persistenza';

export interface ContestoDellaSala {
    sala: ComputedRef<SalaConLaCoda>;
    /** I gesti segnati che il server non ha ancora risposto, nell'ordine. */
    inCoda: ComputedRef<GestoDellaSala[]>;
    /** Se il browser tiene il lavoro senza rete (`tiene`, `non_promette`, `non_tiene`); null finché non lo sa. */
    tenuta: Ref<TenutaDelBrowser | null>;
    /** «computer», «telefono», «tablet»: come si dice «Su questo computer». */
    dispositivo: 'computer' | 'telefono' | 'tablet';
    /**
     * Segna un gesto: con l'ora data (`ora`, «08:40», nel giorno `giorno` o in quello della seduta), dal foglio della sala con l'ora scritta, altrimenti con
     * l'ora di adesso.
     */
    segna: <T extends TipoDiGesto>(tipo: T, dati: DatiDeiGesti[T], opzioni?: { base?: number | null; ora?: string; giorno?: string }) => Promise<void>;
    /** Adesso, con l'orologio del server (lo scarto di questo dispositivo). */
    adesso: () => Date;
    puoGestire: ComputedRef<boolean>;
    /** «Registra dal foglio della sala»: le ore sono quelle scritte sul foglio. */
    dalFoglio: Ref<boolean>;
    /** L'ora del prossimo gesto dal foglio («19:05»). */
    oraDelFoglio: Ref<string>;
    /** L'ora del gesto che si sta per segnare, come si legge («18:42»): quella del foglio, o adesso. */
    oraDelGesto: () => string;
    nomeDi: (anagraficaId: number | null) => string;
    /**
     * La discussione di un punto cambiata nell'editor sotto il voto: va sul disco del browser al più ogni 300 millisecondi e parte dopo la pausa, con un solo
     * testo in attesa per punto (`useSalaSenzaRete.scriviLaDiscussione`, la stessa tenuta della pagina del verbale). Mai un ritardo in memoria.
     */
    scriviLaDiscussione: (puntoId: number, documento: import('@/lib/assemblee/verbale/struttura').NodoDelVerbale) => void;
}

export const CONTESTO_DELLA_SALA: InjectionKey<ContestoDellaSala> = Symbol('la sala');

export function useSala(): ContestoDellaSala {
    const c = inject(CONTESTO_DELLA_SALA);
    if (!c) throw new Error('Un pezzo della sala fuori dalla pagina della sala.');

    return c;
}
