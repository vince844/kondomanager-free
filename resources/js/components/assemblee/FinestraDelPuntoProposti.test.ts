// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Aggiungi un punto» con i punti proposti che non sono ancora nell'ordine del giorno: in cima i proposti con il loro gruppo e la loro frase, ognuno con
 * «Aggiungi» (lo stesso invio del riquadro dei proposti, alla rotta `punti.dalle-proposte`, con la maggioranza scelta nel riquadro, o quella proposta, in una
 * visita parziale che rilegge anche il passo «Convocazione» già letto); sotto
 * «Oppure scrivi un punto nuovo» con il modulo di sempre. Nella modifica di un punto i proposti non ci sono; senza proposti la finestra è quella di sempre.
 *
 * Che cosa non copre: il server, che rilegge le proposte (`PreparazioneDellAssembleaTest`); il modulo del punto (gli altri test della finestra).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: (...a: unknown[]) => post(...a), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }], maggioranze: [], esiti: [], a_chi: [], unita: 26,
    palazzine: [], scale: [], tabelle: [], maggioranze_proposte: {} };
const proposta = (chiave: string, testo: string, extra: Record<string, unknown> = {}) => ({ chiave_fonte: chiave, testo, testo_pubblico: testo, frase: null,
    materia: 'altro', maggioranza: 'ordinaria', avviso: null, amministrazione: 'ordinaria', origine: 'dal_programma', fonte: 'programma', spuntato: true, memo_id: null,
    oggetti: [], esercizio_id: null, importo: null, ...extra });
const PROPOSTE = [
    { chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('varie', 'Varie ed eventuali', { maggioranza: 'senza_voto', origine: 'di_serie' })] },
    { chiave: 'da_te', titolo: 'Da te', punti: [] },
    { chiave: 'dal_programma', titolo: 'Dal programma', punti: [proposta('fattura:45', 'Ratifica della spesa di € 1.800,00 per la colonna dell\'acqua',
        { frase: 'Fattura n. 45 di Idraulica Bianchi, in sforo motivato.', maggioranza: 'meta_del_valore' })] },
];
const punto = { id: 8, numero: 2, testo: 'Il portone', testo_pubblico: null, materia: 'lavori', maggioranza: 'ordinaria', conduttore: null, ambito: null,
    palazzina_id: null, scala_id: null, tabella_id: null, importo: null, dati: null };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); document.body.innerHTML = ''; });
async function monta(p: Record<string, unknown> = {}) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea ordinaria del 15/12/2026', tabellaDellAssemblea: null, punto: null, scelte, proposte: PROPOSTE, ...p } as never });
    await flushPromises();
}
const q = (s: string) => document.body.querySelector(s);
const tutti = (s: string) => [...document.body.querySelectorAll(s)];

describe('«Aggiungi un punto» con i proposti', () => {
    test('in cima i proposti con il gruppo e la frase; sotto «Oppure scrivi un punto nuovo» con il modulo', async () => {
        await monta();
        const proposti = tutti('[data-testid="proposto-nella-finestra"]');
        expect(proposti).toHaveLength(2);
        expect(proposti[0].textContent).toContain('Varie ed eventuali');
        expect(proposti[0].textContent).toContain('Di serie');
        expect(proposti[1].textContent).toContain('Dal programma');
        expect(proposti[1].textContent).toContain('Fattura n. 45 di Idraulica Bianchi, in sforo motivato.');
        expect(proposti[1].querySelector('[data-testid="gruppo-del-proposto"]')!.className).toContain('rounded-md');
        expect(q('[data-testid="oppure-scrivi"]')!.textContent).toContain('Oppure scrivi un punto nuovo');
        expect(q('[data-testid="testo-punto"]')).not.toBeNull();
        // I proposti stanno prima del modulo.
        expect(q('[data-testid="proposti-nella-finestra"]')!.compareDocumentPosition(q('[data-testid="testo-punto"]')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    test('«Aggiungi» manda il proposto alla rotta di sempre, con la maggioranza proposta, in una visita parziale', async () => {
        await monta();
        (tutti('[data-testid="proposto-nella-finestra"]')[1].querySelector('button') as HTMLButtonElement).click();
        expect(post).toHaveBeenCalledWith('/gestionale.assemblee.punti.dalle-proposte',
            { proposte: ['fattura:45'], maggioranze: { 'fattura:45': 'meta_del_valore' } },
            expect.objectContaining({ only: ['ordine', 'proposte', 'passi', 'flash'], preserveScroll: true }));
    });

    test('«Aggiungi» usa la maggioranza scelta nel riquadro dei proposti, e la dice; con il passo «Convocazione» già letto lo rilegge', async () => {
        await monta({ maggioranzeScelte: { 'fattura:45': 'due_terzi' }, rileggi: ['convocazione'],
            scelte: { ...scelte, maggioranze: [{ valore: 'due_terzi', nome: 'Due terzi del valore' }, { valore: 'meta_del_valore', nome: 'Metà del valore' }] } });
        const fattura = tutti('[data-testid="proposto-nella-finestra"]')[1];
        expect(fattura.querySelector('[data-testid="maggioranza-del-proposto"]')!.textContent).toBe('Due terzi del valore');
        (fattura.querySelector('button') as HTMLButtonElement).click();
        expect(post).toHaveBeenCalledWith('/gestionale.assemblee.punti.dalle-proposte',
            { proposte: ['fattura:45'], maggioranze: { 'fattura:45': 'due_terzi' } },
            expect.objectContaining({ only: ['ordine', 'proposte', 'passi', 'flash', 'convocazione'] }));
    });

    test('nella modifica di un punto i proposti non ci sono; senza proposti la finestra è quella di sempre', async () => {
        await monta({ punto });
        expect(q('[data-testid="proposti-nella-finestra"]')).toBeNull();
        expect(q('[data-testid="oppure-scrivi"]')).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        await monta({ proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [] }] });
        expect(q('[data-testid="proposti-nella-finestra"]')).toBeNull();
        expect(q('[data-testid="oppure-scrivi"]')).toBeNull();
        expect(q('[data-testid="testo-punto"]')).not.toBeNull();
    });
});
