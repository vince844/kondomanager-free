/**
 * Quando una scrittura collegata a un'altra è il suo storno (1.11.0-beta.49, voci P4 e P9 del verbale).
 *
 * La pagina della scrittura (`resources/js/pages/gestionale/movimenti/scritture/Show.vue`) ha due riquadri: «Attenzione:
 * movimento annullato» sulla scrittura che uno storno ha annullato, e «Movimento di storno» sulla scrittura che ne annulla
 * un'altra. Fino alla beta.48 comparivano per ogni figlia e per ogni padre, ma non ogni figlia annulla il padre: la compensazione
 * di un incasso valido è sua figlia (`storno_credito`), la rettifica del riallineamento dei fondi o dei crediti è figlia di una
 * scrittura che resta valida, e lo storno di un credito usato prima dell'emissione ha una figlia dell'emissione
 * (`emissione_rata`) che riporta il credito fra i crediti verso condomini.
 *
 * Il criterio è quello del registro di contabilità (`RegistroContabilitaService::stornataSql`): una figlia annulla il padre se è
 * uno degli storni di scrittura (`TipoMovimentoContabile::isStornoDiScrittura()`: giroconto, regolazione immediata, F24,
 * pagamento al fornitore e fattura lasciano l'originale «registrata»), oppure se è la rettifica viva di un padre «annullata»
 * (lo storno di un incasso o di un rimborso sigilla l'originale e tutta la sua famiglia; la rettifica del riallineamento, sigillata
 * con la compensazione, non è lei lo storno). Il server passa tipo e stato di padre e figlie (`ScritturaContabileController::show`).
 */

/** Lo specchio di `TipoMovimentoContabile::isStornoDiScrittura()` (un test lo confronta con il sorgente PHP). */
export const STORNI_DI_SCRITTURA: readonly string[] = [
    'storno_fattura',
    'storno_pagamento_fornitore',
    'storno_regolazione_immediata',
    'storno_pagamento_f24',
    'storno_giroconto',
];

export interface ScritturaCollegata {
    id?: number;
    tipo_movimento: string | null;
    stato?: string | null;
}

/** La figlia annulla il padre: è uno storno di scrittura, o la rettifica viva di un padre annullato. */
export function stornaIlPadre(figlia: ScritturaCollegata, padre: { stato?: string | null }): boolean {
    if (figlia.tipo_movimento !== null && STORNI_DI_SCRITTURA.includes(figlia.tipo_movimento)) {
        return true;
    }

    return figlia.tipo_movimento === 'rettifica' && padre.stato === 'annullata' && figlia.stato !== 'annullata';
}

/** Le figlie che il riquadro «movimento annullato» elenca: quelle che annullano la scrittura. */
export function figlieCheStornano<T extends ScritturaCollegata>(scrittura: {
    id?: number;
    tipo_movimento?: string | null;
    stato?: string | null;
    figlie?: T[] | null;
}): T[] {
    return (scrittura.figlie ?? []).filter((figlia) => stornaIlPadre(figlia, scrittura));
}

/** La scrittura è lo storno del suo padre: il riquadro «Movimento di storno». */
export function eStornoDelPadre(scrittura: ScritturaCollegata & { padre: ScritturaCollegata | null }): boolean {
    return scrittura.padre !== null && stornaIlPadre(scrittura, scrittura.padre);
}

// I nomi con cui li cerca la conferma dello storno degli incassi (`bannerStorno.ts`): un modulo solo, due vocabolari.
export { figlieCheStornano as figlieCheLaStornano, eStornoDelPadre as eUnoStorno };
