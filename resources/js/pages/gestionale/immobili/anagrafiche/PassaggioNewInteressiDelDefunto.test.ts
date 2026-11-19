// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 483 (Coda 663): nel modulo «Registra passaggio» della successione, la card «Gli interessi del defunto dopo il decesso» sotto la scelta
 * dell'arretrato. Compare solo quando la strada dell'arretrato è scelta, con tutte e due le strade; la scelta che parte con l'anteprima (e con la
 * registrazione, che ne rifà il corpo) è il di serie della strada finché l'amministratore non tocca il menu, e la segue quando la strada cambia; toccata,
 * resta. Il caso: Ettore Fabbri muore il 17/11/2026, eredi Elena e Marco al 50 %.
 *
 * Si monta la pagina con `shallowMount` (i figli sono segnaposto), come in `PassaggioNewRevisione.test.ts`; il modulo si riempie dal suo stato, il debounce
 * di 350 ms si fa avanzare con i timer finti, e la risposta del pannello si dà a mano.
 *
 * Cosa NON copre: la card stessa (`InteressiDelDefunto.test.ts`); il server, che mette il di serie anche senza la scelta e scrive la frase
 * (`InteressiDelDefuntoDopoIlDecessoTest.php`); il `post` di Inertia della registrazione, che manda lo stesso corpo dell'anteprima.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
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
const InteressiDelDefunto = (await import('@/components/gestionale/immobili/InteressiDelDefunto.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    post.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const ETTORE = { id: 10, anagrafica: { id: 1, nome: 'Ettore Fabbri', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };
const ELENA = { id: 3, nome: 'Elena', codice_fiscale: null, indirizzo: 'Via Verdi 1' };
const MARCO = { id: 4, nome: 'Marco', codice_fiscale: null, indirizzo: 'Via Verdi 1' };
const FRASE = 'Dal 17/11/2026 la proposta degli interessi di Ettore Fabbri non conta altri giorni: il debito è passato a Elena e Marco, e gli interessi li propone la loro posizione, sulle loro rate.';

/** Il pannello come lo manda il server, senza conguaglio: l'arretrato con la scelta sugli interessi e la sua frase. */
function pannello() {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: '2026-11-16', entrante_dal: '2026-11-17', frase: '' },
        anagrafica: { frasi: [], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse: [], piani_distinti: 1, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: null, piano_da_ricalcolare: null, frasi: [], frasi_del_conguaglio: [], scelta_del_conguaglio: false,
            arretrato: { scelta: 'eredi', legato: false, resta: 0, resta_formattato: '€ 0,00', eredi: [], totale: 30000, frase: null,
                interessi_del_defunto: 'al_decesso', frase_interessi_del_defunto: FRASE },
        },
        obbligati: { frasi: [], copia_autentica_mancante: false },
        invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    };
}

function monta(): Pagina {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'U1' } as never,
            tipo: 'successione',
            rigaPreselezionata: 10,
            titolari: [ETTORE],
            anagrafiche: [ELENA, MARCO],
            pertinenze: [],
            rateEmesseCount: 1,
        },
        // I segnaposto rendono il loro contenuto: la card sta dentro la scheda dell'arretrato.
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

async function lasciaRispondere() {
    await vi.advanceTimersByTimeAsync(350);
    await flushPromises();
}

function ultimoCorpo(): Record<string, unknown> {
    const chiamate = post.mock.calls;
    if (chiamate.length === 0) throw new Error('Il pannello non ha mandato nessuna richiesta.');

    return chiamate[chiamate.length - 1][1] as Record<string, unknown>;
}

/** Lo stato del modulo, come lo riempie chi lavora. */
const modulo = (w: Pagina) => (w.vm as unknown as { form: Record<string, unknown> }).form;

describe('la successione: gli interessi del defunto dopo il decesso', () => {
    test('la card c\'è solo con la strada scelta; la scelta non toccata segue la strada; toccata, resta', async () => {
        post.mockImplementation(async () => ({ data: pannello() }));
        const w = monta();
        const form = modulo(w);
        form.decorrenza = '2026-11-17';
        form.eredi = [{ anagrafica_id: 3, quota: '50' }, { anagrafica_id: 4, quota: '50' }];
        await lasciaRispondere();
        // Senza la strada dell'arretrato il modulo non è completo, e la card non c'è.
        expect(w.findComponent(InteressiDelDefunto).exists()).toBe(false);

        form.arretrato = 'eredi';
        await lasciaRispondere();
        const card = w.findComponent(InteressiDelDefunto);
        expect(card.exists()).toBe(true);
        expect(card.props('strada')).toBe('eredi');
        expect(card.props('modelValue')).toBeNull();
        expect(card.props('frase')).toBe(FRASE);
        expect(ultimoCorpo().interessi_del_defunto).toBe('al_decesso');

        form.arretrato = 'defunto';
        await lasciaRispondere();
        expect(w.findComponent(InteressiDelDefunto).props('strada')).toBe('defunto');
        expect(ultimoCorpo().interessi_del_defunto).toBe('continuano');

        // L'amministratore sceglie «si fermano al decesso» con l'arretrato a nome del defunto: la scelta resta anche cambiando strada.
        w.findComponent(InteressiDelDefunto).vm.$emit('update:modelValue', 'al_decesso');
        await lasciaRispondere();
        expect(ultimoCorpo().interessi_del_defunto).toBe('al_decesso');
        form.arretrato = 'eredi';
        await lasciaRispondere();
        expect(ultimoCorpo().interessi_del_defunto).toBe('al_decesso');
        form.arretrato = 'defunto';
        await lasciaRispondere();
        expect(ultimoCorpo().interessi_del_defunto).toBe('al_decesso');
        expect(w.findComponent(InteressiDelDefunto).props('modelValue')).toBe('al_decesso');
    });

    test('con il legato l\'arretrato resta a nome del defunto, e di serie gli interessi continuano a suo nome', async () => {
        post.mockImplementation(async () => ({ data: pannello() }));
        const w = monta();
        const form = modulo(w);
        form.decorrenza = '2026-11-17';
        form.eredi = [{ anagrafica_id: 3, quota: '50' }, { anagrafica_id: 4, quota: '50' }];
        form.arretrato = 'eredi';
        await lasciaRispondere();
        expect(ultimoCorpo().interessi_del_defunto).toBe('al_decesso');
        (w.vm as unknown as { legato: boolean }).legato = true;
        await lasciaRispondere();
        expect(form.arretrato).toBe('defunto');
        expect(ultimoCorpo().interessi_del_defunto).toBe('continuano');
    });
});
