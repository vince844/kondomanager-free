// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tendina «Visibilità comunicazione» si chiude dopo aver scelto «Programmata» (Coda 589, prova dal vivo del 18/11/2026, decisione 484 (3)).
 *
 * Nella copia di prova, scelta «Programmata», il menu è rimasto aperto sopra il campo nuovo fino a un clic fuori. Qui la tendina vera
 * (`vue-select`, non uno stub) si apre come la apre il mouse, si sceglie la voce come la sceglie il mouse (`click` sulla voce), e il
 * campo dell'ora compare nello stesso giro: il menu deve essere chiuso, nella pagina di creazione e in quella di modifica.
 *
 * Che cosa non copre: il browser vero. In jsdom il menu si chiude: il difetto visto nella copia non si riproduce qui (può dipendere da un
 * secondo clic durante il ridisegno, o dal tocco); se torna, si guarda a video.
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

const PROGRAMMAZIONE = { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: false, adesso: '2026-11-12T11:00' };

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

async function scegliProgrammata(pagina: VueWrapper) {
    const tendina = pagina.get('#stato');
    // Il mouse apre la tendina sul suo riquadro (`mousedown`), poi sceglie la voce (`click`), come fa `vue-select`.
    await tendina.get('.vs__dropdown-toggle').trigger('mousedown');
    await flushPromises();
    expect(tendina.find('.vs__dropdown-menu').exists(), 'la tendina non si è aperta').toBe(true);
    const voce = tendina.findAll('.vs__dropdown-option').find((o) => o.text().includes('comunicazioni.publication.scheduled'));
    expect(voce, 'manca la voce «Programmata»').toBeDefined();
    await voce!.trigger('click');
    await flushPromises();
    return tendina;
}

describe('La tendina della visibilità dopo «Programmata»', () => {
    test('nella pagina di creazione il menu si chiude e compare il campo dell\'ora', async () => {
        const pagina = mount(ComunicazioniNew, {
            props: { condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true, programmazione: PROGRAMMAZIONE } as never,
            global: { stubs: stubs(), mocks: { route: (n: string) => `/${n}` } },
            attachTo: document.body,
        });

        const tendina = await scegliProgrammata(pagina);

        expect(pagina.find('input[data-campo="data-programmata"]').exists()).toBe(true);
        expect(tendina.find('.vs__dropdown-menu').exists()).toBe(false);
        expect(tendina.classes()).not.toContain('vs--open');
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

        const tendina = await scegliProgrammata(pagina);

        expect(pagina.find('input[data-campo="data-programmata"]').exists()).toBe(true);
        expect(tendina.find('.vs__dropdown-menu').exists()).toBe(false);
        pagina.unmount();
    });
});
