/**
 * I testi che l'amministratore legge nelle guide non citano numeri di beta.
 *
 * Chi aggiorna alla 1.11.0 dalla 1.10.0 non ha mai visto le beta: «prima della 1.11.0-beta.43» non gli dice niente,
 * e «prima di questa versione» non gli dice quale. Un testo che ha bisogno di una data dice che cosa il dato è
 * («registrato da una versione più vecchia») o la versione vera (la 1.10.0, la 1.11).
 *
 * Si leggono i sorgenti, come nei test delle guide, togliendo prima i commenti: nei commenti il numero della beta è
 * la memoria di chi scrive il codice e resta dov'è.
 *
 * Due guardie: le guide, una per file (e le due pagine in cui un avviso sta accanto a una guida), e poi **tutto il
 * frontend** (`.vue` e `.ts` non di test), perché le pagine hanno testi propri e copie dei messaggi del server
 * (`AnagraficheEdit.vue`, `ContiNew.vue`). Il gemello lato server è `tests/Feature/System/MessaggiSenzaNumeriDiBetaTest.php`.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const cartellaGuide = 'resources/js/components/guides';

/** Le guide, più le pagine in cui il testo di una guida sta accanto a un avviso (stesso difetto, stessa regola). */
const sorgenti = [
    ...fs.readdirSync(cartellaGuide).filter((f) => f.endsWith('.vue')).map((f) => path.join(cartellaGuide, f)),
    'resources/js/pages/gestionale/movimenti/giroconti/GirocontiList.vue',
    'resources/js/pages/gestionale/movimenti/incassi/IncassoRateList.vue',
];

/** Toglie i commenti HTML, i blocchi e le righe che cominciano con `//` o hanno ` //` dopo uno spazio (non `https://`). */
function senzaCommenti(sorgente: string): string {
    return sorgente
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|\s)\/\/.*$/gm, '$1');
}

/** Le frasi che hanno senso solo con la versione in mano: un numero di beta, o «questa versione» senza dire quale. */
const NUMERO_DI_BETA = /beta[.\s]?\d+|\d+\.\d+\.\d+-beta/i;
const VERSIONE_SENZA_NOME = /(prima di|da|dopo) questa versione/i;

/** Tutti i `.vue` e `.ts` sotto `cartella`, test e dichiarazioni di tipo esclusi. */
function tuttiISorgenti(cartella: string): string[] {
    return fs.readdirSync(cartella, { withFileTypes: true }).flatMap((voce) => {
        const percorso = path.join(cartella, voce.name);
        if (voce.isDirectory()) return tuttiISorgenti(percorso);
        return /\.(vue|ts)$/.test(voce.name) && !/\.(test|spec)\.ts$/.test(voce.name) && !voce.name.endsWith('.d.ts') ? [percorso] : [];
    });
}

function rilievi(sorgente: string): string[] {
    const trovati: string[] = [];
    senzaCommenti(sorgente).split('\n').forEach((riga, i) => {
        if (NUMERO_DI_BETA.test(riga) || VERSIONE_SENZA_NOME.test(riga)) trovati.push(`riga ${i + 1}: ${riga.trim().slice(0, 140)}`);
    });
    return trovati;
}

describe('guide e avvisi del gestionale — niente numeri di beta nei testi visibili', () => {
    test('i sorgenti da guardare ci sono (la guardia non si svuota da sola)', () => {
        expect(sorgenti.length).toBeGreaterThan(15);
        expect(sorgenti).toContain(path.join(cartellaGuide, 'PassaggioProprietaGuide.vue'));
    });

    test.each(sorgenti)('%s', (file) => {
        expect(rilievi(fs.readFileSync(file, 'utf8')), `${file} cita una beta o «questa versione»`).toEqual([]);
    });

    test('nessun altro testo del frontend cita una beta', () => {
        const trovati = tuttiISorgenti('resources/js').flatMap((file) => rilievi(fs.readFileSync(file, 'utf8')).map((r) => `${file} ${r}`));
        expect(trovati, 'testi con un numero di beta, o «questa versione» senza dire quale').toEqual([]);
    });

    test('la guardia morde: un numero di beta nel testo viene trovato, uno nel commento no', () => {
        expect(rilievi('<p>prima della 1.11.0-beta.43 il piano si fermava</p>')).toHaveLength(1);
        expect(rilievi('<p>Riconosciuto dalla beta.73 — prima la pagina vedeva solo il primo caso</p>')).toHaveLength(1);
        expect(rilievi('<p>registrato prima di questa versione</p>')).toHaveLength(1);
        expect(rilievi('<!-- 1.11.0-beta.43 -->\n// beta.55\n/* beta.50 */\n<p>registrato da una versione più vecchia</p>')).toEqual([]);
    });
});
