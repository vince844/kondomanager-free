// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 187 — nella vendita la pagina chiede «Togli … dal condominio» per chi vende, dal pannello (`esce_dal_condominio`): spuntata di serie se dopo l'atto
 * non gli resta niente nel condominio, senza spunta se gli resta un'unità; toccata, la scelta resta dell'amministratore. Le persone spuntate partono con la
 * registrazione (`togli_dal_condominio`).
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import axios from 'axios';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const PassaggioNew = (await import('./PassaggioNew.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    post.mockReset();
    post.mockResolvedValue({ data: {} });
});

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const MARIO = { id: 10, anagrafica: { id: 1, nome: 'Mario Rossi', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };

function monta(): Pagina {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 7' } as never,
            tipo: 'vendita',
            rigaPreselezionata: 10,
            titolari: [MARIO],
            anagrafiche: [],
            pertinenze: [],
            rateEmesseCount: 3,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

const vm = (w: Pagina) => w.vm as unknown as { form: { togli_dal_condominio: number[] }; anteprima: unknown };
function pannello(resta: string[]) {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: null, entrante_dal: '', frase: '' },
        anagrafica: { frasi: [], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse: [], piani_distinti: 1, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: { stato: 'calcolato', anagrafica_uscente_id: 1, anagrafica_entrante_id: 4, quote: [], per_gestione: [], coppie: [], totale_entrante: 0, totale_entrante_formattato: '€ 0,00',
                totale_entrante_assoluto_formattato: '€ 0,00', riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [] },
            piano_da_ricalcolare: null, frasi: [], frasi_del_conguaglio: [], scelta_del_conguaglio: false, fermata_coinquilino: [],
        },
        obbligati: { frasi: [], copia_autentica_mancante: false },
        invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        esce_dal_condominio: [{ id: 1, nome: 'Mario Rossi', resta }],
    };
}

describe('chi vende e il condominio', () => {
    test('senza altre unità la casella «Togli Mario Rossi dal condominio» è spuntata di serie', async () => {
        const w = monta();
        vm(w).anteprima = pannello([]);
        await flushPromises();

        const riquadro = w.find('[data-testid="esce-dal-condominio"]');
        expect(riquadro.text()).toContain('Togli Mario Rossi dal condominio');
        expect(riquadro.text()).toContain('Le sue rate rimaste si incassano lo stesso');
        expect((w.find('[data-testid="togli-1"]').element as HTMLInputElement).checked).toBe(true);
        expect(vm(w).form.togli_dal_condominio).toEqual([1]);
    });

    test('con un\'altra unità la casella non è spuntata e la pagina dice che cosa gli resta; spuntata a mano, resta spuntata', async () => {
        const w = monta();
        vm(w).anteprima = pannello(['Interno B']);
        await flushPromises();

        expect(w.find('[data-testid="esce-dal-condominio"]').text()).toContain('Gli resta Interno B');
        expect(vm(w).form.togli_dal_condominio).toEqual([]);
        await w.find('[data-testid="togli-1"]').trigger('change');
        vm(w).anteprima = pannello(['Interno B']);
        await flushPromises();
        expect(vm(w).form.togli_dal_condominio).toEqual([1]);
    });
});
