// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Mostra di nuovo» nella scheda (decisione 456): sul commento nascosto c'è il pulsante, solo se `azioni.mostra`, senza
 * finestra di conferma; chi non può vede il segno «Nascosto» come prima.
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

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) => `/${nome}/${JSON.stringify(parametri ?? null)}`;

const Commento = (await import('./Commento.vue')).default;

function monta(stato: string, azioni: Record<string, boolean>, canModerate = true) {
    return mount(Commento, {
        props: {
            commento: {
                id: 42,
                corpo: 'Un commento',
                stato,
                autore: { nome: 'Paolo Neri' },
                created_at: '2026-11-10T09:00:00.000Z',
                azioni: { modifica: false, elimina: false, approva: false, nascondi: false, mostra: false, ...azioni },
            },
            canModerate,
            currentUserId: 1,
            porta: 'admin',
        } as never,
    });
}

describe('Commento — «Mostra di nuovo» (decisione 456)', () => {
    beforeEach(() => vi.clearAllMocks());

    // Decisione 457: chi può rimetterlo visibile legge il commento (nome, data, testo, in grigio) con «Nascosto» accanto al pulsante,
    // come vede un commento in attesa; la riga «rimosso da un moderatore» resta per chi non può.
    test('sul nascosto, con azioni.mostra, c\'è il pulsante con il segno «Nascosto», e si leggono nome e testo', () => {
        const w = monta('nascosto', { mostra: true });

        // Decisione 462: «Mostra di nuovo» è un'icona, con il nome nel suggerimento e per chi non vede.
        const pulsante = w.get('[data-azione="mostra-di-nuovo"]');
        expect(pulsante.attributes('aria-label')).toBe('commenti.mostra_di_nuovo');
        expect(w.text()).toContain('commenti.stato_nascosto');
        expect(w.text()).toContain('Paolo Neri');
        expect(w.text()).toContain('Un commento');
        expect(w.text()).not.toContain('commenti.rimosso_da_moderatore');
    });

    test('senza azioni.mostra il testo del nascosto non si legge: resta la riga «rimosso»', () => {
        const w = monta('nascosto', { mostra: false });

        expect(w.text()).not.toContain('Un commento');
        expect(w.text()).toContain('commenti.rimosso_da_moderatore');
    });

    test('senza azioni.mostra il pulsante non c\'è e resta il segno «Nascosto»', () => {
        const w = monta('nascosto', { mostra: false });

        expect(w.find('[data-azione="mostra-di-nuovo"]').exists()).toBe(false);
        expect(w.text()).toContain('commenti.stato_nascosto');
    });

    test('un commento pubblicato non ha il pulsante, nemmeno con azioni.mostra', () => {
        expect(monta('pubblicato', { mostra: true }).find('[data-azione="mostra-di-nuovo"]').exists()).toBe(false);
    });

    test('il clic manda subito la richiesta alla rotta della scheda, senza finestra di conferma', async () => {
        const w = monta('nascosto', { mostra: true });

        await w.get('[data-azione="mostra-di-nuovo"]').trigger('click');

        expect(vi.mocked(router.post)).toHaveBeenCalledWith('/admin.commenti.mostra/{"commento":42}', {}, expect.objectContaining({ preserveScroll: true }));
        expect(w.find('[role="dialog"]').exists()).toBe(false);
    });
});
