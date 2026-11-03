/**
 * Il bollino di stato di una riga dell'estratto conto del condòmino (1.11.0-beta.49, decisione 75.5).
 *
 * Il controller (`EstrattoContoAnagraficaController`) manda `stornata` sulla riga della rettifica di uno storno e, dalla .49,
 * anche sulla riga dell'incasso stornato. La pagina (`EstrattoContoAnagrafica.vue`, `getStatoConfig`) conosceva solo
 * `stornato`: il bollino usciva come un riquadro vuoto, perché `v-if="item.status"` lo disegnava comunque. La stampa
 * (`estratto_conto_anagrafica.blade.php`) conosce tutti e due i nomi: pagina e stampa devono dire la stessa cosa, con le
 * stesse etichette.
 *
 * La regola esce dalla pagina e sta qui; la pagina disegna il bollino solo se `statoRigaEstratto()` non è null, e sceglie
 * colore e icona dal `tono`.
 */

import { describe, expect, test } from 'vitest';
import { statoRigaEstratto } from './statoRigaEstratto';

describe('statoRigaEstratto', () => {
    test('«stornata», come la manda il controller, ha il bollino STORNATA in grigio (prima: un riquadro vuoto)', () => {
        expect(statoRigaEstratto('stornata')).toEqual({ etichetta: 'STORNATA', tono: 'grigio' });
    });

    // Le etichette della stampa (estratto_conto_anagrafica.blade.php, `$statoLabels`), e i toni dei colori della pagina.
    test.each([
        ['pagata', 'PAGATA', 'verde'],
        ['parzialmente_pagata', 'PARZIALE', 'ambra'],
        ['partial', 'PARZIALE', 'ambra'],
        ['da_pagare', 'NON PAGATA', 'rosso'],
        ['pending', 'NON PAGATA', 'rosso'],
        ['credito', 'COMPENSATA', 'indaco'],
        ['credito_puro', 'CREDITO', 'blu'],
        ['stornata', 'STORNATA', 'grigio'],
        ['stornato', 'STORNATA', 'grigio'],
    ])('«%s» si legge «%s», come nella stampa', (stato, etichetta, tono) => {
        expect(statoRigaEstratto(stato)).toEqual({ etichetta, tono });
    });

    test('senza stato, o con uno stato che la pagina non conosce, niente bollino (non un riquadro vuoto)', () => {
        expect(statoRigaEstratto(null)).toBeNull();
        expect(statoRigaEstratto(undefined)).toBeNull();
        expect(statoRigaEstratto('')).toBeNull();
        expect(statoRigaEstratto('annullata')).toBeNull();
    });
});
