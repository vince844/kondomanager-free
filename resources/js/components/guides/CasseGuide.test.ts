// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina di una risorsa o di un fondo («Modifica risorsa»), dove porta l'azione della tesoreria «Registra lo sblocco».
 *
 * - La pagina ha «Guida» nell'intestazione, come le altre, e apre questa guida nel cassetto.
 * - Aperta mostra le sue parti; chiusa non c'è. Dice che cosa vuol dire la destinazione d'uso, che cosa fa «Consenti sblocco d'emergenza» e che serve la
 *   motivazione.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`), con il numero della riga: la riga c'è e
 *   contiene quel testo.
 * - Ogni nome fra virgolette basse c'è a video nella pagina o nella tesoreria (commenti esclusi).
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const PAGINA = 'resources/js/pages/gestionale/casse/CasseEdit.vue';
const GUIDA = 'resources/js/components/guides/CasseGuide.vue';

describe('la pagina di una risorsa o di un fondo ha «Guida»', () => {
    test('l\'intestazione ha «Guida», che apre la guida della pagina nel cassetto', () => {
        const pagina = leggi(PAGINA);
        expect(pagina).toMatch(/<PageHeaderGuide[\s\S]*?has-text-guide[\s\S]*?@open-text-guide="mostraGuida = true"/);
        expect(pagina).toContain('import CasseGuide from \'@/components/guides/CasseGuide.vue\';');
        expect(pagina).toMatch(/<CasseGuide v-model:open="mostraGuida" \/>/);
    });
});

describe('la guida della risorsa o del fondo', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./CasseGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();

        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('aperta mostra le sue parti, e dice che cosa fa lo sblocco; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['Dati principali', 'La destinazione d\'uso del fondo', 'Lo sblocco d\'emergenza', 'Dalla tesoreria']) expect(t).toContain(parte);
        expect(t).toContain('Consenti sblocco d\'emergenza');
        expect(t).toContain('motivazione');
        expect(t).toContain('Registra lo sblocco');
        // Coda 754 (1): il saldo di apertura registrato sbagliato si corregge eliminando e ricreando la risorsa, e l'eliminazione storna l'apertura.
        expect(t).toContain('eliminandola, il programma storna la sua apertura nel libro giornale, e restano visibili tutte e due');
        // Rilievo 5 dello scettico della tappa 6: un fondo con un giroconto da confermare su una fattura non si elimina (`Cassa::motivoBloccoEliminazione`).
        expect(t).toContain('Un fondo che copre una fattura con il giroconto ancora da confermare non si elimina: conferma il giroconto o storna la fattura.');
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-casse"]')).toBeNull();
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(voci.length).toBeGreaterThanOrEqual(5);
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('ogni nome fra virgolette basse c\'è a video nella pagina o nella tesoreria', async () => {
        const t = await apri();
        const sorgenti = [PAGINA, 'resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts'].map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(3);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });
});
