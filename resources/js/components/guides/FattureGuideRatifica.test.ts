// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guida delle fatture dice i tre pulsanti della ratifica sulla pagina di una fattura, con un caso: «Paga per l'urgenza» (si paga subito, il motivo resta
 * sulla fattura e va nel punto e nell'avviso, la spesa si riferisce alla prima assemblea, la ratifica arriva con la delibera), «Da portare in assemblea» (un
 * promemoria, niente si paga), «Ratifica» (si lega la delibera); più «Collega la delibera» per una ratifica fatta prima. E la finestra «Paga per l'urgenza»
 * dice sotto il campo che il motivo lo leggono anche i condòmini.
 *
 * Si legge il sorgente, come nei test delle altre guide: conta la frase, non il disegno. I nomi fra virgolette basse ci sono nei componenti della pagina.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const leggi = (f: string) => fs.readFileSync(f, 'utf8').replace(/\s+/g, ' ');
const guida = leggi('resources/js/components/guides/FattureGuide.vue');
const parte = guida.slice(guida.indexOf('La pagina di una fattura: i tre pulsanti della ratifica'));

describe('FattureGuide — la pagina di una fattura: i tre pulsanti della ratifica', () => {
    test('la parte c\'è, con il caso e i tre pulsanti, e «Collega la delibera» per una ratifica fatta prima', () => {
        expect(guida).toContain('La pagina di una fattura: i tre pulsanti della ratifica');
        for (const frase of ['«Paga per l\'urgenza»', 'art. 1135 c. 2', 'Perché era urgente', 'nell\'avviso di convocazione', '«Da portare in assemblea»',
            'niente si paga', '«Ratifica»', 'la delibera', '«Collega la delibera»', 'Idraulica Bianchi']) {
            expect(parte).toContain(frase);
        }
    });

    test('dice «Sbloccata per l\'urgenza» finché la fattura non è pagata, «Pagata per l\'urgenza» dopo, come il punto proposto', () => {
        expect(parte).toContain('la fattura dice Sbloccata per l\'urgenza finché non la paghi, Pagata in parte per l\'urgenza fino al saldo, Pagata per l\'urgenza');
        expect(parte).toContain('lo stesso dice il punto proposto per la prossima assemblea');
    });

    test('i nomi dei pulsanti che la parte cita ci sono nella pagina della fattura e nelle sue finestre', () => {
        const pagina = ['resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue', 'resources/js/components/assemblee/PagaPerLUrgenza.vue',
            'resources/js/components/assemblee/PulsanteDaPortareInAssemblea.vue', 'resources/js/components/assemblee/RatificaDellaSpesa.vue'].map(leggi).join(' ');
        const nomi = [...new Set([...parte.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !/[.!?]$/.test(n));
        expect(nomi.length).toBeGreaterThanOrEqual(4);
        expect(nomi.filter((n) => !pagina.includes(n))).toEqual([]);
    });

    test('la finestra «Paga per l\'urgenza» dice sotto il campo che il motivo lo leggono anche i condòmini, nell\'avviso', () => {
        expect(leggi('resources/js/components/assemblee/PagaPerLUrgenza.vue'))
            .toContain('Resta sulla fattura e va sotto il punto dell\'ordine del giorno: lo leggono anche i condòmini, nell\'avviso di convocazione.');
    });
});
