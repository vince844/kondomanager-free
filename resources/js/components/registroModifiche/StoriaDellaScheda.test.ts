// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La «Storia» di una scheda montata per intero (1.12.0-beta.14, 750 C5): il pulsante dell'intestazione apre il cassetto, il cassetto legge la storia, e
 * «Oscura i suoi dati nel registro» apre la finestra dell'oscuramento, che carica da sé l'anteprima. Le tre parti hanno le loro prove (`StoriaCassetto.test.ts`,
 * `FinestraOscuramento.test.ts`); qui c'è il collegamento fra loro, che mancava: il difetto segnalato («il pulsante non apre la finestra») non si riproduce, e
 * questa prova lo tiene fermo.
 *
 * Che cosa non copre: le regole del server (`OscuramentoTest.php`) e la prova a video.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const rete = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: rete }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, hasPermission: () => true, hasRole: () => false }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()), router: { visit: vi.fn(), reload: vi.fn(), get: vi.fn() } }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}?${new URLSearchParams(Object.entries(p ?? {}).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)])).toString()}`;

const Storia = (await import('./StoriaDellaScheda.vue')).default;

const soggetto = { soggetto_tipo: 'anagrafica', soggetto_id: 57, nome: 'Carlo Fontana' };
const STORIA = { titolo: 'Storia di Carlo Fontana', sottotitolo: 'Anagrafica · 1 modifica', totale: 0, pagina: 1, altre: 0, righe: [], oscuramento: soggetto };
const ANTEPRIMA = {
    soggetto: { tipo: 'anagrafica', id: 57, nome: 'Carlo Fontana', parola: 'Anagrafica', condomini: 'Via Roma 12', descrizione: 'Ex condòmino' },
    righe: { quante: 3, dal: '01/11/2026', al: '15/01/2027', elenco: [] }, altre: { quante: 0, elenco: [] }, oggi: '12/03/2027', sostituzione: 'oscurato il 12/03/2027',
};

let w: VueWrapper | null = null;
beforeEach(() => {
    rete.get.mockReset();
    rete.post.mockReset();
    rete.get.mockImplementation(async (indirizzo: string) => ({ data: indirizzo.includes('oscuramento') ? ANTEPRIMA : STORIA }));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('la «Storia» di una persona, dal pulsante alla finestra', () => {
    test('«Storia» apre il cassetto, «Oscura i suoi dati nel registro» apre la finestra e questa carica l\'anteprima della persona', async () => {
        w = mount(Storia, { attachTo: document.body, props: { tipo: 'anagrafica', id: 57 } });
        document.body.querySelector<HTMLButtonElement>('[data-testid="pulsante-storia"]')!.click();
        await flushPromises();
        expect(rete.get.mock.calls[0][0]).toBe('/registro-modifiche.storia?tipo=anagrafica&id=57');
        expect(document.body.querySelector('[data-testid="finestra-oscuramento"]')).toBeNull();

        const pulsante = document.body.querySelector<HTMLButtonElement>('button[data-testid="oscura"]')!;
        expect(pulsante.textContent!.replace(/\s+/g, ' ').trim()).toBe('Oscura i suoi dati nel registro');
        pulsante.click();
        await flushPromises();

        // Due chiamate: la storia, poi l'anteprima dell'oscuramento della stessa persona.
        expect(rete.get).toHaveBeenCalledTimes(2);
        expect(rete.get.mock.calls[1][0]).toBe('/registro-modifiche.oscuramento.anteprima?soggetto_tipo=anagrafica&soggetto_id=57');
        const testo = document.body.textContent!.replace(/\s+/g, ' ');
        expect(testo).toContain('Oscura i dati di Carlo Fontana nel registro');
        expect(document.body.querySelector('[data-testid="quante"]')!.textContent).toContain('3 righe con i suoi dati');
    });
});
