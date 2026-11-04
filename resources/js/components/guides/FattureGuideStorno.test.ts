/**
 * Coda 455 (punto 6d) — la guida delle fatture dice l'eccezione dello storno: la spesa ad personam che una rata emessa ha già chiesto.
 *
 * La guida diceva che una fattura in un piano che ha già incassato si stornava («usa lo storno»), e che lo storno si rifiutava solo dentro un
 * piano che non ha ancora incassato. Il codice (`FatturaPassiva::motivoBloccoStorno`, in coda a `motivoSpesaAdPersonamGiaChiesta`) rifiuta lo
 * storno anche con gli incassi quando la fattura ha righe su una o più unità (`righe.immobile_id`) e una rata emessa di un piano che la contiene
 * ha già chiesto quella spesa: lo storno la toglierebbe dall'unità una seconda volta, e la strada passa dal piano. Non «una sola unità»: il
 * codice guarda le righe con un'unità, una o più. La rifiutano le rotte dello storno (`Beta59313StornoFatturaTest`); qui si legge che la guida
 * lo dica.
 *
 * Si legge il sorgente, come nei test delle altre guide: conta la frase, non il disegno.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const sorgente = fs.readFileSync('resources/js/components/guides/FattureGuide.vue', 'utf8').replace(/\s+/g, ' ');

describe('FattureGuide — l\'eccezione dello storno (Coda 455, punto 6d)', () => {
    test('«È in un piano rate che ha già incassato → usa lo storno» dice l\'eccezione: righe su unità specifiche già chieste da una rata emessa', () => {
        expect(sorgente).toContain(
            'e quanto i condòmini hanno versato per quella spesa si regola con una delibera. Se però la fattura ha righe su unità specifiche (una spesa ad personam) che una rata emessa ha già chiesto, anche lo storno si rifiuta: il motivo dice la strada, che passa dal piano.',
        );
    });

    test('il paragrafo di «Storna — non consentito» dice che anche con gli incassi lo storno si rifiuta per quella spesa, e la strada', () => {
        expect(sorgente).toContain(
            'Con gli incassi lo storno resta possibile, tranne un caso: la fattura con righe su unità specifiche (una spesa ad personam) che una rata emessa ha già chiesto non si storna nemmeno così, perché lo storno toglierebbe la spesa dall\'unità una seconda volta. Si risale dal piano come dice il motivo: di norma si stornano gli incassi delle sue rate, si annullano le emissioni, si riporta il piano in bozza e lo si elimina; poi si storna la fattura.',
        );
    });

    test('non dice «una sola unità»: il codice guarda le righe con un\'unità, una o più', () => {
        expect(sorgente).not.toMatch(/una sola unità/i);
    });

    test('controprova: le altre frasi della scala non cambiano', () => {
        expect(sorgente).toContain('<strong>È in un piano rate con rate emesse, ma senza incassi</strong> → annulla le emissioni di quel piano e riportalo in bozza.');
        expect(sorgente).toContain('<strong>«Storna — non consentito»</strong> segue la stessa scala. Una fattura dentro un piano rate che non ha ancora incassato non si storna: le rate del piano la chiederebbero ancora.');
        expect(sorgente).toContain('poi si storna la fattura, e le altre fatture del piano tornano disponibili per un piano nuovo.');
        expect(sorgente).toContain('Il motivo che leggi è esattamente quello che applicherebbe il sistema se provassi: non è una previsione, è la stessa regola.');
    });
});
