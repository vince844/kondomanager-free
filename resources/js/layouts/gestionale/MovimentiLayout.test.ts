// @vitest-environment jsdom

/**
 * Il menu dei Movimenti su una riga, con le icone: «Incassi», «Fatture», «Pagamenti» nel menu, con il nome intero della pagina al passaggio del
 * mouse e nella tendina; le altre voci come prima. La barra è quella comune (`NavSezione`), con la sua opzione «una riga».
 */
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { condominio: { id: 7, nome: 'Le Rose' }, esercizio: { id: 3 } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, a: Record<string, number> = {}) => '/admin/' + p.replace(/:(\w+)/g, (_, n: string) => String(a[n])) }),
}));

const Layout = (await import('./MovimentiLayout.vue')).default;

describe('il menu dei Movimenti', () => {
    test('una riga, nove voci con le icone; quattro nomi corti, con il nome intero accanto', () => {
        const barra = shallowMount(Layout).findComponent({ name: 'NavSezione' });
        expect(barra.props('unaRiga')).toBe(true);
        const voci = barra.props('items') as Array<{ title: string; breve?: string; icon?: unknown }>;
        expect(voci.map((v) => v.breve ?? v.title)).toEqual(['Incassi', 'Rate scadute', 'Fatture', 'Pagamenti', 'Giroconti', 'Ritenute e F24', 'Libro Giornale', 'Registro',
            'Rendiconto']);
        expect(voci.filter((v) => v.breve).map((v) => [v.breve, v.title])).toEqual([['Incassi', 'Incassi rate'], ['Fatture', 'Fatture passive'], ['Pagamenti', 'Pagamenti fornitori'],
            ['Registro', 'Registro di contabilità']]);
        expect(voci.every((v) => !!v.icon)).toBe(true);
    });

    test('«Rendiconto» apre la Sintesi, e resta acceso sulla situazione patrimoniale, che sta sul suo indirizzo di sempre', () => {
        const voci = shallowMount(Layout).findComponent({ name: 'NavSezione' }).props('items') as Array<{ title: string; href: string; anche?: string[] }>;
        const rendiconto = voci.find((v) => v.title === 'Rendiconto');
        expect(rendiconto?.href).toBe('/admin/gestionale/7/esercizi/3/rendiconto');
        expect(rendiconto?.anche).toEqual(['/admin/gestionale/7/esercizi/3/stato-patrimoniale']);
    });
});
