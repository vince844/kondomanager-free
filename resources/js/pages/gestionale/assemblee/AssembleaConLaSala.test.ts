// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina dell'assemblea con il passo «In sala»: la sala (le deleghe ricevute, la fotografia provvisoria) arriva quando il passo si apre, con una visita
 * parziale, e sta nel suo punto d'innesto; «Apri la sala» nell'intestazione porta alla sala dal giorno della prima convocazione («Torna in sala» durante),
 * spento prima con il perché. Le card del passo chiedono alla pagina le sue finestre: «Vedi le deleghe» il cassetto delle deleghe, «Segna una delega
 * ricevuta» (senza deleghe) la finestra; dopo una stampa dalla card, tornando alla pagina i passi e la sala si rileggono (il foglio torna verde).
 *
 * Che cosa non copre: il passo da solo (`PassoInSala.test.ts`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { salaDaAprire } from '@/components/assemblee/sala/salaDiProva';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Deferred: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href" v-bind="$attrs"><slot /></a>' },
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
    SchedaConvocazione: true, SchedaFascicolo: true, ConvocazioneGuide: true, AssembleaGuide: true, OrdineDelGiorno: true, RiunioneRegistrata: true,
    DiarioDellAssemblea: true, DateDellAssemblea: true, TabellaDiProprieta: true, TenutaFuoriDalProgramma: true, ConfirmDialog: true,
    DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa,
    DropdownMenuItem: { props: ['disabled'], emits: ['select'], template: '<div role="menuitem" :data-disabled="disabled ? \'\' : undefined" @click="!disabled && $emit(\'select\')"><slot /></div>' },
    DropdownMenuSeparator: true, CassettoDelDiario: true,
    PassoInSala: finto('PassoInSala', ['sala', 'apriLaSala']),
    CassettoDelleDeleghe: finto('CassettoDelleDeleghe', ['open']),
    SegnaUnaDelegaRicevuta: finto('SegnaUnaDelegaRicevuta', ['open']),
};

const Pagina = (await import('./Assemblea.vue')).default;
const passo = (chiave: string, numero: number, nome: string, stato: string) => ({ chiave, numero, nome, stato, riga: null, perche: null, manca: [], scopo: null, piede: '' });
const PASSI = [passo('date', 1, 'Date e ordine del giorno', 'fatto'), passo('documenti', 2, 'Documenti', 'fatto'), passo('convocazione', 3, 'Convocazione', 'fatto'),
    passo('sala', 4, 'In sala', 'adesso'), passo('verbale', 5, 'Verbale e trasmissione', 'chiuso')];

function props(piu: Record<string, unknown> = {}) {
    return {
        condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], esercizio: null,
        assemblea: { id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', sottotitolo: '', stato: 'convocata',
            stato_parola: 'Convocata', tenuta_fuori: false, in_preparazione: false, riconvocata_da: null, sedute: [], tipo: 'ordinaria', ambito: 'condominio', modalita: 'presenza',
            apri_la_sala: { attiva: false, perche: 'La sala si apre il giorno della prima convocazione, il 14/12/2026.' } },
        ordine: [], passi: PASSI, scheda: null, punto: null, memo: null,
        scelte: { esiti: [], materie: [], maggioranze: [], maggioranze_proposte: {}, palazzine: [], scale: [], tabelle: [], unita: 0 },
        riunioni_a_mano: [], puo: { gestire: true, registrare_il_verbale: false },
        ...piu,
    };
}
let w: VueWrapper | null = null;
beforeEach(() => { inertia.reload.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Pagina as never, { attachTo: document.body, props: props(piu) as never, global: { stubs } });
    await flushPromises();

    return w;
}

describe('la pagina dell\'assemblea con il passo «In sala»', () => {
    test('il passo di adesso è la sala: arriva con una visita parziale, e sta nel suo punto d\'innesto', async () => {
        await monta();
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['sala'] });
        expect(w!.get('[data-innesto="sala"]').text()).toContain('Un momento…');
        w!.unmount();
        await monta({ sala: salaDaAprire() });
        expect(w!.find('[data-innesto="sala"] [data-finto="PassoInSala"]').exists()).toBe(true);
    });

    test('«Apri la sala» nel menu «Azioni»: spenta prima del giorno, con il perché sotto la voce; dal giorno porta alla sala, durante «Torna in sala»', async () => {
        await monta({ scheda: 'date' });
        const spento = w!.get('[data-testid="apri-la-sala"]');
        expect(spento.attributes('data-disabled')).toBeDefined();
        expect(spento.get('[data-testid="perche-sala"]').text()).toContain('il 14/12/2026');
        w!.unmount();
        await monta({ scheda: 'date', assemblea: { ...props().assemblea, stato: 'in_corso', apri_la_sala: { attiva: true, perche: 'L\'assemblea è in corso.', url: '/sala',
            testo: 'Torna in sala' } } });
        const acceso = w!.get('[data-testid="apri-la-sala"]');
        expect(acceso.element.tagName).toBe('A');
        expect(acceso.attributes('href')).toBe('/sala');
        expect(acceso.get('[data-testid="nome-voce"]').text()).toBe('Torna in sala');
    });

    test('«Vedi le deleghe» della card apre il cassetto delle deleghe; senza deleghe la card apre subito «Segna una delega ricevuta»', async () => {
        const conLaVoce = (collegamento: Record<string, unknown>) => PASSI.map((p) => (p.chiave === 'sala' ? { ...p, manca: [{ chiave: 'deleghe', fatto: true,
            informa: true, titolo: 'Deleghe ricevute', dettaglio: '1 valida', collegamento: { url: null, passo: null, ...collegamento } }] } : p));
        const aperta = (nome: string) => JSON.parse(w!.get(`[data-finto="${nome}"]`).attributes('data-prop')!).open;
        await monta({ sala: salaDaAprire(), passi: conLaVoce({ testo: 'Vedi le deleghe', apre: 'deleghe' }) });
        expect(aperta('CassettoDelleDeleghe')).toBe(false);
        await w!.get('[data-testid="che-cosa-manca"] [data-testid="pulsante-della-voce"]').trigger('click');
        expect(aperta('CassettoDelleDeleghe')).toBe(true);
        expect(aperta('SegnaUnaDelegaRicevuta')).toBe(false);
        w!.unmount();

        await monta({ sala: salaDaAprire(), passi: conLaVoce({ testo: 'Segna una delega ricevuta', apre: 'segna_delega' }) });
        await w!.get('[data-testid="che-cosa-manca"] [data-testid="pulsante-della-voce"]').trigger('click');
        expect(aperta('SegnaUnaDelegaRicevuta')).toBe(true);
        expect(aperta('CassettoDelleDeleghe')).toBe(false);
        w!.unmount();

        // Chi non gestisce le assemblee non può segnare una delega: la card non ha il pulsante.
        await monta({ sala: salaDaAprire(), passi: conLaVoce({ testo: 'Segna una delega ricevuta', apre: 'segna_delega' }), puo: { gestire: false, registrare_il_verbale: false } });
        expect(w!.find('[data-testid="che-cosa-manca"] [data-testid="pulsante-della-voce"]').exists()).toBe(false);
    });

    test('dopo una stampa dalla card, tornando alla pagina i passi e la sala si rileggono: il foglio ristampato torna verde', async () => {
        const passi = PASSI.map((p) => (p.chiave === 'sala' ? { ...p, manca: [{ chiave: 'foglio', fatto: false, informa: false, rifare: true, titolo: 'Il foglio della sala',
            dettaglio: 'una delega è cambiata dopo la stampa del 13/12 alle 17:00: ristampalo',
            collegamento: { testo: 'Ristampa il foglio', url: '/foglio', passo: null, stampa: true } }] } : p));
        await monta({ sala: salaDaAprire(), passi });
        inertia.reload.mockClear();
        const pulsante = w!.get('[data-testid="che-cosa-manca"] [data-testid="pulsante-della-voce"]');
        pulsante.element.addEventListener('click', (e) => e.preventDefault());
        await pulsante.trigger('click');
        expect(inertia.reload).not.toHaveBeenCalled();
        window.dispatchEvent(new Event('focus'));
        await flushPromises();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(inertia.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['passi', 'sala'] }));
        // Una volta sola: tornare di nuovo alla pagina non rilegge ancora.
        window.dispatchEvent(new Event('focus'));
        await flushPromises();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
    });
});
