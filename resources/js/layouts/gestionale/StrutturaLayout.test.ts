// @vitest-environment jsdom

/**
 * Le schede dell'area «Condominio»: «Impostazioni» è l'ultima, dopo «Recupero dei crediti», con la sua icona e il suo indirizzo.
 */
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { condominio: { id: 16, nome: 'Via Verdi 12' } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, a: Record<string, number> = {}) => '/admin/' + p.replace(/:(\w+)/g, (_, n: string) => String(a[n])) }),
}));

const Layout = (await import('./StrutturaLayout.vue')).default;

describe('le schede del condominio', () => {
    test('«Impostazioni» in fondo, con l\'icona e l\'indirizzo del condominio', () => {
        const voci = shallowMount(Layout).findComponent({ name: 'NavSezione' }).props('items') as Array<{ title: string; href: string; icon?: unknown }>;
        expect(voci.map((v) => v.title)).toEqual(['Dettagli', 'Palazzine', 'Scale', 'Risorse e fondi', 'Saldi Iniziali', 'Già versato', 'Recupero dei crediti', 'Impostazioni']);
        expect(voci.at(-1)!.href).toBe('/admin/gestionale/16/impostazioni');
        expect(voci.every((v) => !!v.icon)).toBe(true);
    });
});
