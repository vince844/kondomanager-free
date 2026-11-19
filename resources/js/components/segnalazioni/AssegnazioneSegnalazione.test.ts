// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Assegnata a» della scheda di una segnalazione nel pannello (decisione 294).
 *
 * Componente: `@/components/segnalazioni/AssegnazioneSegnalazione.vue`, con le props `segnalazioneId`, `assegnataA`
 * (`null` o `{ id, name, attivo, da, il, alle }`) e `assegnazione` (`{ puo_assegnare, io, assegnabili }`).
 *
 * - Chi può assegnare ha una tendina (`v-select` con `id="assegnatario"`) con chi può ricevere, e «Assegna a me»
 *   (`button[data-azione="assegna-a-me"]`) finché la segnalazione non è già sua. Ogni scelta chiama subito il comando
 *   `admin.segnalazioni.assegna` con `assigned_to`; svuotare la tendina manda `null`.
 * - Chi non può assegnare legge il nome, o «Nessuno».
 * - Se chi segue la segnalazione non ha più il pannello, il riquadro lo dice (`[data-avviso="assegnatario-non-attivo"]`).
 * - Sotto, «Assegnata da … il … alle …» (`[data-campo="assegnata-da"]`).
 * - Se il server rifiuta il comando, il motivo compare nel riquadro (`[data-errore="assegnazione"]`): la scheda non mostra
 *   i messaggi di esito, quindi senza questo un rifiuto non si vedrebbe.
 *
 * Il valore mostrato viene sempre dalla prop: dopo il comando la pagina si ricarica e la prop cambia.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, argomenti?: Record<string, unknown>) => (argomenti ? `${chiave} ${JSON.stringify(argomenti)}` : chiave),
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string, argomenti?: unknown) => `/${nome}/${JSON.stringify(argomenti ?? {})}`;

const AssegnazioneSegnalazione = (await import('@/components/segnalazioni/AssegnazioneSegnalazione.vue')).default;

const assegnabili = [
    { id: 4, name: 'Anna Collaboratrice' },
    { id: 9, name: 'Elena Studio' },
    { id: 1, name: 'Studio Bianchi' },
];
const elena = { id: 9, name: 'Elena Studio', attivo: true, da: 'Studio Bianchi', il: '10/11/2026', alle: '09:30' };

function monta(assegnataA: unknown, assegnazione: Record<string, unknown> = {}) {
    return shallowMount(AssegnazioneSegnalazione, {
        props: {
            segnalazioneId: 31,
            assegnataA,
            assegnazione: { puo_assegnare: true, io: 1, assegnabili, ...assegnazione },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (globalThis as Record<string, unknown>).route } },
    });
}

const tendina = (w: VueWrapper) => w.findAllComponents(vSelect).find((c) => c.attributes('id') === 'assegnatario');
const pulsante = (w: VueWrapper) => w.find('button[data-azione="assegna-a-me"]');
const chiamate = () => vi.mocked(router.patch).mock.calls;

beforeEach(() => {
    vi.spyOn(router, 'patch').mockImplementation(() => undefined as never);
    vi.mocked(router.patch).mockClear();
});

describe('AssegnazioneSegnalazione — chi può assegnare', () => {
    test('ha la tendina con chi può ricevere, sul nome di chi segue la segnalazione', () => {
        const w = monta(elena);

        expect(w.find('[data-campo="assegnata-a"]').exists()).toBe(true);
        expect(tendina(w), 'manca la tendina con id «assegnatario»').toBeDefined();
        expect(tendina(w)!.props('options')).toEqual(expect.arrayContaining(assegnabili));
        expect(tendina(w)!.props('modelValue')).toBe(9);
        expect(tendina(w)!.props('label')).toBe('name');
    });

    test('su una segnalazione di nessuno la tendina è vuota, con il suo invito', () => {
        const w = monta(null);

        expect(tendina(w)!.props('modelValue')).toBeNull();
        expect(tendina(w)!.props('placeholder')).toBe('segnalazioni.assignment.placeholder');
    });

    test('scegliere una persona chiama subito il comando, per quella segnalazione', async () => {
        const w = monta(null);

        tendina(w)!.vm.$emit('update:modelValue', 4);
        await flushPromises();

        expect(chiamate()).toHaveLength(1);
        expect(String(chiamate()[0][0])).toContain('segnalazioni.assegna');
        expect(String(chiamate()[0][0])).toContain('31');
        expect(chiamate()[0][1]).toEqual({ assigned_to: 4 });
        // P7 della lente P (Fase 1-bis della beta.12): una visita parziale (standard 16), non la scheda intera; con il codice di prima solo
        // `preserveScroll`. Il messaggio («Segnalazione assegnata a …») arriva con `flash`, lo stato forse cambiato con `segnalazione`.
        expect(chiamate()[0][2]).toMatchObject({ preserveScroll: true, preserveState: true, only: ['segnalazione', 'inbox_count', 'flash'] });
    });

    test('svuotare la tendina toglie l\'assegnazione', async () => {
        const w = monta(elena);

        tendina(w)!.vm.$emit('update:modelValue', null);
        await flushPromises();

        expect(chiamate()).toHaveLength(1);
        expect(chiamate()[0][1]).toEqual({ assigned_to: null });
    });

    test('scegliere di nuovo la stessa persona non chiama niente', async () => {
        const w = monta(elena);

        tendina(w)!.vm.$emit('update:modelValue', 9);
        await flushPromises();

        expect(chiamate()).toHaveLength(0);
    });

    test('«Assegna a me» c\'è finché la segnalazione non è già di chi guarda, e manda il suo id', async () => {
        const w = monta(elena);
        expect(pulsante(w).exists()).toBe(true);
        expect(pulsante(w).text()).toContain('segnalazioni.assignment.assign_to_me');

        await pulsante(w).trigger('click');
        expect(chiamate()).toHaveLength(1);
        expect(chiamate()[0][1]).toEqual({ assigned_to: 1 });
        // P7 della lente P (Fase 1-bis della beta.12): anche «Assegna a me» con la visita parziale.
        expect(chiamate()[0][2]).toMatchObject({ preserveScroll: true, preserveState: true, only: ['segnalazione', 'inbox_count', 'flash'] });

        expect(pulsante(monta(null)).exists()).toBe(true);
        expect(pulsante(monta({ ...elena, id: 1, name: 'Studio Bianchi' })).exists()).toBe(false);
    });

    test('se il server rifiuta, il riquadro dice perché; al tentativo dopo il messaggio sparisce', async () => {
        vi.mocked(router.patch).mockImplementationOnce(((_url: unknown, _dati: unknown, opzioni: { onError?: (errori: Record<string, string>) => void }) => {
            opzioni.onError?.({ assigned_to: 'Questa persona non può ricevere segnalazioni.' });
        }) as never);
        const w = monta(null);
        expect(w.find('[data-errore="assegnazione"]').exists()).toBe(false);

        tendina(w)!.vm.$emit('update:modelValue', 4);
        await flushPromises();
        expect(w.find('[data-errore="assegnazione"]').text()).toContain('Questa persona non può ricevere segnalazioni.');

        tendina(w)!.vm.$emit('update:modelValue', 9);
        await flushPromises();
        expect(w.find('[data-errore="assegnazione"]').exists()).toBe(false);
    });

    test('il valore mostrato segue la prop, non l\'ultima scelta', async () => {
        const w = monta(null);
        tendina(w)!.vm.$emit('update:modelValue', 4);
        await flushPromises();
        expect(tendina(w)!.props('modelValue')).toBeNull();

        await w.setProps({ assegnataA: { id: 4, name: 'Anna Collaboratrice', attivo: true, da: 'Studio Bianchi', il: '12/11/2026', alle: '11:00' } } as never);
        expect(tendina(w)!.props('modelValue')).toBe(4);
    });
});

describe('AssegnazioneSegnalazione — chi non può assegnare', () => {
    test('legge il nome, senza tendina e senza pulsante', () => {
        const w = monta(elena, { puo_assegnare: false, assegnabili: [] });

        expect(w.find('[data-campo="assegnata-a"]').text()).toContain('Elena Studio');
        expect(tendina(w)).toBeUndefined();
        expect(pulsante(w).exists()).toBe(false);
    });

    test('su una segnalazione di nessuno legge «Nessuno»', () => {
        const w = monta(null, { puo_assegnare: false, assegnabili: [] });

        expect(w.find('[data-campo="assegnata-a"]').text()).toContain('segnalazioni.assignment.none');
    });
});

describe('AssegnazioneSegnalazione — da chi, quando, e chi non è più attivo', () => {
    test('dice da chi e quando è stata assegnata', () => {
        const riga = monta(elena).find('[data-campo="assegnata-da"]');

        expect(riga.exists()).toBe(true);
        expect(riga.text()).toContain('segnalazioni.assignment.assigned_by');
        expect(riga.text()).toContain('Studio Bianchi');
        expect(riga.text()).toContain('10/11/2026');
        expect(riga.text()).toContain('09:30');
    });

    test('senza chi ha assegnato dice solo quando; senza nessuno non dice niente', () => {
        const riga = monta({ ...elena, da: null }).find('[data-campo="assegnata-da"]');
        expect(riga.text()).toContain('segnalazioni.assignment.assigned_on');
        expect(riga.text()).not.toContain('assigned_by');

        expect(monta(null).find('[data-campo="assegnata-da"]').exists()).toBe(false);
    });

    test('se chi la segue non ha più il pannello, lo dice a tutti e due', () => {
        const ida = { id: 12, name: 'Ida Sospesa', attivo: false, da: 'Studio Bianchi', il: '10/11/2026', alle: '09:30' };

        for (const puo of [true, false]) {
            const avviso = monta(ida, { puo_assegnare: puo }).find('[data-avviso="assegnatario-non-attivo"]');
            expect(avviso.exists()).toBe(true);
            expect(avviso.text()).toContain('segnalazioni.assignment.inactive');
            expect(avviso.text()).toContain('Ida Sospesa');
        }
        expect(monta(elena).find('[data-avviso="assegnatario-non-attivo"]').exists()).toBe(false);
    });

    test('chi la segue e non è più attivo resta leggibile nella tendina', () => {
        const ida = { id: 12, name: 'Ida Sospesa', attivo: false, da: null, il: '10/11/2026', alle: '09:30' };
        const w = monta(ida);

        expect(tendina(w)!.props('modelValue')).toBe(12);
        expect(tendina(w)!.props('options')).toEqual(expect.arrayContaining([...assegnabili, expect.objectContaining({ id: 12, name: 'Ida Sospesa' })]));
    });
});
