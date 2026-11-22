// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Una scheda sola manda. Due schede della stessa sala nello stesso browser leggono la stessa coda: si accordano con il lucchetto del browser
 * (`navigator.locks`), e manda chi lo prende; l'altra lascia perdere e riprova al tentativo dopo. Il numero di ogni gesto copre comunque i doppi: se due schede
 * mandassero lo stesso gesto, il server lo conterebbe una volta. Un browser senza lucchetti manda e basta.
 */
type Lucchetti = { request: (nome: string, opzioni: { ifAvailable: boolean }, lavoro: (l: unknown) => Promise<unknown>) => Promise<unknown> };

/** Fa il lavoro se il lucchetto è libero; rende `false` se lo tiene un'altra scheda. */
export async function seLaSchedaManda(nome: string, lavoro: () => Promise<void>, lucchetti: Lucchetti | undefined = (globalThis.navigator as any)?.locks): Promise<boolean> {
    if (!lucchetti) {
        await lavoro();
        return true;
    }
    let fatto = false;
    await lucchetti.request(nome, { ifAvailable: true }, async (lucchetto) => {
        if (lucchetto === null) return;
        fatto = true;
        await lavoro();
    });

    return fatto;
}
