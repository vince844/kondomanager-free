// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Modifica dati» nella scheda di un'anagrafica si mostra con «Modifica anagrafiche» (decisioni 137 e 138.1).
 *
 * La scheda chiede «Visualizza anagrafiche»; il modulo di modifica, dove porta il pulsante, chiede a
 * `AnagraficaPolicy::update` «Modifica anagrafiche». Chi ha la sola consultazione legge la scheda senza il pulsante;
 * l'amministratore e il collaboratore di serie hanno tutti e due i permessi, e per loro non cambia niente.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/anagrafiche/7',
        props: {
            flash: {},
            anagrafica: { id: 7 },
            auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } },
        },
    }),
}));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.anagrafica ? `/${parametri.anagrafica}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const AnagraficheView = (await import('./AnagraficheView.vue')).default;

function scheda(extra: Record<string, unknown> = {}) {
    return mount(AnagraficheView, {
        props: {
            ...extra,
            anagrafica: {
                id: 7, nome: 'Mario Rossi', indirizzo: 'Via Roma 1', email: 'mario@example.com', email_secondaria: null, pec: null,
                telefono: null, cellulare: null, codice_fiscale: 'RSSMRA80A01H501U', condomini: [], user: null,
            },
            immobili: [],
            documenti_count: 0,
        },
        global: {
            mocks: { route },
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                AnagraficaLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { template: '<header><slot name="actions" /></header>' },
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
                TooltipProvider: { template: '<div><slot /></div>' },
                Tooltip: { template: '<div><slot /></div>' },
                TooltipTrigger: { template: '<div><slot /></div>' },
                TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
}

function pulsanteModifica() {
    return scheda().find('header a');
}

beforeEach(() => {
    chiGuarda.ruoli = ['amministratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'];
});

describe('AnagraficheView — «Modifica dati» chiede il permesso del modulo di modifica (decisioni 137 e 138.1)', () => {
    test('con «Modifica anagrafiche» il pulsante c\'è e apre il modulo di modifica', () => {
        const pulsante = pulsanteModifica();

        expect(pulsante.exists()).toBe(true);
        expect(pulsante.text()).toContain('Modifica dati');
        expect(pulsante.attributes('href')).toBe('/admin.anagrafiche.edit/7');
    });

    test('il collaboratore di serie, che ha «Modifica anagrafiche», vede il pulsante', () => {
        chiGuarda.ruoli = ['collaboratore'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche'];

        expect(pulsanteModifica().exists()).toBe(true);
    });

    test('decisione 138 — con la sola consultazione la scheda si legge e il pulsante «Modifica dati» non c\'è', () => {
        chiGuarda.ruoli = ['Consultazione'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];
        const pagina = scheda();

        expect(pagina.find('header a').exists()).toBe(false);
        expect(pagina.text()).toContain('mario@example.com');
    });

    test('decisione 138 — «Crea anagrafiche» senza «Modifica anagrafiche» non basta: il pulsante «Modifica dati» non c\'è', () => {
        chiGuarda.ruoli = ['Inserimento'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];

        expect(pulsanteModifica().exists()).toBe(false);
    });

    test('decisione 138 — «Modifica utenti» non basta: è il permesso degli account', () => {
        chiGuarda.ruoli = ['Segreteria'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica utenti'];

        expect(pulsanteModifica().exists()).toBe(false);
    });
});

describe('AnagraficheView — in «Dettagli» le assemblee sono una riga che porta alla linguetta (decisione 651)', () => {
    test('«N assemblee in M condomini · l\'ultima il … · Vedi le sue assemblee», con il collegamento alla linguetta; l\'elenco non c\'è più', () => {
        const pagina = scheda({ assemblee_riepilogo: { totale: 7, condomini: 2, ultima: '15/12/2026' } });
        const riga = pagina.find('[data-testid="riga-assemblee"]');

        expect(riga.text().replace(/\s+/g, ' ')).toContain("7 assemblee in 2 condomini · l'ultima il 15/12/2026 · Vedi le sue assemblee");
        expect(riga.find('a').attributes('href')).toBe('/admin/anagrafiche/7/assemblee');
        expect(pagina.find('[data-testid="le-sue-assemblee"]').exists()).toBe(false);
    });

    test('senza assemblee: «Nessuna assemblea, per ora»; senza «Vede le assemblee» (null) la riga non c\'è', () => {
        expect(scheda({ assemblee_riepilogo: { totale: 0, condomini: 0, ultima: null } }).find('[data-testid="riga-assemblee"]').text()).toBe('Nessuna assemblea, per ora');
        expect(scheda({ assemblee_riepilogo: null }).find('[data-testid="riga-assemblee"]').exists()).toBe(false);
        expect(scheda().find('[data-testid="riga-assemblee"]').exists()).toBe(false);
    });
});
