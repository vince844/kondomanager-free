// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guida delle fatture (`FattureGuide.vue`), «Mettere a rate una spesa» (Coda 689), una prova per frase:
 * - dove sta: `LeRateDellaFattura.vue` in `FatturaShow.vue`, e la voce del menu in `DataTableRowActions.vue`;
 * - solo se resta qualcosa: `SpesaDaMettereARate::perLaFattura()` (`fuori`, `piano`);
 * - la scheda giusta: `dalle_voci` con la voce, `fuori_preventivo` con la fattura nel carrello;
 * - la delibera della gestione: `delibera_id` nell'indirizzo, per chi gestisce le assemblee.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const leggi = (f: string) => fs.readFileSync(f, 'utf8').replace(/\s+/g, ' ');
const guida = leggi('resources/js/components/guides/FattureGuide.vue');

describe('FattureGuide — mettere a rate una spesa', () => {
    test('dove sta, e solo se resta qualcosa', () => {
        expect(guida).toContain('Sulla pagina della fattura, nel riquadro «Le rate», e nel menu della riga dell\'elenco: «Metti a rate questa spesa».');
        expect(guida).toContain('C\'è solo se resta qualcosa da mettere a rate; altrimenti il riquadro dice «Nei piani della voce» e porta al piano.');
    });
    test('la scheda giusta, con tutto già scelto', () => {
        expect(guida).toContain('Apre il nuovo piano sulla scheda giusta, con tutto già scelto: una spesa sulla voce va su «Dalle voci del preventivo».');
        expect(guida).toContain('Una fattura con una riga «Fuori preventivo» va su «Spese fuori preventivo», con la fattura nel carrello.');
        // Coda 742: la voce a € 0 nel preventivo va con le fatture, e il riquadro lo dice con le parole del server.
        expect(guida).toContain('Anche una spesa su una voce che nel preventivo vale € 0: il riquadro dice «La voce … non è nel preventivo».');
        expect(leggi('app/Services/Gestionale/SpesaDaMettereARate.php')).toContain('non è nel preventivo');
        // La fattura mista e il fondo (1.12.0-beta.14): la guida dice le parole del riquadro.
        expect(guida).toContain('Se la fattura ha anche righe «Fuori preventivo» o di un\'unità, la riga sulla voce a € 0 resta al conguaglio del rendiconto.');
        expect(guida).toContain('Se la copre il fondo di riserva non si mette a rate: il riquadro dice il fondo, o che la sua cassa non c\'è più.');
        expect(leggi('app/Services/Gestionale/SpesaDaMettereARate.php')).toContain('la copertura è di un fondo che non c\\\'è più');
        expect(leggi('app/Services/Gestionale/SpesaDaMettereARate.php')).toContain('resta al conguaglio del rendiconto');
        expect(leggi('app/Services/Gestionale/SpesaDaMettereARate.php')).toContain('non si mette a rate');
    });
    test('la delibera della gestione', () => {
        expect(guida).toContain('Con la delibera della gestione già scelta, se la spesa ne ha una. Il piano nasce quando lo salvi.');
    });
    test('i nomi che la guida cita ci sono nelle pagine', () => {
        const pagine = ['resources/js/components/gestionale/movimenti/fatture/LeRateDellaFattura.vue', 'resources/js/components/gestionale/movimenti/fatture/DataTableRowActions.vue',
            'app/Services/Gestionale/SpesaDaMettereARate.php'].map(leggi).join(' ');
        for (const nome of ['Le rate', 'Metti a rate questa spesa', 'Nei piani della voce', 'Apri il piano']) {
            expect(pagine).toContain(nome);
        }
    });
});
