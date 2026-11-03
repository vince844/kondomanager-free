/**
 * Coda 325 — la casella della dichiarazione delle provvigioni, nelle due pagine del fornitore.
 *
 * Per l'art. 25-bis c. 2 DPR 600/1973 (dal 2027 art. 39 c. 2 D.Lgs. 33/2025) la ritenuta è commisurata al 50% delle
 * provvigioni e scende al 20% se il percipiente dichiara di avvalersi in via continuativa dell'opera di dipendenti o di
 * terzi. La casella si salva e nessun calcolo la legge (decisione 153.6: resta un'annotazione): il suo testo dice la
 * dichiarazione con le parole della legge, che la base ridotta è al 20%, e che il calcolo lo fa il regime.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const pagine = ['resources/js/pages/fornitori/FornitoriNew.vue', 'resources/js/pages/fornitori/FornitoriEdit.vue'];

function testoDellaCasella(file: string): string {
    const sorgente = fs.readFileSync(file, 'utf8');
    const m = sorgente.match(/<Label for="provvigioni_base_ridotta"[^>]*>([\s\S]*?)<\/Label>/);

    return (m?.[1] ?? '').replace(/\s+/g, ' ').trim();
}

describe('Coda 325 — la dichiarazione delle provvigioni nel modulo del fornitore', () => {
    test.each(pagine)('in %s la casella dice la base ridotta al 20%, con le parole della legge, e che il calcolo lo fa il regime', (file) => {
        const testo = testoDellaCasella(file);

        expect(testo, 'la casella non è stata trovata: il test sarebbe verde senza guardare').not.toBe('');
        expect(testo).not.toContain('50%');
        expect(testo).toContain('dell\'opera di dipendenti o di terzi');
        expect(testo).toContain('È un\'annotazione: la ritenuta la calcola il regime');
        // Il regime è nominato con le parole della voce della tendina (`TipoRitenuta::label()`, senza l'articolo di legge).
        expect(testo).toContain('«Provvigioni — base ridotta al 20% con la dichiarazione del percipiente, 4,6%»');
    });

    test('le due pagine dicono la stessa frase', () => {
        expect(testoDellaCasella(pagine[0])).toBe(testoDellaCasella(pagine[1]));
    });
});
