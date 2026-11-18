// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il condominio di chi scrive, accanto al nome, nella discussione unica di una comunicazione a più condomìni (decisione 458 (b)).
 *
 * Il server lo manda solo allo studio e solo quando i condomìni sono più di uno (`condomini`, il primo è quello del commento,
 * gli altri seguono per nome): il componente lo mostra quando c'è. Un nome solo, e «+N» per gli altri, con tutti i condomìni
 * nel suggerimento al passaggio del mouse; il nome del condominio si tronca, la riga non va a capo per questo.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

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

// Il suggerimento vero si apre solo al passaggio del mouse, in un portale: qui le sue parti mostrano il contenuto sul posto.
const suggerimentoSulPosto = {
    TooltipProvider: { template: '<div><slot /></div>' },
    Tooltip: { template: '<div><slot /></div>' },
    TooltipTrigger: { template: '<div data-suggerimento-apre><slot /></div>' },
    TooltipContent: { template: '<div data-suggerimento><slot /></div>' },
};

function monta(stato: 'pubblicato' | 'in_attesa' | 'nascosto', condomini?: string[], azioni: Record<string, boolean> = {}) {
    return mount(Commento, {
        props: {
            commento: {
                id: 42,
                corpo: 'Il portone resta aperto?',
                stato,
                autore: { nome: 'Mario Rossi' },
                created_at: '2026-11-17T09:00:00.000Z',
                azioni: { modifica: false, elimina: false, approva: false, nascondi: false, mostra: false, ...azioni },
                ...(condomini !== undefined ? { condomini } : {}),
            },
            canModerate: true,
            currentUserId: 1,
            porta: 'admin',
        } as never,
        global: { stubs: suggerimentoSulPosto },
    });
}

describe('Commento — il condominio di chi scrive (decisione 458 (b))', () => {
    test('con tre condomìni: il nome, il primo condominio e «+2», e tutti e tre nel suggerimento', () => {
        const w = monta('pubblicato', ['Via Roma', 'Piazza Dante', 'Via Garibaldi']);

        expect(w.get('[data-condominio-autore]').text()).toMatch(/^·\s*Via Roma/);
        expect(w.get('[data-condominio-nome]').text()).toBe('Via Roma');
        expect(w.get('[data-condominio-altri]').text()).toBe('+2');

        const suggerimento = w.get('[data-suggerimento]');
        expect(suggerimento.findAll('p').map((p) => p.text())).toEqual(['Via Roma', 'Piazza Dante', 'Via Garibaldi']);
        // Il suggerimento si apre dal condominio, non da un'altra parte della riga.
        expect(w.get('[data-suggerimento-apre]').find('[data-condominio-nome]').exists()).toBe(true);

        // Accanto al nome dell'autore, nella stessa riga che non va a capo per questo: il nome del condominio si tronca, «+2» no.
        const intestazione = w.get('[data-autore-commento]');
        expect(intestazione.text()).toContain('Mario Rossi');
        expect(intestazione.find('[data-condominio-autore]').exists()).toBe(true);
        expect(intestazione.classes()).toEqual(expect.arrayContaining(['flex', 'min-w-0']));
        expect(intestazione.classes()).not.toContain('flex-wrap');
        expect(w.get('[data-condominio-nome]').classes()).toContain('truncate');
        expect(w.get('[data-condominio-altri]').classes()).toContain('shrink-0');
    });

    test('con un condominio solo il nome senza «+N»; senza condomìni, o senza il campo (il portale), niente accanto al nome', () => {
        const w = monta('pubblicato', ['Via Roma']);

        expect(w.get('[data-condominio-nome]').text()).toBe('Via Roma');
        expect(w.find('[data-condominio-altri]').exists()).toBe(false);

        expect(monta('pubblicato', []).find('[data-condominio-autore]').exists()).toBe(false);
        expect(monta('pubblicato').find('[data-condominio-autore]').exists()).toBe(false);
        expect(monta('pubblicato').text()).toContain('Mario Rossi');
    });

    test('anche sul commento in attesa e sul nascosto che chi modera legge', () => {
        expect(monta('in_attesa', ['Via Roma', 'Via Garibaldi']).get('[data-condominio-altri]').text()).toBe('+1');
        expect(monta('in_attesa', ['Via Roma', 'Via Garibaldi'], { approva: true }).get('[data-condominio-altri]').text()).toBe('+1');
        expect(monta('nascosto', ['Via Roma', 'Via Garibaldi'], { mostra: true }).get('[data-condominio-altri]').text()).toBe('+1');
    });
});
