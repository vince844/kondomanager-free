// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una comunicazione: le letture (Coda 594, decisioni 310 e 311).
 *
 * La pagina è la stessa per il pannello e per il portale, e **decide il server** che cosa mostrare:
 * - con la prop `letture` (la manda solo il pannello) c'è una terza riga, `[data-riga="letture"]`, dopo quella dei
 *   commenti e degli allegati, con il componente `LettureComunicazione`;
 * - con la prop `lettura` (la manda solo il portale) dentro la prima riga, `[data-riga="scheda"]`, c'è il componente
 *   `PresaVisione`, con l'id della comunicazione e la lettura ricevuta.
 * Senza le props, niente: la pagina non guarda permessi né porte per questo.
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

const LETTURE = {
    registra_aperture: true, chiedi_presa_visione: false, attive: true,
    riassunto: { destinatari: 1, senza_accesso: 0, aperte: 1, prese_visione: 0 },
    righe: [{ anagrafica_id: 3, nome: 'Mario Rossi', stato: 'aperta', aperta: { il: '11/11/2026', alle: '10:00' }, presa_visione: null, manca: false }],
};
const LETTURA = { apertura: { il: '12/11/2026', alle: '11:00' }, puo_confermare: true, presa_visione: null };

function monta(porta: 'admin' | 'user', inPiu: Record<string, unknown> = {}) {
    stato.porta = porta;

    return shallowMount(ComunicazioniView, {
        props: {
            comunicazione: {
                id: 7, subject: 'Chiusura dell\'acqua', description: 'Martedì dalle 9 alle 12.', priority: 'media',
                is_private: false, is_published: true, is_approved: true, is_featured: false,
                can_comment: true, commenti_moderati: false, chiedi_presa_visione: false,
                created_at: '3 giorni fa', pubblicata_il: '2 ore fa', data_programmata: null,
                created_by: { user: { name: 'Studio Bianchi' } },
            },
            allegati: [],
            allegati_config: { massimo: 10, puo_caricare: false, puo_impostare: false, moderati: false },
            commenti_config: { can_comment: true, commenti_moderati: false, puo_impostare: false, puo_moderare: false },
            discussioni: [],
            ...inPiu,
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const precede = (a: Element, b: Element) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);

describe('ComunicazioniView — il riquadro delle letture, nel pannello', () => {
    test('con le letture c\'è una terza riga, dopo i commenti e gli allegati, con il riquadro', () => {
        const pagina = monta('admin', { letture: LETTURE });
        const riga = pagina.find('[data-riga="letture"]');
        const riquadro = riga.findComponent({ name: 'LettureComunicazione' });

        expect(riga.exists()).toBe(true);
        expect(riquadro.exists()).toBe(true);
        expect(riquadro.props('letture')).toEqual(LETTURE);
        expect(precede(pagina.find('[data-riga="commenti-allegati"]').element, riga.element)).toBe(true);
        expect(pagina.find('[data-riga="scheda"]').findComponent({ name: 'LettureComunicazione' }).exists()).toBe(false);
    });

    test('il riquadro c\'è anche quando le letture non sono attive: come si attivano lo dice lui', () => {
        const spente = { registra_aperture: false, chiedi_presa_visione: false, attive: false, riassunto: null, righe: [] };
        const pagina = monta('admin', { letture: spente });

        expect(pagina.find('[data-riga="letture"]').findComponent({ name: 'LettureComunicazione' }).props('letture')).toEqual(spente);
    });

    test('senza la prop la riga non c\'è', () => {
        const pagina = monta('admin');

        expect(pagina.find('[data-riga="letture"]').exists()).toBe(false);
        expect(pagina.findComponent({ name: 'LettureComunicazione' }).exists()).toBe(false);
        expect(pagina.findComponent({ name: 'PresaVisione' }).exists()).toBe(false);
    });
});

describe('ComunicazioniView — la presa visione, nel portale', () => {
    test('con la propria lettura c\'è il componente sotto il testo, nella prima riga', () => {
        const pagina = monta('user', { lettura: LETTURA });
        const componente = pagina.find('[data-riga="scheda"]').findComponent({ name: 'PresaVisione' });

        expect(componente.exists()).toBe(true);
        expect(componente.props('comunicazioneId')).toBe(7);
        expect(componente.props('lettura')).toEqual(LETTURA);
        expect(pagina.find('[data-riga="letture"]').exists()).toBe(false);
        expect(pagina.findComponent({ name: 'LettureComunicazione' }).exists()).toBe(false);
    });

    test('senza la prop il componente non c\'è', () => {
        const pagina = monta('user');

        expect(pagina.findComponent({ name: 'PresaVisione' }).exists()).toBe(false);
        expect(pagina.find('[data-riga="letture"]').exists()).toBe(false);
    });

    test('la pagina non decide dalla porta: mostra ciò che il server manda', () => {
        // Un server che mandasse le letture al portale sarebbe un errore del server, non una cosa che la pagina nasconde:
        // qui si guarda solo che la pagina non usi la porta per decidere.
        const pagina = monta('admin', { lettura: LETTURA });

        expect(pagina.findComponent({ name: 'PresaVisione' }).exists()).toBe(true);
    });
});
