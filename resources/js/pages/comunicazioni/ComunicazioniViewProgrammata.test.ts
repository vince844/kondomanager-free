// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una comunicazione: la pubblicazione programmata (Coda 589, decisione 290).
 *
 * - Una comunicazione programmata (nel pannello) dice «Programmata» fra i parametri e «Programmata per il <giorno>
 *   alle <ora>» al posto di «Inviata…». Il giorno e l'ora vengono da `data_programmata` così com'è scritta
 *   (`2027-01-15T09:00` → `15/01/2027`, `09:00`): la pagina non fa conti con i fusi.
 * - Una comunicazione uscita dice «Inviata» con la data di pubblicazione (`pubblicata_il`), non con quella di
 *   creazione; senza `pubblicata_il` ripiega su `created_at`.
 *
 * `trans()` restituisce la chiave seguita dai valori passati, così si legge anche che data è stata scelta.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const stato = vi.hoisted(() => ({ porta: 'admin' as 'admin' | 'user' }));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`,
        generateRoute: (n: string) => `${stato.porta}.${n}`,
        getRolePrefix: () => stato.porta,
        hasPermission: () => true,
        hasRole: () => true,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniView = (await import('./ComunicazioniView.vue')).default;

function monta(campi: Record<string, unknown>, porta: 'admin' | 'user' = 'admin') {
    stato.porta = porta;

    return shallowMount(ComunicazioniView, {
        props: {
            comunicazione: {
                id: 7, subject: 'Chiusura dell\'acqua', description: 'Martedì dalle 9 alle 12.', priority: 'media',
                is_private: false, is_published: true, is_approved: true, is_featured: false,
                can_comment: true, commenti_moderati: false,
                created_at: '3 giorni fa', pubblicata_il: null, data_programmata: null,
                created_by: { user: { name: 'Studio Bianchi' } },
                ...campi,
            },
            allegati: [],
            allegati_config: { massimo: 10, puo_caricare: false, puo_impostare: false, moderati: false },
            commenti_config: { can_comment: true, commenti_moderati: false, puo_impostare: false, puo_moderare: false },
            discussioni: [],
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('ComunicazioniView — una comunicazione programmata', () => {
    test('dice «Programmata» e per quando, al posto di «Inviata»', () => {
        const testo = monta({ is_published: false, data_programmata: '2027-01-15T09:00' }).text();

        expect(testo).toContain('comunicazioni.publication.scheduled');
        expect(testo).toContain('comunicazioni.scheduling.scheduled_for');
        expect(testo).toContain('15/01/2027');
        expect(testo).toContain('09:00');
        expect(testo).not.toContain('comunicazioni.visibility.sent_on_by');
        expect(testo).not.toContain('comunicazioni.publication.draft');
    });

    test('il giorno e l\'ora sono quelli scritti, senza conti con i fusi', () => {
        const testo = monta({ is_published: false, data_programmata: '2027-07-01T00:30' }).text();

        expect(testo).toContain('01/07/2027');
        expect(testo).toContain('00:30');
    });

    test('una bozza senza ora resta una bozza', () => {
        const testo = monta({ is_published: false, data_programmata: null }).text();

        expect(testo).toContain('comunicazioni.publication.draft');
        expect(testo).not.toContain('comunicazioni.publication.scheduled');
        expect(testo).not.toContain('comunicazioni.scheduling.scheduled_for');
    });
});

describe('ComunicazioniView — «Inviata» dice la data di pubblicazione', () => {
    test('con la data di pubblicazione la mostra, e non quella di creazione', () => {
        for (const porta of ['admin', 'user'] as const) {
            const testo = monta({ pubblicata_il: '2 ore fa' }, porta).text();

            expect(testo).toContain('comunicazioni.visibility.sent_on_by');
            expect(testo).toContain('2 ore fa');
            expect(testo).not.toContain('3 giorni fa');
        }
    });

    test('senza la data di pubblicazione ripiega su quella di creazione', () => {
        const testo = monta({ pubblicata_il: null }).text();

        expect(testo).toContain('comunicazioni.visibility.sent_on_by');
        expect(testo).toContain('3 giorni fa');
    });
});
