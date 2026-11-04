/**
 * Coda 466: la finestra «Pagamento Non Consentito» è la stessa nella registrazione (`PagamentoNew.vue`) e nella modifica (`PagamentoEdit.vue`), e dalla
 * Coda 466 si apre anche nella modifica. Tutte e due dicono la cifra che il server manda (`illegal_cash`), propongono solo i metodi che la pagina
 * offre (bonifico e assegno: la carta non c'è), scrivono la soglia come gli importi («€ 5.000,00») e non citano una forchetta di sanzioni. Non
 * dicono che il blocco non si aggira: guarda il metodo e non la cassa, e un'uscita dalla cassa dei contanti con un altro metodo passa (Coda 478).
 *
 * Il test legge i due sorgenti: la finestra della modifica si monta in `PagamentoEditContantiRifiutati.test.ts`; quella della registrazione
 * non ha un test che la monti. Non copre: il testo nelle altre lingue (le pagine sono solo in italiano).
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const finestra = (file: string) => {
    const sorgente = readFileSync(resolve(__dirname, file), 'utf8');
    const inizio = sorgente.indexOf('Pagamento Non Consentito');
    const fine = sorgente.indexOf('Cambio Metodo di Pagamento', inizio);
    expect(inizio).toBeGreaterThan(-1);
    expect(fine).toBeGreaterThan(inizio);

    return sorgente.slice(inizio, fine);
};

describe.each(['PagamentoNew.vue', 'PagamentoEdit.vue'])('la finestra del limite dei contanti in %s', (file) => {
    test('dice la cifra del server, i soli metodi della pagina e la soglia scritta come gli importi, senza sanzioni', () => {
        const testo = finestra(file);

        expect(testo).toContain('{{ illegalCashMsg }}');
        expect(testo).toContain('€ 5.000,00');
        expect(testo).not.toContain('5.000€');
        expect(testo).not.toMatch(/Carta di credito/i);
        expect(testo).not.toContain('50.000');
        expect(testo).not.toContain('non può essere aggirato');
    });
});
