// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina di creazione di una comunicazione (pannello): la pubblicazione programmata (Coda 589, decisione 290).
 *
 * La tendina dello stato (`v-select` con `id="stato"`) ha una terza voce, «Programmata», per chi può programmare: il
 * suo valore è `'scheduled'`; «Pubblicata» e «Bozza» restano `true` e `false`. Scelta «Programmata» compare il campo
 * dell'ora, un `<input type="datetime-local">` con `data-campo="data-programmata"`, con la nota del fuso
 * (`data-nota="fuso"`) e, se il pianificatore non risulta attivo, l'avviso (`data-avviso="pianificatore-fermo"`).
 *
 * La pagina manda sempre `data_programmata`: l'ora scritta con «Programmata», `null` con gli altri due stati. Con
 * «Programmata» manda `is_published: false`.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';

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
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;

function monta(programmazione: Record<string, unknown> | null = {}) {
    return shallowMount(ComunicazioniNew, {
        props: {
            condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true,
            ...(programmazione === null ? {} : {
                programmazione: { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00', ...programmazione },
            }),
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const tendina = (pagina: VueWrapper) => pagina.findAllComponents(vSelect).find((c) => c.attributes('id') === 'stato')!;
const valori = (pagina: VueWrapper) => (tendina(pagina).props('options') as { value: unknown; label: string }[]).map((o) => o.value);
const campo = (pagina: VueWrapper) => pagina.find('input[data-campo="data-programmata"]');
const mandato = () => vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;

async function scegli(pagina: VueWrapper, stato: unknown) {
    tendina(pagina).vm.$emit('update:modelValue', stato);
    await flushPromises();
}

function invia(pagina: VueWrapper) {
    (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('ComunicazioniNew — la voce «Programmata» della tendina', () => {
    test('chi può programmare la trova fra gli stati, con la sua etichetta', () => {
        const pagina = monta();
        const voce = (tendina(pagina).props('options') as { value: unknown; label: string }[]).find((o) => o.value === 'scheduled');

        expect(valori(pagina)).toEqual(expect.arrayContaining([true, false, 'scheduled']));
        expect(voce!.label).toBe('comunicazioni.publication.scheduled');
    });

    test('chi non può programmare trova solo «Bozza» e «Pubblicata»', () => {
        expect(valori(monta({ puo_programmare: false })).sort()).toEqual([false, true]);
        // Una pagina aperta senza la prop (un'altra versione del server) non offre ciò che non sa.
        expect(valori(monta(null)).sort()).toEqual([false, true]);
    });
});

describe('ComunicazioniNew — il campo dell\'ora', () => {
    test('di serie il campo non c\'è; scelta «Programmata» compare, con il fuso e l\'ora minima', async () => {
        const pagina = monta();
        await flushPromises();
        expect(campo(pagina).exists()).toBe(false);
        expect(pagina.find('[data-nota="fuso"]').exists()).toBe(false);

        await scegli(pagina, 'scheduled');

        expect(campo(pagina).exists()).toBe(true);
        expect(campo(pagina).attributes('type')).toBe('datetime-local');
        expect(campo(pagina).attributes('min')).toBe('2026-11-12T11:00');
        // Decisione 504: con il fuso italiano la riga sotto la data non c'è.
        expect(pagina.find('[data-nota="fuso"]').exists()).toBe(false);
        expect(pagina.find('[data-avviso="pianificatore-fermo"]').exists()).toBe(false);
    });

    test('decisione 504: con un fuso diverso da quello italiano sotto la data c\'è solo «Ora di …»', async () => {
        const pagina = monta({ fuso: 'Europe/London' });
        await scegli(pagina, 'scheduled');
        expect(pagina.find('[data-nota="fuso"]').text()).toContain('comunicazioni.scheduling.timezone_hint');
    });

    test('tornando a «Pubblicata» o a «Bozza» il campo sparisce', async () => {
        const pagina = monta();
        await scegli(pagina, 'scheduled');
        expect(campo(pagina).exists()).toBe(true);

        await scegli(pagina, true);
        expect(campo(pagina).exists()).toBe(false);

        await scegli(pagina, 'scheduled');
        await scegli(pagina, false);
        expect(campo(pagina).exists()).toBe(false);
    });

    test('con il pianificatore fermo, chi sceglie «Programmata» lo legge', async () => {
        const pagina = monta({ pianificatore_attivo: false });
        await flushPromises();
        expect(pagina.find('[data-avviso="pianificatore-fermo"]').exists()).toBe(false);

        await scegli(pagina, 'scheduled');

        expect(pagina.find('[data-avviso="pianificatore-fermo"]').text()).toContain('comunicazioni.scheduling.scheduler_down');
    });
});

describe('ComunicazioniNew — che cosa manda', () => {
    test('con «Programmata» manda l\'ora scritta e `is_published` falso', async () => {
        const pagina = monta();
        await scegli(pagina, 'scheduled');
        await campo(pagina).setValue('2027-01-15T09:00');

        invia(pagina);

        expect(mandato()).toMatchObject({ is_published: false, data_programmata: '2027-01-15T09:00' });
    });

    test('con «Pubblicata» e con «Bozza» manda `data_programmata` vuota, anche se un\'ora era stata scritta', async () => {
        const pagina = monta();
        await scegli(pagina, 'scheduled');
        await campo(pagina).setValue('2027-01-15T09:00');

        await scegli(pagina, true);
        invia(pagina);
        expect(mandato()).toMatchObject({ is_published: true, data_programmata: null });

        await scegli(pagina, false);
        invia(pagina);
        expect(mandato()).toMatchObject({ is_published: false, data_programmata: null });
    });

    test('aperta e mandata senza toccare lo stato, manda «Pubblicata» senza ora, come prima', async () => {
        const pagina = monta();
        await flushPromises();

        invia(pagina);

        expect(mandato()).toMatchObject({ is_published: true, data_programmata: null });
    });
});
