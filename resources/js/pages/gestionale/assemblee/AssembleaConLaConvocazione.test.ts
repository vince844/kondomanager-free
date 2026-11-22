// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina dell'assemblea monta la convocazione (Coda 679): nel passo «Convocazione» la `SchedaConvocazione` con la prop `convocazione`, nel passo
 * «Documenti» la `SchedaFascicolo` con la prop `fascicolo`, chieste al server quando il passo si apre (una visita parziale); finché non arrivano, «Un
 * momento…». Il pulsante «Fascicolo» compone il PDF con un POST alla rotta del fascicolo; «Guida» apre la guida della convocazione quando il passo aperto è
 * la convocazione o i documenti, altrimenti quella dell'assemblea. Al posto delle card della testata, la riga del passo sulla strada.
 *
 * Adeguato ai passi guidati: le schede e le card non ci sono più; si apre il passo con un clic sulla strada, e la riga del passo dice ciò che dicevano le
 * card «Convocazione» e «Termini».
 *
 * Che cosa non copre: le due schede dentro (hanno i loro test, `SchedaConvocazione.test.ts`, `SchedaFascicolo.test.ts`); i dati (li prova il server,
 * `PaginaConLaConvocazioneTest`).
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
const passa = { template: '<div><slot /><slot name="actions" /></div>' };
const stubs = {
    GestionaleLayout: passa,
    PageHeaderGuide: { emits: ['open-text-guide'], template: '<header><button data-testid="apri-guida" @click="$emit(\'open-text-guide\')">Guida</button><slot name="actions" /></header>' },
    SchedaConvocazione: finto('SchedaConvocazione', ['convocazione']),
    SchedaFascicolo: finto('SchedaFascicolo', ['fascicolo']),
    ConvocazioneGuide: finto('ConvocazioneGuide', ['open']),
    AssembleaGuide: finto('AssembleaGuide', ['open']),
    DateDellAssemblea: true, OrdineDelGiorno: true, TabellaDiProprieta: true, DiarioDellAssemblea: true, TenutaFuoriDalProgramma: true, RiunioneRegistrata: true,
    ConfirmDialog: true, DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa,
    DropdownMenuItem: { props: ['disabled'], emits: ['select'], template: '<div role="menuitem" :data-disabled="disabled ? \'\' : undefined" @click="!disabled && $emit(\'select\')"><slot /></div>' },
    DropdownMenuSeparator: true, CassettoDelDiario: true,
};

const Pagina = (await import('./Assemblea.vue')).default;

const passo = (chiave: string, numero: number, nome: string, stato: string, riga: string | null = null) => ({
    chiave, numero, nome, stato, riga, perche: stato === 'chiuso' ? riga : null, manca: [], scopo: null, piede: '',
});
const PASSI = [
    passo('date', 1, 'Date e ordine del giorno', 'fatto', '14/12 e 15/12 · 6 punti'), passo('documenti', 2, 'Documenti', 'fatto'),
    passo('convocazione', 3, 'Convocazione', 'adesso', '9 da spedire · entro il 03/12'), passo('sala', 4, 'In sala', 'chiuso', 'si apre il 14/12'),
    passo('verbale', 5, 'Verbale e trasmissione', 'chiuso', 'dopo la sala'),
];

function props(piu: Record<string, unknown> = {}) {
    return {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null,
        assemblea: {
            id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', sottotitolo: '', stato: 'in_preparazione',
            stato_parola: 'In preparazione', tenuta_fuori: false, in_preparazione: true, apri_la_sala: { attiva: false, perche: '' }, riconvocata_da: null,
            sedute: [], tipo: 'ordinaria', ambito: 'condominio', modalita: 'presenza',
        },
        ordine: [], passi: PASSI,
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
/** Il passo sulla strada (dal tablet in su): un pulsante, se si apre. */
const sullaStrada = (chiave: string) => w!.get(`[data-testid="strada"] [data-passo="${chiave}"] button`);

describe('la pagina dell\'assemblea con la convocazione', () => {
    test('il passo «Convocazione» si chiede al server quando si apre, e intanto dice «Un momento…»', async () => {
        await monta();
        await sullaStrada('convocazione').trigger('click');
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['convocazione'] });
        expect(w!.find('[data-innesto="convocazione"]').text()).toContain('Un momento…');
        expect(w!.find('[data-finto="SchedaConvocazione"]').exists()).toBe(false);
    });

    test('con la prop il passo «Convocazione» monta SchedaConvocazione, e «Documenti» SchedaFascicolo, nei loro punti d\'innesto', async () => {
        await monta({ scheda: 'convocazione', convocazione: { stato: 'non_spedita', rotte: { pec: '/pec' } }, fascicolo: { righe: [], rotte: { componi: '/c' } } });
        const convocazione = w!.find('[data-innesto="convocazione"] [data-finto="SchedaConvocazione"]');
        expect(convocazione.exists()).toBe(true);
        expect(JSON.parse(convocazione.attributes('data-prop')!).convocazione.rotte.pec).toBe('/pec');
        const fascicolo = w!.find('[data-innesto="fascicolo"] [data-finto="SchedaFascicolo"]');
        expect(fascicolo.exists()).toBe(true);
        expect(JSON.parse(fascicolo.attributes('data-prop')!).fascicolo.rotte.componi).toBe('/c');
    });

    test('al posto delle card della testata, la riga del passo sulla strada', async () => {
        await monta();
        expect(w!.find('[data-testid="card-convocazione"]').exists()).toBe(false);
        expect(w!.get('[data-testid="strada"] [data-passo="convocazione"]').text()).toContain('9 da spedire · entro il 03/12');
        expect(w!.get('[data-testid="strada"] [data-passo="date"]').text()).toContain('14/12 e 15/12 · 6 punti');
    });

    test('«Fascicolo» nel menu «Azioni» compone il PDF con un POST alla rotta del fascicolo; senza «Gestisce le assemblee» la voce non c\'è', async () => {
        await monta();
        const pulsante = w!.find('[data-testid="pulsante-fascicolo"]');
        expect(pulsante.attributes('disabled')).toBeUndefined();
        await pulsante.trigger('click');
        await flushPromises();
        expect(chiesti.url).toEqual(['/admin.gestionale.assemblee.fascicolo.componi/3/9']);
        expect(w!.find('[data-testid="esito-fascicolo"]').text()).toBe('Il PDF è aperto in una scheda nuova.');
        w!.unmount();
        await monta({ puo: { gestire: false, registrare_il_verbale: false } });
        expect(w!.find('[data-testid="pulsante-fascicolo"]').exists()).toBe(false);
    });

    test('«Guida» apre la guida della convocazione dal passo «Convocazione», quella dell\'assemblea dalle date', async () => {
        await monta({ scheda: 'convocazione', convocazione: {} });
        expect(w!.find('[data-finto="ConvocazioneGuide"]').exists()).toBe(true);
        expect(w!.find('[data-finto="AssembleaGuide"]').exists()).toBe(false);
        await w!.find('[data-testid="apri-guida"]').trigger('click');
        expect(JSON.parse(w!.find('[data-finto="ConvocazioneGuide"]').attributes('data-prop')!).open).toBe(true);
        await sullaStrada('date').trigger('click');
        expect(w!.find('[data-finto="AssembleaGuide"]').exists()).toBe(true);
    });
});
