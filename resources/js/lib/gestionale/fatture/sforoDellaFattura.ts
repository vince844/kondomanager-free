// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La giustificazione dello sforo che la pagina di registrazione manda con la fattura (`dati_extra.override_budget`), in centesimi.
 *
 * Coda 693 (d), decisione 532. Due parti possono chiederla:
 * - **l'imprevisto urgente**: la riga «Fuori preventivo» con «Intervento d'urgenza» (art. 1135 c.c.), giustificata nella finestra «Configura nuova voce di
 *   spesa»; la sua strategia resta anche nei dati della finestra (`log_legale_sopravvenienza`), che il server legge per la copertura dal fondo;
 * - **le voci del preventivo** che un'altra riga sfora, giustificate nella finestra dello sforo.
 *
 * Fino alla 1.12.0-beta.11 la prima chiudeva la porta alla seconda: con l'urgenza la finestra dello sforo non si apriva più, e lo sforo registrato era
 * quello del solo imprevisto. Con le due, lo sforo è di tutta la fattura, la strategia e il fondo sono quelli scelti per le voci (l'imprevisto ha i suoi
 * nella finestra «Fuori preventivo») e la motivazione tiene le due. Il server toglie la parte dell'imprevisto dalla copertura delle voci
 * (`FatturaPassivaService::registraFattura`).
 */

export type StrategiaDelloSforo = 'conguaglio_fine_anno' | 'rata_integrativa' | 'fondo_riserva';

export interface ImprevistoUrgente {
    motivazione: string;
    importoCents: number;
    strategia: StrategiaDelloSforo;
    fondoId: number | null;
}

export interface SforoDelleVoci {
    motivazione: string;
    importoCents: number;
    strategia: StrategiaDelloSforo;
    fondoId: number | null;
    timestamp: string;
}

/** L'imprevisto urgente dai dati della finestra «Fuori preventivo», con la sua parte comune; `null` con la delibera o senza dati. */
export const imprevistoUrgente = (finestra: Record<string, any> | null | undefined, parteComuneCents: number): ImprevistoUrgente | null => {
    if (!finestra || !finestra.is_ordinario) return null;

    return {
        motivazione: String(finestra.motivazione_sforo ?? ''),
        importoCents: parteComuneCents,
        strategia: finestra.strategia_rientro as StrategiaDelloSforo,
        fondoId: finestra.fondo_patrimoniale_id ?? null,
    };
};

/** La giustificazione da mandare, o `null` se non c'è niente da giustificare. */
export const giustificazioneDelloSforo = (voci: SforoDelleVoci | null, imprevisto: ImprevistoUrgente | null): Record<string, unknown> | null => {
    if (voci) {
        return {
            motivazione: imprevisto ? `${imprevisto.motivazione} — ${voci.motivazione}` : voci.motivazione,
            importo_sforo: (imprevisto?.importoCents ?? 0) + voci.importoCents,
            budget_residuo_al_momento: -voci.importoCents,
            timestamp: voci.timestamp,
            strategia_rientro: voci.strategia,
            fondo_patrimoniale_id: voci.fondoId,
        };
    }
    if (imprevisto) {
        return {
            motivazione: imprevisto.motivazione,
            importo_sforo: imprevisto.importoCents,
            strategia_rientro: imprevisto.strategia,
            fondo_patrimoniale_id: imprevisto.fondoId,
        };
    }

    return null;
};
