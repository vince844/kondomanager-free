// @vitest-environment jsdom

/** Le azioni di una riga dell'elenco degli esercizi: «Modifica» e «Rendiconto», che apre la sintesi del rendiconto di quell'esercizio. */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));
const route = (nome: string, p: Record<string, number>) => `/${nome}/${p.condominio}/${p.esercizio}`;

const Azioni = (await import('./DataTableRowActions.vue')).default;

describe('le azioni di un esercizio', () => {
    test('«Rendiconto» apre la sintesi del rendiconto di quell\'esercizio', () => {
        const slot = { template: '<div><slot /></div>' };
        const w = mount(Azioni, {
            props: { esercizio: { id: 9 } as any, condominio: { id: 3 } as any },
            global: { mocks: { route }, stubs: { DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot, DropdownMenuLabel: slot } },
        });
        expect(w.find('[data-test="rendiconto"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto');
        expect(w.find('[data-test="rendiconto"]').text()).toBe('Rendiconto');
        w.unmount();
    });
});
