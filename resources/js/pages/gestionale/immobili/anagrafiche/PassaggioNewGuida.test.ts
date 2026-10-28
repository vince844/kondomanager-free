// @vitest-environment jsdom

/**
 * Coda 383 — la guida «Registrare un passaggio» si apre sulla scheda del tipo di passaggio scelto (decisione 143): la pagina (`PassaggioNew.vue`)
 * le passa il tipo. Che la guida, ricevuto il tipo, apra la scheda giusta è in `PassaggioProprietaGuideSchede.test.ts`; qui si legge solo ciò
 * che la pagina le consegna.
 *
 * Si monta la pagina con `shallowMount` (i figli sono segnaposto) e si leggono i props della guida. L'anteprima del server non serve: `axios.post`
 * resta in attesa.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn(() => new Promise(() => {})) } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const PassaggioNew = (await import('./PassaggioNew.vue')).default;
const PassaggioProprietaGuide = (await import('@/components/guides/PassaggioProprietaGuide.vue')).default;
const PageHeaderGuide = (await import('@/components/PageHeaderGuide.vue')).default;

function monta(tipo: string) {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo: tipo as never,
            rigaPreselezionata: 11,
            titolari: [
                { id: 10, anagrafica: { id: 1, nome: 'Ugo', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null },
                { id: 11, anagrafica: { id: 2, nome: 'Ines', codice_fiscale: null }, tipologia: 'inquilino', quota: 100, data_inizio: '2019-01-01', data_fine: null },
            ],
            anagrafiche: [],
            pertinenze: [],
            rateEmesseCount: 6,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

describe('PassaggioNew — la guida (Coda 383, decisione 143)', () => {
    test.each(['vendita', 'inizio_locazione', 'fine_locazione', 'usufrutto', 'successione'])('Coda 383 — la pagina del tipo «%s» passa alla guida lo stesso tipo', (tipo) => {
        const w = monta(tipo);

        expect(w.findComponent(PassaggioProprietaGuide).props('tipo')).toBe(tipo);
    });

    test('Coda 383 — la guida parte chiusa e si apre dal pulsante «Guida» della pagina', async () => {
        const w = monta('fine_locazione');
        expect(w.findComponent(PassaggioProprietaGuide).props('open')).toBe(false);

        w.findComponent(PageHeaderGuide).vm.$emit('open-text-guide');
        await nextTick();

        expect(w.findComponent(PassaggioProprietaGuide).props('open')).toBe(true);
        expect(w.findComponent(PassaggioProprietaGuide).props('tipo')).toBe('fine_locazione');
    });
});
