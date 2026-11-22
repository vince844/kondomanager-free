// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 736: «Da portare in assemblea» nel riquadro «Sei mesi per agire». In fondo alla riga aperta di un esercizio con persone da seguire, accanto a
 * «Registra la dispensa dell'assemblea», il pulsante apre la finestra del memo con la scheda dei sei mesi di quell'esercizio (`sei_mesi`, l'id
 * dell'esercizio): il memo e il punto che il programma propone sono un punto solo. Una riga senza persone aperte non ce l'ha; chi non gestisce le assemblee
 * non lo vede.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

let gestisce = true;
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}`, hasPermission: () => gestisce }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Widget = (await import('./SeiMesiPerAgireWidget.vue')).default;

const passo = { caso: 'R11', passo: 'sollecito', frase: 'Nessuna lettera.', note: [], possibili: ['sollecito', 'agito'], rata_compensa: null, termine: null };
const riga = (id: number, quante: number, altro: Record<string, unknown> = {}) => ({
    chiave: `sei-mesi-${id}`, esercizio: { id, nome: `Esercizio ${id === 25 ? 2025 : 2026}` }, data: '2026-06-30', giorni: 41, colore: 'vicina', aperta: true,
    piu_avanti: false, quante, cifra: quante * 20000,
    cartellini: { senza_lettera: quante, sollecito: 0, diffida_spedita: 0, diffida_da_spedire: 0, accordo: 0, dispensata: quante === 0 ? 2 : 0 },
    persone: Array.from({ length: quante }, (_, i) => ({ anagrafica_id: i + 1, nome: `Persona ${i + 1}`, unita: [`Int. ${i + 1}`], cifra: 20000, credito: 0,
        situazione: 'senza_lettera', passo, torna: null, lettere_da_spedire: [] })),
    tutte: [], chiuse: quante === 0 ? [{ anagrafica_id: 9, nome: 'Gialli', cifra: 20000, motivo: 'dispensa', frase: 'Dispensa dell\'assemblea del 15/03/2026.' }] : [],
    in_blocco: [], oltre: null, risposta: null, compito: true, compito_tornato: null, ...altro,
});
const seiMesi = (righe: unknown[]) => ({ oggi: '2026-05-20', passo_proposto: true, troppe: null, righe, senza_data: null, rendiconti: [], compiti: [] });

const FinestraDelMemo = { name: 'FinestraDelMemo', props: ['open', 'condominioId', 'origineTipo', 'origineId', 'testo'], template: '<div data-testid="finestra-memo" />' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; gestisce = true; document.body.innerHTML = ''; });
const monta = async (righe: unknown[]) => {
    w = mount(Widget, { attachTo: document.body, props: { seiMesi: seiMesi(righe) as any, condominioId: 3, puoRispondere: true },
        global: { stubs: { FinestraDelMemo, LettereDialog: true, AccordoRientroDialog: true, FascicoloLegaleDialog: true } } });
    await flushPromises();

    return w;
};

describe('«Da portare in assemblea» nel riquadro dei sei mesi', () => {
    test('in fondo alla riga aperta, accanto a «Registra la dispensa dell\'assemblea»: la finestra del memo con i sei mesi dell\'esercizio', async () => {
        const r = await monta([riga(25, 4)]);
        const fondo = r.get('[data-testid="sei-mesi-25"] [data-testid="fondo-riga"]');
        expect(fondo.text()).toContain('Registra la dispensa dell\'assemblea');
        const pulsante = fondo.get('[data-testid="da-portare-in-assemblea"]');
        expect(pulsante.text()).toBe('Da portare in assemblea');
        const finestra = r.getComponent(FinestraDelMemo);
        expect(finestra.props('origineTipo')).toBe('sei_mesi');
        expect(finestra.props('origineId')).toBe(25);
        expect(finestra.props('condominioId')).toBe(3);
        expect(finestra.props('open')).toBe(false);
        await pulsante.trigger('click');
        expect(finestra.props('open')).toBe(true);
    });

    test('una riga senza persone da seguire non ce l\'ha; chi non gestisce le assemblee non lo vede', async () => {
        let r = await monta([riga(26, 0)]);
        expect(r.find('[data-testid="da-portare-in-assemblea"]').exists()).toBe(false);
        r.unmount();
        gestisce = false;
        r = await monta([riga(25, 4)]);
        expect(r.find('[data-testid="sei-mesi-25"] [data-testid="fondo-riga"]').exists()).toBe(true);
        expect(r.find('[data-testid="da-portare-in-assemblea"]').exists()).toBe(false);
    });
});
