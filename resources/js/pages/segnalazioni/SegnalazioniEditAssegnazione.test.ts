// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina di modifica di una segnalazione nel pannello: «Assegnata a» e «Approvazione» sono campi del modulo (giro 3,
 * decisione 303).
 *
 * - Nella pagina di modifica tutto si salva con «Salva»: il riquadro che si salvava subito e il pulsante «Approva» non
 *   ci sono più (restano nella scheda).
 * - «Assegnata a» sta in «Gestione operativa», in un contenitore con `data-campo="assegnata-a"`: per chi può assegnare
 *   è una tendina `v-select` con `id="assigned_to"`, legata a `form.assigned_to`; gli altri leggono il nome.
 * - «Approvazione» sta accanto, in un contenitore con `data-campo="approvazione"`, solo per chi approva le
 *   segnalazioni: una tendina `v-select` con `id="is_approved"` e due voci, legata a `form.is_approved`.
 * - Chi non ha il permesso non ha il campo e non lo manda.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';
import AssegnazioneSegnalazione from '@/components/segnalazioni/AssegnazioneSegnalazione.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
// I permessi di chi apre la pagina: `null` = tutti (l'amministratore), altrimenti l'elenco dei concessi.
const permessi = vi.hoisted(() => ({ concessi: null as string[] | null }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true,
        hasPermission: (chiesti: string[]) => permessi.concessi === null || chiesti.some((p) => permessi.concessi!.includes(p)),
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniEdit = (await import('./SegnalazioniEdit.vue')).default;

const elena = { id: 9, name: 'Elena Studio', attivo: true, da: 'Studio Bianchi', il: '10/11/2026', alle: '09:30' };
const assegnazione = { puo_assegnare: true, io: 1, assegnabili: [{ id: 9, name: 'Elena Studio' }, { id: 1, name: 'Studio Bianchi' }] };
const nonAssegna = { puo_assegnare: false, io: 1, assegnabili: [] };

function segnalazione(campi: Record<string, unknown> = {}) {
    return {
        id: 31, subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media', stato: 'aperta',
        is_private: false, is_published: true, is_approved: false, is_featured: false, can_comment: true,
        condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
        anagrafiche: [], assegnata_a: elena,
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}, altreProps: Record<string, unknown> = { assegnazione }) {
    return shallowMount(SegnalazioniEdit, {
        props: { condomini: [], anagrafiche: [], segnalazione: segnalazione(campi), ...altreProps } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Stato = { form: Record<string, unknown> & { isDirty: boolean }; submit: () => void };
const stato = (w: VueWrapper) => (w.vm as unknown as { $: { setupState: Stato } }).$.setupState;
const tendina = (w: VueWrapper, id: string) => w.findAllComponents(vSelect).find((c) => c.attributes('id') === id);
const campoAssegnata = (w: VueWrapper) => w.find('form [data-campo="assegnata-a"]');
const campoApprovazione = (w: VueWrapper) => w.find('form [data-campo="approvazione"]');
const avviso = (w: VueWrapper) => w.find('[data-avviso="da-approvare"]');

/** Preme «Salva» e restituisce ciò che parte: l'indirizzo e i campi. */
function salva(w: VueWrapper): { indirizzo: string; campi: Record<string, unknown> } {
    stato(w).submit();
    const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;

    return { indirizzo: String(chiamata[0]), campi: chiamata[1] as Record<string, unknown> };
}

beforeEach(() => {
    permessi.concessi = null;
    for (const verbo of ['put', 'patch', 'post'] as const) {
        vi.spyOn(router, verbo).mockImplementation(() => undefined as never);
        vi.mocked(router[verbo]).mockClear();
    }
});

describe('SegnalazioniEdit — «Assegnata a» è un campo del modulo', () => {
    test('sta dentro il modulo, e il riquadro che si salvava subito non c\'è più', async () => {
        const w = monta();
        await flushPromises();

        expect(w.findComponent(AssegnazioneSegnalazione).exists()).toBe(false);
        expect(w.text()).not.toContain('segnalazioni.section.assignment_title');
        expect(campoAssegnata(w).exists()).toBe(true);
        expect(campoAssegnata(w).text()).toContain('segnalazioni.assignment.label');
        expect(campoAssegnata(w).find('#assigned_to').exists()).toBe(true);
    });

    test('la tendina propone chi può ricevere, per nome, e parte da chi la segue', async () => {
        const w = monta();
        await flushPromises();

        expect(tendina(w, 'assigned_to')!.props('options')).toEqual(assegnazione.assegnabili);
        expect(tendina(w, 'assigned_to')!.props('label')).toBe('name');
        expect(tendina(w, 'assigned_to')!.props('modelValue')).toBe(9);
        expect((tendina(w, 'assigned_to')!.props('reduce') as (p: unknown) => unknown)({ id: 1, name: 'Studio Bianchi' })).toBe(1);
        expect(stato(w).form.assigned_to).toBe(9);
    });

    test('una segnalazione di nessuno parte con il campo vuoto', async () => {
        const w = monta({ assegnata_a: null });
        await flushPromises();

        expect(tendina(w, 'assigned_to')!.props('modelValue')).toBeNull();
        expect(stato(w).form.assigned_to).toBeNull();
        expect(w.find('[data-campo="assegnata-da"]').exists()).toBe(false);
    });

    test('chi la segue e non può più ricevere resta fra le scelte, e la pagina lo dice', async () => {
        const w = monta({ assegnata_a: { ...elena, id: 12, name: 'Ida Sospesa', attivo: false } });
        await flushPromises();

        expect(tendina(w, 'assigned_to')!.props('options')).toEqual([...assegnazione.assegnabili, { id: 12, name: 'Ida Sospesa' }]);
        expect(tendina(w, 'assigned_to')!.props('modelValue')).toBe(12);
        expect(campoAssegnata(w).find('[data-avviso="assegnatario-non-attivo"]').exists()).toBe(true);
    });

    test('sotto la tendina si legge da chi e quando è stata assegnata', async () => {
        const w = monta();
        await flushPromises();

        expect(campoAssegnata(w).find('[data-campo="assegnata-da"]').exists()).toBe(true);
        expect(campoAssegnata(w).find('[data-avviso="assegnatario-non-attivo"]').exists()).toBe(false);
    });

    test('scegliere un nome non salva niente: parte con «Salva», insieme agli altri campi', async () => {
        const w = monta();
        await flushPromises();

        tendina(w, 'assigned_to')!.vm.$emit('update:modelValue', 1);
        await flushPromises();

        expect(stato(w).form.assigned_to).toBe(1);
        expect(vi.mocked(router.put)).not.toHaveBeenCalled();
        expect(vi.mocked(router.patch)).not.toHaveBeenCalled();

        const { indirizzo, campi } = salva(w);
        expect(indirizzo).toContain('segnalazioni.update');
        expect(campi).toMatchObject({ subject: 'Il cancello non chiude', stato: 'aperta', assigned_to: 1 });
        expect(vi.mocked(router.put)).toHaveBeenCalledTimes(1);
    });

    test('svuotare la tendina manda il campo vuoto: è così che si toglie l\'assegnazione', async () => {
        const w = monta();
        await flushPromises();

        tendina(w, 'assigned_to')!.vm.$emit('update:modelValue', null);
        await flushPromises();

        const { campi } = salva(w);
        expect(Object.keys(campi)).toContain('assigned_to');
        expect(campi.assigned_to).toBeNull();
    });

    test('chi non può assegnare legge il nome, senza tendina, e non manda il campo', async () => {
        const w = monta({}, { assegnazione: nonAssegna });
        await flushPromises();

        expect(tendina(w, 'assigned_to')).toBeUndefined();
        expect(campoAssegnata(w).text()).toContain('Elena Studio');
        expect(Object.keys(salva(w).campi)).not.toContain('assigned_to');

        const diNessuno = monta({ assegnata_a: null }, { assegnazione: nonAssegna });
        await flushPromises();
        expect(campoAssegnata(diNessuno).text()).toContain('segnalazioni.assignment.none');
    });

    test('quando la segnalazione torna dal server, il campo riparte da lì e il modulo non risulta modificato', async () => {
        const w = monta();
        await flushPromises();

        await w.setProps({ segnalazione: segnalazione({ stato: 'in lavorazione', assegnata_a: { ...elena, id: 1, name: 'Studio Bianchi' } }) } as never);
        await flushPromises();

        expect(stato(w).form.assigned_to).toBe(1);
        expect(stato(w).form.stato).toBe('in lavorazione');
        expect(stato(w).form.isDirty).toBe(false);
    });
});

describe('SegnalazioniEdit — «Approvazione» è una tendina del modulo', () => {
    test('chi approva le segnalazioni ha la tendina con le due voci, non il pulsante né la frase', async () => {
        const w = monta();
        await flushPromises();

        expect(campoApprovazione(w).exists()).toBe(true);
        expect(campoApprovazione(w).text()).toContain('segnalazioni.label.approval');
        expect(campoApprovazione(w).find('#is_approved').exists()).toBe(true);
        expect(tendina(w, 'is_approved')!.props('options')).toEqual([
            { value: true, label: 'segnalazioni.approval.approved' },
            { value: false, label: 'segnalazioni.approval.pending' },
        ]);
        expect(tendina(w, 'is_approved')!.props('clearable')).toBe(false);
        expect(tendina(w, 'is_approved')!.props('modelValue')).toBe(false);
        expect((tendina(w, 'is_approved')!.props('reduce') as (o: unknown) => unknown)({ value: true, label: 'x' })).toBe(true);
        expect(w.find('button[data-azione="approva"]').exists()).toBe(false);
        expect(avviso(w).exists()).toBe(false);
        expect(w.text()).not.toContain('segnalazioni.publication.needs_approval');
    });

    test('scegliere «Approvata» non salva niente: parte con «Salva»', async () => {
        const w = monta();
        await flushPromises();

        tendina(w, 'is_approved')!.vm.$emit('update:modelValue', true);
        await flushPromises();

        expect(stato(w).form.is_approved).toBe(true);
        expect(vi.mocked(router.put)).not.toHaveBeenCalled();

        const { indirizzo, campi } = salva(w);
        expect(indirizzo).toContain('segnalazioni.update');
        expect(campi).toMatchObject({ is_approved: true, is_published: true, assigned_to: 9 });
    });

    test('senza toccarla, «Salva» manda lo stato che la segnalazione ha già', async () => {
        const inAttesa = monta({ is_approved: false });
        const approvata = monta({ is_approved: 1 });
        await flushPromises();

        expect(salva(inAttesa).campi.is_approved).toBe(false);
        expect(tendina(approvata, 'is_approved')!.props('modelValue')).toBe(true);
        expect(salva(approvata).campi.is_approved).toBe(true);
    });

    test('chi non approva le segnalazioni non ha la tendina, legge chi approva, e non manda il campo', async () => {
        permessi.concessi = ['Modifica segnalazioni', 'Approva comunicazioni'];
        const w = monta();
        await flushPromises();

        expect(campoApprovazione(w).exists()).toBe(false);
        expect(tendina(w, 'is_approved')).toBeUndefined();
        expect(avviso(w).text()).toContain('segnalazioni.publication.waiting_approver');
        expect(Object.keys(salva(w).campi)).not.toContain('is_approved');
    });

    test('su una segnalazione approvata chi non approva non legge nessuna frase', async () => {
        permessi.concessi = ['Modifica segnalazioni'];
        const w = monta({ is_approved: true });
        await flushPromises();

        expect(avviso(w).exists()).toBe(false);
        expect(campoApprovazione(w).exists()).toBe(false);
    });

    test('quando la segnalazione torna dal server approvata, la tendina lo dice e il modulo non risulta modificato', async () => {
        const w = monta();
        await flushPromises();

        await w.setProps({ segnalazione: segnalazione({ is_approved: true }) } as never);
        await flushPromises();

        expect(stato(w).form.is_approved).toBe(true);
        expect(tendina(w, 'is_approved')!.props('modelValue')).toBe(true);
        expect(stato(w).form.isDirty).toBe(false);
    });
});

describe('SegnalazioniEdit — l\'ordine dei campi di «Gestione operativa»', () => {
    // La lavorazione sulla prima riga (stato, priorità, chi la segue), la pubblicazione sulla seconda (visibilità,
    // approvazione): ogni campo occupa un terzo della riga, come i tre che c'erano già.
    test('«Assegnata a» sta dopo la priorità e prima della visibilità; «Approvazione» dopo la visibilità', async () => {
        const w = monta();
        await flushPromises();
        const html = w.html();
        const posto = (pezzo: string) => html.indexOf(pezzo);

        expect(posto('id="priority"')).toBeGreaterThan(-1);
        expect(posto('id="visibility"')).toBeGreaterThan(-1);
        expect(posto('id="priority"')).toBeLessThan(posto('data-campo="assegnata-a"'));
        expect(posto('data-campo="assegnata-a"')).toBeLessThan(posto('id="visibility"'));
        expect(posto('id="visibility"')).toBeLessThan(posto('data-campo="approvazione"'));
        expect(campoAssegnata(w).classes()).toContain('sm:col-span-2');
        expect(campoApprovazione(w).classes()).toContain('sm:col-span-2');
    });
});

describe('SegnalazioniEdit — chi non assegna e non approva', () => {
    test('manda il modulo di sempre, senza i due campi', async () => {
        permessi.concessi = ['Modifica segnalazioni'];
        const w = monta({}, { assegnazione: nonAssegna });
        await flushPromises();

        expect(salva(w).campi).toEqual({
            subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media', stato: 'aperta',
            condominio_id: 3, can_comment: true, is_featured: false, is_published: true, anagrafiche: [], avvisa_destinatari: false,
        });
    });
});
