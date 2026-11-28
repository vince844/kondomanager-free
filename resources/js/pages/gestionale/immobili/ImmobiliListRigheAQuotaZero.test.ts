// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Unità immobiliari» — il riquadro delle righe di proprietà chiuse a quota 0.
 *
 * Il server manda `righeAQuotaZero` (una riga per caso, con l'indirizzo dello storico); la pagina le mostra in testa all'elenco, con le parole della bozza
 * approvata: la frase con il numero, la tabella (unità, persona, periodo, giorni che nessuno paga, «Apri lo storico») e il cassetto «Perché ci sono». Senza
 * casi il riquadro non c'è. Nessuna correzione da qui: ogni riga porta allo storico dell'unità.
 *
 * **Cosa resta scoperto**: l'elenco delle unità sotto il riquadro (la sua prova è `DataTable`), e la navigazione vera verso lo storico.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

(globalThis as any).route = (n: string) => `/${n}`;

const ImmobiliList = (await import('./ImmobiliList.vue')).default;

const passa = { template: '<div><slot /></div>' };

function riga(extra: Record<string, unknown>): any {
    return { riga_id: 1, immobile_id: 5, unita: 'Interno 5', anagrafica_id: 10, persona: 'Edil Costruzioni Srl', tipologia: 'proprietario', dal: '2026-01-01', al: '2026-09-15',
        periodo: '1 gen 2026 – 15 set 2026', giorni: 258, url_storico: '/condomini/3/immobili/5/anagrafiche?storico=1', ...extra };
}

function monta(righe?: any[]) {
    return mount(ImmobiliList, {
        props: { condominio: { id: 3, nome: 'Condominio Aurora' } as any, condomini: [], immobili: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } as any, ...(righe === undefined ? {} : { righeAQuotaZero: righe }) },
        global: {
            mocks: { route: (n: string) => `/${n}` },
            stubs: {
                GestionaleLayout: passa, DataTable: true, CondominioDropdown: true, Alert: true,
                PageHeaderGuide: { props: ['guides'], template: '<div data-testid="guida">{{ guides.map((g) => g.description).join(" ") }}</div>' },
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
        },
    });
}

describe('ImmobiliList — le righe di proprietà chiuse a quota 0', () => {
    test('con tre casi: la frase con il numero, la tabella con le colonne della bozza e un «Apri lo storico» per riga', () => {
        const w = monta([
            riga({}),
            riga({ riga_id: 2, immobile_id: 11, unita: 'Interno 11', periodo: '1 gen 2026 – 22 feb 2026', giorni: 53, url_storico: '/condomini/3/immobili/11/anagrafiche?storico=1' }),
            riga({ riga_id: 3, immobile_id: 30, unita: 'Interno 30', periodo: '1 gen 2026 – 23 set 2026', giorni: 266, url_storico: '/condomini/3/immobili/30/anagrafiche?storico=1' }),
        ]);

        const riquadro = w.get('[data-testid="righe-a-quota-zero"]');
        expect(riquadro.text()).toContain('3 righe di proprietà chiuse con quota 0 %, seguite da un altro proprietario: nei loro giorni nessuno paga per quelle unità.');
        expect(riquadro.findAll('th').map((t) => t.text()).filter(Boolean)).toEqual(['Unità', 'Persona', 'Periodo', 'Giorni che nessuno paga']);
        const righe = riquadro.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()));
        expect(righe).toEqual([
            ['Interno 5', 'Edil Costruzioni Srl', '1 gen 2026 – 15 set 2026', '258', 'Apri lo storico'],
            ['Interno 11', 'Edil Costruzioni Srl', '1 gen 2026 – 22 feb 2026', '53', 'Apri lo storico'],
            ['Interno 30', 'Edil Costruzioni Srl', '1 gen 2026 – 23 set 2026', '266', 'Apri lo storico'],
        ]);
        expect(riquadro.findAll('tbody a').map((a) => a.attributes('href'))).toEqual([
            '/condomini/3/immobili/5/anagrafiche?storico=1', '/condomini/3/immobili/11/anagrafiche?storico=1', '/condomini/3/immobili/30/anagrafiche?storico=1',
        ]);
        // I giorni sono cifre: a destra, con le cifre della stessa larghezza.
        expect(riquadro.findAll('tbody td').filter((td) => td.text() === '258')[0].classes()).toEqual(expect.arrayContaining(['text-right', 'tabular-nums']));
    });

    test('con un caso solo la frase è al singolare', () => {
        const w = monta([riga({})]);

        expect(w.get('[data-testid="righe-a-quota-zero"]').text()).toContain('1 riga di proprietà chiusa con quota 0 %, seguita da un altro proprietario: nei suoi giorni nessuno paga per quell\'unità.');
    });

    test('il cassetto «Perché ci sono» dice la storia in tre frasi, senza numeri di beta', () => {
        const w = monta([riga({})]);

        const cassetto = w.get('[data-testid="righe-a-quota-zero"] details');
        expect(cassetto.get('summary').text()).toBe('Perché ci sono');
        expect(cassetto.findAll('li').map((l) => l.text())).toEqual([
            'Nelle versioni fino alla 1.10 il programma non accettava due proprietari al 100 % sulla stessa unità, anche in periodi diversi.',
            'Per andare avanti si abbassava a 0 % chi aveva venduto.',
            'Oggi il controllo è giorno per giorno: la quota giusta si può rimettere.',
        ]);
    });

    test('senza casi il riquadro non c\'è, né con la lista vuota né con la prop mancante (un server di prima)', () => {
        expect(monta([]).find('[data-testid="righe-a-quota-zero"]').exists()).toBe(false);
        expect(monta().find('[data-testid="righe-a-quota-zero"]').exists()).toBe(false);
    });

    test('la guida della pagina dice dove si correggono le righe a quota 0', () => {
        const w = monta([]);

        expect(w.get('[data-testid="guida"]').text()).toContain('Una riga di proprietà chiusa a quota 0 % lascia i suoi giorni senza pagante: se compare nel riquadro sopra l\'elenco, aprila dallo storico.');
    });
});
