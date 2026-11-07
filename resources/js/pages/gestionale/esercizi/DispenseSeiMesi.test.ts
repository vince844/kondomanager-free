// @vitest-environment jsdom

/**
 * La pagina della dispensa dei sei mesi: le dispense registrate con le loro parole (la revocata dice «revocata il …»), e il modulo che non
 * parte senza la data, gli esercizi e la scelta «per tutte le persone» o «solo per alcune», che non è già fatta. Aperta e mandata senza toccare
 * niente, non manda niente.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a) },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./DispenseSeiMesi.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); });

const monta = () => {
    w = mount(Pagina, {
        props: {
            condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: null,
            esercizi: [{ id: 1, nome: 'Esercizio 2026', stato: 'aperto' }], persone: [{ id: 7, nome: 'Ugo' }],
            dispense: [
                { id: 1, data_delibera: '2027-03-20', numero_verbale: '12', nota: null, tutte_le_persone: true, esercizi: [{ id: 1, nome: 'Esercizio 2026' }], persone: [], revocata_il: '2027-06-01', nota_revoca: null, verbale: null },
            ],
            testo_norma: '«Salvo che…»', oggi: '2027-07-10',
        } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    return w;
};

describe('la pagina della dispensa', () => {
    test('la dispensa registrata dice la delibera, il verbale e la revoca', () => {
        const testo = monta().get('[data-testid="dispensa"]').text();
        expect(testo).toContain('Assemblea del 20/03/2027');
        expect(testo).toContain('verbale n. 12');
        expect(testo).toContain('revocata il 01/06/2027');
    });

    test('il modulo non parte senza la data, gli esercizi e la scelta delle persone, e la scelta non è già fatta', async () => {
        const pagina = monta();
        const pulsante = () => pagina.findAll('button').find((b) => b.text() === 'Registra la dispensa')!;
        expect(pulsante().attributes('disabled')).toBeDefined();
        expect((pagina.findAll('input[type="radio"]') as any[]).map((r) => (r.element as HTMLInputElement).checked)).toEqual([false, false]);
        await pulsante().trigger('click');
        expect(post).not.toHaveBeenCalled();
    });
});
