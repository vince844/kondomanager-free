// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida dei saldi spiega «Dichiara l'origine del pregresso»: a che cosa serve (di quando è il debito, per la fascia e le date dei sei mesi; l'importo
 * non cambia), quando si usa («origine non dichiarata»), le voci del menu, «Solo quelli senza origine» e «Scegli tutti», la strada da «Rate scadute»
 * («dichiarala dai saldi»). Ogni nome fra «» della parte c'è nei sorgenti della pagina, della finestra, del pannello del saldo e dell'elenco.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import SaldiGuide from './SaldiGuide.vue';

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenti = [
    'resources/js/pages/gestionale/saldi/SaldiList.vue',
    'resources/js/components/gestionale/saldi/DichiaraOrigineDialog.vue',
    'resources/js/components/gestionale/saldi/SaldiDetailPanel.vue',
    'resources/js/lib/gestionale/debiti/originePregresso.ts',
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'app/Services/Gestionale/Debiti/OriginePregresso.php',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

function parte(): HTMLElement {
    const w = mount(SaldiGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
            },
        },
    });

    return w.get('[data-testid="guida-origine-del-pregresso"]').element as HTMLElement;
}

describe('la guida dei saldi: «Dichiara l\'origine del pregresso»', () => {
    test('la parte c\'è, con il suo titolo, e dice le cose che contano', () => {
        const el = parte();
        expect((el.querySelector('h3')?.textContent ?? '').trim()).toBe('Dichiara l\'origine del pregresso');
        const testo = (el.textContent ?? '').replace(/\s+/g, ' ');
        for (const frase of [
            'Dice di quando è il debito riportato da prima',
            'la fascia di ritardo e le date dei sei mesi per agire',
            'L\'importo del saldo non cambia',
            '«origine non dichiarata»',
            '«Dichiara l\'origine del pregresso, in blocco»',
            '«Dovuto con questo piano»',
            '«Prima del primo esercizio»',
            '«Togli l\'origine (non dichiarata)»',
            '«Solo quelli senza origine»',
            '«dichiarala dai saldi»',
        ]) {
            expect(testo, frase).toContain(frase);
        }
    });

    test('ogni nome fra «» della parte c\'è nei sorgenti', () => {
        const sorgenteGuida = leggi('resources/js/components/guides/SaldiGuide.vue');
        const inizio = sorgenteGuida.indexOf('data-testid="guida-origine-del-pregresso"');
        expect(inizio).toBeGreaterThan(0);
        const sezione = sorgenteGuida.slice(inizio, sorgenteGuida.indexOf('</section>', inizio));
        const citati = [...new Set([...sezione.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(8);
        const nellaPagina = (nome: string) => new RegExp(nome.split(/\bN\b/).map((p) => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join('(?:\\{\\{[^}]+\\}\\}|\\$\\{[^}]+\\})')).test(sorgenti);
        expect(citati.filter((nome) => !nellaPagina(nome))).toEqual([]);
    });
});
