// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'estratto conto della persona.
 *
 * - Niente scritte sotto gli 11 pixel nelle parti degli addebiti personali, del menu «Azioni», delle unità, della legenda e delle righe della tabella (le
 *   descrizioni del menu, «Rate emesse e addebiti», il numero e il tipo delle unità, «(Dare)» e «(Avere)», «Registrata il», il protocollo, la gestione).
 * - Uno zero è grigio: con il saldo finale a zero il titolo della card e «PAREGGIO» non sono verdi.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ url: '/admin/gestionale/28/anagrafiche/7/estratto-conto', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string, parametri: Record<string, unknown> = {}) =>
    `/${name}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const SORGENTE = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue'), 'utf8').split('\n');
const SOTTO_GLI_11 = /text-\[(?:[0-9]|10|10\.5)px\]/;

describe('l\'estratto conto: niente scritte sotto gli 11 pixel', () => {
    test.each([
        ['Lettere</DropdownMenuLabel>'], ['Addebiti</DropdownMenuLabel>'], ['Stampe</DropdownMenuLabel>'], ['Un contributo, o una spesa di una sola unità già fatturata'],
        ['Le spese per un pagamento in ritardo'], ['Rate emesse e addebiti'], ['quanti-addebiti-personali'], ['{{ anagrafica.immobili.length }}</Badge>'],
        ['{{ immobile.pivot.tipologia }}'], ['percentuale(immobile.pivot.quota)'], ['Legenda:'], ['(Dare)'], ['(Avere)'], ['Registrata il'], ['{{ riga.protocollo }}'],
        ['{{ riga.gestione }}'],
    ])('%s', (parte) => {
        const righe = SORGENTE.map((r, i) => ({ r, n: i + 1 })).filter(({ r }) => r.includes(parte));
        expect(righe.length).toBeGreaterThan(0);
        expect(righe.filter(({ r }) => SOTTO_GLI_11.test(r)).map(({ n }) => n)).toEqual([]);
    });
});

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;
const Intestazione = { name: 'PageHeaderGuide', props: ['pageTitle', 'pageSubtitle', 'guides', 'breadcrumbs', 'condominio', 'esercizio', 'backUrl', 'backText', 'hasTextGuide', 'textGuideTitle'],
    template: '<div data-testid="intestazione"><slot name="actions" /></div>' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

function monta(saldo: number, testo: string) {
    w = shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Giuseppe Rossi', codice_fiscale: null, immobili: [] } as never, timeline: [],
            stats: { totale_addebiti: '€ 100,00', totale_versamenti: '€ 100,00', saldo_finale: testo, saldo_raw: saldo, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null },
            rimborso: { quote: [], casse: [], credito_in_saldi: 0 }, addebiti: [] as never,
        },
        global: { renderStubDefaultSlot: true, stubs: { PageHeaderGuide: Intestazione }, mocks: { route: (name: string) => `/${name}` } },
    });
    const titolo = w.findAll('h3').find((h) => /^Saldo finale$/i.test(h.text()))!;

    return titolo.element.closest('.rounded-lg') as HTMLElement;
}
const colori = (card: HTMLElement, colore: string) => [card, ...card.querySelectorAll('*')]
    .filter((el) => new RegExp(`(^|\\s)text-${colore}-`).test(el.getAttribute('class') ?? '')).map((el) => (el.textContent ?? '').trim() || el.tagName.toLowerCase());

describe('l\'estratto conto: uno zero è grigio', () => {
    test('con il saldo finale a zero il titolo, l\'icona e «PAREGGIO» non sono verdi né rossi', () => {
        const card = monta(0, '€ 0,00');
        expect(colori(card, 'emerald')).toEqual([]);
        expect(colori(card, 'red')).toEqual([]);
        expect(card.textContent).toContain('PAREGGIO');
    });

    test('il controllo: un credito resta verde, un debito rosso', () => {
        expect(colori(monta(-1500, '-€ 15,00'), 'emerald').length).toBeGreaterThan(0);
        w!.unmount();
        expect(colori(monta(1500, '€ 15,00'), 'red').length).toBeGreaterThan(0);
    });
});
