// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della sala: la pagina della sala ha «Guida» nell'intestazione, che apre questa guida nel cassetto.
 *
 * - Aperta mostra le sue parti; chiusa non c'è. Dice le regole della legge che la sala applica, con l'articolo.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo.
 * - Ogni nome fra virgolette basse c'è a video nella sala, nei suoi pezzi o nelle impostazioni delle assemblee (commenti esclusi).
 * - A schema: nessuna voce è un paragrafo lungo, e niente scritte sotto i 12 pixel.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const GUIDA = 'resources/js/components/guides/SalaGuide.vue';
const PAGINA = 'resources/js/pages/gestionale/assemblee/Sala.vue';
const SORGENTI = [PAGINA, 'resources/js/pages/impostazioni/ImpostazioniAssemblee.vue',
    ...['resources/js/components/assemblee/sala', 'resources/js/components/assemblee/sala/rete'].flatMap((cartella) => readdirSync(resolve(process.cwd(), cartella))
        .filter((f) => f.endsWith('.vue')).map((f) => join(cartella, f)))];

describe('la guida della sala', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./SalaGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();

        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('la pagina della sala ha «Guida», che apre questa guida', () => {
        const s = leggi(PAGINA);
        expect(s).toMatch(/<PageHeaderGuide[\s\S]*?has-text-guide[\s\S]*?@open-text-guide="mostraGuida = true"/);
        expect(s).toContain('<SalaGuide v-model:open="mostraGuida" />');
    });

    test('aperta mostra le sue parti e dice le regole con l\'articolo; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['La barra', 'L\'apertura', 'Chi è una testa', 'Le presenze e le deleghe', 'Il voto', 'Sospendere, chiudere, rinviare', 'Senza rete, e la carta']) {
            expect(t, parte).toContain(parte);
        }
        for (const regola of ['art. 1136 c. 6 c.c.', 'Cass. 25558/2020', 'art. 67 c. 2 disp. att.', 'art. 67 c. 6 e 7 disp. att.', 'art. 67 c. 1 disp. att.',
            'art. 67 c. 5 disp. att.', 'Cass. 1853/2018', 'art. 66 c. 5 disp. att.', 'art. 1130 n. 7 c.c.', 'niente dati sulla salute', 'decide il presidente',
            'si vota lo stesso', 'vanno nel verbale', 'niente si sovrascrive', 'registrato dal foglio della sala']) {
            expect(t, regola).toContain(regola);
        }
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-sala"]')).toBeNull();
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(voci.length).toBeGreaterThanOrEqual(25);
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('ogni nome fra virgolette basse c\'è a video nella sala, nei suoi pezzi o nelle impostazioni delle assemblee', async () => {
        const t = await apri();
        const sorgenti = SORGENTI.map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(15);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('il caso misto: due teste di serie, perché le comunioni nate da titoli diversi restano distinte; una testa se il condominio ha una regola sua', async () => {
        const t = await apri();
        expect(t).toContain('due teste (di serie) o una');
        expect(t).toContain('le comunioni nate da titoli diversi restano distinte anche nell\'assemblea');
    });

    test('la sala aperta (decisione 664): i convocati prima dell\'apertura, i gesti nel menu ⋯, i contitolari senza voto, i tanti punti, le dichiarazioni', async () => {
        const t = await apri();
        expect(t).toContain('Prima di «Apri l\'assemblea» la card è l\'elenco dei convocati, con il mezzo della convocazione');
        expect(t).toContain('«In collegamento» e «Delega» stanno nel menu ⋯ della riga');
        expect(t).toContain('l\'altra persona in comproprietà entra con la spunta «senza voto»');
        expect(t).not.toContain('contitolare');
        expect(t).toContain('Con più di sei punti');
        expect(t).toContain('«Modifica» e «Togli»');
        expect(t).toContain('Un gesto arrivato resta a video');
    });

    test('a schema: nessuna voce è un paragrafo lungo, niente sotto i 12 pixel', () => {
        const sorgente = leggi(GUIDA);
        const voci = [...sorgente.matchAll(/<span>([^<]+)<\/span><\/li>/g)].map((m) => m[1]);
        expect(voci.length).toBeGreaterThanOrEqual(25);
        expect(voci.filter((v) => v.length > 240)).toEqual([]);
        expect(sorgente).not.toMatch(/text-\[(?:[0-9]|1[01])px\]/);
    });

    test('il punto di una parte (Coda 734): la seconda riga anche per il gruppo di una tabella, e la riga azzurra di chi vota; «Discorde» spiegato (rilievo B8)', async () => {
        const t = await apri();
        expect(t).toContain('Su un punto della sola scala, o votato con una tabella di un gruppo, una seconda riga dice il conto della parte');
        expect(t).not.toContain('dice chi paga, non quanti devono esserci');
        expect(t).toContain('chi altro è in sala non vota su quel punto (art. 1123 c. 3 c.c.)');
        expect(t).toContain('«Discorde»: i comproprietari presenti votano in modo diverso; di serie l\'unità conta astenuta, con i suoi millesimi; se nelle impostazioni '
            + 'lo spegni, decide il presidente.');
    });
});
