// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu in alto del gestionale senza un esercizio aperto: «Gestioni», «Piani conti» e «Piani rate» puntavano a `esercizi/undefined/…`, cioè a
 * una pagina 404. Ora le tre voci non ci sono e il menu, quello largo e quello del telefono, dice «Nessun esercizio aperto» con il collegamento
 * agli esercizi. Con un esercizio i collegamenti sono quelli di sempre; una pagina che non manda `esercizio` non ha le tre voci e non dice che
 * l'esercizio manca.
 *
 * Che cosa non copre: il disegno del browser; le altre voci e i permessi del menu.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const pagina: { url: string; props: Record<string, unknown> } = { url: '/admin/gestionale/7', props: {} };

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) },
    usePage: () => pagina,
    router: { post: vi.fn(), flushAll: vi.fn() },
}));

/** Il cassetto del telefono si disegna aperto, con il contenuto al suo posto. */
vi.mock('@/components/ui/sheet', () => {
    const lascia = (nome: string) => defineComponent({ name: nome, setup: (_, { slots }) => () => h('div', { 'data-stub': nome }, slots.default?.()) });

    return { Sheet: lascia('Sheet'), SheetContent: lascia('SheetContent'), SheetHeader: lascia('SheetHeader'), SheetTitle: lascia('SheetTitle'), SheetTrigger: lascia('SheetTrigger') };
});

const GestionaleHeader = (await import('./GestionaleHeader.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

function monta(esercizio: unknown) {
    pagina.props = {
        auth: { user: { name: 'Anna Bianchi', roles: ['amministratore'], permissions: [] } },
        condominio: { id: 7, nome: 'Via Roma 12' },
        ...(esercizio === undefined ? {} : { esercizio }),
    };
    w = mount(GestionaleHeader, { global: { stubs: { AppLogo: true, AppLogoIcon: true } } });

    return w;
}

const indirizzi = () => w!.findAll('a').map((a) => a.attributes('href') ?? '');
const voci = (titolo: string) => w!.findAll('a').filter((a) => a.text() === titolo);

describe('il menu del gestionale e l\'esercizio aperto', () => {
    test('con un esercizio le tre voci portano alle pagine di quell\'esercizio e la frase non c\'è', () => {
        monta({ id: 3, nome: 'Esercizio 2026' });

        for (const [titolo, fine] of [['Gestioni', 'gestioni'], ['Piani conti', 'piani-conti'], ['Piani rate', 'piani-rate']]) {
            expect(voci(titolo).length, titolo).toBeGreaterThan(0);
            expect(voci(titolo).every((a) => a.attributes('href') === `/admin/gestionale/7/esercizi/3/${fine}`), titolo).toBe(true);
        }
        expect(w!.text()).not.toContain('Nessun esercizio aperto');
    });

    test('senza un esercizio aperto le tre voci non ci sono, e «Nessun esercizio aperto» porta agli esercizi, nel menu largo e nel cassetto', () => {
        monta(null);

        expect(indirizzi().filter((i) => i.includes('undefined') || i.includes('null'))).toEqual([]);
        for (const titolo of ['Gestioni', 'Piani conti', 'Piani rate']) {
            expect(voci(titolo), titolo).toHaveLength(0);
        }
        const avvisi = voci('Nessun esercizio aperto');
        expect(avvisi).toHaveLength(2);
        for (const a of avvisi) {
            expect(a.attributes('href')).toBe('/admin/gestionale/7/esercizi');
            expect(a.classes()).toContain('rounded-md');
            expect(a.classes()).toContain('text-xs');
        }
        // Le altre voci restano.
        expect(voci('Esercizi').length).toBeGreaterThan(0);
        expect(voci('Movimenti').length).toBeGreaterThan(0);
    });

    test('una pagina che non manda l\'esercizio non ha le tre voci e non dice che l\'esercizio manca', () => {
        monta(undefined);

        expect(indirizzi().filter((i) => i.includes('undefined'))).toEqual([]);
        expect(voci('Piani rate')).toHaveLength(0);
        expect(w!.text()).not.toContain('Nessun esercizio aperto');
    });
});
