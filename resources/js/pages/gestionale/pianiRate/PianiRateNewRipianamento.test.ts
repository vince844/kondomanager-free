// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 743 (decisione 655): «Prepara il piano di ripianamento» apre lo straordinario con il debito ereditato da ripianare. Fino alla beta.13 il carrello era
 * vuoto per costruzione e il pulsante diceva «Scegli almeno una fattura per salvare»: il piano non si salvava.
 *
 * - il riquadro «Il debito ereditato da ripianare» sulla gestione della pregressa, con le righe a schema, la cifra proposta e la tabella;
 * - il pulsante «Salva piano rate» senza fatture nel carrello, e il debito che viaggia con il piano; tolta la spunta, il pulsante torna a chiedere una fattura;
 * - su un'altra gestione il riquadro non c'è; gli errori della cifra e della tabella stanno sotto i campi.
 *
 * Cosa resta scoperto: il disegno a 375 px e il tema scuro, a video; il server (`RipianamentoDelDeficitTest`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: (_u: string, _d: unknown, o: any) => o.onSuccess({ props: { saldoInfo: { applicabile: false } } }), post: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true,
    ScopertoWarning: true,
    DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
};

const ORDINARIA = { id: 35, nome: 'Ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' };
const STRAORDINARIA = { id: 36, nome: 'Facciata 2026', tipo: 'straordinaria', data_inizio: '2026-01-01' };
const RIPIANAMENTO = {
    compito_id: 501, fornitore: 'Idraulica Bianchi', voce: 'Ripianamento del debito verso Idraulica Bianchi', scoperto: 120000, ripianato: 0, massimo: 120000, proposto: 120000,
    limite: 'scoperto',
    gestione_id: ORDINARIA.id, gestione: 'Ordinaria 2026', fatture: [{ id: 9, numero: 'IB-77', rata_0: 200000 }],
    tabelle: [{ id: 3, nome: 'Proprietà' }, { id: 4, nome: 'Scale' }],
};
const FUORI = { gestione: 'Ordinaria 2026', sulle_voci: { totale: 0, fatture: [] }, altri_esercizi: [], gia_nei_piani: { totale: 0, fatture: [] } };

let pagina: VueWrapper | null = null;

async function monta(gestione: number = ORDINARIA.id, ripianamento: Record<string, unknown> = RIPIANAMENTO) {
    axios.get.mockReset();
    axios.get.mockImplementation(async (url?: string) => ({ data: url?.includes('fetch-fatture-fuori-dal-carrello') ? FUORI : [] }));
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Via delle Acacie 12' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [ORDINARIA, STRAORDINARIA] as never, saldoInfo: { saldo: 0, applicabile: false, motivo: '' }, anagraficheDisponibili: [],
            hasPianoEsistente: false, ripianamento: ripianamento as never,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
    (pagina.vm as any).form.gestione_id = gestione;
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
const riquadro = () => document.body.querySelector('[data-blocco="ripianamento"]');
const pulsanteSalva = () => document.body.querySelector('[data-pulsante="salva"]') as HTMLButtonElement;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il debito ereditato da ripianare', () => {
    test('la pagina si apre sullo straordinario, con il riquadro, le righe a schema, la cifra proposta e il nome del piano', async () => {
        await monta();

        expect(form().tipo).toBe('straordinario');
        expect(form().nome).toBe('Ripianamento del debito verso Idraulica Bianchi');
        const righe = [...riquadro()!.querySelectorAll('li')].map((li) => li.textContent!.replace(/\s+/g, ' ').trim());
        expect(righe).toEqual([
            'Debito ereditato verso Idraulica Bianchi: lo scoperto di oggi è € 1.200,00; al massimo € 1.200,00 (fattura n. IB-77).',
            'Con il piano la cifra diventa una spesa dell\'esercizio sulla voce «Ripianamento del debito verso Idraulica Bianchi», a carico dei proprietari.',
            'Si scrive solo quando salvi il piano. Se elimini il piano, si toglie.',
        ]);
        expect((riquadro()!.querySelector('#ripianamento_importo') as HTMLInputElement).value).toBe('1200.00');
        // Il carrello vuoto non dice «Nessuna fattura da mettere a rate qui» accanto al debito.
        expect(document.body.querySelector('[data-blocco="carrello-vuoto"]')).toBeNull();
    });

    test('senza fatture nel carrello il pulsante dice «Salva piano rate», e il debito viaggia con il piano con la cifra e la tabella', async () => {
        await monta();
        const invio = vi.spyOn(form(), 'post').mockImplementation(() => undefined as never);
        (pagina!.vm as any).ripianamentoTabella = 3;
        await flushPromises();

        expect(pulsanteSalva().textContent!.trim()).toBe('Salva piano rate');
        await pagina!.find('form').trigger('submit');
        expect(invio).toHaveBeenCalledTimes(1);
        expect(form().ripianamento).toEqual({ compito_id: 501, importo: '1200.00', tabella_id: 3 });
        expect(form().fatture_config).toEqual([]);
    });

    test('tolta la spunta, il debito non viaggia e il pulsante torna a chiedere una fattura', async () => {
        await monta();
        (pagina!.vm as any).ripianamentoAttivo = false;
        await flushPromises();

        expect(pulsanteSalva().textContent!.trim()).toBe('Scegli almeno una fattura per salvare');
        expect(riquadro()!.querySelector('#ripianamento_importo')).toBeNull();
    });

    test('su un\'altra gestione il riquadro non c\'è e il debito non viaggia', async () => {
        await monta(STRAORDINARIA.id);
        const invio = vi.spyOn(form(), 'post').mockImplementation(() => undefined as never);

        expect(riquadro()).toBeNull();
        expect(pulsanteSalva().textContent!.trim()).toBe('Scegli almeno una fattura per salvare');
        await pagina!.find('form').trigger('submit');
        expect(invio).not.toHaveBeenCalled();
    });

    test('in un esercizio che non è quello della pregressa la pagina si ferma e dice dove: niente riquadro, niente debito nel piano', async () => {
        const altrove = 'Il debito ereditato verso Idraulica Bianchi è registrato nell\'esercizio «Esercizio 2026»: il ripianamento si prepara in quell\'esercizio.';
        await monta(ORDINARIA.id, { ...RIPIANAMENTO, altrove });
        const invio = vi.spyOn(form(), 'post').mockImplementation(() => undefined as never);

        expect(form().tipo).toBe('straordinario');
        expect(riquadro()).toBeNull();
        expect(document.body.querySelector('[data-blocco="ripianamento-altrove"]')!.textContent).toContain(altrove);
        expect(pulsanteSalva().textContent!.trim()).toBe('Scegli almeno una fattura per salvare');
        await pagina!.find('form').trigger('submit');
        expect(invio).not.toHaveBeenCalled();
    });

    test('una cifra sopra il massimo lo dice prima di salvare; gli errori del server stanno sotto i due campi', async () => {
        await monta();
        (pagina!.vm as any).ripianamentoImporto = '2.500,00';
        form().errors = { 'ripianamento.tabella_id': 'Scegli la tabella con cui dividere il debito ereditato.' };
        await flushPromises();

        expect(riquadro()!.querySelector('[data-testo="cifra-fuori"]')!.textContent).toBe('Al massimo € 1.200,00: è lo scoperto di oggi, la parte dei debiti ereditati che i pregressi chiesti ai condòmini non coprono.');
        expect(document.body.querySelector('label[for="ripianamento_tabella"]')!.closest('.space-y-1\\.5')!.textContent).toContain('Scegli la tabella con cui dividere il debito ereditato.');
    });
});
