// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'importo di un punto di spesa nella finestra del punto (decisioni 622 (1) e (2)): con le materie di spesa (lavori, innovazioni, innovazioni agevolate,
 * videosorveglianza, progetti del territorio) c'è il campo «Importo», facoltativo, con la riga che dice a che cosa serve; il punto lo passa in euro come
 * scritto (diventa centesimi sul server, una volta sola). Con una materia che non è di spesa il campo non c'è e un importo rimasto non viaggia.
 *
 * Che cosa non copre: il server (`CorrezioniDellaSimulazioneTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" :data-valore="modelValue" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }, { valore: 'altro', nome: 'Altro' }],
    maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }], esiti: [], a_chi: [], unita: 26, palazzine: [], scale: [], tabelle: [], maggioranze_proposte: {} };
const lavori = { testo: 'Sostituzione del portone d\'ingresso', testo_pubblico: null, materia: 'lavori', maggioranza: 'ordinaria', conduttore: null,
    ambito: 'condominio', palazzina_id: null, scala_id: null, tabella_id: null, importo: '14.500,00' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel);
async function monta(bozza: Record<string, unknown>) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaNome: 'Assemblea ordinaria di Via Roma 12',
        tabellaDellAssemblea: 'Proprietà', punto: null, scelte, nellaPagina: true, bozza } as never });
    await flushPromises();
}

describe('l\'importo di un punto di spesa', () => {
    test.each(['lavori', 'innovazioni', 'innovazioni_agevolate', 'videosorveglianza', 'progetti_territorio'])('con la materia %s il campo «Importo» c\'è e il punto lo passa', async (materia) => {
        await monta({ ...lavori, materia });
        const campo = q<HTMLInputElement>('[data-testid="importo-del-punto"]')!;
        expect(campo.value).toBe('14.500,00');
        expect(q('[data-testid="importo-del-punto-riga"]')!.textContent).toContain('Serve al seguito: «Crea il piano straordinario» lo propone e il fondo speciale lo confronta.');
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect(w!.emitted('salva')![0][0]).toMatchObject({ materia, importo: '14.500,00' });
    });

    test('è facoltativo: vuoto, il punto passa importo nullo', async () => {
        await monta({ ...lavori, importo: '' });
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect(w!.emitted('salva')![0][0]).toMatchObject({ materia: 'lavori', importo: null });
    });

    test('con una materia che non è di spesa il campo non c\'è e un importo rimasto non viaggia', async () => {
        await monta({ ...lavori, materia: 'altro' });
        expect(q('[data-testid="importo-del-punto"]')).toBeNull();
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect(w!.emitted('salva')![0][0]).toMatchObject({ materia: 'altro', importo: null });
    });
});
