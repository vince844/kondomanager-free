// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'editor del verbale a video: la barra con i soli formati approvati (otto pulsanti, ognuno con il suo nome per chi legge lo schermo), il testo che esce
 * nella forma della struttura, la parte «Dati della sala» con l'etichetta e lo sfondo (mai il bordo spesso a sinistra), la sola lettura, la scritta del
 * salvataggio accanto agli strumenti. E il testo in sola lettura (`TestoDelVerbale`), disegnato dalla struttura con le funzioni di Vue: i casi ostili del file
 * in comune restano testo. Nessun `v-html` nelle pagine e nei pezzi del verbale.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import EditorDelVerbale from './EditorDelVerbale.vue';
import TestoDelVerbale from './TestoDelVerbale.vue';
import { datiDellaSala, documento, paragrafo, titolo, valida, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';

// L'editor (Tiptap): su una macchina carica supera i cinque secondi di serie.
vi.setConfig({ testTimeout: 20000 });
(globalThis as unknown as { ClipboardEvent: unknown }).ClipboardEvent ??= class extends Event { clipboardData = null; };
const leggi = (f: string) => readFileSync(resolve(process.cwd(), f), 'utf8');
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const BLOCCO = datiDellaSala('voto:12', ['Favorevoli: 9 condòmini per 506,40 millesimi.']);

async function editor(props: Record<string, unknown> = {}) {
    w = mount(EditorDelVerbale, { attachTo: document.body, props: { modelValue: documento([paragrafo('Testo')]), ...props } });
    await flushPromises();
    return w;
}

describe('la barra dell\'editor', () => {
    test('ha solo i formati approvati, in quest\'ordine, ognuno con il suo nome', async () => {
        const e = await editor();
        const nomi = e.findAll('[data-testid="barra-dell-editor"] button').map((b) => b.attributes('aria-label'));
        expect(nomi).toEqual(['Grassetto', 'Corsivo', 'Sottolineato', 'Elenco puntato', 'Elenco numerato', 'Titolo', 'Annulla', 'Ripeti']);
        expect(e.find('[data-testid="barra-dell-editor"]').classes()).toContain('flex-wrap');
    });

    test('il grassetto si accende e il testo esce nella forma della struttura', async () => {
        const e = await editor();
        const ed = (e.vm as unknown as { editor: { commands: { selectAll: () => void } } }).editor;
        ed.commands.selectAll();
        await e.find('[aria-label="Grassetto"]').trigger('click');
        // La barra si ridisegna dopo due fotogrammi, come fa l'editor con Vue.
        await new Promise((r) => setTimeout(r, 60));
        await flushPromises();
        const uscite = e.emitted('update:modelValue') as NodoDelVerbale[][];
        const ultima = uscite[uscite.length - 1][0];
        expect(valida(ultima).ok).toBe(true);
        expect(JSON.stringify(ultima)).toContain('"bold"');
        expect(e.find('[aria-label="Grassetto"]').attributes('aria-pressed')).toBe('true');
    });

    test('la scritta del salvataggio sta accanto agli strumenti', async () => {
        const e = await editor({ scritta: { testo: 'Salvato alle 20:14', dettaglio: 'tutto sul server' } });
        expect(e.find('[data-testid="scritta-del-salvataggio"]').text()).toContain('Salvato alle 20:14');
    });

    test('in sola lettura la barra non c\'è e il testo non si cambia', async () => {
        const e = await editor({ soloLettura: true });
        expect(e.find('[data-testid="barra-dell-editor"]').exists()).toBe(false);
        expect(e.find('[contenteditable="true"]').exists()).toBe(false);
    });
});

describe('la parte «Dati della sala» nell\'editor', () => {
    test('ha l\'etichetta e lo sfondo, mai il bordo spesso a sinistra', async () => {
        const e = await editor({ modelValue: documento([paragrafo('Prima'), BLOCCO]) });
        const parte = e.find('[data-testid="dati-della-sala"]');
        expect(parte.exists()).toBe(true);
        expect(parte.text()).toContain('Dati della sala');
        expect(parte.text()).toContain('506,40');
        expect(parte.html()).not.toMatch(/border-l-(2|4|8)/);
    });

    test('una parte di più frasi, appena generata, non è «cambiata a mano»; lo diventa quando si scrive (rilievo C58)', async () => {
        // Il programma scrive l'originale con le frasi separate da un a capo: il testo dei paragrafi va letto allo stesso modo, non incollato.
        const voti = datiDellaSala('voto:13', ['Si vota alle ore 01:13.', 'Favorevoli: 3 condòmini per 760,00 millesimi.', 'La proposta è approvata.']);
        const e = await editor({ modelValue: documento([paragrafo('Prima'), voti]) });
        expect(e.find('[data-testid="etichetta-della-sala"]').text()).toBe('Dati della sala');
        const cambiata = { ...voti, content: [...(voti.content ?? []).slice(0, 2), paragrafo('La proposta è respinta.')] };
        await e.setProps({ modelValue: documento([paragrafo('Prima'), cambiata]) });
        await flushPromises();
        expect(e.find('[data-testid="etichetta-della-sala"]').text()).toBe('Dati della sala · cambiata a mano');
    });
});

describe('il testo in sola lettura', () => {
    test('disegna titoli, elenchi, segni e la parte della sala dalla struttura', () => {
        const doc = documento([titolo('4. Il portone'), { type: 'paragraph', content: [{ type: 'text', text: 'Forte', marks: [{ type: 'bold' }] }] }, BLOCCO,
            { type: 'orderedList', attrs: { start: 3 }, content: [{ type: 'listItem', content: [paragrafo('Tre')] }] }]);
        w = mount(TestoDelVerbale, { props: { documento: doc } });
        expect(w.find('h2').text()).toBe('4. Il portone');
        expect(w.find('strong').text()).toBe('Forte');
        expect(w.find('ol').attributes('start')).toBe('3');
        expect(w.find('[data-testid="dati-della-sala"]').text()).toContain('Dati della sala');
    });

    test('i casi ostili del file in comune restano testo: niente script, gestori, collegamenti o immagini', () => {
        const casi = JSON.parse(leggi('tests/Fixtures/verbale/struttura.json')) as { validi: { nome: string; ostile: boolean; documento: unknown }[];
            rifiutati: { nome: string; ostile: boolean; documento: unknown }[] };
        for (const c of [...casi.validi, ...casi.rifiutati].filter((x) => x.ostile)) {
            w = mount(TestoDelVerbale, { props: { documento: c.documento as NodoDelVerbale } });
            const html = w.html();
            for (const via of ['<script', 'onclick', '<a ', '<img', 'href=']) expect(html, `${c.nome}: ${via}`).not.toContain(via);
            w.unmount();
            w = null;
        }
        w = mount(TestoDelVerbale, { props: { documento: documento([paragrafo('<script>alert(1)</script>')]) } });
        expect(w.text()).toContain('<script>alert(1)</script>');
        expect(w.find('script').exists()).toBe(false);
    });
});

describe('nessun v-html nel verbale', () => {
    test('le pagine e i pezzi del verbale non usano v-html, né innerHTML', () => {
        const cartella = 'resources/js/components/assemblee/verbale';
        const file = [...readdirSync(resolve(process.cwd(), cartella), { recursive: true }) as string[]].filter((f) => f.endsWith('.vue')).map((f) => join(cartella, f));
        file.push('resources/js/pages/gestionale/assemblee/Verbale.vue', 'resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue');
        for (const f of file) {
            const s = leggi(f);
            expect(s, f).not.toMatch(/v-html|innerHTML/);
        }
    });
});
