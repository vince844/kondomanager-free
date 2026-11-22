// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La struttura del testo del verbale nel browser: le stesse regole del server (`StrutturaDelVerbale`), sullo stesso file di casi
 * (`tests/Fixtures/verbale/struttura.json`, che legge anche `StrutturaDelVerbaleTest`), con la stessa forma canonica, lo stesso testo semplice e la stessa
 * impronta. L'impronta si calcola senza `crypto.subtle`: una pagina servita senza https (il server dell'ufficio) non lo ha.
 *
 * Che cosa non copre: l'editor (i suoi test sono della pagina del verbale).
 */
import { describe, expect, test } from 'vitest';
import casi from '../../../../../tests/Fixtures/verbale/struttura.json';
import {
    BYTE_MASSIMI, NODI_MASSIMI, PROFONDITA_MASSIMA, blocchi, canonico, caratteri, datiDellaSala, documento, errore, impronta, paragrafo, sha256, testo, titolo, valida,
} from './struttura';

type Caso = { nome: string; ostile: boolean; documento: unknown; canonico?: string; testo?: string; caratteri?: number; impronta?: string; errore?: string };
const validi = (casi.validi as Caso[]).map((c) => [c.nome, c] as const);
const rifiutati = (casi.rifiutati as Caso[]).map((c) => [c.nome, c] as const);

describe('la struttura del verbale è quella del server', () => {
    test('i limiti', () => {
        expect([BYTE_MASSIMI, PROFONDITA_MASSIMA, NODI_MASSIMI]).toEqual([casi.limiti.byte, casi.limiti.profondita, casi.limiti.nodi]);
    });

    test.each(validi)('valido: %s', (_n, c) => {
        const esito = valida(c.documento);
        expect(esito.ok).toBe(true);
        if (!esito.ok) return;
        expect(canonico(esito.documento)).toBe(c.canonico);
        expect(testo(esito.documento)).toBe(c.testo);
        expect(caratteri(esito.documento)).toBe(c.caratteri);
        expect(impronta(esito.documento)).toBe(c.impronta);
        const ancora = valida(esito.documento);
        expect(ancora.ok && canonico(ancora.documento)).toBe(c.canonico);
    });

    test.each(rifiutati)('rifiutato: %s', (_n, c) => {
        const esito = valida(c.documento);
        expect(esito.ok).toBe(false);
        expect(errore(c.documento)).toBe(c.errore);
    });

    test('i casi ostili ci sono tutti', () => {
        expect(rifiutati.map(([n]) => n)).toEqual(expect.arrayContaining(['ostile: un\'immagine', 'ostile: un attributo onclick', 'ostile: un collegamento',
            'ostile: l\'HTML al posto della struttura']));
        expect(validi.map(([n]) => n)).toContain('ostile: <script> nel testo resta testo');
    });

    test('un testo oltre 1 MB si rifiuta', () => {
        const lungo = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'a'.repeat(BYTE_MASSIMI) }] }] };
        expect(errore(lungo)).toBe('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
    });

    test('lo SHA-256 è quello di sempre', () => {
        expect(sha256('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
        expect(sha256('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
        expect(sha256('Città € 😀')).toBe('7f507bde9627e4a16276ee2dc6a80ec532ec131b44e92c9df8be3c831c1ce891');
        expect(sha256('x'.repeat(1000))).toBe('44f8354494a5ba03ba1792a8d3e9c534c47a9181980fde7a3f44b06ef2ae7c7f');
    });

    test('i mattoni sono quelli del server: il paragrafo, il titolo, la parte «Dati della sala»', () => {
        const d = documento([titolo('4. Sostituzione del portone'), paragrafo('Il sig. Marino dichiara di essere il titolare della prima impresa.'),
            datiDellaSala('voto:7', ['Favorevoli: 9 condòmini per 506,40 millesimi.', 'Contrari: 2 condòmini per 14,60 millesimi.']), paragrafo('')]);
        const v = valida(d);
        expect(v.ok && canonico(v.documento)).toBe(canonico(d));
        expect(testo(d)).toBe('4. Sostituzione del portone\nIl sig. Marino dichiara di essere il titolare della prima impresa.\n'
            + 'Favorevoli: 9 condòmini per 506,40 millesimi.\nContrari: 2 condòmini per 14,60 millesimi.\n');
        expect(blocchi(d)).toEqual({ 'voto:7': 'Favorevoli: 9 condòmini per 506,40 millesimi.\nContrari: 2 condòmini per 14,60 millesimi.' });
    });
});
