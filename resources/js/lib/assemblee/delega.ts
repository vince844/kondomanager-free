// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La delega all'amministratore in «Scegli la delibera» (decisione 622 (3), la scena 4 della bozza approvata con la decisione 623): il tetto e la scadenza
 * accanto alla delibera, «In vigore fino al 31/12/2027 · Fino a € 2.000,00». Scaduta o revocata si vede spenta, con il perché del menu.
 */
import { euro, giornoItaliano } from '@/lib/assemblee/assemblee';
import type { DeliberaDelMenu } from '@/types/assemblee';

/** «In vigore fino al 31/12/2027 · Fino a € 2.000,00»; «Fino a € 2.000,00» spenta; la frase del server se manca il tetto o l'ambito; null senza delega. */
export function fraseDellaDelega(d: DeliberaDelMenu): string | null {
    const delega = d.delega;
    if (!delega) return null;
    if (delega.tetto === null || delega.ambito === null) return `Delega: ${delega.frase}`;
    const tetto = `Fino a ${euro(delega.tetto)}`;
    if (!delega.in_vigore) return tetto;
    return delega.fino_al ? `In vigore fino al ${giornoItaliano(delega.fino_al)} · ${tetto}` : `In vigore · ${tetto}`;
}
