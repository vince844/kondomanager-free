// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Il registro dei verbali» (scena 2 della bozza del verbale, scene 3, 7 e 8 della bozza delle scene): le righe con il numero, l'annotazione senza numero, la
 * colonna «Nel libro di carta» con «Stampa la pagina» e «Segna incollata»; la prima volta «Da dove parte il numero?»; il registro della scala; nomina e
 * revoca con la riga proposta, «Correggi» in evidenza e «Conferma la riga», la nomina in corso la prima volta.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { registroDiProva } from '@/components/assemblee/verbale/verbaleDiProva';

const inertia = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Head: { render: () => null }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/', props: { auth: { user: { name: 'Andrea Costa', roles: ['amministratore'], permissions: [] } }, flash: {} } }) }));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return { default: defineComponent({ name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
        setup(p, { emit, attrs }) {
            return () => h('div', { 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => h('button', { type: 'button', 'data-opzione': i,
                onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o) }, String((o as Record<string, unknown>)[p.label ?? 'label']))));
        } }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

let w: VueWrapper | null = null;
beforeEach(() => { Object.values(inertia).forEach((f) => f.mockReset()); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const ARCHIVIO = { url: 'http://km.test/admin/gestionale/3/assemblee', testo: 'Assemblee' };
async function monta(registro = registroDiProva(), ritorno: { url: string; testo: string } = ARCHIVIO) {
    const Pagina = (await import('./RegistroDeiVerbali.vue')).default;
    w = mount(Pagina as never, { attachTo: document.body, props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], registro, ritorno } as never,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { props: ['pageTitle', 'pageSubtitle', 'guides', 'backUrl', 'backText'],
            template: '<header><h1>{{ pageTitle }}</h1><p>{{ pageSubtitle }}</p><ul data-testid="le-guide"><li v-for="g in guides" :key="g.title">{{ g.title }}</li></ul>'
                + '<a v-if="backUrl" data-testid="indietro" :href="backUrl">{{ backText }}</a><slot name="accanto-al-titolo" /><slot name="actions" /></header>' } } } });
    await flushPromises();
    return w;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('la testa del registro (decisione 657)', () => {
    test('le tre card della guida, e «Indietro» verso l\'archivio, l\'assemblea o il verbale da cui si è venuti, come dice il server', async () => {
        const p = await monta();
        expect(p.findAll('[data-testid="le-guide"] li').map((x) => x.text())).toEqual(['Il registro', 'La copia firmata', 'Le nomine']);
        expect(p.find('[data-testid="indietro"]').text()).toBe('Assemblee');
        expect(p.find('[data-testid="indietro"]').attributes('href')).toBe(ARCHIVIO.url);
        w!.unmount();
        const dallAssemblea = await monta(registroDiProva(), { url: 'http://km.test/admin/gestionale/3/assemblee/9', testo: 'L\'assemblea' });
        expect(dallAssemblea.find('[data-testid="indietro"]').text()).toBe('L\'assemblea');
        expect(dallAssemblea.find('[data-testid="indietro"]').attributes('href')).toBe('http://km.test/admin/gestionale/3/assemblee/9');
        w!.unmount();
        const dalVerbale = await monta(registroDiProva(), { url: 'http://km.test/admin/gestionale/3/assemblee/9/il-verbale?verbale=7', testo: 'Il verbale' });
        expect(dalVerbale.find('[data-testid="indietro"]').text()).toBe('Il verbale');
    });
});

describe('i verbali', () => {
    test('le righe con il numero e l\'annotazione senza numero; la colonna del libro di carta; «Stampa il registro»', async () => {
        const p = await monta();
        const righe = p.findAll('[data-testid="riga-del-registro"]');
        expect(righe).toHaveLength(2);
        expect(righe[0].text()).toContain('42');
        expect(righe[0].text()).toContain('Sottoscritto');
        expect(righe[1].text()).toContain('—');
        expect(righe[1].text()).toContain('Mancata costituzione');
        expect(righe[1].text()).toContain('Incollata nel libro il 16/12/2026');
        expect(righe[0].find('[data-testid="stampa-la-pagina"]').attributes('href')).toBe('http://km.test/admin/gestionale/3/assemblee/9/il-verbale/7/libro');
        // Decisione 659: dal registro si apre il verbale, e il verbale ha «← Il registro».
        expect(righe[0].find('[data-testid="apri-il-verbale"]').attributes('href')).toBe('http://km.test/admin/gestionale/3/assemblee/9/il-verbale?verbale=7&da=registro');
        expect(righe[1].find('[data-testid="apri-il-verbale"]').exists()).toBe(false);
        await righe[0].find('[data-testid="segna-incollata"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('http://km.test/admin/gestionale/3/assemblee-registro/libro', expect.objectContaining({ verbale_id: 7 }), expect.anything());
        expect(p.find('[data-testid="stampa-il-registro"]').attributes('href')).toContain('/assemblee-registro/stampa');
        expect(testo()).toContain('(art. 1130 n. 7 c.c.)');
    });

    test('la prima volta «Da dove parte il numero?»: continua il libro dal n. 41', async () => {
        const p = await monta();
        expect(p.find('[data-testid="da-impostare"]').exists()).toBe(true);
        await p.find('[data-testid="indica-da-dove-parte"]').trigger('click');
        await flushPromises();
        const ultimo = document.body.querySelector('[data-testid="ultimo-numero"]') as HTMLInputElement;
        ultimo.value = '41';
        ultimo.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(testo()).toContain('Il prossimo verbale registrato qui prende il n. 42.');
        (document.body.querySelector('[data-testid="conferma-partenza"]') as HTMLButtonElement).click();
        expect(inertia.post).toHaveBeenCalledWith('http://km.test/admin/gestionale/3/assemblee-registro/partenza',
            { registro: 'condominio', scelta: 'libro', ultimo: 41, del: null }, expect.anything());
    });

    test('il registro della scala si sceglie in alto', async () => {
        const p = await monta();
        await p.find('[data-testid="scegli-il-registro"] [data-opzione="1"]').trigger('click');
        expect(inertia.get).toHaveBeenCalledWith('http://km.test/admin/gestionale/3/assemblee-registro', { registro: 'scala:5' }, expect.anything());
    });
});

describe('nomina e revoca', () => {
    test('la riga proposta con «Correggi» in evidenza e «Conferma la riga»; la prima volta la nomina in corso', async () => {
        const p = await monta();
        await p.find('[data-testid="scheda-nomine"]').trigger('click');
        const proposta = p.find('[data-testid="riga-proposta"]');
        expect(proposta.text()).toContain('Dal verbale n. 42, punto 6, approvato: «Nomina: Andrea Costa, dal 15/12/2026».');
        expect(proposta.find('[data-testid="correggi-la-riga"]').classes()).toContain('bg-slate-900');
        await proposta.find('[data-testid="conferma-la-riga"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('http://km.test/admin/gestionale/3/assemblee-registro/nomine/2/conferma', {}, expect.anything());
        expect(p.find('[data-testid="nomina-da-indicare"]').exists()).toBe(true);
        await proposta.find('[data-testid="correggi-la-riga"]').trigger('click');
        await flushPromises();
        const nome = document.body.querySelector('[data-testid="nome-amministratore"]') as HTMLInputElement;
        expect(nome.value).toBe('Andrea Costa');
        nome.value = 'Studio Marchetti';
        nome.dispatchEvent(new Event('input'));
        await flushPromises();
        (document.body.querySelector('[data-testid="salva-nomina"]') as HTMLButtonElement).click();
        expect(inertia.patch).toHaveBeenCalledWith('http://km.test/admin/gestionale/3/assemblee-registro/nomine/2', expect.objectContaining({ amministratore: 'Studio Marchetti' }),
            expect.anything());
    });
});
