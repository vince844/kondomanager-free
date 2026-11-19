// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Crea segnalazione» nel pannello: la pagina si apre, e ha il campo «Assegnata a» (giro 3, decisione 303).
 *
 * La pagina leggeva il valore di serie di «Commenti in moderazione» da una variabile che non esisteva (`page`), e non
 * si apriva: era una pagina bianca. Il valore arriva come prop dichiarata, `commenti_segnalazioni_moderati_di_serie`.
 *
 * Chi può assegnare ha, in «Gestione operativa», una tendina `v-select` con `id="assigned_to"` dentro un contenitore
 * con `data-campo="assegnata-a"`, legata a `form.assigned_to`: parte vuota. Il campo parte con il modulo solo se è
 * stato scelto qualcuno; chi non può assegnare non ha il campo e non lo manda.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniNew = (await import('./SegnalazioniNew.vue')).default;

const assegnazione = { puo_assegnare: true, io: 1, assegnabili: [{ id: 9, name: 'Elena Studio' }, { id: 1, name: 'Studio Bianchi' }] };

function monta(altreProps: Record<string, unknown> = { assegnazione }) {
    return shallowMount(SegnalazioniNew, {
        props: { condomini: [], anagrafiche: [], allegati_massimo: 5, ...altreProps } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Stato = { form: Record<string, unknown>; submit: () => void };
const stato = (w: VueWrapper) => (w.vm as unknown as { $: { setupState: Stato } }).$.setupState;
const tendina = (w: VueWrapper) => w.findAllComponents(vSelect).find((c) => c.attributes('id') === 'assigned_to');
const campo = (w: VueWrapper) => w.find('form [data-campo="assegnata-a"]');

/** Preme «Salva» e restituisce ciò che parte: l'indirizzo e i campi. */
function salva(w: VueWrapper): { indirizzo: string; campi: Record<string, unknown> } {
    stato(w).submit();
    const chiamata = vi.mocked(router.post).mock.calls.at(-1)!;

    return { indirizzo: String(chiamata[0]), campi: chiamata[1] as Record<string, unknown> };
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('SegnalazioniNew — la pagina si apre', () => {
    test('si monta, con il modulo e i suoi campi', async () => {
        const w = monta();
        await flushPromises();

        expect(w.find('form').exists()).toBe(true);
        expect(stato(w).form.subject).toBe('');
    });

    test('«Commenti in moderazione» parte dal valore di serie che manda il server', async () => {
        const accesa = monta({ assegnazione, commenti_segnalazioni_moderati_di_serie: true });
        const spenta = monta({ assegnazione, commenti_segnalazioni_moderati_di_serie: false });
        const assente = monta();
        await flushPromises();

        expect(stato(accesa).form.commenti_moderati).toBe(true);
        expect(stato(spenta).form.commenti_moderati).toBe(false);
        expect(stato(assente).form.commenti_moderati).toBe(false);
    });
});

describe('SegnalazioniNew — «Assegnata a»', () => {
    test('chi può assegnare ha la tendina dentro il modulo, vuota, con chi può ricevere', async () => {
        const w = monta();
        await flushPromises();

        expect(campo(w).exists()).toBe(true);
        expect(campo(w).text()).toContain('segnalazioni.assignment.label');
        expect(campo(w).find('#assigned_to').exists()).toBe(true);
        expect(tendina(w)!.props('options')).toEqual(assegnazione.assegnabili);
        expect(tendina(w)!.props('label')).toBe('name');
        expect(tendina(w)!.props('modelValue')).toBeNull();
        expect((tendina(w)!.props('reduce') as (p: unknown) => unknown)({ id: 9, name: 'Elena Studio' })).toBe(9);
    });

    test('sta dopo la priorità e prima della visibilità, largo un terzo della riga come loro', async () => {
        const w = monta();
        await flushPromises();
        const html = w.html();
        const posto = (pezzo: string) => html.indexOf(pezzo);

        expect(posto('id="priority"')).toBeGreaterThan(-1);
        expect(posto('id="priority"')).toBeLessThan(posto('data-campo="assegnata-a"'));
        expect(posto('data-campo="assegnata-a"')).toBeLessThan(posto('id="visibility"'));
        expect(campo(w).classes()).toContain('sm:col-span-2');
    });

    test('scelto un nome, parte con il modulo', async () => {
        const w = monta();
        await flushPromises();

        tendina(w)!.vm.$emit('update:modelValue', 9);
        await flushPromises();

        const { indirizzo, campi } = salva(w);
        expect(indirizzo).toContain('segnalazioni.store');
        expect(campi.assigned_to).toBe(9);
    });

    test('senza una scelta il campo non parte: la segnalazione nasce di nessuno', async () => {
        const w = monta();
        await flushPromises();

        expect(Object.keys(salva(w).campi)).not.toContain('assigned_to');

        tendina(w)!.vm.$emit('update:modelValue', 9);
        tendina(w)!.vm.$emit('update:modelValue', null);
        await flushPromises();

        expect(Object.keys(salva(w).campi)).not.toContain('assigned_to');
    });

    test('chi non può assegnare non ha il campo e non lo manda', async () => {
        for (const altreProps of [{ assegnazione: { puo_assegnare: false, io: 1, assegnabili: [] } }, {}]) {
            const w = monta(altreProps);
            await flushPromises();

            expect(campo(w).exists()).toBe(false);
            expect(tendina(w)).toBeUndefined();
            expect(Object.keys(salva(w).campi)).not.toContain('assigned_to');
        }
    });
});
