// @vitest-environment jsdom

/**
 * Le schede del rendiconto con lo stato in testa: «Bozza» finché il rendiconto non è fermato, poi «Fermato per l'assemblea il …», poi «Approvato il …
 * (verbale n. …)», come lo manda il server a ogni scheda; fuori dalla Sintesi un collegamento alle versioni. Senza lo stato, la riga non c'è.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const pagina: { props: Record<string, unknown> } = { props: {} };
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, usePage: () => pagina }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, par: Record<string, unknown> = {}) => `/${Object.entries(par).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}` }),
}));

const RendicontoSchede = (await import('./RendicontoSchede.vue')).default;

let w: VueWrapper | null = null;
const monta = (attiva: string, stato?: Record<string, unknown>) => {
    w?.unmount();
    pagina.props = stato === undefined ? {} : { stato_rendiconto: stato };
    w = mount(RendicontoSchede, { props: { condominioId: 3, esercizioId: 9, attiva } as any });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });

describe('lo stato del rendiconto in testa alle schede', () => {
    test('la bozza, senza collegamenti', () => {
        const s = monta('cassa', { stato: 'bozza', frase: 'Bozza: le cifre cambiano a ogni registrazione.', versione_id: null }).find('[data-test="stato-rendiconto"]');
        expect(s.text()).toBe('Bozza: le cifre cambiano a ogni registrazione.');
        expect(s.attributes('data-stato')).toBe('bozza');
        expect(s.find('a').exists()).toBe(false);
    });

    test('presentato e approvato: la frase del server e, fuori dalla Sintesi, le versioni nella Sintesi', () => {
        const presentato = monta('conguaglio', { stato: 'presentata', frase: 'Fermato per l\'assemblea il 01/03/2027.', versione_id: 4 }).find('[data-test="stato-rendiconto"]');
        expect(presentato.text()).toContain('Fermato per l\'assemblea il 01/03/2027.');
        expect(presentato.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto');
        const approvato = monta('sintesi', { stato: 'approvata', frase: 'Approvato il 15/03/2027 (verbale n. 2).', versione_id: 4 }).find('[data-test="stato-rendiconto"]');
        expect(approvato.text()).toBe('Approvato il 15/03/2027 (verbale n. 2).');
        expect(approvato.find('a').exists()).toBe(false);
    });

    test('senza lo stato dal server la riga non c\'è, e le otto schede restano', () => {
        const p = monta('riepilogo');
        expect(p.find('[data-test="stato-rendiconto"]').exists()).toBe(false);
        expect(p.findAll('[data-scheda]')).toHaveLength(8);
    });

    test('dopo l\'approvazione, se il giornale è cambiato: l\'avviso sotto lo stato, con «Vedi che cosa è cambiato» verso il confronto della Sintesi', () => {
        const dopo = 'Dopo l\'approvazione del 15/03/2027 sono cambiate registrazioni dell\'esercizio: le schede non sono più quelle approvate. Il rendiconto approvato resta quello del PDF.';
        const fuori = monta('cassa', { stato: 'approvata', frase: 'Approvato il 15/03/2027 (verbale n. 2).', versione_id: 4, dopo }).find('[data-test="dopo-l-approvazione"]');
        expect(fuori.text()).toContain(dopo);
        expect(fuori.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto#confronto');
        expect(monta('sintesi', { stato: 'approvata', frase: 'Approvato il 15/03/2027.', versione_id: 4, dopo }).find('[data-test="dopo-l-approvazione"] a').attributes('href')).toBe('#confronto');
        expect(monta('cassa', { stato: 'approvata', frase: 'Approvato il 15/03/2027.', versione_id: 4, dopo: null }).find('[data-test="dopo-l-approvazione"]').exists()).toBe(false);
    });
});
