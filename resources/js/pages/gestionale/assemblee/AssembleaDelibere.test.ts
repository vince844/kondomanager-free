// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina dell'assemblea con il passo 6 «Delibere eseguite» (scena 1 della bozza del seguito delle delibere):
 *
 * - sei passi sulla strada; il passo 6 con il lucchetto fino al verbale registrato («si apre con il verbale registrato»), e niente badge
 *   in alto (decisioni 645 e 648: le tre card sono del passo); al telefono «Passo 6 di 6»;
 * - «?scheda=delibere» apre il passo e chiede il seguito con una visita parziale; la delibera o la cosa dell'indirizzo vanno al passo in evidenza, con il
 *   sottotitolo «Dall'assemblea del 15/12/2026, verbale n. 42.»; «Guida» apre la guida delle delibere;
 * - il verbale annullato: il passo chiuso si apre lo stesso;
 * - la riunione tenuta su carta: niente strada, il seguito nella sua pagina chiesto con il diario, «Verbale da caricare» accanto al titolo, le cose da fare
 *   delle delibere passate a «Elimina»;
 * - «Crea il piano straordinario» si apre dalla cosa dell'indirizzo (`?cosa=31`, l'azione del compito, della card e della tabella) nella strada e nella
 *   riunione tenuta su carta, una finestra sola; chiusa, l'indirizzo perde la cosa;
 * - chi vede soltanto: la testa del passo 6 senza il pulsante dell'azione, e la finestra non si apre.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Deferred: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/9', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}/${p?.assemblea ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const finto = (nome: string, prop: string[] = []) => defineComponent({
    name: nome, props: prop, setup: (p) => () => h('div', { 'data-finto': nome, 'data-prop': JSON.stringify(p) }),
});
const passa = { template: '<div><slot /><slot name="actions" /></div>' };
const stubs = {
    GestionaleLayout: passa,
    PageHeaderGuide: { emits: ['open-text-guide'], template: '<header><slot name="accanto-al-titolo" /><slot name="actions" /></header>' },
    SchedaConvocazione: finto('SchedaConvocazione', ['convocazione']),
    SchedaFascicolo: finto('SchedaFascicolo', ['fascicolo']),
    ConvocazioneGuide: finto('ConvocazioneGuide', ['open']),
    AssembleaGuide: finto('AssembleaGuide', ['open']),
    OrdineDelGiorno: finto('OrdineDelGiorno', ['proposte', 'rileggi']),
    RiunioneRegistrata: finto('RiunioneRegistrata', ['cose']),
    PassoDelleDelibere: finto('PassoDelleDelibere', ['seguito', 'esiti', 'sottotitolo', 'suCarta', 'evidenzia']),
    CreaIlPianoStraordinario: finto('CreaIlPianoStraordinario', ['open', 'cosaId']),
    DelibereGuide: finto('DelibereGuide', ['open']),
    DiarioDellAssemblea: finto('DiarioDellAssemblea', ['diario']),
    CassettoDelDiario: finto('CassettoDelDiario', ['open']),
    DateDellAssemblea: true, TabellaDiProprieta: true, TenutaFuoriDalProgramma: true,
    ConfirmDialog: true, DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa, DropdownMenuSeparator: true,
    DropdownMenuItem: { props: ['disabled'], emits: ['select'], template: '<div role="menuitem" @click="!disabled && $emit(\'select\')"><slot /></div>' },
};

const Pagina = (await import('./Assemblea.vue')).default;

const passo = (chiave: string, numero: number, nome: string, stato: string, piu: Record<string, unknown> = {}) => ({
    chiave, numero, nome, stato, riga: null, perche: null, manca: [], scopo: null, piede: `Il piede di ${nome}.`, ...piu,
});
const PASSI = [
    passo('date', 1, 'Date e ordine del giorno', 'fatto', { riga: '14/12 e 15/12 · 6 punti', manca: [
        { chiave: 'date', fatto: true, informa: false, titolo: 'Le date e il luogo', dettaglio: '14/12 e 15/12', collegamento: null },
        { chiave: 'ordine', fatto: true, informa: false, titolo: 'L\'ordine del giorno', dettaglio: '6 punti', collegamento: null },
        { chiave: 'tabella', fatto: true, informa: false, titolo: 'La tabella del valore', dettaglio: '«Proprietà» · 7 unità su 7', collegamento: null },
    ] }),
    passo('documenti', 2, 'Documenti', 'fatto', { riga: 'l\'avviso con la delega · nessun allegato' }),
    passo('convocazione', 3, 'Convocazione', 'adesso', { riga: '9 da spedire · entro il 03/12', scopo: 'prima di spedire',
        manca: [{ chiave: 'casella', fatto: false, informa: false, titolo: 'La casella PEC dello studio', dettaglio: 'da impostare', collegamento: null }] }),
    passo('sala', 4, 'In sala', 'chiuso', { riga: 'si apre il 14/12', perche: 'si apre il 14/12' }),
    passo('verbale', 5, 'Verbale e trasmissione', 'chiuso', { riga: 'dopo la sala', perche: 'dopo la sala' }),
];
const proposta = (chiave: string) => ({ chiave_fonte: chiave, testo: chiave, testo_pubblico: null, frase: null, materia: 'altro', maggioranza: 'ordinaria',
    avviso: null, amministrazione: 'ordinaria', origine: 'dal_programma', fonte: 'programma', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null });

function props(piu: Record<string, unknown> = {}) {
    return {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null,
        assemblea: {
            id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', sottotitolo: '', stato: 'in_preparazione',
            stato_parola: 'In preparazione', tenuta_fuori: false, in_preparazione: true, apri_la_sala: { attiva: false, perche: '' }, riconvocata_da: null,
            sedute: [], tipo: 'ordinaria', ambito: 'condominio', modalita: 'presenza',
        },
        ordine: [], passi: PASSI, scheda: null, punto: null, memo: null,
        scelte: { esiti: [], materie: [], maggioranze: [], maggioranze_proposte: {}, palazzine: [], scale: [], tabelle: [], unita: 0 },
        riunioni_a_mano: [], puo: { gestire: true, registrare_il_verbale: false },
        ...piu,
    };
}

let w: VueWrapper | null = null;
beforeEach(() => { inertia.reload.mockClear(); inertia.patch.mockClear(); inertia.visit.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Pagina as never, { attachTo: document.body, props: props(piu) as never, global: { stubs } });
    await flushPromises();

    return w;
}
const visibile = (sel: string) => (w!.get(sel).element as HTMLElement).style.display !== 'none';
const passoSullaStrada = (chiave: string) => w!.get(`[data-testid="strada"] [data-passo="${chiave}"]`);
const prop = (nome: string) => JSON.parse(w!.get(`[data-finto="${nome}"]`).attributes('data-prop')!);
const SEI = [...PASSI.map((p) => ({ ...p, stato: 'fatto' })),
    passo('delibere', 6, 'Delibere eseguite', 'adesso', { riga: '8 su 9 · 1 da eseguire', badge: null, manca: [
        { chiave: 'da_eseguire', fatto: false, informa: false, titolo: 'Da eseguire', dettaglio: 'Crea il piano straordinario, entro il 15/01/2027 · Andrea Costa',
            collegamento: null },
        { chiave: 'trenta_giorni', fatto: true, informa: true, titolo: 'Trenta giorni', dettaglio: 'Passati per tutti dal 22/01/2027. Resta la nullità.', collegamento: null },
    ] })];
const conVerbale = { ...props().assemblea, stato: 'chiusa', in_preparazione: false, data: '2026-12-15', numero_verbale: '42', verbale_da_caricare: false };

describe('il passo 6 «Delibere eseguite» nella strada', () => {
    test('sei passi; il passo aperto non ha il badge in alto (decisioni 645 e 648); «?scheda=delibere» chiede il seguito e mostra la cosa in evidenza', async () => {
        await monta({ assemblea: conVerbale, passi: SEI, scheda: 'delibere', cosa: 31 });
        expect(w!.findAll('[data-testid="strada"] li')).toHaveLength(6);
        expect(w!.get('[data-testid="strada"]').classes()).toContain('grid-cols-6');
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 6 · Delibere eseguite');
        expect(w!.find('[data-testid="da-fare"]').exists()).toBe(false);
        expect(w!.get('[data-testid="strada-telefono"]').text()).toContain('Passo 6 di 6');
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['seguito'] });
        expect(visibile('[data-testid="innesto-delibere"]')).toBe(true);
        expect(prop('PassoDelleDelibere')).toMatchObject({ sottotitolo: 'Dall\'assemblea del 15/12/2026, verbale n. 42.', evidenzia: { delibera: null, cosa: 31 } });
        expect(w!.find('[data-finto="DelibereGuide"]').exists()).toBe(true);
    });

    test('prima del verbale registrato il passo ha il lucchetto e non si apre; con il verbale annullato è chiuso ma si apre lo stesso', async () => {
        const chiuso = SEI.map((p) => (p.chiave === 'delibere' ? { ...p, stato: 'chiuso', riga: 'si apre con il verbale registrato', perche: 'si apre con il verbale registrato',
            badge: null, manca: [] } : p));
        await monta({ assemblea: conVerbale, passi: chiuso });
        expect(passoSullaStrada('delibere').find('[data-testid="lucchetto"]').exists()).toBe(true);
        expect(passoSullaStrada('delibere').find('button').exists()).toBe(false);
        w!.unmount();
        const annullato = chiuso.map((p) => (p.chiave === 'delibere' ? { ...p, riga: 'il verbale è annullato', perche: 'Il verbale è annullato: le delibere applicate restano.',
            apribile: true } : p));
        await monta({ assemblea: conVerbale, passi: annullato });
        await passoSullaStrada('delibere').get('button').trigger('click');
        expect(passoSullaStrada('delibere').find('[data-testid="lucchetto"]').exists()).toBe(true);
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['seguito'] });
        expect(visibile('[data-testid="innesto-delibere"]')).toBe(true);
    });
});

describe('la riunione tenuta su carta', () => {
    test('niente strada: il seguito nella sua pagina, chiesto con il diario; «Verbale da caricare» accanto al titolo; le cose da fare a «Elimina»', async () => {
        const cose = [{ id: 5, delibera_id: 11, testo: 'Firma con Serramenti Marino', stato: 'fatta' }];
        await monta({ assemblea: { ...props().assemblea, stato: 'registrata_a_mano', stato_parola: 'Tenuta su carta', tenuta_fuori: true, in_preparazione: false,
            data: '2026-12-15', numero_verbale: null, verbale_da_caricare: true }, passi: [],
            seguito: { tabella: [{ delibera_id: 11, cose }], riepilogo: { punti: [] } } });
        expect(w!.find('[data-testid="strada-dell-assemblea"]').exists()).toBe(false);
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['diario', 'seguito'] });
        expect(w!.get('[data-testid="verbale-da-caricare-in-testa"]').text()).toBe('Verbale da caricare');
        expect(prop('PassoDelleDelibere')).toMatchObject({ suCarta: true, sottotitolo: 'Dall\'assemblea tenuta su carta il 15/12/2026 · verbale da caricare.' });
        expect(prop('RiunioneRegistrata').cose).toEqual({ 11: cose });
    });
});

describe('«Crea il piano straordinario» dalla cosa dell\'indirizzo', () => {
    const cose = [{ id: 31, delibera_id: 11, tipo: 'crea_piano_straordinario', testo: 'Crea il piano straordinario (€ 14.500,00)', stato: 'da_fare' },
        { id: 32, delibera_id: 11, tipo: 'fuori_dal_programma', testo: 'Firma con Serramenti Marino', stato: 'da_fare' }];
    const seguito = { tabella: [{ delibera_id: 11, cose }], riepilogo: { punti: [] } };
    const suCarta = { ...props().assemblea, stato: 'registrata_a_mano', stato_parola: 'Tenuta su carta', tenuta_fuori: true, in_preparazione: false,
        data: '2026-12-15', numero_verbale: null, verbale_da_caricare: true };
    const finestre = () => w!.findAll('[data-finto="CreaIlPianoStraordinario"]');

    test('nella strada: una finestra sola, aperta con la cosa', async () => {
        await monta({ assemblea: conVerbale, passi: SEI, scheda: 'delibere', cosa: 31, seguito });
        expect(finestre()).toHaveLength(1);
        expect(prop('CreaIlPianoStraordinario')).toEqual({ open: true, cosaId: 31 });
    });

    test('nella riunione tenuta su carta la stessa cosa apre la stessa finestra', async () => {
        await monta({ assemblea: suCarta, passi: [], scheda: 'delibere', cosa: 31, seguito });
        expect(prop('PassoDelleDelibere').suCarta).toBe(true);
        expect(finestre()).toHaveLength(1);
        expect(prop('CreaIlPianoStraordinario')).toEqual({ open: true, cosaId: 31 });
    });

    test('un\'altra cosa, o nessuna, non apre la finestra; chiusa, l\'indirizzo perde la cosa con una visita parziale', async () => {
        await monta({ assemblea: suCarta, passi: [], scheda: 'delibere', cosa: 32, seguito });
        expect(finestre()).toHaveLength(0);
        w!.unmount();
        await monta({ assemblea: suCarta, passi: [], scheda: 'delibere', seguito });
        expect(finestre()).toHaveLength(0);
        w!.unmount();
        await monta({ assemblea: suCarta, passi: [], scheda: 'delibere', cosa: 31, seguito });
        w!.findComponent({ name: 'CreaIlPianoStraordinario' }).vm.$emit('update:open', false);
        expect(inertia.visit).toHaveBeenCalledWith(expect.not.stringContaining('cosa='), expect.objectContaining({ only: ['cosa'], replace: true }));
    });
});

describe('chi vede soltanto le assemblee', () => {
    const seguito = { tabella: [{ delibera_id: 11, cose: [{ id: 31, delibera_id: 11, tipo: 'crea_piano_straordinario', testo: 'Crea il piano straordinario',
        stato: 'da_fare' }] }], riepilogo: { punti: [] } };
    const conAzione = SEI.map((p) => (p.chiave === 'delibere' ? { ...p, manca: [{ ...p.manca[0], collegamento: { testo: 'Crea il piano straordinario',
        url: '/admin/gestionale/3/assemblee/9?scheda=delibere&cosa=31', passo: null } }, p.manca[1]] } : p));
    const pulsanti = () => w!.findAll('[data-testid="che-cosa-manca"] [data-testid="pulsante-della-voce"]').map((b) => b.text());

    test('la testa del passo 6 non ha il pulsante dell\'azione, e la cosa dell\'indirizzo non apre la finestra', async () => {
        await monta({ assemblea: conVerbale, passi: conAzione, scheda: 'delibere', cosa: 31, seguito, puo: { gestire: false, registrare_il_verbale: false } });
        expect(w!.get('[data-testid="che-cosa-manca"]').text()).toContain('Crea il piano straordinario, entro il 15/01/2027');
        expect(pulsanti()).toEqual([]);
        expect(w!.find('[data-finto="CreaIlPianoStraordinario"]').exists()).toBe(false);
    });

    test('chi gestisce ha il pulsante e la finestra', async () => {
        await monta({ assemblea: conVerbale, passi: conAzione, scheda: 'delibere', cosa: 31, seguito });
        expect(pulsanti()).toEqual(['Crea il piano straordinario']);
        expect(w!.find('[data-finto="CreaIlPianoStraordinario"]').exists()).toBe(true);
    });
});
