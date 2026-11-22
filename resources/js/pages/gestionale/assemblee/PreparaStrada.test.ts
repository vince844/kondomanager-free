// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Prepara l'assemblea» con la strada dei cinque passi: in cima la stessa strada della pagina dell'assemblea, con il passo 1 «Date e ordine del giorno»
 * aperto e gli altri quattro chiusi «dopo «Prepara l'assemblea»»; il contenuto del passo 1 è il modulo di sempre, la riga del passo segue le date e i punti
 * spuntati; nel piede «Prepara l'assemblea» al posto di «Avanti». Sotto il titolo le tre card della guida, come nell'elenco delle assemblee; nell'intestazione
 * «Assemblee» torna all'elenco.
 *
 * Che cosa non copre: il salvataggio sul server (`PagineDelleAssembleeTest`); la strada da sola (`StradaDellAssemblea.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() , on: vi.fn(() => () => {}) }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/prepara', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

/** L'intestazione finta: ricorda le card della guida e il pulsante «Indietro». */
const Intestazione = defineComponent({
    name: 'PageHeaderGuide', props: ['guides', 'backUrl', 'backText'],
    setup: (p) => () => h('header', { 'data-testid': 'intestazione', 'data-guide': JSON.stringify((p.guides ?? []).map((g: { title: string }) => g.title)),
        'data-indietro': p.backUrl ?? '', 'data-testo-indietro': p.backText ?? '' }),
});
/** Le date finte: un pulsante scrive le due date della bozza. */
const DateFinte = defineComponent({
    name: 'DateDellAssemblea', props: ['modelValue', 'mostraMancanti'], emits: ['update:modelValue'],
    setup: (p, { emit }) => () => h('button', { 'data-testid': 'scrivi-le-date', 'data-mancanti': String(p.mostraMancanti ?? ''), onClick: () => emit('update:modelValue', { ...p.modelValue, sedute: [
        { convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' }] }) }),
});
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: Intestazione, DateDellAssemblea: DateFinte, AssembleaGuide: true };

const Pagina = (await import('./Prepara.vue')).default;

const chiuso = (chiave: string, numero: number, nome: string) => ({ chiave, numero, nome, stato: 'chiuso', riga: 'dopo «Prepara l\'assemblea»',
    perche: 'dopo «Prepara l\'assemblea»', manca: [], scopo: null, piede: '' });
const PASSI = [
    { chiave: 'date', numero: 1, nome: 'Date e ordine del giorno', stato: 'adesso', riga: null, perche: null, manca: [], scopo: null,
        piede: 'L\'assemblea resta in preparazione: niente parte ai condòmini.' },
    chiuso('documenti', 2, 'Documenti'), chiuso('convocazione', 3, 'Convocazione'), chiuso('sala', 4, 'In sala'), chiuso('verbale', 5, 'Verbale e trasmissione'),
];
const proposta = (chiave: string) => ({ chiave_fonte: chiave, testo: chiave, testo_pubblico: null, frase: null, materia: 'altro', maggioranza: 'ordinaria',
    avviso: null, amministrazione: 'ordinaria', origine: 'di_serie', fonte: 'di_serie', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; inertia.post.mockClear(); document.body.innerHTML = ''; });
async function monta() {
    w = mount(Pagina as never, { attachTo: document.body, global: { stubs }, props: {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, passi: PASSI, memo: null, tabella_di_proprieta: { id: 5, nome: 'Proprietà' },
        proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('rendiconto'), proposta('preventivo'), proposta('varie')] }],
        scelte: { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
            maggioranze_proposte: {} },
    } as never });
    await flushPromises();
}
const sulla = (chiave: string) => w!.get(`[data-testid="strada"] [data-passo="${chiave}"]`);

describe('«Prepara l\'assemblea» con la strada', () => {
    test('in cima la strada: il passo 1 aperto, gli altri quattro chiusi «dopo «Prepara l\'assemblea»»; dentro il modulo di sempre', async () => {
        await monta();
        expect(sulla('date').attributes('data-stato')).toBe('adesso');
        expect(sulla('date').get('button').attributes('aria-current')).toBe('step');
        for (const c of ['documenti', 'convocazione', 'sala', 'verbale']) {
            expect(sulla(c).attributes('data-stato'), c).toBe('chiuso');
            expect(sulla(c).text(), c).toContain('dopo «Prepara l\'assemblea»');
            expect(sulla(c).find('button').exists(), c).toBe(false);
        }
        const contenuto = w!.get('[data-testid="contenuto-del-passo"]');
        expect(contenuto.find('[data-testid="prepara-date"]').exists()).toBe(true);
        expect(contenuto.find('[data-testid="ordine-proposto"]').exists()).toBe(true);
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 1 · Date e ordine del giorno');
        // Il diario non c'è ancora: l'assemblea nasce con «Prepara l'assemblea».
        expect(w!.find('[data-testid="apri-diario"]').exists()).toBe(false);
    });

    test('la riga del passo 1 segue le date scritte e i punti spuntati', async () => {
        await monta();
        expect(sulla('date').text()).toContain('senza le date · 3 punti');
        await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
        expect(sulla('date').text()).toContain('14/12 e 15/12 · 3 punti');
        await w!.findAll('[data-testid="proposta"] [role="checkbox"]')[2].trigger('click');
        expect(sulla('date').text()).toContain('14/12 e 15/12 · 2 punti');
    });

    test('nel piede «Prepara l\'assemblea» al posto di «Avanti», con la frase di sempre; il clic prepara l\'assemblea', async () => {
        await monta();
        const piede = w!.get('[data-testid="piede-del-passo"]');
        expect(piede.find('[data-testid="avanti"]').exists()).toBe(false);
        expect(piede.text()).toContain('Adesso: 3 punti nell\'ordine del giorno. L\'assemblea resta in preparazione: niente parte ai condòmini.');
        const pulsante = piede.get('[data-testid="prepara-assemblea"]');
        expect(pulsante.text()).toBe('Prepara l\'assemblea');
        expect(pulsante.classes()).toEqual(expect.arrayContaining(['h-8', 'text-xs']));
        // Il pulsante è acceso anche senza le date: il clic accende gli errori (la prova è più sotto, «gli errori al clic»).
        expect(pulsante.attributes('disabled')).toBeUndefined();
        await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/admin.gestionale.assemblee.store/3', expect.objectContaining({ proposte: ['rendiconto', 'preventivo', 'varie'] }), expect.anything());
    });

    test('sotto il titolo le tre card della guida; nell\'intestazione «Assemblee» torna all\'elenco', async () => {
        await monta();
        const testa = w!.get('[data-testid="intestazione"]');
        expect(JSON.parse(testa.attributes('data-guide')!)).toEqual(['Che cosa prepari qui', 'Le date e la legge', 'Dopo «Prepara l\'assemblea»']);
        expect(testa.attributes('data-indietro')).toBe('/admin.gestionale.assemblee.index/3');
        expect(testa.attributes('data-testo-indietro')).toBe('Assemblee');
    });
});

describe('gli errori al clic su «Prepara l\'assemblea», non prima', () => {
    test('il pulsante è acceso; senza le date il clic non manda niente e accende gli errori delle date', async () => {
        await monta();
        expect(w!.get('[data-testid="scrivi-le-date"]').attributes('data-mancanti')).toBe('false');
        const prepara = w!.get('[data-testid="prepara-assemblea"]');
        expect(prepara.attributes('disabled')).toBeUndefined();
        await prepara.trigger('click');
        expect(inertia.post).not.toHaveBeenCalled();
        expect(w!.get('[data-testid="scrivi-le-date"]').attributes('data-mancanti')).toBe('true');
    });

    test('senza una tabella di proprietà generale la riga sotto la tabella è un consiglio grigio, non un avviso', async () => {
        w = mount(Pagina as never, { attachTo: document.body, global: { stubs }, props: {
            condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, passi: PASSI, memo: null, tabella_di_proprieta: null, proposte: [],
            scelte: { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
                maggioranze_proposte: {} },
        } as never });
        const nota = w!.get('[data-testid="nota-tabella"]');
        expect(nota.classes()).toContain('text-slate-500');
        expect(nota.classes().some((c) => c.includes('amber'))).toBe(false);
    });

    test('nessuna tabella segnata: la frase lo dice com\'è, e scelta una tabella la casella «Segna» la manda con «Prepara l\'assemblea»', async () => {
        w = mount(Pagina as never, { attachTo: document.body, global: { stubs }, props: {
            condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, passi: PASSI, memo: null, tabella_di_proprieta: null, proposte: [],
            scelte: { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [],
                tabelle: [{ id: 29, nome: 'Tabella spese generali', controlli: {} }], maggioranze_proposte: {} },
        } as never });
        await flushPromises();
        expect(w!.get('[data-testid="nota-tabella"]').text()).toBe('Nessuna tabella di Via Roma 12 è segnata come di proprietà generale.');
        expect(w!.find('[data-testid="segna-proprieta"]').exists()).toBe(false);

        const menu = w!.findAllComponents({ name: 'VSelect' }).find((c) => (c.props('options') as { nome: string }[]).some((o) => o.nome === 'Nessuna, la scelgo dopo'))!;
        menu.vm.$emit('update:modelValue', 29);
        await flushPromises();
        const segna = w!.get('[data-testid="segna-proprieta"]');
        expect(segna.text()).toContain('Segna «Tabella spese generali» come tabella di proprietà generale di Via Roma 12');
        await segna.get('[role="checkbox"]').trigger('click');
        await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect((inertia.post.mock.calls[0] as unknown[])[1]).toMatchObject({ tabella_id: 29, segna_di_proprieta: true });
    });
});
