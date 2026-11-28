// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Incolla da un foglio di calcolo»: che cosa succede alla griglia quando la finestra applica.
 *
 * La lettura del testo ha le sue prove in `incollaMillesimi.test.ts`. Qui si guarda l'altra metà:
 * i valori riconosciuti entrano nelle righe giuste, un'unità non ancora associata entra con il suo
 * valore, e le righe che l'incolla non nomina **restano come sono**. La finestra non salva: lo
 * dice l'ultima prova, che conta le richieste partite.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

// I permessi dell'utente della prova: «Modifica condomini» di serie, tolto dove la prova lo dice.
const permessi = { valore: ['Modifica condomini'] as string[] };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: permessi.valore } } },
    }),
}));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const QuoteList = (await import('./QuoteList.vue')).default;
const IncollaMillesimi = (await import('@/components/gestionale/tabelle/IncollaMillesimi.vue')).default;

function immobile(i: number) {
    return {
        id: i, nome: `Int ${i}`, interno: String(i), piano: '1', superficie: 80, palazzina: null, scala: null,
        particella_catasto: '658', subalterno_catasto: String(10 + i),
    };
}

/** Tre unità nel condominio; le prime due già nella tabella, con 500 e 300. */
function monta() {
    return mount(QuoteList, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            tabella: { id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard', quota: 'millesimi', attiva: true, numero_decimali: 2 } as never,
            millesimi: [
                { id: 1, immobile: immobile(1), valore: '500', coefficienti: null },
                { id: 2, immobile: immobile(2), valore: '300', coefficienti: null },
            ] as never,
            immobili: [immobile(1), immobile(2), immobile(3)] as never,
        },
        global: {
            mocks: { route: (name: string) => `/${name}` },
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { template: '<div />' },
                Link: { template: '<a><slot /></a>' },
                'v-select': true,
            },
        },
    });
}

afterEach(() => {
    permessi.valore = ['Modifica condomini'];
});

const caselle = (w: ReturnType<typeof monta>) => w.findAll('input').map((i) => (i.element as HTMLInputElement).value);

describe('«Incolla da un foglio di calcolo» nella pagina delle quote', () => {
    test('il pulsante c\'è, accanto all\'associazione in blocco, e apre la finestra', async () => {
        const w = monta();
        const pulsante = w.find('[data-test="apri-incolla"]');

        expect(pulsante.exists()).toBe(true);
        expect(pulsante.text()).toContain('Incolla da un foglio');
        expect(w.findComponent(IncollaMillesimi).props('open')).toBe(false);

        await pulsante.trigger('click');

        expect(w.findComponent(IncollaMillesimi).props('open')).toBe(true);
    });

    test('senza «Modifica condomini» il pulsante e la finestra non ci sono, come «Salva quote»', () => {
        permessi.valore = [];
        const w = monta();

        expect(w.find('[data-test="apri-incolla"]').exists()).toBe(false);
        expect(w.findComponent(IncollaMillesimi).exists()).toBe(false);
    });

    test('la finestra riceve tutte le unità del condominio, anche quelle non ancora associate', () => {
        const w = monta();

        expect((w.findComponent(IncollaMillesimi).props('unita') as { id: number }[]).map((u) => u.id)).toEqual([1, 2, 3]);
    });

    test('la finestra sa quali unità sono già nella griglia e che valore hanno adesso, anche dopo una modifica non salvata', async () => {
        const w = monta();
        const finestra = () => w.findComponent(IncollaMillesimi);

        expect(finestra().props('inTabella')).toEqual([1, 2]);
        expect(finestra().props('adesso')).toEqual({ 1: '500.00', 2: '300.00' });

        finestra().vm.$emit('applica', [{ immobileId: 3, valore: '200' }, { immobileId: 1, valore: '450' }]);
        await nextTick();

        expect(finestra().props('inTabella')).toEqual([1, 2, 3]);
        expect(finestra().props('adesso')).toEqual({ 1: '450.00', 2: '300.00', 3: '200.00' });
    });

    test('un valore incollato su un\'unità già in elenco la cambia, e le altre restano come sono', async () => {
        const w = monta();

        w.findComponent(IncollaMillesimi).vm.$emit('applica', [{ immobileId: 2, valore: '333.5' }]);
        await nextTick();

        expect(caselle(w)).toContain('500.00');
        // Come dopo l'uscita dalla casella: i decimali della tabella sono un minimo.
        expect(caselle(w)).toContain('333.50');
        expect(caselle(w)).not.toContain('300.00');
        expect(w.text()).toContain('833.50');
    });

    test('un\'unità non ancora associata entra nell\'elenco con il suo valore', async () => {
        const w = monta();

        w.findComponent(IncollaMillesimi).vm.$emit('applica', [{ immobileId: 3, valore: '200' }]);
        await nextTick();

        expect(w.text()).toContain('Int 3');
        expect(caselle(w)).toContain('200.00');
        expect(w.text()).toContain('1000.00');
    });

    test('un\'unità che il condominio non ha non entra', async () => {
        const w = monta();

        w.findComponent(IncollaMillesimi).vm.$emit('applica', [{ immobileId: 99, valore: '200' }]);
        await nextTick();

        expect(caselle(w)).not.toContain('200.00');
        expect(w.text()).toContain('800.00');
    });

    test('un valore più preciso dei decimali della tabella entra intero', async () => {
        const w = monta();

        w.findComponent(IncollaMillesimi).vm.$emit('applica', [{ immobileId: 2, valore: '166.245' }]);
        await nextTick();

        expect(caselle(w)).toContain('166.245');
    });

    test('applicare non salva: nessuna richiesta parte finché non si preme «Salva»', async () => {
        const richieste = vi.spyOn(globalThis, 'fetch' as never).mockImplementation((() => Promise.reject(new Error('non deve partire'))) as never);
        const xhr = vi.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(() => undefined);
        const w = monta();

        w.findComponent(IncollaMillesimi).vm.$emit('applica', [{ immobileId: 1, valore: '450' }]);
        await nextTick();

        expect(richieste).not.toHaveBeenCalled();
        expect(xhr).not.toHaveBeenCalled();
    });
});
