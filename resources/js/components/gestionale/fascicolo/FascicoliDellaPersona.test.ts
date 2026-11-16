// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il fascicolo per il legale nella scheda «Corrispondenza» dell'estratto conto: il pulsante che apre la finestra per questa persona, e i fascicoli già preparati
 * con l'indice e il prospetto di quel giorno, che si riaprono dalla riga della persona.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Fascicoli = (await import('./FascicoliDellaPersona.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('il fascicolo nell\'estratto conto', () => {
    test('il pulsante apre la finestra per la persona; i fascicoli preparati con l\'indice e il prospetto', async () => {
        w = mount(Fascicoli, {
            attachTo: document.body,
            props: { condominioId: 3, persona: { id: 7, nome: 'Rossi' }, fascicoli: [
                { quando: '21/05/2026 10:00', utente: 'Vincenzo', cifra: 50000, mancanze: 2,
                    documenti: [{ id: 41, nome: 'fascicolo-legale-indice-rossi-2026-05-21.pdf', etichetta: 'Indice' }, { id: 42, nome: 'x.pdf', etichetta: 'Rate non versate' }] },
            ] },
            global: { stubs: { FascicoloLegaleDialog: true } },
        });
        await flushPromises();

        expect(testo()).toContain('Fascicolo per il legale');
        expect(testo()).toContain('Preparato il 21/05/2026 10:00 da Vincenzo · € 500,00 · 2 mancanze');
        const collegamenti = Array.from(document.body.querySelectorAll('[data-testid="fascicoli-preparati"] a')) as HTMLAnchorElement[];
        expect(collegamenti.map((a) => a.textContent?.trim())).toEqual(['Indice', 'Rate non versate']);
        expect(collegamenti[0].getAttribute('href')).toContain('anagrafiche.fascicolo-legale.documento');
        expect(collegamenti[0].getAttribute('href')).toContain('documento=41');

        const finestra = w.findComponent({ name: 'FascicoloLegaleDialog' });
        expect(finestra.props('open')).toBe(false);
        (document.body.querySelector('[data-testid="prepara-fascicolo"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(finestra.props('open')).toBe(true);
        expect(finestra.props('persona')).toEqual({ id: 7, nome: 'Rossi' });
    });

    test('senza fascicoli preparati, solo il pulsante', async () => {
        w = mount(Fascicoli, { attachTo: document.body, props: { condominioId: 3, persona: { id: 7, nome: 'Rossi' }, fascicoli: [] }, global: { stubs: { FascicoloLegaleDialog: true } } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="fascicoli-preparati"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="prepara-fascicolo"]')).not.toBeNull();
    });
});
