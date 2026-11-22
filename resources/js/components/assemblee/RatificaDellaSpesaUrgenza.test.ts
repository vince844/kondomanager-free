// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Sbloccata per l'urgenza» finché la fattura non è pagata, «Pagata per l'urgenza» dopo: la finestra «Ratifica» lo dice nella sua testata, con la stessa
 * regola della pagina della fattura (lo stato del pagamento, anche in parte). La forma della fattura delle finestre porta lo stato del pagamento.
 *
 * Che cosa non copre: la scelta della delibera (`ScegliLaDelibera`, con le sue prove); il server (`CateneDeiPassiTest`, catena 3).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./RatificaDellaSpesa.vue')).default;
const { fatturaDellaRatifica, parolaDellUrgenza } = await import('@/lib/assemblee/ratifica');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const riga = (stato_pagamento: string) => ({ id: 45, numero_documento: '45', data_documento: '2026-11-12', totale_documento: 180000, stato_approvazione: 'approvata',
    stato_pagamento, fornitore: { ragione_sociale: 'Idraulica Bianchi' } });

async function monta(stato: string) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', fattura: fatturaDellaRatifica(riga(stato)) },
        global: { stubs: { ScegliLaDelibera: true, PulsanteDaPortareInAssemblea: true } } });
    await flushPromises();
}

describe('«Sbloccata», poi «Pagata», per l\'urgenza', () => {
    test('la regola: pagata dice «Pagata per l\'urgenza», in parte «Pagata in parte per l\'urgenza» fino al saldo, altrimenti «Sbloccata per l\'urgenza»', () => {
        expect(fatturaDellaRatifica(riga('parziale')).stato_pagamento).toBe('parziale');
        expect(parolaDellUrgenza('aperta')).toBe('sbloccata per l\'urgenza');
        expect(parolaDellUrgenza('pagata')).toBe('pagata per l\'urgenza');
        expect(parolaDellUrgenza('parziale')).toBe('pagata in parte per l\'urgenza');
        expect(parolaDellUrgenza(null)).toBe('sbloccata per l\'urgenza');
    });

    test('la testata della finestra «Ratifica»: sbloccata finché non è pagata, pagata dopo', async () => {
        await monta('aperta');
        expect(document.body.textContent).toContain('sbloccata per l\'urgenza');
        w!.unmount();
        document.body.innerHTML = '';
        await monta('pagata');
        expect(document.body.textContent).toContain('pagata per l\'urgenza');
        expect(document.body.textContent).not.toContain('sbloccata per l\'urgenza');
    });
});
