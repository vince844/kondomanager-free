/**
 * Il bollino di stato di una riga dell'estratto conto del condòmino (1.11.0-beta.49, decisione 75.5).
 *
 * Il controller (`EstrattoContoAnagraficaController`) manda `stornata` sulla riga della rettifica di uno storno e, dalla .49, anche
 * sulla riga dell'incasso o della compensazione stornati. La pagina conosceva solo `stornato`, e il bollino usciva come un
 * riquadro vuoto. Le etichette sono quelle della stampa (`estratto_conto_anagrafica.blade.php`, `$statoLabels`): pagina e stampa
 * dicono la stessa cosa. La pagina sceglie colore e icona dal `tono`.
 */

export type TonoStato = 'verde' | 'ambra' | 'rosso' | 'indaco' | 'blu' | 'grigio';

export interface StatoRigaEstratto {
    etichetta: string;
    tono: TonoStato;
}

const STATI: Record<string, StatoRigaEstratto> = {
    pagata: { etichetta: 'PAGATA', tono: 'verde' },
    parzialmente_pagata: { etichetta: 'PARZIALE', tono: 'ambra' },
    partial: { etichetta: 'PARZIALE', tono: 'ambra' },
    da_pagare: { etichetta: 'NON PAGATA', tono: 'rosso' },
    pending: { etichetta: 'NON PAGATA', tono: 'rosso' },
    credito: { etichetta: 'COMPENSATA', tono: 'indaco' },
    credito_puro: { etichetta: 'CREDITO', tono: 'blu' },
    stornata: { etichetta: 'STORNATA', tono: 'grigio' },
    stornato: { etichetta: 'STORNATA', tono: 'grigio' },
};

/** Il bollino della riga, o null: senza stato, o con uno stato che la pagina non conosce, niente bollino. */
export function statoRigaEstratto(stato: string | null | undefined): StatoRigaEstratto | null {
    if (!stato) {
        return null;
    }

    return Object.prototype.hasOwnProperty.call(STATI, stato) ? { ...STATI[stato] } : null;
}
