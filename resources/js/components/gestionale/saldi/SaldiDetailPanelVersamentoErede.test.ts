// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 529: le due righe della coppia del versamento di un erede sul debito ereditato (`e_versamento_erede`, il campo che il server manda, provato in
 * `IncassoDelDebitoEreditatoTest.php`) non hanno la matita né il cestino: un lucchetto con il perché. Una riga legata scritta a mano resta modificabile.
 *
 * Cosa resta scoperto: il server (il rifiuto della modifica e dell'eliminazione è nella prova PHP); il tema scuro e il telefono.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: false };
const riga = (id: number, persona: number, nome: string, centesimi: number, extra: Record<string, unknown> = {}) => ({
    id, saldo_iniziale: centesimi, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: persona,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: { id: persona, nome, cognome: '' }, subentro_id: null,
    passaggio_id: null, e_legata: false, passaggio_in_parole: null, ...extra,
});

async function monta(saldi: unknown[]): Promise<VueWrapper> {
    const w = mount(SaldiDetailPanel as never, {
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: [
                { id: 5, nome: 'Elena', cognome: '', pivot: { id: 100, tipologia: 'proprietario', data_fine: null } },
                { id: 1, nome: 'Ettore Fabbri', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: '2026-11-16' } },
            ], saldi, passaggi_legabili: [] },
            gestioni: [GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();

    return w;
}

describe('la coppia del versamento di un erede nel Wallet', () => {
    test('le due righe hanno il lucchetto con il perché, e né la matita né il cestino', async () => {
        const coppia = { passaggio_id: 3, e_legata: true, passaggio_in_parole: 'la successione del 17 novembre 2026', origine: 'automatico', e_versamento_erede: true };
        const w = await monta([riga(1, 5, 'Elena', -15000, coppia), riga(2, 1, 'Ettore Fabbri', 15000, coppia)]);
        const lucchetti = w.findAll('[data-testid="lucchetto-versamento-erede"]');
        expect(lucchetti).toHaveLength(2);
        expect(lucchetti[0].attributes('title')).toContain('si toglie stornando l\'incasso');
        expect(w.html()).not.toContain('lucide-pencil');
        expect(w.html()).not.toContain('lucide-trash');
    });

    test('una riga legata scritta a mano resta modificabile', async () => {
        const w = await monta([riga(1, 5, 'Elena', -15000, { passaggio_id: 3, e_legata: true, passaggio_in_parole: 'la successione del 17 novembre 2026' })]);
        expect(w.findAll('[data-testid="lucchetto-versamento-erede"]')).toHaveLength(0);
        expect(w.html()).toContain('lucide-pencil');
    });
});
