// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Prepara l'assemblea» per i condòmini di un gruppo (Coda 734, scene 7 e 11 della bozza approvata, decisione 699): scelto «I condòmini della tabella
 * «Box»», la tabella del valore è «Box», ferma, con la riga «la tabella del gruppo»; le proposte di tutto il condominio (il rendiconto, il preventivo, la
 * nomina, la dispensa, le regole del recupero) non si offrono, le altre restano e si tolgono la spunta come per la scala; «Prepara l'assemblea» manda
 * `ambito` gruppo con `tabella_id` il gruppo; la finestra del punto sa che l'assemblea è del gruppo.
 *
 * Che cosa non copre: il menu «Per chi» (`DateDellAssembleaGruppo.test.ts`), la finestra (`FinestraDelPuntoGruppo.test.ts`), il server
 * (`AssembleaDiUnGruppoTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/create', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options', 'disabled'], template: '<div class="v-select-finto" />' } }));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const FinestraFinta = defineComponent({
    name: 'FinestraDelPunto', props: { open: Boolean, nellaPagina: Boolean, bozza: Object, erroriDelPunto: Object, assembleaNome: String, tabellaDellAssemblea: String,
        scelte: Object, punto: Object, condominioId: Number, condominioNome: String, gruppo: Object },
    emits: ['update:open', 'salva'], setup: () => () => h('div', { 'data-testid': 'finestra-finta' }),
});
/** Le date finte: un clic sceglie le date e «Per chi» dato. */
const DateFinte = defineComponent({
    name: 'DateDellAssemblea', props: ['modelValue'], emits: ['update:modelValue'],
    setup: (p, { emit }) => () => h('button', { 'data-testid': 'per-chi-il-gruppo', onClick: () => emit('update:modelValue', { ...p.modelValue, tipo: 'straordinaria',
        perimetro: 'gruppo:5', sedute: [{ convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' },
            { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' }] }) }),
});
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, DateDellAssemblea: DateFinte, AssembleaGuide: true, FinestraDelPunto: FinestraFinta };

const Pagina = (await import('./Prepara.vue')).default;

const PASSI = [
    { chiave: 'date', numero: 1, nome: 'Date e ordine del giorno', stato: 'adesso', riga: null, perche: null, manca: [], scopo: null, piede: '' },
];
const proposta = (chiave: string, materia: string) => ({ chiave_fonte: chiave, testo: chiave, testo_pubblico: null, frase: null, materia, maggioranza: 'ordinaria',
    avviso: null, amministrazione: 'ordinaria', origine: 'di_serie', fonte: 'di_serie', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null });
const controllo = (conQuota: number, unita: number) => ({ frase: `${conQuota} unità su ${unita} · 1.000,00 millesimi`, copre: false, esatta: false, con_quota: conQuota, unita });
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }], esiti: [], a_chi: [], unita: 34,
    palazzine: [], scale: [], maggioranze_proposte: {},
    tabelle: [{ id: 5, nome: 'Box', unita: 8, di_un_gruppo: true, millesimi: '1.000,00', controlli: { condominio: controllo(8, 34), 'gruppo:5': controllo(8, 8) } },
        { id: 6, nome: 'Proprietà', unita: 26, di_un_gruppo: false, millesimi: '1.000,00', controlli: { condominio: controllo(26, 34) } }] };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; inertia.post.mockReset(); document.body.innerHTML = ''; });
async function monta(delGruppo: { di_serie?: unknown[]; da_te?: unknown[] } = {}) {
    w = mount(Pagina as never, { attachTo: document.body, global: { stubs }, props: {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null, passi: PASSI, memo: null, tabella_di_proprieta: { id: 6, nome: 'Proprietà' },
        proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('rendiconto:1', 'rendiconto'), proposta('preventivo:1', 'preventivo'),
            proposta('stato', 'comunicazione'), proposta('varie', 'varie'), ...(delGruppo.di_serie ?? [])] },
        { chiave: 'dal_programma', titolo: 'Dal programma', punti: [proposta('dispensa:1', 'dispensa'), proposta('fattura:9', 'ratifica')] },
        ...(delGruppo.da_te ? [{ chiave: 'da_te', titolo: 'Da te', punti: delGruppo.da_te }] : [])], scelte,
    } as never });
    await flushPromises();
}
type Opzione = { valore: number | null; nome: string; sotto?: string };
const menuDellaTabella = () => w!.findAllComponents({ name: 'VSelect' }).find((c) => (c.props('options') as Opzione[]).some((o) => o.nome === 'Proprietà' || o.nome === 'Box'))!;
const testiProposti = () => w!.findAll('[data-testid="proposta"]').map((p) => p.find('p').text().trim());

describe('«Prepara l\'assemblea» per i condòmini di un gruppo', () => {
    test('di serie la tabella si sceglie e tutte le proposte ci sono', async () => {
        await monta();
        expect(menuDellaTabella().props('disabled')).toBeFalsy();
        expect(testiProposti().length).toBe(6);
        expect(w!.find('[data-testid="tabella-del-gruppo"]').exists()).toBe(false);
    });

    test('scelto il gruppo, la tabella del valore è «Box», ferma, con la riga della tabella del gruppo', async () => {
        await monta();
        await w!.get('[data-testid="per-chi-il-gruppo"]').trigger('click');
        await flushPromises();
        expect(menuDellaTabella().props('disabled')).toBe(true);
        expect(menuDellaTabella().props('modelValue')).toBe(5);
        expect(w!.get('[data-testid="tabella-del-gruppo"]').text().replace(/\s+/g, ' ')).toBe('Box · la tabella del gruppo · 8 unità su 8 · 1.000,00 millesimi');
        expect(w!.find('[data-testid="segna-proprieta"]').exists()).toBe(false);
    });

    test('le proposte di tutto il condominio non si offrono; le altre restano, non spuntate; «Varie ed eventuali» spuntata', async () => {
        await monta();
        await w!.get('[data-testid="per-chi-il-gruppo"]').trigger('click');
        await flushPromises();
        expect(testiProposti()).toEqual(['stato', 'varie', 'fattura:9']);
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        const [, dati] = inertia.post.mock.calls[0] as [string, Record<string, unknown>];
        expect(dati).toMatchObject({ ambito: 'gruppo', tabella_id: 5, proposte: ['varie'] });
    });

    test('le proposte di un gruppo (il memo del punto rinviato, il rendiconto della sua gestione) ci sono solo con «Per chi» quel gruppo; il suo memo spuntato, '
        + 'quello di tutto il condominio no', async () => {
        const delGruppo = (p: ReturnType<typeof proposta>, gruppo: number, origine: string) => ({ ...p, gruppo_id: gruppo, spuntato: false, origine });
        await monta({
            di_serie: [delGruppo(proposta('rendiconto_gestione:4', 'rendiconto'), 5, 'di_serie'), delGruppo(proposta('rendiconto_gestione:8', 'rendiconto'), 7, 'di_serie')],
            da_te: [delGruppo({ ...proposta('memo:3', 'altro'), memo_id: 3 }, 5, 'da_te'), proposta('memo:2', 'altro')],
        });
        expect(testiProposti()).not.toContain('memo:3');
        expect(testiProposti()).not.toContain('rendiconto_gestione:4');
        await w!.get('[data-testid="per-chi-il-gruppo"]').trigger('click');
        await flushPromises();
        expect(testiProposti()).toEqual(['stato', 'varie', 'rendiconto_gestione:4', 'fattura:9', 'memo:3', 'memo:2']);
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        const [, dati] = inertia.post.mock.calls[0] as [string, Record<string, unknown>];
        expect(dati).toMatchObject({ ambito: 'gruppo', tabella_id: 5, proposte: ['varie', 'memo:3'] });
    });

    test('la finestra del punto sa che l\'assemblea è del gruppo', async () => {
        await monta();
        expect(w!.findComponent({ name: 'FinestraDelPunto' }).props('gruppo')).toBeNull();
        await w!.get('[data-testid="per-chi-il-gruppo"]').trigger('click');
        await flushPromises();
        expect(w!.findComponent({ name: 'FinestraDelPunto' }).props('gruppo')).toEqual({ tabella_id: 5, nome: 'Box', unita: 8 });
        expect(w!.findComponent({ name: 'FinestraDelPunto' }).props('assembleaNome'))
            .toBe('Assemblea straordinaria dei condòmini della tabella «Box» di Via Roma 12 · prima convocazione 14/12/2026, seconda 15/12/2026');
    });
});
