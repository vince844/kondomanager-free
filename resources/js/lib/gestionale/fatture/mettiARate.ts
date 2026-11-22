// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 689, «Metti a rate questa spesa»: ciò che il server dice della fattura (`SpesaDaMettereARate::perLaFattura()`), in centesimi; e quando il menu della
 * riga dell'elenco offre la voce (la fattura vera, non stornata né pregressa: la scheda giusta la sceglie il server al clic).
 */
export interface SpesaDaMettereARate {
    fuori: number;
    frase: string;
    url: string | null;
    scheda: 'dalle_voci' | 'fuori_preventivo';
    piano: { nome: string; url: string } | null;
}

/** Il menu della riga offre «Metti a rate questa spesa»: una fattura (non una nota), non stornata, non pregressa. */
export function siMetteARateDallElenco(f: { tipo_documento?: string | null; stato_pagamento?: string | null; is_pregresso?: boolean | null; dati_extra?: { is_stornata?: boolean } | null }): boolean {
    return (f.tipo_documento ?? 'fattura') === 'fattura' && f.stato_pagamento !== 'stornata' && !f.is_pregresso && !f.dati_extra?.is_stornata;
}
