// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu «Azioni» nell'intestazione della dashboard del condominio (decisione 574, scena 2 della bozza): un pulsante solo, alto `h-8` come «Guida», con
 * l'icona del fulmine; dentro oggi «Da portare in assemblea», che apre la finestra del memo fuori dal menu (il menu non è modale, così la finestra prende il
 * fuoco). Lo vede chi gestisce le assemblee; senza, il menu non c'è. La dashboard lo usa al posto del pulsante di prima, e nelle altre pagine il pulsante
 * resta dov'è.
 *
 * Che cosa non copre: il telefono (l'intestazione è in «Opzioni», com'era).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const permessi = { gestisce: true };
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => permessi.gestisce }) }));
vi.mock('@/components/ui/toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Azioni = (await import('./AzioniDellaDashboard.vue')).default;
const Finta = { props: ['open', 'condominioId'], template: '<div data-testid="finestra-stub" :data-aperta="open" />' };
// Il menu montato aperto: le voci come le legge chi guarda, e il clic che le sceglie.
const contenitore = { template: '<div><slot /></div>' };
const voce = { emits: ['select'], template: '<div role="menuitem" @click="$emit(\'select\', $event)"><slot /></div>' };
const MENU = { DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: contenitore, DropdownMenuItem: voce };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; permessi.gestisce = true; document.body.innerHTML = ''; });

describe('il menu «Azioni» della dashboard', () => {
    test('un pulsante «Azioni» alto come «Guida»; dentro «Da portare in assemblea», che apre la finestra del memo fuori dal menu', async () => {
        w = mount(Azioni, { attachTo: document.body, props: { condominioId: 3 }, global: { stubs: { FinestraDelMemo: Finta, ...MENU } } });
        const pulsante = w.get('[data-testid="menu-azioni"]');
        expect(pulsante.text()).toContain('Azioni');
        expect(pulsante.classes()).toContain('h-8');
        expect(w.get('[data-testid="finestra-stub"]').attributes('data-aperta')).toBe('false');

        const voci = [...document.body.querySelectorAll('[role="menuitem"]')] as HTMLElement[];
        expect(voci.map((v) => (v.textContent ?? '').trim())).toEqual(['Da portare in assemblea']);
        voci[0].click();
        await flushPromises();
        expect(w.get('[data-testid="finestra-stub"]').attributes('data-aperta')).toBe('true');
    });

    test('senza «Gestisce le assemblee» il menu non c\'è; la dashboard lo usa al posto del pulsante di prima, non modale', () => {
        permessi.gestisce = false;
        w = mount(Azioni, { props: { condominioId: 3 }, global: { stubs: { FinestraDelMemo: Finta } } });
        expect(w.find('[data-testid="menu-azioni"]').exists()).toBe(false);
        const componente = readFileSync(join(__dirname, 'AzioniDellaDashboard.vue'), 'utf8');
        expect(componente).toContain('<DropdownMenu :modal="false">');
        expect(componente).toContain('<Zap');
        const dashboard = readFileSync(join(__dirname, '../../pages/gestionale/dashboard/Dashboard.vue'), 'utf8');
        expect(dashboard).toContain('<AzioniDellaDashboard :condominio-id="condominio.id"');
        expect(dashboard).not.toContain('<PulsanteDaPortareInAssemblea');
    });
});
