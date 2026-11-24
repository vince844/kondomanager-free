// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guardia delle parole con il genere nel modulo delle assemblee (rilievo C59, decisioni 673 (5) e 692): il programma non sa se una persona è un uomo o
 * una donna, e le frasi che vanno a schermo o in stampa usano la forma senza genere («in sala dalle 19:20», «fuori dalle 19:30», «L'avviso è per le unità:
 * …», «in comproprietà», «Mai in sala», «Se resta nella lista»). La tappa 3 della 1.12.0-beta.14 le ha tolte; questa prova fallisce se una torna.
 *
 * Cerca nei file del modulo che scrivono testi: i servizi (`app/Services/Assemblee`), i controller (`app/Http/Controllers/Assemblee`), i componenti
 * (`resources/js/components/assemblee`), le pagine (`resources/js/pages/gestionale/assemblee`) e le loro librerie (`resources/js/lib/assemblee`). I
 * commenti non contano (dicono il codice, non vanno a schermo), le prove nemmeno (citano le frasi vecchie per dire che non ci sono più).
 *
 * Che cosa non copre: le forme con «chi» («chi non è convocato», «chi è convocato»), che sono quelle generali della lingua e non nominano una persona; i
 * nomi delle cose («l'entrata alle 19:20», «l'uscita», «È convocata l'assemblea»); i nomi dei ruoli di legge («delegato», «presidente», «conduttore»,
 * «proprietario»); le parole fuori dal modulo, che hanno una Coda loro.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const CARTELLE = ['app/Services/Assemblee', 'app/Http/Controllers/Assemblee', 'resources/js/components/assemblee', 'resources/js/pages/gestionale/assemblee',
    'resources/js/lib/assemblee'];
/** Le stampe del modulo: i modelli Blade `assemblea_*` (e `_assemblea_*`) di `resources/views/pdf/gestionale`, che scrivono testi per i condòmini come le pagine. */
const STAMPE = { cartella: 'resources/views/pdf/gestionale', nome: /^_?assemblea_.*\.blade\.php$/ };

/** Le forme con il genere che la tappa ha tolto, e quella che le prende il posto (per il messaggio). */
const FORME: [RegExp, string][] = [
    // Il participio della persona («entrato alle 19:20»), non il nome («l'entrata alle 19:20», «l'uscita alle 19:30»), che resta.
    [/\bentrato alle\b/i, '«in sala dalle 19:20»'],
    [/\buscito alle\b/i, '«fuori dalle 19:30»'],
    [/\brientrat[oa]\b/i, '«di nuovo in sala dalle 19:40»'],
    [/\bmai entrat[oa] in questa seduta\b/i, '«mai in sala in questa seduta»'],
    [/\bNon sono mai entrati\b/i, '«Mai in sala»'],
    [/(?:\bLei è|È) convocat[oa] per\b/, '«La convocazione la riguarda…», «L\'avviso è per le unità: …»'],
    [/\bper cui è convocat[oa]\b/i, '«I punti che la riguardano»'],
    [/ è collegat[oa], senza voto\b/i, '«è in collegamento, senza voto»'],
    [/\bcontitolare\b/i, '«in comproprietà»'],
    [/\bSe lo (?:lasci|togli)\b/, '«Se resta nella lista», «Se esce dalla lista»'],
    [/\bToglilo\b/, '«Togli dalla lista»'],
    [/\bprima segnalo entrat[oa]\b/i, '«prima segna l\'entrata»'],
    // Il participio che nomina una persona nella riga del registro e nello stato della convocazione («chi lo riceve: aggiunto Rossi», «Non più convocato»).
    [/\bchi lo riceve: (?:aggiunto|tolto)\b/i, '«aggiunta a mano: …», «tolta dalla lista: …»'],
    [/\b(?:aggiunto|tolto) (?:alla|dalla) lista\b/i, '«aggiunta alla lista», «tolta dalla lista»'],
    [/\bNon più convocat[oa]\b/i, '«Non più fra i convocati»'],
];

/** I commenti diventano spazi, tenendo gli a capo: i numeri delle righe restano quelli del file. */
const bianco = (m: string) => m.replace(/[^\n]/g, ' ');
const senzaCommenti = (s: string) => s.replace(/\{\{--[\s\S]*?--\}\}/g, bianco).replace(/<!--[\s\S]*?-->/g, bianco).replace(/\/\*[\s\S]*?\*\//g, bianco).replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1 ');

function file(cartella: string): string[] {
    const dove = resolve(process.cwd(), cartella);
    return readdirSync(dove).flatMap((nome) => {
        const percorso = join(dove, nome);
        if (statSync(percorso).isDirectory()) return file(join(cartella, nome));
        return /\.(php|vue|ts)$/.test(nome) && !/\.test\.ts$/.test(nome) ? [join(cartella, nome)] : [];
    });
}

describe('le parole senza genere del modulo delle assemblee', () => {
    test('nessuna forma con il genere tolta dalla tappa torna in un file che scrive testi', () => {
        const stampe = readdirSync(resolve(process.cwd(), STAMPE.cartella)).filter((n) => STAMPE.nome.test(n)).map((n) => join(STAMPE.cartella, n));
        expect(stampe.length).toBeGreaterThan(8);
        const tutti = [...CARTELLE.flatMap(file), ...stampe];
        expect(tutti.length).toBeGreaterThan(100);
        const trovate: string[] = [];
        for (const f of tutti) {
            const righe = senzaCommenti(readFileSync(resolve(process.cwd(), f), 'utf8')).split('\n');
            righe.forEach((riga, i) => {
                for (const [forma, posto] of FORME) {
                    if (forma.test(riga)) trovate.push(`${f}:${i + 1} ${forma} → ${posto}`);
                }
            });
        }
        expect(trovate).toEqual([]);
    });

    test('la guardia vede le forme vecchie (la prova della prova)', () => {
        const vecchie = ['Russo Anna, contitolare, presente senza voto', 'entrato alle 19:20: assente ai punti 1 e 2', 'uscito alle 19:30', 'è rientrato alle 19:40',
            'È convocato per: Int. 3, box 21.', 'Lei è convocato per il punto 5', 'Anche Russo Anna è collegato, senza voto', 'Se lo lasci', 'Toglilo dalla lista',
            'I punti per cui è convocato', 'non è mai entrato in questa seduta', 'chi lo riceve: aggiunto Rossi Mario', 'chi lo riceve: tolto Rossi Mario',
            'tolto dalla lista, conta astenuto',
            'Non più convocato'];
        for (const v of vecchie) expect(FORME.some(([forma]) => forma.test(v)), v).toBe(true);
        // Le forme con «chi», quelle generali della lingua, restano.
        for (const v of ['annullabile da chi non è convocato', 'decidono chi è convocato', 'con l\'entrata alle 19:20', 'È convocata l\'assemblea']) expect(FORME.some(([forma]) => forma.test(v)), v).toBe(false);
    });
});
