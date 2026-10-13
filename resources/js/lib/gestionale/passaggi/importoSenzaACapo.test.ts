/**
 * 1.11.0-beta.51, Coda 255 (locale) — `spezzaPerImporti`: una frase del server si divide in pezzi di testo e importi, perché il simbolo e
 * la cifra stiano nello stesso elemento che non va a capo (`FraseConImporti.vue`, che ne fa uno span `whitespace-nowrap` per importo).
 *
 * Il testo che il server manda non cambia (141 file di test PHP fissano «€ 1…»; `MoneyHelper::format` e le stampe non si toccano). Il file è
 * `resources/js/lib/gestionale/passaggi/importoSenzaACapo.ts`.
 *
 * Il contratto che questi test fissano (la funzione è `spezzaPerImporti(testo)`): restituisce un
 * elenco di pezzi `{ testo: string, importo: boolean }` che, uniti, rifanno la frase di partenza, parola per parola; un pezzo `importo` è la sola
 * cifra con il simbolo («€ 127,04», «€ 1.234,56»), senza il punto, la virgola o la parentesi che la seguono.
 *
 * Cosa NON copre: il componente (`FraseConImporti.test.ts`) e i punti in cui si usa (`Beta51ImportiSenzaACapo.test.ts`); l'importo negativo
 * «€ -127,04»: finché la 251-c non ha chiuso ogni punto la regola lo tollera, e a 251-c chiusa non compare più, quindi non lo fissiamo.
 */
import { describe, expect, test } from 'vitest';
import { spezzaPerImporti } from './importoSenzaACapo';

/** Gli importi, nell'ordine, della frase spezzata. */
function importiDi(testo: string): string[] {
    return spezzaPerImporti(testo).filter((p) => p.importo).map((p) => p.testo);
}

describe('importoSenzaACapo — spezzaPerImporti (Coda 255)', () => {
    test('una frase con un importo e il punto finale: tre pezzi, il punto fuori', () => {
        expect(spezzaPerImporti('Elsa riceve € 127,04.')).toEqual([
            { testo: 'Elsa riceve ', importo: false },
            { testo: '€ 127,04', importo: true },
            { testo: '.', importo: false },
        ]);
    });

    test('le migliaia con il punto: «€ 1.234,56» è un importo solo', () => {
        expect(importiDi('Restano € 1.234,56 da regolare.')).toEqual(['€ 1.234,56']);
        expect(importiDi('Fanno € 12.345.678,90 in tutto.')).toEqual(['€ 12.345.678,90']);
    });

    test('più importi nella stessa frase, nell\'ordine in cui compaiono', () => {
        expect(importiDi('Il conguaglio dà € 127,04 a Elsa e € 1.127,04 a Ugo, che restano a loro carico.')).toEqual(['€ 127,04', '€ 1.127,04']);
    });

    test('la virgola, i due punti e la parentesi che seguono la cifra restano fuori', () => {
        expect(importiDi('dà € 127,04, poi')).toEqual(['€ 127,04']);
        expect(importiDi('(€ 127,04): vedi')).toEqual(['€ 127,04']);
        expect(importiDi('credito di € 127,04; debito')).toEqual(['€ 127,04']);
    });

    test('un importo a inizio o a fine di frase, o da solo, è un pezzo', () => {
        expect(spezzaPerImporti('€ 5,00')).toEqual([{ testo: '€ 5,00', importo: true }]);
        expect(importiDi('€ 5,00 a Elsa')).toEqual(['€ 5,00']);
        expect(importiDi('a Elsa € 5,00')).toEqual(['€ 5,00']);
    });

    test('una frase senza importi è un pezzo solo, che non è un importo', () => {
        expect(spezzaPerImporti('Nessun importo qui, né 120 giorni a Ugo né il 50 %.')).toEqual([{ testo: 'Nessun importo qui, né 120 giorni a Ugo né il 50 %.', importo: false }]);
    });

    test('una cifra senza il simbolo non è un importo: «1.200,00» e «120 giorni» restano testo', () => {
        expect(importiDi('Sono 1.200,00 senza simbolo e 245 giorni')).toEqual([]);
    });

    test('«€ 127,04 a credito»: la cifra si ferma alla cifra, le parole che seguono restano fuori', () => {
        expect(spezzaPerImporti('€ 127,04 a credito')).toEqual([
            { testo: '€ 127,04', importo: true },
            { testo: ' a credito', importo: false },
        ]);
    });

    test('uniti, i pezzi rifanno la frase di partenza, parola per parola, per ogni frase del server', () => {
        const frasi = [
            'Ugo ha € 180,00 scaduti e non pagati (€ 162,00 sull\'unità, € 18,00 su «Box 12»).',
            'La parte di Elsa sull\'intero piano è € 1.208,22, e le rate in bozza valgono € 1.200,00: coprono meno dei suoi giorni.',
            'Il conguaglio non cambia: nessun importo, 120 giorni a Ugo, 245 a Elsa.',
            '',
            '€ 90,74',
            'Su «Spese generali» il già versato è di Ugo (€ 300,00) e dell\'unità (€ 200,00): la differenza è € 44,75, e vale il conguaglio.',
        ];
        for (const frase of frasi) {
            expect(spezzaPerImporti(frase).map((p) => p.testo).join('')).toBe(frase);
        }
    });
});
