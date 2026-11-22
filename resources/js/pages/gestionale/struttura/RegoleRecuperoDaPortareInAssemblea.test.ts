// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 736: «Da portare in assemblea» nelle regole del recupero, nella card «Oggi». Con la regola di oggi la finestra del memo parte da quella regola (il
 * punto «Criteri per l'addebito degli interessi e delle spese di recupero dei crediti», materia «Regole del recupero dei crediti», e dopo il voto «Scrivi
 * la regola»); senza regole parte dallo stesso testo, scritto. Chi non gestisce le assemblee non lo vede.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

let gestisce = true;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}`, hasPermission: () => gestisce }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RegoleRecupero.vue')).default;

const regola = { id: 4, dal: '2026-01-01', tasso_tipo: 'legale', tasso: 'Tasso legale dell\'anno', fonte: null, riferimento: null, giorni_decorrenza: 0,
    giorni_tolleranza: null, termine_lettere_giorni: null, avviso: null, creata_da: 'Vincenzo', usata: null };
const FinestraDelMemo = { name: 'FinestraDelMemo', props: ['open', 'condominioId', 'origineTipo', 'origineId', 'testo'], template: '<div data-testid="finestra-memo" />' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; gestisce = true; });
const monta = (regole: unknown[], diOggi: number | null) => {
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: null, regole, di_oggi: diOggi, tasso_di_oggi: '1,60 %', tabella_legale: [],
            legali_a_mano: [], anni_mancanti: [], anni_da_scrivere: [], puo_scrivere_tasso_legale: false, fonti: [], frase_senza_regole: 'Senza una regola vale la legge.',
            avvisi_fonti: {}, oggi: '2026-11-15' } as any,
        global: { stubs: {
            GestionaleLayout: { template: '<div><slot /></div>' }, StrutturaLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true,
            RegoleRecuperoGuide: true, TassoLegaleAManoDialog: true, EsitiDellaScheda: true, FinestraDelMemo,
        } },
    });

    return w;
};

describe('«Da portare in assemblea» nelle regole del recupero', () => {
    test('con la regola di oggi: nella card «Oggi», la finestra del memo con quella regola', async () => {
        const pagina = monta([regola], 4);
        const pulsante = pagina.get('[data-testid="di-oggi"] [data-testid="da-portare-in-assemblea"]');
        expect(pulsante.text()).toBe('Da portare in assemblea');
        const memo = pagina.getComponent(FinestraDelMemo);
        expect(memo.props('origineTipo')).toBe('regola_recupero');
        expect(memo.props('origineId')).toBe(4);
        expect(memo.props('testo')).toBeNull();
        await pulsante.trigger('click');
        expect(memo.props('open')).toBe(true);
    });

    test('senza regole: lo stesso punto, scritto; chi non gestisce le assemblee non lo vede', () => {
        let pagina = monta([], null);
        const memo = pagina.getComponent(FinestraDelMemo);
        expect(memo.props('origineTipo')).toBeNull();
        expect(memo.props('testo')).toBe('Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti');
        pagina.unmount();
        gestisce = false;
        pagina = monta([regola], 4);
        expect(pagina.find('[data-testid="da-portare-in-assemblea"]').exists()).toBe(false);
    });
});
