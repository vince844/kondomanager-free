// @vitest-environment jsdom

/**
 * La colonna «Rendiconto» dell'elenco degli esercizi: lo stato che il server manda per ogni esercizio (in bozza, fermato per l'assemblea, approvato),
 * in breve, con la frase intera nel titolo; senza lo stato, un trattino.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

vi.mock('@/components/gestionale/esercizi/DataTableRowActions.vue', () => ({ default: { template: '<span />' } }));
vi.mock('@/components/gestionale/esercizi/DataTableColumnHeader.vue', () => ({ default: { template: '<span />' } }));

const { getColumns } = await import('./columns');

const cella = (stati: Record<number, any>, id: number) => {
    const colonna = getColumns({ id: 3, nome: 'Via Verdi' } as any, stati).find((c) => c.id === 'rendiconto')!;
    const Cella = defineComponent({ render: () => h('div', [(colonna.cell as any)({ row: { original: { id } } })]) });

    return mount(Cella).find('div');
};

describe('la colonna del rendiconto negli esercizi', () => {
    test('fermato e approvato in breve, la frase intera nel titolo', () => {
        const stati = {
            9: { stato: 'presentata', frase: 'Fermato per l\'assemblea il 01/03/2027.', breve: 'Fermato il 01/03/2027' },
            8: { stato: 'approvata', frase: 'Approvato il 15/03/2026 (verbale n. 4).', breve: 'Approvato il 15/03/2026' },
        };
        expect(cella(stati, 9).text()).toBe('Fermato il 01/03/2027');
        expect(cella(stati, 9).find('[data-stato-rendiconto]').attributes('title')).toBe('Fermato per l\'assemblea il 01/03/2027.');
        expect(cella(stati, 8).find('[data-stato-rendiconto="approvata"]').text()).toBe('Approvato il 15/03/2026');
    });

    test('senza lo stato, un trattino', () => {
        expect(cella({}, 9).text()).toBe('–');
    });
});
