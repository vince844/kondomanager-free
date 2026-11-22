// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La delega all'amministratore nella finestra del punto (decisione 622 (3), la scena 4 della bozza approvata): con la materia «Delega all'amministratore»
 * compaiono i tre campi («Fino a», «Per che cosa», «Fino al»), e il punto passa il tetto come importo e per che cosa e fino a quando nei dati; senza il
 * tetto la finestra dice che la delega non vale finché non si scrive. Con un'altra materia i tre campi non ci sono e niente della delega viaggia.
 *
 * Che cosa non copre: il server (`DelegaNelPuntoTest`); il menu «Per che cosa» aperto a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" :data-valore="modelValue" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'delega', nome: 'Delega all\'amministratore' }, { valore: 'altro', nome: 'Altro' }],
    maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }], esiti: [], a_chi: [], unita: 26, palazzine: [], scale: [], tabelle: [], maggioranze_proposte: {} };
const delega = { testo: 'Delega all\'amministratore: accordi con i fornitori fino a € 2.000,00, fino al 31/12/2027', testo_pubblico: null, materia: 'delega',
    maggioranza: null, conduttore: null, ambito: 'condominio', palazzina_id: null, scala_id: null, tabella_id: null, importo: '2.000,00',
    dati: { delega: { ambito: 'accordi_fornitori', fino_al: '2027-12-31' } } };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel);
async function monta(bozza: Record<string, unknown>) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaNome: 'Assemblea ordinaria di Via Roma 12',
        tabellaDellAssemblea: 'Proprietà', punto: null, scelte, nellaPagina: true, bozza } as never });
    await flushPromises();
}

describe('la delega nella finestra del punto', () => {
    test('con la materia della delega i tre campi; il punto passa il tetto come importo, e per che cosa e fino a quando nei dati', async () => {
        await monta(delega);
        const gruppo = q('[data-testid="la-delega"]')!;
        expect(gruppo.textContent).toContain('Fino a');
        expect(gruppo.textContent).toContain('Per che cosa');
        expect(gruppo.textContent).toContain('Fino al');
        expect(gruppo.textContent).toContain('In bacheca e nella convocazione affissa va la riga neutra: «Delega all\'amministratore per gli accordi con i fornitori.»');
        expect(q<HTMLInputElement>('[data-testid="delega-tetto"]')!.value).toBe('2.000,00');
        expect(q('[data-testid="delega-ambito"]')!.getAttribute('data-valore')).toBe('accordi_fornitori');
        expect((q('[data-testid="delega-fino-al"]')!.textContent ?? '').trim()).toContain('31/12/2027');
        expect(q('[data-testid="delega-senza-tetto"]')).toBeNull();
        // Le scritte del gruppo non vanno sotto i 12 pixel.
        expect(gruppo.innerHTML).not.toMatch(/text-\[(10|11)px\]/);

        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect(w!.emitted('salva')![0][0]).toMatchObject({ materia: 'delega', importo: '2.000,00', dati: { delega: { ambito: 'accordi_fornitori', fino_al: '2027-12-31' } } });
    });

    test('senza il tetto la finestra lo dice: la delega non vale finché non si scrive', async () => {
        await monta({ ...delega, importo: '' });
        expect(q('[data-testid="delega-senza-tetto"]')!.textContent).toContain('Senza il tetto la delega non vale finché non lo scrivi');
    });

    test('con un\'altra materia i tre campi non ci sono e la delega non viaggia', async () => {
        await monta({ ...delega, materia: 'altro', dati: undefined });
        expect(q('[data-testid="la-delega"]')).toBeNull();
        q<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        await flushPromises();
        expect((w!.emitted('salva')![0][0] as Record<string, unknown>).dati).toBeUndefined();
    });
});
