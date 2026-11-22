// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra del punto in «Prepara l'assemblea» (`nella-pagina`): l'assemblea non c'è ancora, e il punto resta nella pagina.
 *
 * - «Aggiungi il punto» passa alla pagina i campi come li manderebbe al server (`salva`) e chiude la finestra: niente parte, né `post` né `patch`. La frase
 *   in cima dice che il punto nasce con l'assemblea.
 * - Con `bozza` la finestra riparte dal punto scritto (il testo, la frase per la bacheca, la materia, la maggioranza, l'importo come era scritto), con «Modifica
 *   il punto» e «Salva le modifiche»; gli errori del server sul punto si vedono riaprendolo.
 * - Fuori da «Prepara l'assemblea» la finestra resta quella di sempre: «Salva il punto» manda al server.
 *
 * Che cosa non copre: la pagina che tiene i punti (`PreparaPuntiScritti.test.ts`); il server (`PuntiScrittiInPreparaTest`); i menu `v-select` aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
const patch = vi.fn();
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: (...a: unknown[]) => post(...a), patch: (...a: unknown[]) => patch(...a), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }, { valore: 'altro', nome: 'Altro' }],
    maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }, { valore: 'meta_del_valore', nome: 'Metà del valore' }], esiti: [], a_chi: [], unita: 26, palazzine: [],
    scale: [], tabelle: [], maggioranze_proposte: {} };
const bozza = { testo: 'Il cancello del cortile: preventivi e spesa', testo_pubblico: 'Il cancello del cortile', materia: 'lavori', maggioranza: 'meta_del_valore',
    conduttore: null, ambito: 'condominio', palazzina_id: null, scala_id: null, tabella_id: null, importo: '8.200,00' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); patch.mockReset(); document.body.innerHTML = ''; });
async function monta(extra: Record<string, unknown> = {}) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaNome: 'Assemblea ordinaria di Via Roma 12',
        tabellaDellAssemblea: 'Proprietà', punto: null, scelte, nellaPagina: true, ...extra } as never });
    await flushPromises();
}
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel);
async function scrivi(testo: string) {
    const t = q<HTMLTextAreaElement>('[data-testid="testo-punto"]')!;
    t.value = testo;
    t.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('la finestra del punto in «Prepara l\'assemblea»', () => {
    test('«Aggiungi il punto» passa il punto alla pagina e chiude: niente parte per il server; la frase dice che nasce con l\'assemblea', async () => {
        await monta();
        expect(q('[data-testid="punto-nella-pagina"]')!.textContent).toContain('Il punto resta in questa pagina e nasce con l\'assemblea, quando premi «Prepara l\'assemblea».');
        const salva = q<HTMLButtonElement>('[data-testid="salva-punto"]')!;
        expect(salva.textContent!.trim()).toBe('Aggiungi il punto');
        await scrivi('La pulizia delle scale');
        salva.click();
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
        expect(patch).not.toHaveBeenCalled();
        expect(w!.emitted('salva')).toHaveLength(1);
        expect(w!.emitted('salva')![0][0]).toEqual({ testo: 'La pulizia delle scale', testo_pubblico: null, materia: 'altro', maggioranza: 'ordinaria', conduttore: null,
            ambito: 'condominio', palazzina_id: null, scala_id: null, tabella_id: null, importo: null });
        expect(w!.emitted('update:open')!.at(-1)).toEqual([false]);
    });

    test('con la bozza riparte dal punto scritto, con «Modifica il punto» e gli errori del server; «Salva le modifiche» passa i campi cambiati', async () => {
        await monta({ bozza, erroriDelPunto: { importo: 'L\'importo si scrive così: 8.200,00.' } });
        expect(q('[data-testid="finestra-punto"]')!.textContent).toContain('Modifica il punto');
        expect(q<HTMLTextAreaElement>('[data-testid="testo-punto"]')!.value).toBe('Il cancello del cortile: preventivi e spesa');
        expect(q('[data-testid="finestra-punto"]')!.textContent).toContain('L\'importo si scrive così: 8.200,00.');
        const salva = q<HTMLButtonElement>('[data-testid="salva-punto"]')!;
        expect(salva.textContent!.trim()).toBe('Salva le modifiche');
        await scrivi('Il cancello del cortile');
        salva.click();
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
        expect(w!.emitted('salva')![0][0]).toEqual({ ...bozza, testo: 'Il cancello del cortile' });
    });

    test('fuori da «Prepara l\'assemblea» la finestra resta quella di sempre: «Salva il punto» manda al server, senza la frase della pagina', async () => {
        await monta({ nellaPagina: false, assembleaId: 7 });
        expect(q('[data-testid="punto-nella-pagina"]')).toBeNull();
        expect(q('[data-testid="salva-punto"]')!.textContent!.trim()).toBe('Salva il punto');
        await scrivi('La pulizia delle scale');
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        expect(post).toHaveBeenCalledTimes(1);
        expect(w!.emitted('salva')).toBeUndefined();
    });
});

describe('la frase per la bacheca, con l\'interruttore', () => {
    test('spento di serie: niente casella, la riga dice che cosa va in bacheca, l\'icona spiega; acceso la casella compare e la frase parte', async () => {
        await monta();
        const interruttore = q('[data-testid="frase-bacheca"]')!;
        expect(interruttore.getAttribute('data-state')).toBe('unchecked');
        expect(q('[data-testid="testo-bacheca"]')).toBeNull();
        expect(q('[data-testid="bacheca-spenta"]')!.textContent!.trim()).toBe('Spento: in bacheca va solo di che cosa parla il punto, mai la frase dell\'avviso.');
        // La «i» è quella del programma: si raggiunge con il Tab e si apre al tocco, non il `title` del browser che al telefono non compare.
        expect(q('[data-testid="spiega-bacheca"]')!.tagName).toBe('BUTTON');
        expect(q('[data-testid="spiega-bacheca"]')!.getAttribute('aria-label')).toContain('inquilini compresi');
        expect(q('[data-testid="spiega-bacheca"]')!.getAttribute('title')).toBeNull();

        interruttore.click();
        await flushPromises();
        const t = q<HTMLTextAreaElement>('[data-testid="testo-bacheca"]')!;
        t.value = 'Il cancello del cortile';
        t.dispatchEvent(new Event('input'));
        await scrivi('Il cancello del cortile: preventivi della ditta Bianchi');
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect((w!.emitted('salva')![0][0] as { testo_pubblico: string | null }).testo_pubblico).toBe('Il cancello del cortile');
    });

    test('un punto con la frase riapre acceso; spento, la frase scritta non parte', async () => {
        await monta({ bozza });
        const interruttore = q('[data-testid="frase-bacheca"]')!;
        expect(interruttore.getAttribute('data-state')).toBe('checked');
        expect(q<HTMLTextAreaElement>('[data-testid="testo-bacheca"]')!.value).toBe('Il cancello del cortile');
        interruttore.click();
        await flushPromises();
        expect(q('[data-testid="testo-bacheca"]')).toBeNull();
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect((w!.emitted('salva')![0][0] as { testo_pubblico: string | null }).testo_pubblico).toBeNull();
    });
});
