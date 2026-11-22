// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il browser tiene il lavoro senza rete? Prima di aprire la sala il programma chiede al browser di non cancellare i dati per fare spazio
 * (`navigator.storage.persist()`) e guarda che cosa risponde:
 *
 * - **`tiene`**: il browser lo promette;
 * - **`non_promette`**: il browser tiene i dati ma non promette di non cancellarli se il disco si riempie (succede spesso: Chrome promette solo ai siti che
 *   conosce bene). Basta per la sala di una sera: una nota sopra «Apri l'assemblea», che non ferma;
 * - **`non_tiene`**: il deposito non si apre, o lo spazio è quello di una finestra privata (che cancella tutto quando si chiude). La sala lo dice prima di
 *   aprirsi e propone il foglio.
 *
 * La finestra privata non si dichiara: la si riconosce dallo spazio, molto piccolo. È una stima: per questo la sala scrive «sembra una finestra privata».
 */
export type TenutaDelBrowser = 'tiene' | 'non_promette' | 'non_tiene';

/** Sotto questo spazio il browser sembra una finestra privata: una finestra normale ha almeno qualche gigabyte. */
export const SPAZIO_DI_UNA_FINESTRA_PRIVATA = 120 * 1024 * 1024;

export interface Tenuta {
    tenuta: TenutaDelBrowser;
    /** Perché, in una riga: per la guida e per la prova a video. */
    perche: string;
}

type Spazio = Pick<StorageManager, 'persist' | 'persisted' | 'estimate'>;

/** Valuta il browser: `depositoAperto` dice se il deposito sul disco si è aperto. */
export async function valutaIlBrowser(spazio: Partial<Spazio> | undefined, depositoAperto: boolean): Promise<Tenuta> {
    if (!depositoAperto) {
        return { tenuta: 'non_tiene', perche: 'Il deposito del browser non si apre.' };
    }
    try {
        const stima = spazio?.estimate ? await spazio.estimate() : undefined;
        if (stima?.quota !== undefined && stima.quota < SPAZIO_DI_UNA_FINESTRA_PRIVATA) {
            return { tenuta: 'non_tiene', perche: 'Lo spazio del browser è quello di una finestra privata.' };
        }
        const promesso = spazio?.persist ? await spazio.persist() : false;
        if (promesso || (spazio?.persisted ? await spazio.persisted() : false)) {
            return { tenuta: 'tiene', perche: 'Il browser promette di tenere i dati.' };
        }
    } catch {
        // Un browser che non risponde a queste domande tiene i dati senza prometterlo.
    }

    return { tenuta: 'non_promette', perche: 'Il browser tiene i dati, ma non promette di non cancellarli.' };
}
