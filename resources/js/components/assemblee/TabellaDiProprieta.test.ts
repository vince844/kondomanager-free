// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tabella del valore nella pagina dell'assemblea (§3 P12, R14) per l'assemblea dei condòmini di un gruppo (Coda 734, scena 7 della bozza approvata): la
 * tabella è il gruppo, ferma come in «Prepara l'assemblea». La riga dice «la tabella del gruppo», e «Cambia» non c'è (il server rifiuta il cambio: il gruppo e
 * la tabella decidono chi è convocato). Per l'assemblea di tutto il condominio «Cambia» resta.
 *
 * Che cosa non copre: il rifiuto del server (`AssembleaDiUnGruppoTest`, caso 1).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn(), patch: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const TabellaDiProprieta = (await import('./TabellaDiProprieta.vue')).default;

const scelte = { tabelle: [{ id: 6, nome: 'Proprietà', unita: 27, controlli: {} }, { id: 7, nome: 'Box', unita: 8, di_un_gruppo: true, controlli: {} }],
    palazzine: [], scale: [], perimetri: [] };
const dellAssemblea = (piu: Record<string, unknown>) => ({ id: 9, ambito: 'condominio', palazzina_id: null, scala_id: null, perimetro: 'Tutto il condominio',
    perimetro_tipo: 'condominio', perimetro_id: 3, in_preparazione: true, gruppo: null,
    tabella: { id: 6, nome: 'Proprietà', controllo: { frase: '27 unità su 27 · 1.000,00 millesimi', avvisi: [] } }, tabella_di_proprieta: { id: 6, nome: 'Proprietà' },
    ...piu });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const monta = async (assemblea: Record<string, unknown>) => {
    w = mount(TabellaDiProprieta, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', assemblea: assemblea as never,
        scelte: scelte as never, gestisce: true } });
    await flushPromises();
};
const riga = () => (document.body.querySelector('[data-testid="controllo-tabella"]')?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('la tabella del valore dell\'assemblea', () => {
    test('l\'assemblea di tutto il condominio: la riga e «Cambia»', async () => {
        await monta(dellAssemblea({}));
        expect(riga()).toBe('Tabella «Proprietà» · 27 unità su 27 · 1.000,00 millesimi');
        expect(document.body.querySelector('[data-testid="cambia-tabella"]')).not.toBeNull();
    });

    test('l\'assemblea dei condòmini della tabella «Box»: la tabella del gruppo, ferma, senza «Cambia»', async () => {
        await monta(dellAssemblea({ ambito: 'gruppo', perimetro: 'Solo i condòmini della tabella «Box»', perimetro_tipo: 'gruppo', perimetro_id: 7,
            tabella: { id: 7, nome: 'Box', controllo: { frase: '8 unità su 8 · 1.000,00 millesimi', avvisi: [] } }, tabella_di_proprieta: { id: 7, nome: 'Box' },
            gruppo: { tabella_id: 7, nome: 'Box', unita: 8 } }));
        expect(riga()).toBe('Tabella «Box» · la tabella del gruppo · 8 unità su 8 · 1.000,00 millesimi');
        expect(document.body.querySelector('[data-testid="cambia-tabella"]')).toBeNull();
        expect(document.body.textContent).not.toContain('di proprietà generale di i condòmini');
    });
});
