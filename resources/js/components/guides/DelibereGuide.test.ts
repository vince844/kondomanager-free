// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida del seguito delle delibere (il passo 6 «Delibere eseguite», la riunione tenuta su carta), nel cassetto:
 *
 * - aperta mostra le sue parti; chiusa non c'è; la pagina dell'assemblea la apre con «Guida» sul passo 6;
 * - a schema: ogni frase è una voce corta (niente paragrafi lunghi), con il carattere di 14 pixel;
 * - ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo;
 * - ogni nome fra virgolette basse c'è nelle pagine del seguito o nelle frasi che scrive il server (commenti esclusi);
 * - dice le cose che la tappa chiede: la conferma, «Si applica adesso» e ciò che resta da fare, gli esiti, «Segna eseguita», i trenta giorni con «Resta la
 *   nullità», i segni che non fermano niente, la riunione su carta, «Elimina» con il motivo.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const GUIDA = 'resources/js/components/guides/DelibereGuide.vue';
const cartella = (c: string, ext: string) => readdirSync(resolve(process.cwd(), c)).filter((f) => f.endsWith(ext)).map((f) => join(c, f));
const SORGENTI = [
    ...cartella('resources/js/components/assemblee/seguito', '.vue'), 'resources/js/lib/assemblee/seguito.ts', 'resources/js/pages/gestionale/assemblee/Assemblea.vue',
    'resources/js/components/assemblee/RiunioneRegistrata.vue', 'app/Services/Assemblee/PassiDellAssemblea.php', 'app/Services/Assemblee/DeliberaInParole.php',
    ...cartella('app/Services/Assemblee/Seguito', '.php'), ...cartella('app/Services/Assemblee/Seguito/Effetti', '.php'),
];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
async function apri(open = true) {
    const Guida = (await import('./DelibereGuide.vue')).default;
    w = mount(Guida, { attachTo: document.body, props: { open } });
    await flushPromises();

    return (document.body.textContent ?? '').replace(/\s+/g, ' ');
}

describe('la guida delle delibere eseguite', () => {
    test('aperta mostra le sue parti; chiusa non c\'è; la pagina dell\'assemblea la apre sul passo 6', async () => {
        const t = await apri();
        for (const parte of ['Che cosa cambia nel gestionale', 'Adesso, o da fare', 'Gli esiti', 'Eseguita, o da eseguire', 'I trenta giorni e i segni',
            'La riunione tenuta su carta', '«Elimina» con il motivo']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-delibere"]')).toBeNull();
        expect(leggi('resources/js/pages/gestionale/assemblee/Assemblea.vue')).toContain('<DelibereGuide v-else-if="guidaDelleDelibere" v-model:open="mostraGuida" />');
    });

    test('a schema: voci corte, mai sotto il carattere di 14 pixel', () => {
        const sorgente = leggi(GUIDA);
        const frasi = [...sorgente.matchAll(/<span>([^<]+)<\/span><\/li>/g)].map((m) => m[1]);
        expect(frasi.length).toBeGreaterThanOrEqual(25);
        expect(frasi.filter((f) => f.length > 160)).toEqual([]);
        expect(sorgente).not.toMatch(/text-\[(1[01]|[0-9])px\]|text-xs/);
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('ogni nome fra virgolette basse c\'è nelle pagine del seguito o nelle frasi del server', async () => {
        const t = await apri();
        const sorgenti = SORGENTI.map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(10);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('dice la conferma, i trenta giorni con la nullità, i segni che non fermano niente, la riunione su carta, il motivo di «Elimina»', async () => {
        const t = await apri();
        for (const frase of ['i moduli cambiano solo con «Conferma»', 'con la data diventa un compito dell\'Action inbox', 'Resta la nullità, che non ha termine',
            'Non ferma niente (art. 1137 c.c.)', 'appena segnati gli esiti', '«verbale da caricare» al posto del numero', 'I testi che restano scritti nelle schede dicono il giorno e il punto',
            'Nella finestra il motivo è sempre obbligatorio', 'Una delibera senza cose da fare si elimina senza motivo',
            'Resta tutto nel diario dell\'assemblea e nel registro delle modifiche']) {
            expect(t, frase).toContain(frase);
        }
    });
});
