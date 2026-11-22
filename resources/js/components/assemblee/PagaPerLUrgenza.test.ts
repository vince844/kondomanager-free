// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra «Paga per l'urgenza»: la motivazione della spesa oltre il preventivo si scrive una volta sola, alla registrazione della fattura. La finestra
 * parte già scritta con quella, e la frase sotto il campo dice da dove viene; si cambia solo se serve. Senza una motivazione di prima parte vuota. Il motivo
 * lo leggono anche i condòmini, sotto il punto nell'avviso di convocazione.
 *
 * Che cosa non copre: lo sblocco dal server (`RatificaDallaPaginaTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (originale) => ({ ...(await originale<typeof import('@inertiajs/vue3')>()), router: { post: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./PagaPerLUrgenza.vue')).default;
const fattura = (motivazione: string | null) => ({ id: 45, numero_documento: '45', data_documento: '2026-11-12', fornitore_nome: 'Idraulica Bianchi',
    totale_documento: 180000, stato_approvazione: 'sforo_motivato', sforo_oltre: 30000, voce_sforo: 'Manutenzione', motivazione_sforo: motivazione });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const campo = () => document.body.querySelector('[data-testid="motivo-urgenza"]') as HTMLTextAreaElement;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('la finestra «Paga per l\'urgenza»', () => {
    test('parte già scritta con la motivazione della registrazione, e lo dice', async () => {
        w = mount(Finestra, { attachTo: document.body, props: { open: false, condominioId: 3, condominioNome: 'Via Roma 12',
            fattura: fattura('Rottura della colonna montante: lavori non rinviabili') } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(campo().value).toBe('Rottura della colonna montante: lavori non rinviabili');
        expect(testo()).toContain('È la motivazione scritta quando hai registrato la fattura: cambiala solo se serve.');
        expect(testo()).toContain('lo leggono anche i condòmini, nell\'avviso di convocazione.');
    });

    test('senza una motivazione di prima parte vuota, senza la frase della ripresa', async () => {
        w = mount(Finestra, { attachTo: document.body, props: { open: false, condominioId: 3, condominioNome: 'Via Roma 12', fattura: fattura(null) } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(campo().value).toBe('');
        expect(testo()).not.toContain('È la motivazione scritta quando hai registrato la fattura');
    });
});
