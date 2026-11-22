// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina dell'assemblea con la strada dei cinque passi (la variante A della bozza dei passi guidati): la strada prende il posto delle tre card, dei
 * pallini, della fila delle schede e del menu delle schede al telefono.
 *
 * - Senza `scheda` si apre il passo di adesso, chiesto al server con una visita parziale; `scheda` apre il passo di sempre («documenti» per il fascicolo).
 * - Un clic sulla strada apre il passo: il contenuto di oggi (le date e l'ordine del giorno, `SchedaFascicolo`, `SchedaConvocazione`), e «Guida» quella del
 *   passo. Il diario non è più un passo né un collegamento della strada: «Diario» nel menu «Azioni» lo apre nel cassetto di lato.
 * - Nel passo 1 i punti proposti che aspettano entrano nella voce «L'ordine del giorno» («6 punti · 3 proposti aspettano · Vedili»): le voci restano tre, e
 *   non bloccano «Avanti». Le voci portano dentro la pagina: le date, la tabella del valore, l'ordine del giorno hanno il loro `id`.
 * - Salvare le date rilegge `passi`, non più le card, e il passo «Convocazione» o «Documenti» già letto; una riunione registrata a mano non ha la strada.
 *
 * Che cosa non copre: la strada da sola (`StradaDellAssemblea.test.ts`); le regole dei passi (`PassiDellAssembleaTest`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
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
    PageHeaderGuide: { emits: ['open-text-guide'], template: '<header><slot name="actions" /></header>' },
    SchedaConvocazione: finto('SchedaConvocazione', ['convocazione']),
    SchedaFascicolo: finto('SchedaFascicolo', ['fascicolo']),
    ConvocazioneGuide: finto('ConvocazioneGuide', ['open']),
    AssembleaGuide: finto('AssembleaGuide', ['open']),
    OrdineDelGiorno: finto('OrdineDelGiorno', ['proposte', 'rileggi']),
    RiunioneRegistrata: finto('RiunioneRegistrata'),
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
beforeEach(() => { inertia.reload.mockClear(); inertia.patch.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Pagina as never, { attachTo: document.body, props: props(piu) as never, global: { stubs } });
    await flushPromises();

    return w;
}
const visibile = (sel: string) => (w!.get(sel).element as HTMLElement).style.display !== 'none';
const passoSullaStrada = (chiave: string) => w!.get(`[data-testid="strada"] [data-passo="${chiave}"]`);

describe('la pagina dell\'assemblea con la strada', () => {
    test('la strada prende il posto delle tre card, dei pallini, delle schede e del menu delle schede', async () => {
        await monta();
        expect(w!.find('[data-testid="strada-dell-assemblea"]').exists()).toBe(true);
        for (const via of ['card-assemblea', 'card-convocazione', 'passi', 'schede']) expect(w!.find(`[data-testid="${via}"]`).exists(), via).toBe(false);
        expect(w!.find('.v-select-finto').exists()).toBe(false);
    });

    test('senza «scheda» si apre il passo di adesso, chiesto al server', async () => {
        await monta();
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['convocazione'] });
        expect(visibile('[data-testid="innesto-convocazione"]')).toBe(true);
        expect(visibile('[data-testid="scheda-date"]')).toBe(false);
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 3 · Convocazione');
        expect(w!.find('[data-finto="ConvocazioneGuide"]').exists()).toBe(true);
    });

    // Vincenzo, 21/11/2026 sera: dopo «Annulla e riconvoca» la pagina dell'assemblea nuova restava sul passo «Convocazione» di prima con «Un momento…»:
    // Inertia teneva viva la stessa pagina, e il passo aperto (con i suoi dati da chiedere) non ripartiva. Quando cambia l'assemblea, riparte.
    test('quando arriva un\'altra assemblea nella stessa pagina (la riconvocata), si apre il suo passo di adesso, chiesto al server', async () => {
        await monta();
        expect(visibile('[data-testid="innesto-convocazione"]')).toBe(true);
        inertia.reload.mockClear();
        const nuovi = PASSI.map((p) => (p.chiave === 'documenti' ? { ...p, stato: 'adesso' } : p.chiave === 'convocazione' ? { ...p, stato: 'chiuso' } : p));
        await w!.setProps({ ...props(), assemblea: { ...props().assemblea, id: 10 }, passi: nuovi, convocazione: undefined } as never);
        await flushPromises();
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 2 · Documenti');
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['fascicolo'] });
    });

    test('«scheda» apre il passo di sempre: «documenti» con SchedaFascicolo, chiesto al server', async () => {
        await monta({ scheda: 'documenti' });
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['fascicolo'] });
        expect(visibile('[data-testid="innesto-fascicolo"]')).toBe(true);
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 2 · Documenti');
        w!.unmount();
        await monta({ scheda: 'documenti', fascicolo: { righe: [], rotte: { componi: '/c' } } });
        expect(w!.find('[data-innesto="fascicolo"] [data-finto="SchedaFascicolo"]').exists()).toBe(true);
    });

    test('un clic sulla strada apre il passo e la sua guida; la strada non ha più il «Diario dell\'assemblea», che sta nel menu «Azioni»', async () => {
        await monta();
        await passoSullaStrada('date').get('button').trigger('click');
        expect(visibile('[data-testid="scheda-date"]')).toBe(true);
        expect(visibile('[data-testid="innesto-convocazione"]')).toBe(false);
        expect(w!.find('[data-finto="AssembleaGuide"]').exists()).toBe(true);
        expect(passoSullaStrada('date').get('button').attributes('aria-current')).toBe('step');
        expect(w!.find('[data-testid="apri-diario"]').exists()).toBe(false);
        expect(w!.find('[data-testid="scheda-diario"]').exists()).toBe(false);
        await w!.get('[data-testid="voce-diario"]').trigger('click');
        expect(JSON.parse(w!.get('[data-finto="CassettoDelDiario"]').attributes('data-prop')!).open).toBe(true);
        // Il passo aperto resta quello di prima.
        expect(visibile('[data-testid="scheda-date"]')).toBe(true);
        // I passi chiusi non si aprono.
        expect(passoSullaStrada('sala').find('button').exists()).toBe(false);
    });

    test('nel passo 1 i proposti entrano nella voce «L\'ordine del giorno»: «6 punti · 3 proposti aspettano», con «Vedili»; le voci restano tre e non bloccano', async () => {
        await monta({ scheda: 'date', proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('varie')] },
            { chiave: 'dal_programma', titolo: 'Dal programma', punti: [proposta('fattura:45'), proposta('piano_rate:12')] }] });
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        expect(voci).toHaveLength(3);
        const ordine = voci[1];
        expect(ordine.text()).toContain('L\'ordine del giorno');
        expect(ordine.get('[data-testid="riga-della-voce"]').text()).toBe('6 punti · 3 proposti aspettano');
        expect(ordine.attributes('data-fatto')).toBe('informa');
        const vedili = ordine.get('[data-testid="pulsante-della-voce"]');
        expect([vedili.text(), vedili.attributes('href')]).toEqual(['Vedili', '#proposti']);
        expect(w!.find('[data-testid="da-fare"]').exists()).toBe(false);
        expect(w!.get('[data-testid="avanti"]').attributes('disabled')).toBeUndefined();
        // Senza «Gestisce le assemblee» il riquadro dei proposti non c'è: la voce resta quella del server.
        w!.unmount();
        await monta({ scheda: 'date', puo: { gestire: false, registrare_il_verbale: false }, proposte: [{ chiave: 'di_serie', titolo: 'Di serie', punti: [proposta('varie')] }] });
        expect(w!.findAll('[data-testid="che-cosa-manca"] li')[1].text()).not.toContain('proposti');
    });

    test('le voci del passo 1 portano dentro la pagina: le date e la tabella del valore hanno il loro id', async () => {
        await monta({ scheda: 'date' });
        expect(w!.find('#date-dell-assemblea').exists()).toBe(true);
        expect(w!.find('#tabella-del-valore').exists()).toBe(true);
    });

    test('con il passo «Convocazione» già letto, i gesti delle date e dell\'ordine del giorno lo rileggono (le lettere da ristampare si vedono subito)', async () => {
        await monta({ scheda: 'date' });
        expect(JSON.parse(w!.get('[data-finto="OrdineDelGiorno"]').attributes('data-prop')!).rileggi).toEqual([]);
        w!.unmount();
        await monta({ scheda: 'date', convocazione: { rotte: {} }, fascicolo: { righe: [] } });
        expect(JSON.parse(w!.get('[data-finto="OrdineDelGiorno"]').attributes('data-prop')!).rileggi).toEqual(['convocazione', 'fascicolo']);
    });

    test('salvare le date rilegge i passi, non più le card (la visita parziale nel sorgente: il pulsante si accende solo con le date cambiate)', async () => {
        await monta({ scheda: 'date' });
        expect(w!.find('[data-testid="salva-date"]').exists()).toBe(true);
        const sorgente = (await import('node:fs')).readFileSync((await import('node:path')).resolve(process.cwd(), 'resources/js/pages/gestionale/assemblee/Assemblea.vue'), 'utf8');
        expect(sorgente).toContain('only: [\'assemblea\', \'passi\', \'flash\', ...giaLetti.value]');
        expect(sorgente).not.toContain('testata');
    });

    test('una riunione registrata a mano non ha la strada: le sue delibere e il diario, come oggi', async () => {
        await monta({ assemblea: { ...props().assemblea, stato: 'registrata_a_mano', in_preparazione: false }, passi: [] });
        expect(w!.find('[data-testid="strada-dell-assemblea"]').exists()).toBe(false);
        expect(w!.find('[data-finto="RiunioneRegistrata"]').exists()).toBe(true);
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['diario', 'seguito'] });
    });
});

describe('«Documenti» si fa con «Avanti: Convocazione»', () => {
    const conDocumentiDaVedere = PASSI.map((p) => (p.chiave === 'documenti' ? { ...p, stato: 'adesso' }
        : p.chiave === 'convocazione' ? { ...p, stato: 'dopo', perche: 'si apre quando «Documenti» è fatto' } : p));

    test('sul passo «Documenti» di adesso «Avanti: Convocazione» è acceso e segna i documenti visti dal server', async () => {
        inertia.post.mockClear();
        await monta({ passi: conDocumentiDaVedere, scheda: 'documenti' });
        const avanti = w!.get('[data-testid="documenti-visti"]');
        expect(avanti.text()).toContain('Avanti: Convocazione');
        expect(avanti.attributes('disabled')).toBeUndefined();
        await avanti.trigger('click');
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(String(inertia.post.mock.calls[0][0])).toContain('assemblee.documenti-visti');
        expect(w!.find('[data-testid="avanti"]').exists()).toBe(false);

        // Il server risponde con la stessa pagina: la pagina apre la convocazione e la chiede, senza restare su «Documenti» ad aspettare.
        inertia.reload.mockClear();
        (inertia.post.mock.calls[0][2] as { onSuccess: () => void }).onSuccess();
        await flushPromises();
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 3 · Convocazione');
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['convocazione'] });
    });

    test('con «Documenti» già fatto resta l\'«Avanti» di sempre, che apre la convocazione senza chiedere niente al server', async () => {
        inertia.post.mockClear();
        await monta({ scheda: 'documenti' });
        expect(w!.find('[data-testid="documenti-visti"]').exists()).toBe(false);
        expect(w!.get('[data-testid="avanti"]').text()).toContain('Avanti: Convocazione');
    });
});

