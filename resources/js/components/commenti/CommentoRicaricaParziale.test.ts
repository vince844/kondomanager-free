// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Standard 16, la potenza di Inertia (Vincenzo, 17/11/2026 sera: «quando eseguo delle azioni sui commenti si ricarica la pagina»): nelle
 * schede della segnalazione e della comunicazione un'azione su un commento chiede indietro solo ciò che cambia (i commenti, il messaggio,
 * il numero dell'Inbox) e tiene lo stato della pagina; senza `ricarica` il componente fa la visita di prima.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), delete: vi.fn() },
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => `admin.${n}`, hasPermission: () => true, hasRole: () => true, getRolePrefix: () => 'admin' }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) => `/${nome}/${JSON.stringify(parametri ?? null)}`;

const Commento = (await import('./Commento.vue')).default;
const DiscussioniComunicazione = (await import('./DiscussioniComunicazione.vue')).default;
const ListaCommenti = (await import('./ListaCommenti.vue')).default;
const FormCommento = (await import('./FormCommento.vue')).default;

const RICARICA = ['discussioni', 'flash', 'inbox_count'];

function commento(stato: string, azioni: Record<string, boolean>) {
    return { id: 42, corpo: 'Un commento', stato, autore: { nome: 'Paolo Neri' }, created_at: '2026-11-10T09:00:00.000Z', azioni: { modifica: false, elimina: false, approva: false, nascondi: false, mostra: false, ...azioni } };
}

describe('Commento — le azioni con una visita parziale', () => {
    beforeEach(() => vi.clearAllMocks());

    test('con `ricarica`, «Approva» chiede indietro solo quelle proprietà e tiene lo stato della pagina', async () => {
        const w = mount(Commento, { props: { commento: commento('in_attesa', { approva: true }), canModerate: true, porta: 'admin', ricarica: RICARICA } as never });

        await w.get('[data-azione="approva"]').trigger('click');

        expect(vi.mocked(router.post)).toHaveBeenCalledWith(expect.any(String), {}, expect.objectContaining({ preserveScroll: true, preserveState: true, only: RICARICA }));
    });

    // Vincenzo, 17/11/2026 notte: «in alto nella pagina io però vedo la barra di caricamento». Con `ricarica` la barra di Inertia non
    // compare: il caricamento si vede sul commento stesso, attenuato finché la risposta non arriva (standard 16).
    test('con `ricarica` niente barra in cima alla pagina: il commento si attenua finché la risposta non arriva', async () => {
        const w = mount(Commento, { props: { commento: commento('in_attesa', { approva: true }), canModerate: true, porta: 'admin', ricarica: RICARICA } as never });

        await w.get('[data-azione="approva"]').trigger('click');
        const opzioni = vi.mocked(router.post).mock.calls[0][2] as { showProgress?: boolean; onStart?: () => void; onFinish?: () => void };
        expect(opzioni.showProgress).toBe(false);

        opzioni.onStart?.();
        await w.vm.$nextTick();
        expect(w.get('[data-commento="42"]').attributes('aria-busy')).toBe('true');
        expect(w.get('[data-commento="42"]').classes()).toContain('opacity-60');

        opzioni.onFinish?.();
        await w.vm.$nextTick();
        expect(w.get('[data-commento="42"]').attributes('aria-busy')).toBe('false');
        expect(w.get('[data-commento="42"]').classes()).not.toContain('opacity-60');
    });

    test('con `ricarica`, «Mostra di nuovo» fa lo stesso', async () => {
        const w = mount(Commento, { props: { commento: commento('nascosto', { mostra: true }), canModerate: true, porta: 'admin', ricarica: RICARICA } as never });

        await w.get('[data-azione="mostra-di-nuovo"]').trigger('click');

        expect(vi.mocked(router.post)).toHaveBeenCalledWith(expect.any(String), {}, expect.objectContaining({ preserveState: true, only: RICARICA }));
    });

    test('senza `ricarica` la visita resta quella di prima', async () => {
        const w = mount(Commento, { props: { commento: commento('in_attesa', { approva: true }), canModerate: true, porta: 'admin' } as never });

        await w.get('[data-azione="approva"]').trigger('click');

        const opzioni = vi.mocked(router.post).mock.calls[0][2] as Record<string, unknown>;
        expect(opzioni.preserveScroll).toBe(true);
        expect(opzioni.only).toBeUndefined();
    });
});

describe('Le liste dei commenti passano `ricarica` ai commenti e al modulo', () => {
    test('DiscussioniComunicazione', () => {
        const w = mount(DiscussioniComunicazione, {
            props: {
                comunicazioneId: 7, porta: 'admin', ricarica: RICARICA,
                discussioni: [{ chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true, commenti: [commento('pubblicato', {})] }],
                config: { can_comment: true, commenti_moderati: false, puo_impostare: true, puo_moderare: true },
            } as never,
            global: { mocks: { route: (globalThis as unknown as { route: unknown }).route } },
        });

        expect(w.findComponent(Commento).props('ricarica')).toEqual(RICARICA);
        expect(w.findComponent(FormCommento).props('ricarica')).toEqual(RICARICA);
    });

    test('ListaCommenti', () => {
        const w = mount(ListaCommenti, {
            props: {
                segnalazioneId: 9, currentUserId: 1, ricarica: ['segnalazione', 'flash', 'inbox_count'],
                commenti: [commento('pubblicato', {})], commentiInAttesa: [],
                commentiConfig: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            } as never,
            global: { mocks: { route: (n: string) => `/${n}` } },
        });

        expect(w.findComponent(Commento).props('ricarica')).toEqual(['segnalazione', 'flash', 'inbox_count']);
        expect(w.findComponent(FormCommento).props('ricarica')).toEqual(['segnalazione', 'flash', 'inbox_count']);
    });
});
