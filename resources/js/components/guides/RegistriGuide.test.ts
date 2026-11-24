// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina «Registri» (1.12.0-beta.14, decisione 698): le email e gli errori.
 *
 * - La pagina ha «Guida» nell'intestazione, come le altre, e apre questa guida nel cassetto.
 * - Aperta mostra le sue parti; chiusa non c'è. Dice il codice, gli avvisi, il rapporto, quanto restano, il codice che non c'è.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`), con il numero della riga: la riga c'è e
 *   contiene quel testo.
 * - Ogni nome fra virgolette basse c'è a video nella pagina, nelle sue finestre, nella pagina d'errore o nella Action inbox (commenti esclusi).
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const PAGINA = 'resources/js/pages/impostazioni/impostazioniLogs.vue';
const GUIDA = 'resources/js/components/guides/RegistriGuide.vue';
const A_VIDEO = [
    PAGINA,
    'resources/js/components/impostazioni/errori/SchedaErrori.vue',
    'resources/js/components/impostazioni/errori/FinestraDellErrore.vue',
    'resources/js/lib/impostazioni/errori.ts',
    'lang/it/errors.php',
    'lang/it/impostazioni.php',
    'app/Services/Gestionale/Inbox/TipiDeiCompiti.php',
    'config/notifications.php',
    'app/Enums/Permission.php',
];

describe('la pagina «Registri» ha «Guida»', () => {
    test('l\'intestazione ha «Guida», che apre la guida della pagina nel cassetto', () => {
        const pagina = leggi(PAGINA);
        expect(pagina).toMatch(/<PageHeaderGuide[\s\S]*?has-text-guide[\s\S]*?@open-text-guide="mostraGuida = true"/);
        expect(pagina).toContain('import RegistriGuide from \'@/components/guides/RegistriGuide.vue\';');
        expect(pagina).toMatch(/<RegistriGuide v-model:open="mostraGuida" \/>/);
    });
});

describe('la guida dei registri', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./RegistriGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();

        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('aperta mostra le sue parti, e dice che cosa fare di un errore; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['La scheda «Email»', 'Il codice di un errore', 'Gli avvisi', 'Il rapporto', 'Quanto restano', 'Un codice che non c\'è']) expect(t).toContain(parte);
        expect(t).toContain('Copia il rapporto');
        expect(t).toContain('90 giorni');
        expect(t).toContain('storage/logs/laravel.log');
        // Il rapporto: ciò che è garantito, e i nomi che possono restare.
        expect(t).toContain('Senza password, senza i dati dei moduli e i valori delle query');
        expect(t).toContain('i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.');
        expect(t).not.toContain('nomi di persone');
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-registri"]')).toBeNull();
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(voci.length).toBeGreaterThanOrEqual(10);
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('ogni nome fra virgolette basse c\'è a video nella pagina, nelle sue finestre, nella pagina d\'errore o nella Action inbox', async () => {
        const t = await apri();
        const sorgenti = A_VIDEO.map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(5);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });
});
