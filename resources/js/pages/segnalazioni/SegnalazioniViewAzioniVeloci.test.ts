// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le azioni veloci sulla scheda della segnalazione del pannello (Coda 671, decisioni 466 (2) e 487).
 *
 * - Per chi può modificare la segnalazione (`puo_modificare`, dal server) lo stato lavorazione e la priorità della card dei parametri sono pulsanti
 *   (`[data-parametro-veloce]`) con un nome per chi non vede («Cambia lo stato lavorazione: Aperta») che aprono un menu con i valori; per gli altri
 *   restano testo.
 * - Una voce scelta si salva subito con la rotta `segnalazioni.parametro`, un campo solo, con una visita parziale (`only`, `preserveState`,
 *   `preserveScroll`); il valore che c'è già non manda niente.
 * - «Chiusa» apre prima la finestra «Chiudere la segnalazione?»: si salva solo con la conferma.
 * - Un rifiuto del server si legge sotto il valore.
 * - «Assegnata a» sta nella stessa card, con l'etichetta della stessa misura delle altre (12 px).
 *
 * Il menu a tendina vero (reka-ui) apre il suo contenuto in un portale: qui è sostituito da contenitori semplici, e una voce si sceglie con
 * l'evento `select` che il menu vero manda al clic, al tocco e con Invio. Che cosa non copre: l'apertura vera del menu con la tastiera e al
 * tocco (si guarda a video), il salvataggio (lo prova il Pest `tests/Feature/Segnalazioni/AzioniVelociSchedaTest.php`).
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => `admin.${n}`, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? `/${JSON.stringify(p)}` : ''}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;

const passa = { template: '<div><slot /></div>' };
const voce = { emits: ['select'], template: '<button type="button" v-bind="$attrs" @click="$emit(\'select\', $event)"><slot /></button>', inheritAttrs: false };
const finestra = {
    props: ['modelValue', 'title', 'description', 'confirmText'],
    emits: ['confirm', 'update:modelValue'],
    template: '<div v-if="modelValue" data-finestra :data-titolo="title"><p>{{ description }}</p><button type="button" data-conferma @click="$emit(\'confirm\')">{{ confirmText }}</button></div>',
};

function monta(puoModificare: boolean, campi: Record<string, unknown> = {}) {
    return mount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 31, subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media', stato: 'aperta',
                is_private: false, is_published: true, is_approved: true, is_locked: false, can_comment: true, created_at: 'ieri',
                created_by: { user: { name: 'mrossi' }, anagrafica: { nome: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                anagrafiche: [], commenti: [], commentiInAttesa: [], assegnata_a: null,
                ...campi,
            },
            puo_modificare: puoModificare,
            commenti_config: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            assegnazione: { puo_assegnare: false, io: 1, assegnabili: [] },
        } as never,
        global: {
            mocks: { route: (globalThis as Record<string, unknown>).route },
            stubs: {
                AppLayout: passa,
                PageHeaderGuide: { template: '<div><slot name="actions" /></div>' },
                ListaCommenti: true, AllegatiRiquadro: true,
                DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa, DropdownMenuItem: voce,
                ConfirmDialog: finestra,
            },
        },
    });
}

const parametro = (w: VueWrapper, campo: string) => w.find(`[data-parametro-veloce="${campo}"]`);
const scegli = async (w: VueWrapper, valore: string) => {
    const voci = w.findAll(`[data-voce="${valore}"]`);
    expect(voci.length, `manca la voce «${valore}»`).toBeGreaterThan(0);
    await voci[0].trigger('click');
    await flushPromises();
};
const chiamate = () => vi.mocked(router.patch).mock.calls;

beforeEach(() => {
    vi.spyOn(router, 'patch').mockImplementation(() => undefined as never);
    vi.mocked(router.patch).mockClear();
});

describe('le azioni veloci della card dei parametri', () => {
    test('per chi può modificare lo stato e la priorità sono pulsanti con il nome per chi non vede, e il menu ha i valori', () => {
        const w = monta(true);

        expect(parametro(w, 'stato').element.tagName).toBe('BUTTON');
        expect(parametro(w, 'stato').attributes('aria-label')).toBe('segnalazioni.quick.change_status: segnalazioni.status.open');
        expect(parametro(w, 'priority').attributes('aria-label')).toBe('segnalazioni.quick.change_priority: segnalazioni.priority.medium');
        expect(w.findAll('[data-voce]').map((v) => v.attributes('data-voce'))).toEqual(['aperta', 'in lavorazione', 'chiusa', 'bassa', 'media', 'alta', 'urgente']);
        // Il valore di adesso è segnato.
        expect(w.get('[data-voce="aperta"]').attributes('aria-checked')).toBe('true');
        expect(w.get('[data-voce="chiusa"]').attributes('aria-checked')).toBe('false');
    });

    test('per chi non può modificare restano testo', () => {
        const w = monta(false);

        expect(parametro(w, 'stato').exists()).toBe(false);
        expect(parametro(w, 'priority').exists()).toBe(false);
        expect(w.get('[data-parametro="stato"]').text()).toContain('segnalazioni.status.open');
        expect(w.get('[data-parametro="priority"]').text()).toContain('segnalazioni.priority.medium');
    });

    test('«In lavorazione» e una priorità si salvano al clic, un campo solo, con una visita parziale', async () => {
        const w = monta(true);

        await scegli(w, 'in lavorazione');
        expect(chiamate()).toHaveLength(1);
        expect(String(chiamate()[0][0])).toContain('admin.segnalazioni.parametro');
        expect(chiamate()[0][1]).toEqual({ stato: 'in lavorazione' });
        expect(chiamate()[0][2]).toMatchObject({ only: ['segnalazione', 'puo_modificare', 'inbox_count'], preserveState: true, preserveScroll: true });

        await scegli(w, 'urgente');
        expect(chiamate()[1][1]).toEqual({ priority: 'urgente' });
    });

    test('il valore che c\'è già non manda niente', async () => {
        const w = monta(true);

        await scegli(w, 'aperta');
        await scegli(w, 'media');

        expect(chiamate()).toHaveLength(0);
    });

    test('«Chiusa» chiede prima la conferma, e si salva solo confermando', async () => {
        const w = monta(true);
        expect(w.find('[data-finestra]').exists()).toBe(false);

        await scegli(w, 'chiusa');

        expect(chiamate()).toHaveLength(0);
        const conferma = w.get('[data-finestra]');
        expect(conferma.attributes('data-titolo')).toBe('segnalazioni.quick.close_title');
        expect(conferma.text()).toContain('segnalazioni.quick.close_description');

        await conferma.get('[data-conferma]').trigger('click');
        expect(chiamate()).toHaveLength(1);
        expect(chiamate()[0][1]).toEqual({ stato: 'chiusa' });
    });

    test('un rifiuto del server si legge sotto il valore', async () => {
        vi.mocked(router.patch).mockImplementation(((_u: string, _d: unknown, opzioni: { onError?: (e: Record<string, string>) => void }) => {
            opzioni.onError?.({ priority: 'Il valore scelto non è valido.' });
        }) as never);
        const w = monta(true);

        await scegli(w, 'alta');

        expect(w.get('[data-errore="priority"]').text()).toBe('Il valore scelto non è valido.');
    });

    test('«Assegnata a» sta nella stessa card, con l\'etichetta della stessa misura delle altre', () => {
        const w = monta(true);
        const card = w.get('[data-card-parametri]');
        const etichetta = card.get('[data-campo="assegnata-a"] p');

        expect(etichetta.classes()).toContain('text-xs');
        expect(etichetta.classes()).not.toContain('text-[10px]');
    });
});
