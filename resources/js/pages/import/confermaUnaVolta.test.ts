// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La schermata di conferma dell'importazione (1.12.0-beta.14, tappa 5):
 * - la conferma parte una volta sola: mentre la richiesta è in corso «Importa N record» e «Importa senza i saldi» sono spenti, e un
 *   secondo clic non manda niente (il doppio clic riportava a metà un'importazione appena entrata);
 * - il condominio che c'è già, quando chi importa non ha «Modifica condomini»: al posto di «Unisci» e «Lascia com'è» la frase con il
 *   permesso che serve, ripetuta accanto al pulsante spento;
 * - la persona che c'è già, quando chi importa non ha «Modifica anagrafiche»: resta «Lascia com'è», con la frase del permesso che serve
 *   per unire; un «Unisci» già sul lotto non conta come decisione presa.
 * Le props sono come le manda `ImportController::anteprima()`; nomi e cifre inventati.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: {} } }),
    router: { post: (...a: unknown[]) => post(...a), put: vi.fn(), delete: vi.fn() },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Anteprima = (await import('./ImportAnteprima.vue')).default;

const STUBS = { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div />' }, ImportGuide: { template: '<div />' } };
const MOCKS = { route: (nome: string) => `/${nome}` };

type Voce = { chiave_decisione: string; cosa: string; nome: string; esistente: string; motivo: string; scelte: string[]; senza_permesso?: boolean };

function props(altro: Record<string, unknown> = {}, voci: Voce[] = []) {
    return {
        lotto: { uuid: 'u-1', stato: 'in_corso' },
        anteprima: {
            condominio: { nome: 'Condominio Esempio', codice_fiscale: null, indirizzo: 'Via Roma 12', esercizio: { etichetta: '2026', dal: '01/01/2026', al: '31/12/2026', solare: true } },
            collisioni: { totale: voci.length, voci },
            titolarita: { totale: 2, unita_coinvolte: 2, unita_senza_titolare: [], elenco: [] },
            persone: { totale: 2, senza_codice_fiscale: 0, da_dividere: [] },
            unita: { totale: 2, elenco: [] },
            tabelle: [],
            saldi: null,
            totale_record: 14,
        },
        decisioni: {},
        confermabile: true,
        senza_saldi: { possibile: false, motivi: [], non_entra: [] },
        esercizi_aperti: null,
        ...altro,
    };
}

let pagina: VueWrapper | null = null;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    post.mockReset();
});

const pulsante = (w: VueWrapper, testo: string) => w.findAll('button').find((b) => b.text().trim().startsWith(testo));

describe('la conferma parte una volta sola', () => {
    test('mentre la richiesta è in corso il pulsante è spento e un secondo clic non manda niente; alla risposta si riaccende', async () => {
        pagina = mount(Anteprima, { props: props() as never, global: { stubs: STUBS, mocks: MOCKS } });

        await pulsante(pagina, 'Importa 14 record')!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        const [indirizzo, dati, opzioni] = post.mock.calls[0] as [string, unknown, { onStart: () => void; onFinish: () => void }];
        expect(indirizzo).toBe('/import.conferma');
        expect(dati).toEqual({});

        opzioni.onStart();
        await pagina.vm.$nextTick();
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeDefined();
        await pulsante(pagina, 'Importa 14 record')!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);

        opzioni.onFinish();
        await pagina.vm.$nextTick();
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeUndefined();
    });

    test('lo stesso per «Importa senza i saldi»', async () => {
        pagina = mount(Anteprima, {
            props: props({
                confermabile: false,
                senza_saldi: {
                    possibile: true,
                    motivi: [{ codice: 'saldi.persona_sconosciuta', motivo: 'Il saldo di «Lucia Verde» è intestato a un nome che non è nel foglio «2 persone».', strada: 'Correggi.' }],
                    non_entra: [{ cosa: 'Saldi di apertura', dettaglio: '2 posizioni dal foglio dei saldi' }],
                },
            }) as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        await pulsante(pagina, 'Importa senza i saldi')!.trigger('click');
        const [, dati, opzioni] = post.mock.calls[0] as [string, unknown, { onStart: () => void }];
        expect(dati).toEqual({ senza_saldi: true });

        opzioni.onStart();
        await pagina.vm.$nextTick();
        expect(pulsante(pagina, 'Importa senza i saldi')?.attributes('disabled')).toBeDefined();
        await pulsante(pagina, 'Importa senza i saldi')!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('il condominio che c\'è già, senza «Modifica condomini»', () => {
    const condominio: Voce = {
        chiave_decisione: 'condominio:97555000188', cosa: 'condominio', nome: 'CONDOMINIO ESEMPIO', esistente: 'Condominio Esempio',
        motivo: 'codice_fiscale', scelte: ['unisci', 'salta'],
    };

    test('al posto di «Unisci» e «Lascia com\'è» la frase con il permesso che serve, ripetuta accanto al pulsante spento', () => {
        pagina = mount(Anteprima, { props: props({ confermabile: false }, [{ ...condominio, senza_permesso: true }]) as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Unisci')).toBeUndefined();
        expect(pulsante(pagina, 'Lascia com\'è')).toBeUndefined();
        expect(pagina.find('[data-senza-permesso]').text()).toContain('Per importare dentro «Condominio Esempio» serve il permesso «Modifica condomini»');
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeDefined();
        expect(pagina.text()).not.toContain('Resta da decidere');
    });

    test('con il permesso la scelta c\'è, come prima', () => {
        pagina = mount(Anteprima, { props: props({}, [{ ...condominio, senza_permesso: false }]) as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Unisci')).toBeDefined();
        expect(pulsante(pagina, 'Lascia com\'è')).toBeDefined();
        expect(pagina.find('[data-senza-permesso]').exists()).toBe(false);
    });
});

describe('la persona che c\'è già, senza «Modifica anagrafiche»', () => {
    const persona: Voce = {
        chiave_decisione: 'soggetti:rossi mario', cosa: 'persona', nome: 'ROSSI MARIO', esistente: 'Rossi Mario',
        motivo: 'codice_fiscale', scelte: ['salta'], senza_permesso: true,
    };

    test('resta «Lascia com\'è», con il permesso che serve per unire; la conferma aspetta la decisione', () => {
        pagina = mount(Anteprima, { props: props({ confermabile: false }, [persona]) as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Unisci')).toBeUndefined();
        expect(pulsante(pagina, 'Lascia com\'è')).toBeDefined();
        expect(pagina.find('[data-senza-permesso-persona]').text())
            .toContain('Per aggiornare «Rossi Mario» con i dati del file («Unisci») serve il permesso «Modifica anagrafiche»');
        expect(pagina.text()).not.toContain('Modifica condomini');
        expect(pagina.text()).toContain('Resta da decidere 1 cosa che esiste già');
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeDefined();
    });

    test('un «Unisci» già sul lotto non è una decisione presa: la voce resta aperta, con «Lascia com\'è»', () => {
        pagina = mount(Anteprima, {
            props: props({ confermabile: false, decisioni: { 'soggetti:rossi mario': 'unisci' } }, [persona]) as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        expect(pagina.text()).not.toContain('Hai scelto');
        expect(pulsante(pagina, 'Lascia com\'è')).toBeDefined();
        expect(pagina.text()).toContain('Resta da decidere 1 cosa che esiste già');
    });
});
