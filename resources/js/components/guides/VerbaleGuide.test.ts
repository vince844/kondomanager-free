// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina «Il verbale»: la pagina ha «Guida» nell'intestazione, che apre questa guida nel cassetto.
 *
 * - Aperta mostra le sue parti, con le regole e l'articolo; chiusa non c'è.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo.
 * - Ogni nome fra virgolette basse c'è a video nelle pagine e nei pezzi del verbale (commenti esclusi).
 * - A schema: nessuna voce è un paragrafo lungo, e niente scritte sotto i 12 pixel.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const GUIDA = 'resources/js/components/guides/VerbaleGuide.vue';
const PAGINA = 'resources/js/pages/gestionale/assemblee/Verbale.vue';
const SORGENTI = ['resources/js/pages/gestionale/assemblee/Verbale.vue', 'resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue', 'resources/js/pages/impostazioni/ModelliLettere.vue', 'resources/js/lib/senzaRete/verbale.ts',
    ...['resources/js/components/assemblee/verbale', 'resources/js/components/assemblee/verbale/registro'].flatMap((c) => readdirSync(resolve(process.cwd(), c))
        .filter((f) => f.endsWith('.vue')).map((f) => join(c, f)))];

describe('La guida della pagina «Il verbale»', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./VerbaleGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();
        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('la pagina ha «Guida», che apre questa guida', () => {
        const s = leggi(PAGINA);
        expect(s).toMatch(/<PageHeaderGuide[\s\S]*?has-text-guide[\s\S]*?@open-text-guide="mostraGuida = true"/);
        expect(s).toContain('<VerbaleGuide v-model:open="mostraGuida" />');
    });

    test('aperta mostra le sue parti e dice le regole; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['La discussione in sala', 'Il verbale dal modello', 'Le cifre che non tornano', 'Il salvataggio e le versioni', 'Senza rete e a computer spento', 'Chi altro è dentro', 'La bozza, la copia firmata, il numero', 'La trasmissione e i trenta giorni', 'La privacy']) expect(t, parte).toContain(parte);
        for (const regola of ['art. 1137 c. 2 c.c.', 'art. 1130 n. 7 c.c.', 'calcolato in modo prudente', 'mai in bacheca', 'niente si sovrascrive', 'finestra privata', '60 secondi', 'pochi secondi', 'almeno ogni 30 secondi', 'non ferma niente']) expect(t, regola).toContain(regola);
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-verbale"]')).toBeNull();
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(voci.length).toBeGreaterThanOrEqual(30);
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('ogni nome fra virgolette basse c\'è a video nelle pagine e nei pezzi del verbale', async () => {
        const t = await apri();
        const sorgenti = SORGENTI.map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(5);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('a schema: nessuna voce oltre le 220 lettere, niente sotto i 12 pixel', () => {
        const sorgente = leggi(GUIDA);
        const frasi = [...sorgente.matchAll(/<span>([^<]+)<\/span><\/li>/g)].map((m) => m[1]);
        expect(frasi.filter((f) => f.length > 220)).toEqual([]);
        expect(sorgente).not.toMatch(/text-\[(1[01]|[0-9])px\]/);
    });
});
