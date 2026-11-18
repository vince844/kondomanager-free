// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un componente Vue ha solo i suoi blocchi (`<script>`, `<template>`, `<style>`) e i commenti HTML: nessun testo fuori dai blocchi e nessuna
 * riga che apre un blocco di codice di un documento («```»). Il compilatore ignora il testo fuori dai blocchi, quindi la pagina funziona lo
 * stesso e niente lo segnala: lo dice questa guardia (Fase 5 della 1.12.0-beta.11, un testo estraneo in testa e in fondo a una pagina del
 * portale dal 23/12/2025).
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

const RADICE = join(__dirname);

function componenti(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return nome === 'node_modules' ? [] : componenti(percorso);
        return nome.endsWith('.vue') ? [percorso] : [];
    });
}

/** Il testo fuori dai blocchi di primo livello e dai commenti HTML, senza gli spazi. */
function testoFuoriDaiBlocchi(sorgente: string): string {
    return sorgente
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<(script|template|style)\b[^>]*>[\s\S]*<\/\1>/g, '')
        .trim();
}

describe('I componenti Vue non hanno testo fuori dai blocchi', () => {
    const tutti = componenti(RADICE);

    test('ci sono componenti da controllare', () => {
        expect(tutti.length).toBeGreaterThan(100);
    });

    test('nessun componente ha testo fuori dai blocchi, né una riga «```»', () => {
        const fuori = tutti
            .map((percorso) => ({ file: relative(RADICE, percorso), sorgente: readFileSync(percorso, 'utf8') }))
            .filter(({ sorgente }) => /^```/m.test(sorgente) || testoFuoriDaiBlocchi(sorgente) !== '')
            .map(({ file }) => file);

        expect(fuori).toEqual([]);
    });
});
