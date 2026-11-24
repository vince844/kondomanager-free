// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 757 (2), 1.12.0-beta.14: nella vendita il ruolo di chi compra non si sceglie. Il modulo offriva il menu «Proprietario» / «Nudo proprietario» nella
 * vendita senza riserva, ma il server (`AnteprimaPassaggioRequest`) rifiuta ogni ruolo diverso da quello di chi vende (422): ora c'è il badge, come nella
 * successione, con il ruolo di chi vende («Nudo proprietario» con la riserva d'usufrutto), e accanto all'etichetta il tooltip che dice la regola.
 *
 * Si monta la pagina con `shallowMount`, come in `PassaggioNewInteressiDelDefunto.test.ts`; la risposta del pannello non serve (si guarda il modulo).
 *
 * Cosa NON copre: il server (`AnteprimaPassaggioRequest`, prove nei test dei passaggi); l'aspetto del tooltip.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import axios from 'axios';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const PassaggioNew = (await import('./PassaggioNew.vue')).default;
const BadgeRuolo = (await import('@/components/gestionale/immobili/BadgeRuolo.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    post.mockReset();
    post.mockResolvedValue({ data: {} });
});

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const proprietario = { id: 10, anagrafica: { id: 1, nome: 'Ugo Rossi', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };
const nudo = { ...proprietario, tipologia: 'nuda_proprietario' };
const ELSA = { id: 3, nome: 'Elsa Bianchi', codice_fiscale: null, indirizzo: 'Via Verdi 1' };

function monta(titolare: typeof proprietario): Pagina {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'U1' } as never,
            tipo: 'vendita',
            rigaPreselezionata: 10,
            titolari: [titolare],
            anagrafiche: [ELSA],
            pertinenze: [],
            rateEmesseCount: 1,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

const modulo = (w: Pagina) => (w.vm as unknown as { form: Record<string, unknown> }).form;
const riquadroRuolo = (w: Pagina) => w.find('[data-testid="ruolo-vendita"]');

describe('la vendita: il ruolo di chi compra è quello di chi vende', () => {
    test('da un proprietario nessun menu, il badge «Proprietario», il tooltip con la regola, e il ruolo mandato è quello di chi vende', async () => {
        const w = monta(proprietario);
        await flushPromises();

        expect(riquadroRuolo(w).exists()).toBe(true);
        expect(riquadroRuolo(w).findComponent(BadgeRuolo).props('ruolo')).toBe('proprietario');
        expect(w.find('v-select-stub[label="label"][options]').exists()).toBe(false);
        expect(w.text()).toContain('Chi compra entra con il ruolo di chi vende. Se chi vende si tiene l\'usufrutto, spunta la casella qui sotto.');
        expect(modulo(w).tipologia).toBe('proprietario');
    });

    test('con la riserva d\'usufrutto il badge dice «Nudo proprietario» e il ruolo mandato è quello', async () => {
        const w = monta(proprietario);
        await flushPromises();
        (w.vm as unknown as { riserva: boolean }).riserva = true;
        await flushPromises();

        expect(riquadroRuolo(w).findComponent(BadgeRuolo).props('ruolo')).toBe('nuda_proprietario');
        expect(modulo(w).tipologia).toBe('nuda_proprietario');
    });

    test('da un nudo proprietario il badge dice «Nudo proprietario», senza menu', async () => {
        const w = monta(nudo);
        await flushPromises();

        expect(riquadroRuolo(w).findComponent(BadgeRuolo).props('ruolo')).toBe('nuda_proprietario');
        expect(modulo(w).tipologia).toBe('nuda_proprietario');
    });
});
