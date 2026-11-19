// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.12 (H2, decisione 504): dopo «Programmata» la tendina della visibilità resta chiusa anche se arriva una seconda scelta mentre la pagina si
 * ridisegna con il campo dell'ora. `vue-select` chiudeva la tendina con un interruttore (`open = !open`): una seconda scelta della stessa voce la riapriva
 * sopra il campo. Qui la seconda scelta è quella che `vue-select` riceve da un secondo clic sulla voce (il suo metodo `select`), dopo la prima.
 *
 * Che cosa non copre: il browser vero e il tocco (nella copia di prova il menu restava aperto; in jsdom, con un clic solo, si chiudeva anche prima: la
 * prova `ProgrammataMenuChiuso.test.ts`). Il meccanismo dell'interruttore è quello che questa prova riproduce; se il menu restasse aperto per un'altra
 * via, si guarda a video.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

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
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;
const ComunicazioniEdit = (await import('./ComunicazioniEdit.vue')).default;

const PROGRAMMAZIONE = { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00' };

function stubs() {
    return {
        AppLayout: { template: '<div><slot /></div>' },
        PageHeaderGuide: { template: '<div><slot name="actions" /></div>' },
        AllegatiCampoModuli: { template: '<div />' },
        HoverCard: { template: '<div><slot /></div>' },
        HoverCardTrigger: { template: '<div><slot /></div>' },
        HoverCardContent: { template: '<div />' },
    };
}

/** Apre la tendina, sceglie «Programmata» con un clic, poi la seconda scelta della stessa voce che arriva durante il ridisegno. */
async function dueScelte(pagina: VueWrapper) {
    const tendina = pagina.get('#stato');
    await tendina.get('.vs__dropdown-toggle').trigger('mousedown');
    await flushPromises();
    const voce = tendina.findAll('.vs__dropdown-option').find((o) => o.text().includes('comunicazioni.publication.scheduled'))!;
    await voce.trigger('click');
    const vs = pagina.findComponent('#stato');
    const opzione = (vs.props('options') as Array<{ value: unknown }>).find((o) => o.value === 'scheduled');
    (vs.vm as unknown as { select: (o: unknown) => void }).select(opzione);
    await flushPromises();

    return tendina;
}

describe('La tendina della visibilità dopo una seconda scelta di «Programmata»', () => {
    test('nella pagina di creazione resta chiusa, e il campo dell\'ora c\'è', async () => {
        const pagina = mount(ComunicazioniNew, {
            props: { condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true, programmazione: PROGRAMMAZIONE } as never,
            global: { stubs: stubs(), mocks: { route: (n: string) => `/${n}` } },
            attachTo: document.body,
        });
        const tendina = await dueScelte(pagina);
        // Con il codice di prima la seconda scelta la riapriva: `.vs__dropdown-menu` c'era, e la classe `vs--open`.
        expect(tendina.find('.vs__dropdown-menu').exists()).toBe(false);
        expect(tendina.classes()).not.toContain('vs--open');
        expect(pagina.find('input[data-campo="data-programmata"]').exists()).toBe(true);
        pagina.unmount();
    });

    test('nella pagina di modifica lo stesso', async () => {
        const pagina = mount(ComunicazioniEdit, {
            props: {
                comunicazione: {
                    id: 9, subject: 'Lavori', description: 'Testo', priority: 'media', stato: '', is_published: true, is_approved: true, is_featured: false,
                    can_comment: false, commenti_moderati: true, condomini: { options: [] }, anagrafiche: [], programmata_per: null,
                },
                condomini: [], anagrafiche: [], programmazione: PROGRAMMAZIONE,
            } as never,
            global: { stubs: stubs(), mocks: { route: (n: string) => `/${n}` } },
            attachTo: document.body,
        });
        const tendina = await dueScelte(pagina);
        expect(tendina.find('.vs__dropdown-menu').exists()).toBe(false);
        expect(tendina.classes()).not.toContain('vs--open');
        pagina.unmount();
    });
});
