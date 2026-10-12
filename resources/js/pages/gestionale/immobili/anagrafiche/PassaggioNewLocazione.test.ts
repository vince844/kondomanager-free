// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — il modulo della fine di una locazione (`PassaggioNew.vue`), le due cose nuove della pagina che il pannello non
 * vede da solo (decisione 77): il pannello sa che nessuno entra («Nessuno — l'unità resta sfitta») e cambia le parole delle due voci;
 * se cambia chi esce o chi entra, la scelta fatta riguardava un altro passaggio e si rifà (nessuna voce resta già scelta).
 *
 * Si monta la pagina con `shallowMount` (i figli sono segnaposto) e si leggono i props del pannello `AnteprimaPassaggio`. L'anteprima
 * del server non serve: `axios.post` resta in attesa.
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
const AnteprimaPassaggio = (await import('@/components/gestionale/immobili/AnteprimaPassaggio.vue')).default;

function monta() {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo: 'fine_locazione',
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

/** La casella «Nessuno — l'unità resta sfitta». */
function nessuno(w: ReturnType<typeof monta>) {
    const casella = w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Nessuno'));
    if (!casella) throw new Error('La casella «Nessuno» non c\'è.');

    return casella;
}

describe('PassaggioNew — la fine di una locazione (1.11.0-beta.50, decisione 77)', () => {
    test('il pannello sa che nessuno entra solo con la casella «Nessuno» spuntata', async () => {
        const w = monta();
        expect(w.findComponent(AnteprimaPassaggio).props('senzaSuccessore')).toBe(false);
        await nessuno(w).setValue(true);
        expect(w.findComponent(AnteprimaPassaggio).props('senzaSuccessore')).toBe(true);
    });

    test('cambiato chi entra (la casella «Nessuno»), la scelta sul conguaglio già fatta si rifà: nessuna voce resta scelta', async () => {
        const w = monta();
        w.findComponent(AnteprimaPassaggio).vm.$emit('update:sceltaConguaglio', 'scrivi');
        await nextTick();
        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBe('scrivi');
        await nessuno(w).setValue(true);
        await nextTick();
        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBeNull();
    });
});
