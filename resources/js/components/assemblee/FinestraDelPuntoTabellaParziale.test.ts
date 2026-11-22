// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'avviso della tabella parziale nella finestra del punto (decisione 640 (2)): quando la tabella scelta per il punto non comprende tutte le unità del
 * perimetro, una riga arancio dice quante ne ha e che le altre contano come teste a zero millesimi. In Via Roma 12 il punto «Rifacimento della pavimentazione
 * dei box» con la tabella «Box», 8 unità su 26: «La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.». Solo l'avviso: il conto
 * della sala non cambia.
 *
 * - Nessun avviso con la tabella che comprende tutte le unità, né con quella dell'assemblea.
 * - Con «Solo la scala B» il perimetro è la scala: l'avviso conta le unità della scala, perché in sala contano solo i suoi condòmini.
 * - Un'unità sola fuori: «l'altra conta come testa a zero millesimi».
 *
 * Che cosa non copre: le unità e le quote, che manda il server (`TabellaParzialeDelPuntoTest`); i menu `v-select` aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { avvisoDellaTabellaParziale } from '@/lib/assemblee/pagine';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const controllo = (conQuota: number, unita: number, millesimi: string) => ({ frase: `${conQuota} unità su ${unita} · ${millesimi} millesimi`,
    copre: conQuota === unita, esatta: conQuota === unita, con_quota: conQuota, unita });
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }], maggioranze: [], esiti: [], a_chi: [], unita: 26,
    palazzine: [], scale: [{ id: 2, nome: 'B', palazzina_id: null, unita: 10 }],
    tabelle: [
        { id: 5, nome: 'Box', unita: 8, controlli: { condominio: controllo(8, 26, '1.000,00'), 'scala:2': controllo(3, 10, '375,00') } },
        { id: 6, nome: 'Proprietà', unita: 26, controlli: { condominio: controllo(26, 26, '1.000,00'), 'scala:2': controllo(10, 26, '412,00') } },
        { id: 7, nome: 'Scala B', unita: 10, controlli: { condominio: controllo(10, 26, '1.000,00'), 'scala:2': controllo(10, 10, '1.000,00') } },
    ],
    maggioranze_proposte: {} };
const punto = (extra: Record<string, unknown> = {}) => ({
    id: 9, numero: 5, testo: 'Rifacimento della pavimentazione dei box', testo_pubblico: null, materia: 'lavori', materia_parola: 'Lavori',
    maggioranza: 'meta_del_valore', maggioranza_parola: null, maggioranza_libera: null, avviso: null, amministrazione: 'straordinaria', conduttore: null,
    conduttore_parola: null, ambito: 'condominio', palazzina_id: null, scala_id: null, perimetro_parola: null, tabella_id: 5, tabella_nome: 'Box',
    tabella_controllo: null, esercizio_id: null, importo: null, dati: null, fonte: 'a_mano', chiave_fonte: null, da_memo: false, delibere: [],
    ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
async function avviso(p: Record<string, unknown>): Promise<string | null> {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea ordinaria del 15/12/2026', tabellaDellAssemblea: 'Proprietà', punto: p, scelte } as never });
    await flushPromises();
    const riga = document.body.querySelector('[data-testid="avviso-tabella-parziale"]');

    return riga === null ? null : (riga.textContent ?? '').replace(/\s+/g, ' ').trim();
}

describe('l\'avviso della tabella parziale nella finestra del punto', () => {
    test('la tabella «Box» con 8 unità su 26: le altre 18 contano come teste a zero millesimi', async () => {
        expect(await avviso(punto())).toBe('La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.');
        // La riga arancio, con il segno dell'avviso, come gli altri avvisi della finestra.
        expect(document.body.querySelector('[data-testid="avviso-tabella-parziale"]')!.className).toContain('bg-amber-50');
    });

    test('nessun avviso con la tabella che comprende tutte le unità, né con la tabella dell\'assemblea', async () => {
        expect(await avviso(punto({ tabella_id: 6, tabella_nome: 'Proprietà' }))).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        expect(await avviso(punto({ tabella_id: null, tabella_nome: null }))).toBeNull();
    });

    test('con «Solo la scala B» conta le unità della scala: con la sua tabella niente avviso, con «Box» 3 su 10', async () => {
        expect(await avviso(punto({ ambito: 'scala', scala_id: 2, tabella_id: 7, tabella_nome: 'Scala B' }))).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        expect(await avviso(punto({ ambito: 'scala', scala_id: 2 }))).toBe('La tabella ha 3 unità su 10: le altre 7 contano come teste a zero millesimi.');
    });

    test('la frase: un\'unità sola fuori è «l\'altra»; niente avviso senza controllo o con tutte le unità', () => {
        expect(avvisoDellaTabellaParziale({ con_quota: 25, unita: 26 })).toBe('La tabella ha 25 unità su 26: l\'altra conta come testa a zero millesimi.');
        expect(avvisoDellaTabellaParziale({ con_quota: 0, unita: 4 })).toBe('La tabella ha 0 unità su 4: le altre 4 contano come teste a zero millesimi.');
        expect(avvisoDellaTabellaParziale({ con_quota: 26, unita: 26 })).toBeNull();
        expect(avvisoDellaTabellaParziale(null)).toBeNull();
    });
});
