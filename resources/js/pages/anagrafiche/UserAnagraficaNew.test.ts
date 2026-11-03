// @vitest-environment jsdom

/**
 * La pagina dove l'account senza anagrafica completa i suoi dati: dice perché ci si arriva.
 *
 * Il portale manda qui chi non ha un'anagrafica collegata all'account (decisione 134.4,
 * `CheckHasAnagrafica`), con la frase come avviso; la pagina la mostra. Chi ci arriva dall'accesso,
 * senza avviso, vede la pagina come prima. Il server: `tests/Feature/Portale/PortaleSenzaAnagraficaTest.php`.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import Alert from '@/components/Alert.vue';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/user/${p}`, generateRoute: (n: string) => `user.${n}`, hasRole: () => false, hasPermission: () => false }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const UserAnagraficaNew = (await import('./UserAnagraficaNew.vue')).default;

function monta(flash: Record<string, unknown>) {
    pagina.props = { auth: { user: { id: 4, roles: ['utente'], permissions: [] } }, flash };

    return shallowMount(UserAnagraficaNew, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('UserAnagraficaNew — la frase di chi arriva dal portale senza anagrafica', () => {
    test('con l\'avviso del server, la pagina lo mostra com\'è', () => {
        const frase = "Il tuo account non è ancora collegato a un'anagrafica: per usare il portale compila qui i tuoi dati.";
        const avviso = monta({ message: { type: 'warning', message: frase } }).findComponent(Alert);

        expect(avviso.exists()).toBe(true);
        expect(avviso.props('message')).toBe(frase);
        expect(avviso.props('type')).toBe('warning');
    });

    test('senza avviso, come chi ci arriva dall\'accesso, la pagina non mostra niente in più', () => {
        expect(monta({}).findComponent(Alert).exists()).toBe(false);
        expect(monta({ message: null }).findComponent(Alert).exists()).toBe(false);
    });
});
