// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La riga dei campi nella finestra delle lettere nasce dalla lista del server (`TestiLettere::CAMPI`, che la finestra riceve con le opzioni): nomina
 * tutti i campi che il testo può usare, non una copia scritta a mano che ne dimentica qualcuno.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';
import { fraseDeiCampi } from './lettere';

const sorgente = (f: string) => fs.readFileSync(f, 'utf8');

/** I campi di `TestiLettere::CAMPI`, letti dal file del server, nel loro ordine, con la descrizione. */
function campiDelServer(): Record<string, string> {
    const php = sorgente('app/Services/Gestionale/Lettere/TestiLettere.php');
    const blocco = php.match(/public const CAMPI = \[([\s\S]*?)\];/);
    expect(blocco, 'TestiLettere::CAMPI').not.toBeNull();

    return Object.fromEntries([...blocco![1].matchAll(/'(\[[a-z_]+\])'\s*=>\s*'((?:[^'\\]|\\.)*)'/g)].map((m) => [m[1], m[2]]));
}

describe('i campi delle lettere', () => {
    test('la frase nomina tutti i campi del server, nel loro ordine', () => {
        const campi = campiDelServer();
        expect(Object.keys(campi)).toEqual(['[nome]', '[totale]', '[termine]', '[condominio]', '[interessi]', '[spese]', '[tasso]']);
        const frase = fraseDeiCampi(campi);
        expect(frase).toBe('Nel testo puoi usare [nome], [totale], [termine], [condominio], [interessi], [spese] e [tasso]: si riempiono per ogni persona.');
        for (const campo of Object.keys(campi)) {
            expect(frase, campo).toContain(campo);
        }
    });

    test('con un campo solo, o senza la lista, niente elenco storpiato', () => {
        expect(fraseDeiCampi({ '[nome]': 'il nome della persona' })).toBe('Nel testo puoi usare [nome]: si riempie per ogni persona.');
        expect(fraseDeiCampi(undefined)).toBeNull();
        expect(fraseDeiCampi({})).toBeNull();
    });

    test('la finestra delle lettere usa i campi delle opzioni del server, non una frase scritta a mano', () => {
        const finestra = sorgente('resources/js/components/gestionale/lettere/LettereDialog.vue');
        expect(finestra).toContain('fraseDeiCampi(opzioni.campi)');
        expect(finestra).not.toMatch(/\bFRASE_CAMPI\b/);
        // Le opzioni del server portano i campi: `EmissioneLettere::opzioni()`.
        expect(sorgente('app/Services/Gestionale/Lettere/EmissioneLettere.php').replace(/\s+/g, ' ')).toContain("'campi' => TestiLettere::CAMPI");
    });
});
