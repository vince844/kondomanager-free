// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Prepara l'assemblea» (decisione 661, rilievo C40): «L'ordine del giorno» con i punti che entreranno (i proposti spuntati, poi quelli scritti,
 * «Varie ed eventuali» in fondo) e le frecce su e giù per spostarli, con le parole della pagina dell'assemblea («Sposta su», «Sposta giù»).
 *
 * - L'elenco segue le spunte e i punti scritti; «Varie ed eventuali» resta l'ultimo e non si sposta; il primo non sale, l'ultimo prima delle varie non scende.
 * - Le frecce cambiano l'ordine, e l'ordine parte con «Prepara l'assemblea» (`ordine`: le chiavi dei proposti e `scritto:N` per i punti scritti).
 *
 * Che cosa non copre: il server (`PreparaOrdineEDocumentiTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() , on: vi.fn(() => () => {}) }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/create', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

/** La finestra finta: ricorda le prop; la prova la fa salvare con `$emit('salva', …)`. */
const FinestraFinta = defineComponent({
    name: 'FinestraDelPunto', props: { open: Boolean, nellaPagina: Boolean, bozza: Object, erroriDelPunto: Object, assembleaNome: String, tabellaDellAssemblea: String,
        scelte: Object, punto: Object, condominioId: Number, condominioNome: String },
    emits: ['update:open', 'salva'], setup: () => () => h('div', { 'data-testid': 'finestra-finta' }),
});
const DateFinte = defineComponent({
    name: 'DateDellAssemblea', props: ['modelValue'], emits: ['update:modelValue'],
    setup: (p, { emit }) => () => h('button', { 'data-testid': 'scrivi-le-date', onClick: () => emit('update:modelValue', { ...p.modelValue, sedute: [
        { convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' }] }) }),
});
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, DateDellAssemblea: DateFinte, AssembleaGuide: true, FinestraDelPunto: FinestraFinta };

const Pagina = (await import('./Prepara.vue')).default;

const PASSI = [
    { chiave: 'date', numero: 1, nome: 'Date e ordine del giorno', stato: 'adesso', riga: null, perche: null, manca: [], scopo: null,
        piede: 'L\'assemblea resta in preparazione: niente parte ai condòmini.' },
    ...['documenti', 'convocazione', 'sala', 'verbale'].map((chiave, i) => ({ chiave, numero: i + 2, nome: chiave, stato: 'chiuso', riga: 'dopo «Prepara l\'assemblea»',
        perche: 'dopo «Prepara l\'assemblea»', manca: [], scopo: null, piede: '' })),
];
const proposta = (chiave: string) => ({ chiave_fonte: chiave, testo: chiave, testo_pubblico: null, frase: null, materia: 'altro', maggioranza: 'ordinaria',
    avviso: null, amministrazione: 'ordinaria', origine: 'di_serie', fonte: 'di_serie', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null });
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }, { valore: 'riscaldamento', nome: 'Riscaldamento' }],
    maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }, { valore: 'meta_del_valore', nome: 'Metà del valore' }], esiti: [], a_chi: [], unita: 7, palazzine: [],
    scale: [{ id: 4, nome: 'B', palazzina_id: null, unita: 2 }], tabelle: [], maggioranze_proposte: {} };
const punto = (testo: string, extra: Record<string, unknown> = {}) => ({ testo, testo_pubblico: null, materia: 'lavori', maggioranza: 'meta_del_valore', conduttore: null,
    ambito: 'condominio', palazzina_id: null, scala_id: null, tabella_id: null, importo: null, ...extra });


let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; inertia.post.mockReset(); document.body.innerHTML = ''; });
async function monta() {
    w = mount(Pagina as never, { attachTo: document.body, global: { stubs }, props: {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, passi: PASSI, memo: null, tabella_di_proprieta: { id: 5, nome: 'Proprietà' },
        proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('rendiconto'), proposta('preventivo'), proposta('varie')] }], scelte,
    } as never });
    await flushPromises();
}
const finestra = () => w!.findComponent({ name: 'FinestraDelPunto' });
async function scriviUnPunto(dati: Record<string, unknown>) {
    await w!.get('[data-testid="aggiungi-punto"]').trigger('click');
    finestra().vm.$emit('salva', dati);
    finestra().vm.$emit('update:open', false);
    await flushPromises();
}
const voci = () => w!.findAll('[data-testid="voce-dell-ordine"]');
const testi = () => voci().map((v) => v.find('[data-testid="testo-della-voce"]').text());
const freccia = (n: number, verso: 'su' | 'giu') => w!.get(`[aria-label="Sposta ${verso === 'su' ? 'su' : 'giù'} il punto ${n}"]`);
async function mandaLeDate() {
    await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
    await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
}

describe('«L\'ordine del giorno» in «Prepara l\'assemblea»', () => {
    test('i proposti spuntati, poi i punti scritti, «Varie ed eventuali» in fondo, numerati', async () => {
        await monta();
        expect(w!.get('[data-testid="ordine-del-giorno-preparato"]').text()).toContain('L\'ordine del giorno');
        expect(testi()).toEqual(['rendiconto', 'preventivo', 'varie']);
        await scriviUnPunto(punto('Il portone'));
        expect(testi()).toEqual(['rendiconto', 'preventivo', 'Il portone', 'varie']);
        expect(voci().map((v) => v.get('[data-testid="numero-della-voce"]').text())).toEqual(['1', '2', '3', '4']);
    });

    test('le frecce spostano: su e giù, il primo non sale, l\'ultimo prima delle varie non scende, le varie non si muovono', async () => {
        await monta();
        expect(freccia(1, 'su').attributes('disabled')).toBeDefined();
        expect(freccia(2, 'giu').attributes('disabled')).toBeDefined();
        expect(voci()[2].findAll('button')).toHaveLength(0);
        await freccia(1, 'giu').trigger('click');
        expect(testi()).toEqual(['preventivo', 'rendiconto', 'varie']);
        await freccia(2, 'su').trigger('click');
        expect(testi()).toEqual(['rendiconto', 'preventivo', 'varie']);
    });

    test('un punto scritto si sposta fra i proposti, e il nuovo punto resta dove l\'hai messo', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await freccia(3, 'su').trigger('click');
        await freccia(2, 'su').trigger('click');
        expect(testi()).toEqual(['Il portone', 'rendiconto', 'preventivo', 'varie']);
        await scriviUnPunto(punto('La facciata'));
        expect(testi()).toEqual(['Il portone', 'rendiconto', 'preventivo', 'La facciata', 'varie']);
    });

    test('un proposto non spuntato esce dall\'elenco', async () => {
        await monta();
        const caselle = w!.findAll('[data-testid="proposta"] [role="checkbox"]');
        await caselle[0].trigger('click');
        await flushPromises();
        expect(testi()).toEqual(['preventivo', 'varie']);
    });

    test('l\'ordine parte con «Prepara l\'assemblea»: le chiavi dei proposti e scritto:N nell\'ordine dei punti scritti', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await scriviUnPunto(punto('La facciata'));
        await freccia(4, 'su').trigger('click');
        await freccia(3, 'su').trigger('click');
        await freccia(2, 'su').trigger('click');
        expect(testi()).toEqual(['La facciata', 'rendiconto', 'preventivo', 'Il portone', 'varie']);
        await mandaLeDate();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const corpo = inertia.post.mock.calls[0][1] as { ordine: string[]; punti: { testo: string }[] };
        expect(corpo.punti.map((p) => p.testo)).toEqual(['Il portone', 'La facciata']);
        expect(corpo.ordine).toEqual(['scritto:1', 'rendiconto', 'preventivo', 'scritto:0', 'varie']);
    });

    test('senza spostare niente l\'ordine è quello di sempre', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await mandaLeDate();
        expect((inertia.post.mock.calls[0][1] as { ordine: string[] }).ordine).toEqual(['rendiconto', 'preventivo', 'scritto:0', 'varie']);
    });
});
