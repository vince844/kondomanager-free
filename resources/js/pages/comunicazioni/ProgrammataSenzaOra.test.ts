// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le pagine di creazione e di modifica di una comunicazione (pannello): «Programmata» senza l'ora (Coda 589, giro di
 * correzioni 1).
 *
 * Scelta «Programmata» e lasciato vuoto il campo, la pagina mandava `is_published` falso e nessuna ora: il server
 * salvava una bozza, con il messaggio di sempre, e chi programmava credeva di averlo fatto. Il server non può
 * saperlo, perché riceve gli stessi campi di una bozza: si ferma la pagina.
 *
 * - Con «Programmata» e l'ora vuota la pagina non manda niente e mette sul campo l'errore
 *   `comunicazioni.scheduling.date_required`, che si legge nell'`InputError` sotto il campo.
 * - Scritta l'ora, il salvataggio parte e l'errore non c'è più.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';
import InputError from '@/components/InputError.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true,
    }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;
const ComunicazioniEdit = (await import('./ComunicazioniEdit.vue')).default;

const ERRORE = 'comunicazioni.scheduling.date_required';
const programmazione = { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00' };
const opzioni = { global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } } };

function montaNuova() {
    return shallowMount(ComunicazioniNew, {
        props: { condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true, programmazione } as never,
        ...opzioni,
    });
}

function montaModifica(campi: Record<string, unknown> = {}) {
    return shallowMount(ComunicazioniEdit, {
        props: {
            condomini: [], anagrafiche: [],
            comunicazione: {
                id: 11, subject: 'Chiusura dell\'acqua', description: 'Martedì dalle 9 alle 12.', priority: 'media',
                is_private: false, is_published: false, is_approved: true, is_featured: false, can_comment: true,
                data_programmata: null,
                condomini: { options: [{ value: 3, label: 'Condominio Aurora' }], full: [] },
                anagrafiche: [],
                ...campi,
            },
            programmazione,
        } as never,
        ...opzioni,
    });
}

const tendina = (pagina: VueWrapper) => pagina.findAllComponents(vSelect).find((c) => c.attributes('id') === 'stato')!;
const campo = (pagina: VueWrapper) => pagina.find('input[data-campo="data-programmata"]');
const erroreMostrato = (pagina: VueWrapper) => pagina.findAllComponents(InputError).some((e) => e.props('message') === ERRORE);

async function scegli(pagina: VueWrapper, stato: unknown) {
    tendina(pagina).vm.$emit('update:modelValue', stato);
    await flushPromises();
}

async function invia(pagina: VueWrapper) {
    (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
    await flushPromises();
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
    vi.mocked(router.put).mockClear();
});

describe('ComunicazioniNew — «Programmata» senza l\'ora', () => {
    test('la pagina non manda niente e dice di scegliere la data e l\'ora', async () => {
        const pagina = montaNuova();
        await scegli(pagina, 'scheduled');
        expect(erroreMostrato(pagina)).toBe(false);

        await invia(pagina);

        expect(router.post).not.toHaveBeenCalled();
        expect(erroreMostrato(pagina)).toBe(true);
        // Il campo resta lì, per scriverla.
        expect(campo(pagina).exists()).toBe(true);
    });

    test('scritta l\'ora, il salvataggio parte e l\'errore non c\'è più', async () => {
        const pagina = montaNuova();
        await scegli(pagina, 'scheduled');
        await invia(pagina);
        expect(router.post).not.toHaveBeenCalled();

        await campo(pagina).setValue('2027-01-15T09:00');
        await invia(pagina);

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({ is_published: false, data_programmata: '2027-01-15T09:00' });
        expect(erroreMostrato(pagina)).toBe(false);
    });

    test('tornando a «Bozza» l\'errore sparisce e il salvataggio parte, senza ora', async () => {
        const pagina = montaNuova();
        await scegli(pagina, 'scheduled');
        await invia(pagina);
        expect(erroreMostrato(pagina)).toBe(true);

        await scegli(pagina, false);
        expect(erroreMostrato(pagina)).toBe(false);
        await invia(pagina);

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({ is_published: false, data_programmata: null });
    });

    test('con gli altri due stati la pagina manda il modulo come sempre', async () => {
        const pagina = montaNuova();
        await flushPromises();

        await invia(pagina);

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(erroreMostrato(pagina)).toBe(false);
    });
});

describe('ComunicazioniEdit — «Programmata» senza l\'ora', () => {
    test('una bozza portata a «Programmata» senza l\'ora non parte, e la pagina lo dice', async () => {
        const pagina = montaModifica();
        await flushPromises();
        await scegli(pagina, 'scheduled');

        await invia(pagina);

        expect(router.put).not.toHaveBeenCalled();
        expect(erroreMostrato(pagina)).toBe(true);
    });

    test('una programmata a cui si svuota l\'ora non parte', async () => {
        const pagina = montaModifica({ data_programmata: '2027-01-15T09:00' });
        await flushPromises();
        await campo(pagina).setValue('');

        await invia(pagina);

        expect(router.put).not.toHaveBeenCalled();
        expect(erroreMostrato(pagina)).toBe(true);
    });

    test('scritta l\'ora, la modifica parte', async () => {
        const pagina = montaModifica();
        await flushPromises();
        await scegli(pagina, 'scheduled');
        await invia(pagina);
        expect(router.put).not.toHaveBeenCalled();

        await campo(pagina).setValue('2027-02-01T18:30');
        await invia(pagina);

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.put).mock.calls[0][1]).toMatchObject({ is_published: false, data_programmata: '2027-02-01T18:30' });
        expect(erroreMostrato(pagina)).toBe(false);
    });
});
