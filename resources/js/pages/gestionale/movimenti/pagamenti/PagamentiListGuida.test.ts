// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'elenco dei pagamenti ai fornitori ha «Guida» con il suo cassetto e una guida sua, sul modello di quella dell'elenco delle fatture: che cosa entra, le
 * card (due sono anche filtri), la riga colonna per colonna, il menu ⋯, «modifico o storno?», le commissioni senza voce. Non la guida della pagina del
 * nuovo pagamento, che resta con le sue quattro schede.
 *
 * Che cosa non copre: il disegno del cassetto (la prova a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (f: string) => readFileSync(resolve(process.cwd(), f), 'utf8');
const pagina = leggi('resources/js/pages/gestionale/movimenti/pagamenti/PagamentiList.vue');

describe('la guida dell\'elenco dei pagamenti', () => {
    test('«Guida» nell\'intestazione apre la guida dell\'elenco, non quella del nuovo pagamento', () => {
        expect(pagina).toContain('has-text-guide');
        expect(pagina).toContain('@open-text-guide="mostraGuida = true"');
        expect(pagina).toContain('<PagamentiGuide v-model:open="mostraGuida"');
        expect(pagina).not.toContain('PagamentoFornitoreGuide');
    });

    test('la guida dell\'elenco ha le sezioni del modello delle fatture, con le parole delle colonne e del menu', () => {
        const guida = leggi('resources/js/components/guides/PagamentiGuide.vue').replace(/\s+/g, ' ');
        for (const parola of ['Cosa entra in questo elenco', 'Come si legge una riga', 'Il menu ⋯ di un pagamento', 'lo modifico o lo storno?',
            'Pagato nel mese', 'Con ritenuta d\'acconto', 'Operazioni stornate', 'Addebito', 'Originale Stornato', 'Storno Confermato', 'Vedi dettaglio',
            'Scarica distinta', 'Storna pagamento', 'Assegna la voce']) {
            expect(guida).toContain(parola);
        }
    });

    test('la guida del nuovo pagamento resta con le sue quattro schede', () => {
        const nuovo = leggi('resources/js/components/guides/PagamentoFornitoreGuide.vue');
        expect(nuovo).not.toContain('value="elenco"');
        expect(nuovo).toContain('grid-cols-4');
    });
});
