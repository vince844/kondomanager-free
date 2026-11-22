// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il passo 5 della pagina dell'assemblea (scena 10 della bozza delle scene): una riga per riunione con il suo verbale e «Apri», chiesta quando il passo si
 * apre; e i modelli del verbale nella pagina dei modelli (scena 9): il testo del programma che si duplica, i due testi con la parte «Dati della sala» in
 * mezzo, i nove campi, l'anteprima con l'assemblea di esempio, il salvataggio con la struttura dell'editor.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import PassoDelVerbale from './PassoDelVerbale.vue';
import ModelliDelVerbale from './ModelliDelVerbale.vue';
import { ROTTE } from './verbaleDiProva';
import { documento, paragrafo } from '@/lib/assemblee/verbale/struttura';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return { default: defineComponent({ name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
        setup: (p) => () => h('div', String((p.options ?? []).length)) }) };
});
// La pagina monta l'editor (Tiptap): su una macchina carica supera i cinque secondi di serie.
vi.setConfig({ testTimeout: 20000 });
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { ClipboardEvent: unknown }).ClipboardEvent ??= class extends Event { clipboardData = null; };
(globalThis as unknown as { route: unknown }).route = (nome: string, id?: number) => `/${nome}${id ? `/${id}` : ''}`;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('il passo 5', () => {
    test('una riga per riunione, con il suo verbale e «Apri»: sospesa e prosecuzione sono due verbali', () => {
        w = mount(PassoDelVerbale, { attachTo: document.body, props: { verbale: { rotte: ROTTE, riunioni: [
            { seduta_id: 2, giorno: '2026-12-15', convocazione: 'seconda', numero: 1, aperta_il: '2026-12-15T17:42:00Z', chiusa_il: '2026-12-15T20:30:00Z', esito: 'sospesa',
                verbale: { id: 7, stato: 'registrato', numero: 42 }, url: `${ROTTE.pagina}?verbale=7` },
            { seduta_id: 3, giorno: '2026-12-17', convocazione: 'seconda', numero: 2, aperta_il: null, chiusa_il: null, esito: null, verbale: null, url: ROTTE.pagina },
        ] } } });
        const righe = w.findAll('[data-testid="riunione-del-passo"]');
        expect(righe).toHaveLength(2);
        expect(righe[0].text()).toContain('n. 42 · registrato');
        expect(righe[0].find('[data-testid="apri-il-verbale"]').attributes('href')).toBe(`${ROTTE.pagina}?verbale=7`);
        expect(righe[1].text()).toContain('dopo la sala');
        expect(testo()).toContain('Una riunione, un verbale');
    });

    test('la pagina dell\'assemblea chiede il passo quando si apre, e lo monta nel suo punto', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/assemblee/Assemblea.vue'), 'utf8');
        expect(s).toContain('if (s === \'verbale\' && props.verbale === undefined) router.reload({ only: [\'verbale\'] });');
        expect(s).toMatch(/data-innesto="verbale"[\s\S]*<PassoDelVerbale v-else :verbale="verbale" \/>/);
    });
});

describe('i modelli del verbale', () => {
    const sezione = { testo_del_programma: { prima: documento([paragrafo('Il giorno [giorno], alle ore [ora], presso [luogo], si è riunita in [convocazione] l\'assemblea [tipo] dei condòmini del [condominio].')]),
        dopo: documento([paragrafo('Il presidente dichiara chiusa l\'assemblea alle ore [ora_di_chiusura].')]) },
    modelli: [{ id: 4, nome: 'Verbale ordinario dello studio', prima: documento([paragrafo('Regolarmente convocata.')]), dopo: documento([paragrafo('Letto e sottoscritto.')]),
        condominio: null, cambiato_da: 'Andrea Costa', cambiato_il: '2026-10-20' }],
    campi: { '[condominio]': 'il nome del condominio', '[giorno]': 'il giorno della riunione', '[ora_di_chiusura]': 'l\'ora della chiusura' } };

    test('il testo del programma si duplica; il modello ha i due testi, la parte della sala in mezzo, i campi, l\'anteprima; si salva la struttura', async () => {
        w = mount(ModelliDelVerbale, { attachTo: document.body, props: { verbale: sezione, condomini: [], puoModificare: true, filtroCondominio: null } });
        expect(testo()).toContain('Testo del programma');
        expect(testo()).toContain('Non si modifica');
        expect(testo()).toContain('cambiato da Andrea Costa il 20/10/2026');
        await w.find('[data-testid="duplica-programma-verbale"]').trigger('click');
        await flushPromises();
        expect(testo()).toContain('Prima dei punti');
        expect(testo()).toContain('Dopo i punti');
        expect(document.body.querySelector('[data-testid="dati-della-sala-del-modello"]')?.textContent).toContain('Li mette il programma');
        expect(document.body.querySelector('[data-testid="campi-del-verbale"]')?.textContent).toContain('[ora_di_chiusura]');
        expect(document.body.querySelector('[data-testid="anteprima-del-modello"]')?.textContent).toContain('Il giorno 15 dicembre 2026, alle ore 18:42');
        expect(document.body.querySelectorAll('[data-testid="barra-dell-editor"]')).toHaveLength(2);
        (document.body.querySelector('[data-testid="salva-modello-verbale"]') as HTMLButtonElement).click();
        expect(inertia.post).toHaveBeenCalledWith('/impostazioni.stampe.modelli-verbale.store', expect.objectContaining({ nome: 'Verbale dello studio', condominio_id: null,
            prima: sezione.testo_del_programma.prima }), expect.anything());
    });

    test('la pagina dei modelli ha la sezione del verbale, e il filtro per tipo la sceglie', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/impostazioni/ModelliLettere.vue'), 'utf8');
        expect(s).toContain('<option v-if="verbale" value="verbale">Il verbale dell\'assemblea</option>');
        expect(s).toMatch(/<ModelliDelVerbale v-if="verbale && \(filtroTipo === '' \|\| filtroTipo === 'verbale'\)"/);
    });
});
