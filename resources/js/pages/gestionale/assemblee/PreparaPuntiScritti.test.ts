// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Prepara l'assemblea» con i punti scritti dall'amministratore: sotto i punti proposti «Aggiungi un punto» apre la finestra della pagina dell'assemblea
 * (`FinestraDelPunto`, `nella-pagina`), che non manda niente al server; il punto resta nella pagina, nel gruppo «Scritti da te», con «Modifica» e «Togli».
 *
 * - La riga del passo 1 e il piede («Adesso: N punti nell'ordine del giorno…») contano anche i punti scritti.
 * - «Prepara l'assemblea» manda i punti scritti in `punti`, nell'ordine della pagina, insieme ai proposti spuntati.
 * - Un punto scritto che il server rifiuta (`punti.1.testo`) ha l'errore sotto di sé, e riaprendolo con «Modifica» la finestra lo mostra; corretto, l'errore va
 *   via. Togliere un punto toglie anche il suo errore.
 * - La frase dell'ordine del giorno dice che i punti si aggiungono anche qui.
 *
 * Che cosa non copre: la finestra (`FinestraDelPuntoNellaPagina.test.ts`); il server (`PuntiScrittiInPreparaTest`, `CatenaDelPuntoScrittoTest`).
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
const righe = () => w!.findAll('[data-testid="punto-scritto"]');

describe('lasciare la pagina con i punti scritti non ancora preparati', () => {
    test('il browser chiede conferma prima di chiudere la scheda, solo se ci sono punti scritti', async () => {
        await monta();
        const vuota = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(vuota);
        expect(vuota.defaultPrevented).toBe(false);
        await scriviUnPunto(punto('Rifacimento della facciata'));
        const piena = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(piena);
        expect(piena.defaultPrevented).toBe(true);
    });

    test('un clic verso un\'altra pagina chiede «Hai dei punti scritti non ancora salvati: vuoi uscire lo stesso?», e annullando si resta', async () => {
        await monta();
        await scriviUnPunto(punto('Rifacimento della facciata'));
        const prima = inertia.on.mock.calls.find((c: unknown[]) => c[0] === 'before')![1] as (e: unknown) => void;
        const chiede = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const evento = { detail: { visit: { method: 'get' } }, preventDefault: vi.fn() };
        prima(evento);
        expect(chiede).toHaveBeenCalledWith('Hai dei punti scritti non ancora salvati: vuoi uscire lo stesso?');
        expect(evento.preventDefault).toHaveBeenCalled();
        const invio = { detail: { visit: { method: 'post' } }, preventDefault: vi.fn() };
        prima(invio);
        expect(invio.preventDefault).not.toHaveBeenCalled();
        chiede.mockRestore();
    });
});

describe('«Prepara l\'assemblea» con i punti scritti da te', () => {
    test('sotto i proposti «Aggiungi un punto» apre la finestra della pagina dell\'assemblea, che tiene il punto nella pagina; la frase lo dice', async () => {
        await monta();
        const ordine = w!.get('[data-testid="ordine-proposto"]');
        expect(ordine.text()).toContain('I tuoi li scrivi qui con «Aggiungi un punto»');
        expect(ordine.text()).not.toContain('I punti scritti da te li aggiungi nella pagina dell\'assemblea');
        // Sotto i gruppi dei proposti.
        const html = ordine.html();
        // «Aggiungi un punto» sta in alto a destra, nell'intestazione dell'ordine del giorno (come nella pagina dell'assemblea).
        expect(w!.get('[data-testid="intestazione-ordine"]').find('[data-testid="aggiungi-punto"]').exists()).toBe(true);
        expect(html.indexOf('data-testid="aggiungi-punto"')).toBeLessThan(html.indexOf('data-testid="gruppo-proposte"'));
        expect(finestra().props('open')).toBe(false);
        await w!.get('[data-testid="aggiungi-punto"]').trigger('click');
        expect(finestra().props()).toMatchObject({ open: true, nellaPagina: true, bozza: null, tabellaDellAssemblea: 'Proprietà' });
        expect(finestra().props('assembleaNome')).toContain('Assemblea ordinaria di Via Roma 12');
    });

    test('il punto scritto sta in «Scritti da te», con i suoi badge; la riga del passo e il piede lo contano', async () => {
        await monta();
        expect(w!.find('[data-testid="scritti-da-te"]').exists()).toBe(false);
        await scriviUnPunto(punto('Sostituzione del portone d\'ingresso'));
        await scriviUnPunto(punto('Funi dell\'ascensore della scala B', { ambito: 'scala', scala_id: 4, conduttore: 'vota', maggioranza: null }));

        const gruppo = w!.get('[data-testid="scritti-da-te"]');
        expect(gruppo.text()).toContain('Scritti da te');
        expect(gruppo.text()).toContain('prima di «Varie ed eventuali»');
        expect(righe().map((r) => r.find('p').text())).toEqual(['Sostituzione del portone d\'ingresso', 'Funi dell\'ascensore della scala B']);
        expect(righe()[0].text()).toContain('Lavori');
        expect(righe()[0].text()).toContain('Metà del valore');
        expect(righe()[1].text()).toContain('Solo la scala B');
        expect(righe()[1].text()).toContain('Votano i conduttori');
        expect(righe()[1].text()).toContain('Maggioranza da scegliere');
        expect(righe()[0].find('[data-testid="modifica-punto-scritto"]').text()).toBe('Modifica');
        expect(righe()[0].find('[data-testid="togli-punto-scritto"]').text()).toBe('Togli');
        expect(w!.get('[data-testid="piede-del-passo"]').text()).toContain('Adesso: 5 punti nell\'ordine del giorno.');
        expect(w!.get('[data-testid="strada"] [data-passo="date"]').text()).toContain('5 punti');
    });

    test('«Modifica» riapre la finestra con il punto scritto e lo cambia; «Togli» lo toglie, e il conto torna', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await scriviUnPunto(punto('Il cancello'));
        await righe()[0].get('[data-testid="modifica-punto-scritto"]').trigger('click');
        expect(finestra().props()).toMatchObject({ open: true, bozza: punto('Il portone') });
        finestra().vm.$emit('salva', punto('Il portone d\'ingresso', { materia: 'riscaldamento' }));
        await flushPromises();
        expect(righe().map((r) => r.find('p').text())).toEqual(['Il portone d\'ingresso', 'Il cancello']);
        expect(righe()[0].text()).toContain('Riscaldamento');

        await righe()[0].get('[data-testid="togli-punto-scritto"]').trigger('click');
        expect(righe().map((r) => r.find('p').text())).toEqual(['Il cancello']);
        expect(w!.get('[data-testid="piede-del-passo"]').text()).toContain('Adesso: 4 punti nell\'ordine del giorno.');
        await righe()[0].get('[data-testid="togli-punto-scritto"]').trigger('click');
        expect(w!.find('[data-testid="scritti-da-te"]').exists()).toBe(false);
        expect(w!.get('[data-testid="piede-del-passo"]').text()).toContain('Adesso: 3 punti nell\'ordine del giorno.');
    });

    test('«Prepara l\'assemblea» manda i punti scritti in `punti`, nell\'ordine della pagina, con i proposti spuntati', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await scriviUnPunto(punto('Il cancello', { importo: '8.200,00' }));
        await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][1]).toMatchObject({ proposte: ['rendiconto', 'preventivo', 'varie'],
            punti: [punto('Il portone'), punto('Il cancello', { importo: '8.200,00' })] });
    });

    test('l\'errore del server su un punto scritto sta sotto quel punto e nella finestra riaprendolo; corretto o tolto, va via', async () => {
        await monta();
        await scriviUnPunto(punto('Il portone'));
        await scriviUnPunto(punto('Il cancello'));
        await w!.get('[data-testid="scrivi-le-date"]').trigger('click');
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        const opzioni = inertia.post.mock.calls[0][2] as { onError: (e: Record<string, string>) => void; onFinish: () => void };
        opzioni.onError({ 'punti.1.importo': 'L\'importo si scrive così: 8.200,00.', luogo: 'Scrivi dove si tiene.' });
        opzioni.onFinish();
        await flushPromises();

        expect(righe()[0].find('[data-testid="errore-punto-scritto"]').exists()).toBe(false);
        expect(righe()[1].get('[data-testid="errore-punto-scritto"]').text()).toBe('L\'importo si scrive così: 8.200,00.');
        expect(w!.get('[data-testid="errore-dei-punti-scritti"]').text()).toContain('L\'assemblea non è ancora nata');
        // Gli altri errori restano in fondo; quello del punto non si ripete lì.
        const inFondo = w!.findAll('[data-testid="errore-della-pagina"]').map((e) => e.text());
        expect(inFondo).toEqual(['Scrivi dove si tiene.']);

        await righe()[1].get('[data-testid="modifica-punto-scritto"]').trigger('click');
        expect(finestra().props('erroriDelPunto')).toEqual({ importo: 'L\'importo si scrive così: 8.200,00.' });
        finestra().vm.$emit('salva', punto('Il cancello', { importo: '8.200,00' }));
        await flushPromises();
        expect(righe()[1].find('[data-testid="errore-punto-scritto"]').exists()).toBe(false);
        expect(w!.find('[data-testid="errore-dei-punti-scritti"]').exists()).toBe(false);

        // Ancora una volta: l'errore sul primo, poi il primo tolto: l'errore va via con lui.
        await w!.get('[data-testid="prepara-assemblea"]').trigger('click');
        const altre = inertia.post.mock.calls[1][2] as { onError: (e: Record<string, string>) => void; onFinish: () => void };
        altre.onError({ 'punti.0.testo': 'Scrivi il punto, come andrà nell\'avviso.' });
        altre.onFinish();
        await flushPromises();
        expect(righe()[0].get('[data-testid="errore-punto-scritto"]').text()).toBe('Scrivi il punto, come andrà nell\'avviso.');
        await righe()[0].get('[data-testid="togli-punto-scritto"]').trigger('click');
        expect(righe().map((r) => r.find('p').text())).toEqual(['Il cancello']);
        expect(w!.find('[data-testid="errore-punto-scritto"]').exists()).toBe(false);
        expect(w!.find('[data-testid="errore-dei-punti-scritti"]').exists()).toBe(false);
    });
});
