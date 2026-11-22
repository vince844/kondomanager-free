// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'intestazione della pagina dell'assemblea (la scena 3 della bozza approvata dei documenti dei punti e del diario): restano il condominio, «Guida», il
 * menu «Azioni» e «Indietro» all'elenco delle assemblee del condominio, come in «Prepara l'assemblea».
 *
 * - Lo stato («In preparazione», «Convocata», …) è un badge accanto al titolo, non fra i pulsanti.
 * - Fuori dal menu non ci sono più «Storia», «Fascicolo» e «Apri la sala»: il diario ha già le modifiche dell'assemblea, più gli invii.
 * - Nel menu, ognuna con la sua icona: «Diario» (il cassetto di lato), «Fascicolo», «Apri la sala» (spenta, con il perché sotto la voce), poi per chi
 *   gestisce e in preparazione «Tenuta su carta» con «Registra gli esiti dal verbale di carta» ed «Elimina l'assemblea» in rosso.
 * - Il menu c'è sempre, con le voci che servono a chi guarda: chi vede soltanto ha «Diario» e «Apri la sala».
 *
 * Che cosa non copre: la larghezza vera della fila (la misura il browser, a video); il menu aperto dal vero (qui le voci con il menu finto, sempre aperto).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
const chiesti = vi.hoisted(() => ({ url: [] as string[] }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Deferred: { template: '<div><slot name="fallback" /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/assemblee/9', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('axios', () => ({ default: { post: vi.fn(async (url: string) => { chiesti.url.push(url); return { data: new Blob(['%PDF']), headers: {} }; }) } }));
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({
    ...(await originale<typeof import('@/lib/gestionale/incassi/ricevute')>()),
    apriPdfInUnaScheda: vi.fn(async (chiedi: () => Promise<unknown>) => { await chiedi(); return { modo: 'scheda', nome: 'fascicolo.pdf' }; }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}/${p?.assemblea ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

/** Un componente finto che mostra il suo nome e le prop che riceve. */
const finto = (nome: string, prop: string[] = []) => defineComponent({
    name: nome, props: prop, setup: (p) => () => h('div', { 'data-finto': nome, 'data-prop': JSON.stringify(p) }),
});
const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa,
    PageHeaderGuide: {
        props: ['backUrl', 'backText', 'storia', 'condominio', 'hasTextGuide'],
        template: '<header :data-indietro="backUrl" :data-testo-indietro="backText" :data-storia="storia ? \'si\' : \'no\'"><div data-testid="riga-del-titolo"><slot name="accanto-al-titolo" /></div>'
            + '<div data-testid="pulsanti-intestazione"><slot name="actions" /></div></header>',
    },
    SchedaConvocazione: finto('SchedaConvocazione', ['convocazione', 'apriRiconvoca']),
    SchedaFascicolo: finto('SchedaFascicolo', ['fascicolo']),
    ConvocazioneGuide: finto('ConvocazioneGuide', ['open']),
    AssembleaGuide: finto('AssembleaGuide', ['open']),
    CassettoDelDiario: finto('CassettoDelDiario', ['open', 'diario', 'sottotitolo']),
    TenutaFuoriDalProgramma: finto('TenutaFuoriDalProgramma', ['open']),
    DateDellAssemblea: true, OrdineDelGiorno: true, TabellaDiProprieta: true, DiarioDellAssemblea: finto('DiarioDellAssemblea', ['diario']), RiunioneRegistrata: true,
    ConfirmDialog: finto('ConfirmDialog', ['modelValue']),
    DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: { template: '<div data-testid="voci-del-menu"><slot /></div>' },
    DropdownMenuItem: { props: ['disabled'], emits: ['select'], template: '<div role="menuitem" :data-disabled="disabled ? \'\' : undefined" @click="!disabled && $emit(\'select\')"><slot /></div>' },
    DropdownMenuSeparator: { template: '<hr data-testid="separatore" />' },
};

const Pagina = (await import('./Assemblea.vue')).default;

function props(piu: Record<string, unknown> = {}) {
    return {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null,
        assemblea: {
            id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', sottotitolo: '', stato: 'in_preparazione',
            stato_parola: 'In preparazione', tenuta_fuori: false, in_preparazione: true, riconvocata_da: null,
            apri_la_sala: { attiva: false, perche: 'La sala si apre il giorno della prima convocazione, il 14/12/2026. Prima si spedisce la convocazione.' },
            sedute: [], tipo: 'ordinaria', ambito: 'condominio', modalita: 'presenza',
        },
        ordine: [], passi: [],
        scheda: 'date', punto: null, memo: null,
        scelte: { esiti: [], materie: [], maggioranze: [], maggioranze_proposte: {}, palazzine: [], scale: [], tabelle: [], unita: 0 },
        riunioni_a_mano: [], puo: { gestire: true, registrare_il_verbale: false },
        ...piu,
    };
}

let w: VueWrapper | null = null;
beforeEach(() => { inertia.reload.mockClear(); chiesti.url = []; });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Pagina as never, { attachTo: document.body, props: props(piu) as never, global: { stubs } });
    await flushPromises();

    return w;
}
const voci = () => w!.findAll('[data-testid="voci-del-menu"] [role="menuitem"]');
const nomiDelleVoci = () => voci().map((v) => v.get('[data-testid="nome-voce"]').text());
const prop = (nome: string) => JSON.parse(w!.get(`[data-finto="${nome}"]`).attributes('data-prop')!);

describe('l\'intestazione dell\'assemblea', () => {
    test('lo stato è un badge accanto al titolo, non fra i pulsanti', async () => {
        await monta();
        const titolo = w!.get('[data-testid="riga-del-titolo"]');
        expect(titolo.get('[data-testid="stato"]').text()).toBe('In preparazione');
        expect(titolo.get('[data-testid="stato"]').classes()).toContain('rounded-md');
        expect(w!.get('[data-testid="pulsanti-intestazione"]').find('[data-testid="stato"]').exists()).toBe(false);
    });

    test('restano «Guida», il menu «Azioni» e «Indietro» all\'elenco delle assemblee; niente «Storia», e fuori dal menu niente «Fascicolo» né «Apri la sala»', async () => {
        await monta();
        const testa = w!.get('header');
        expect(testa.attributes('data-indietro')).toBe('/admin.gestionale.assemblee.index/3/');
        expect(testa.attributes('data-testo-indietro')).toBe('Assemblee');
        expect(testa.attributes('data-storia')).toBe('no');
        const azioni = w!.get('[data-testid="menu-assemblea"]');
        expect(azioni.text()).toContain('Azioni');
        expect(azioni.classes()).toEqual(expect.arrayContaining(['h-8', 'text-xs', 'rounded-lg', 'shadow-sm']));
        // Fuori dal menu c'è solo il pulsante «Azioni».
        const fuori = w!.get('[data-testid="pulsanti-intestazione"]').findAll('button, a').filter((b) => !b.element.closest('[data-testid="voci-del-menu"]'));
        expect(fuori.map((b) => b.attributes('data-testid'))).toEqual(['menu-assemblea']);
    });

    test('nel menu, ognuna con la sua icona: «Diario», «Fascicolo», «Apri la sala» spenta con il perché sotto, poi «Tenuta su carta» ed «Elimina l\'assemblea» in rosso', async () => {
        await monta();
        expect(nomiDelleVoci()).toEqual(['Diario', 'Fascicolo', 'Apri la sala', 'Tenuta su carta', 'Elimina l\'assemblea']);
        for (const v of voci()) {
            expect(v.find('svg').exists(), v.text()).toBe(true);
        }
        expect(voci()[0].text()).toContain('Le modifiche e gli invii, in un cassetto');
        expect(voci()[1].text()).toContain('Copertina, avviso e documenti in un PDF');
        const sala = w!.get('[data-testid="apri-la-sala"]');
        expect(sala.attributes('data-disabled')).toBeDefined();
        expect(sala.get('[data-testid="perche-sala"]').text()).toBe('La sala si apre il giorno della prima convocazione, il 14/12/2026. Prima si spedisce la convocazione.');
        expect(voci()[3].text()).toContain('Registra gli esiti dal verbale di carta');
        expect(w!.findAll('[data-testid="separatore"]')).toHaveLength(1);
        expect(w!.get('[data-testid="voce-elimina"]').classes()).toContain('text-rose-700');
    });

    // Decisione 652 (2): «Annulla e riconvoca» è un gesto sull'assemblea intera, nel menu «Azioni», in rosso; apre il passo «Convocazione» con la finestra.
    test('«Annulla e riconvoca» nel menu «Azioni» di un\'assemblea convocata: apre il passo della convocazione con la finestra', async () => {
        const conv = props().assemblea as Record<string, unknown>;
        await monta({ assemblea: { ...conv, stato: 'convocata', stato_parola: 'Convocata', in_preparazione: false }, scheda: 'convocazione', convocazione: { rotte: {} } });
        const voce = w!.get('[data-testid="voce-riconvoca"]');
        expect(voce.classes()).toContain('text-rose-700');
        expect(voce.text()).toContain('Annulla e riconvoca');
        expect(prop('SchedaConvocazione').apriRiconvoca ?? 0).toBe(0);
        await voce.trigger('click');
        await flushPromises();
        expect(prop('SchedaConvocazione').apriRiconvoca).toBe(1);
    });

    test('«Annulla e riconvoca» non c\'è in un\'assemblea in preparazione, né per chi non gestisce', async () => {
        await monta();
        expect(w!.find('[data-testid="voce-riconvoca"]').exists()).toBe(false);
    });

    test('«Diario» apre il cassetto di lato; «Tenuta su carta» la sua finestra; «Fascicolo» compone il PDF', async () => {
        await monta();
        expect(prop('CassettoDelDiario').open).toBe(false);
        await w!.get('[data-testid="voce-diario"]').trigger('click');
        expect(prop('CassettoDelDiario')).toMatchObject({ open: true, sottotitolo: 'Ordinaria del 15/12/2026' });
        await w!.get('[data-testid="voce-tenuta-fuori"]').trigger('click');
        expect(prop('TenutaFuoriDalProgramma').open).toBe(true);
        await w!.get('[data-testid="pulsante-fascicolo"]').trigger('click');
        await flushPromises();
        expect(chiesti.url).toEqual(['/admin.gestionale.assemblee.fascicolo.componi/3/9']);
        expect(w!.get('[data-testid="esito-fascicolo"]').text()).toBe('Il PDF è aperto in una scheda nuova.');
    });

    test('dal giorno «Apri la sala» porta alla sala; durante, «Torna in sala»', async () => {
        await monta({ assemblea: { ...props().assemblea, stato: 'in_corso', in_preparazione: false,
            apri_la_sala: { attiva: true, perche: 'L\'assemblea è in corso.', url: '/sala', testo: 'Torna in sala' } } });
        const sala = w!.get('[data-testid="apri-la-sala"]');
        expect(sala.element.tagName).toBe('A');
        expect(sala.attributes('href')).toBe('/sala');
        expect(sala.get('[data-testid="nome-voce"]').text()).toBe('Torna in sala');
        // Fuori dalla preparazione niente «Tenuta su carta» né «Elimina».
        expect(nomiDelleVoci()).toEqual(['Diario', 'Fascicolo', 'Torna in sala']);
    });

    test('il menu c\'è sempre, con le voci di chi guarda: chi vede soltanto ha «Diario» e «Apri la sala»', async () => {
        await monta({ puo: { gestire: false, registrare_il_verbale: false } });
        expect(w!.find('[data-testid="menu-assemblea"]').exists()).toBe(true);
        expect(nomiDelleVoci()).toEqual(['Diario', 'Apri la sala']);
    });

    test('la riunione registrata a mano: il menu con «Diario», e il diario resta sotto le delibere, come oggi', async () => {
        await monta({ assemblea: { ...props().assemblea, stato: 'registrata_a_mano', in_preparazione: false } });
        expect(nomiDelleVoci()).toEqual(['Diario']);
        expect(w!.find('[data-finto="DiarioDellAssemblea"]').exists()).toBe(true);
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['diario', 'seguito'] });
    });

    test('«?scheda=diario» apre il passo di adesso con il cassetto del diario aperto', async () => {
        await monta({ scheda: 'diario' });
        expect(prop('CassettoDelDiario').open).toBe(true);
    });
});
