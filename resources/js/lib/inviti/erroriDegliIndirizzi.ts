// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli errori dei singoli indirizzi di un nuovo invito. Il server li mette sulle chiavi `emails.0`,
 * `emails.1`… (una per indirizzo), e la pagina mostrava solo `emails`: un indirizzo già invitato o
 * scritto male veniva rifiutato senza che a video comparisse niente (1.11.0-beta.45).
 */
export function erroriDegliIndirizzi(errori: Record<string, string | undefined>): string[] {
    return Object.entries(errori)
        .filter(([chiave, messaggio]) => /^emails\.\d+$/.test(chiave) && !!messaggio)
        .sort(([a], [b]) => Number(a.split('.')[1]) - Number(b.split('.')[1]))
        .map(([, messaggio]) => messaggio as string);
}
