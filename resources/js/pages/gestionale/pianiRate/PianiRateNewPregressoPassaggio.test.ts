// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La issue 58 (Coda 174, decisione 674) nella pagina del nuovo piano rate, sulla bozza approvata `beta14_pregresso_passaggio_bozza.html`:
 * - la riga arancio sotto «Saldi iniziali rilevati» quando un pregresso dell'unità tocca un passaggio, con il pulsante che apre la finestra
 *   (scena 5);
 * - la finestra «Riparto saldi iniziali» allo standard delle finestre (decisione 608): il badge «Tocca un passaggio», il riepilogo fisso in
 *   fondo con «Chiudi» e «Conferma il riparto» (scene 1 e 2), il menu «Automatico / A mano» sui saldi dell'unità senza passaggi;
 * - che cosa parte con il piano: la scelta diversa dalla regola come `alla_data`, la regola niente, «Divido a mano» le righe di sempre;
 *   chiudere la finestra (la X, Esc, il clic fuori, «Chiudi») tiene le scelte come «Conferma il riparto» (decisione 689 (1)).
 * - il riquadro «Nessun saldo pregresso» solo senza saldi (rilievo 5).
 * Che cosa NON copre il clic fuori dalla finestra: passa dallo stesso `update:open` della X e di Esc, provati qui.
 *
 * Le risposte del server hanno la forma della rotta vera (`fetch-saldi-analitici`, provata in `Beta14PregressoPassaggioTest`).
 * Che cosa NON copre: il disegno a video (375 px, tema scuro), le parole del blocco «A chi va» (in `AChiVaIlPregresso.test.ts`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: (_u: string, _d: unknown, o: any) => o.onSuccess({ props: { saldoInfo: { applicabile: true } } }), post: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
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
const ugo = { anagrafica_id: 1, nome: 'Ugo Rossi', quota: 100, importo: 18000 };
const elsa = { anagrafica_id: 2, nome: 'Elsa Bianchi', quota: 100, importo: 18000 };
/** Il saldo dell'unità della scena 1: vendita piena dell'1/4/2026. */
const DELL_UNITA = {
    id: 11, tipo: 'solidale', soggetto_nome: 'Unità Interno 3', immobile_nome: 'Interno 3 (Int. 3)', ruolo: 'Condominio', importo: 18000, is_debito: true, immobile_id: 3,
    riparto_previsto: {
        risolvibile: true, ruolo: 'proprietario', ruolo_label: 'Proprietario', motivo: null, quote: [ugo], riferito_al: '2026-01-01', titolari_al: '2026-01-01', esercizio: '2026',
        passaggi: [{ data: '2026-04-01', tipo: 'vendita', etichetta: 'Vendita', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi'] }],
        scelte: [
            { alla_data: '2026-01-01', regola: true, etichetta: 'titolare al 1 gennaio 2026 · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [ugo] },
            { alla_data: '2026-04-01', regola: false, etichetta: 'titolare dal 1 aprile 2026', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [elsa] },
        ],
    },
};
/** Un saldo dell'unità senza passaggi, e un nominale. */
const SENZA_PASSAGGI = { ...DELL_UNITA, id: 12, immobile_nome: 'Interno 5 (Int. 5)', importo: 6000, riparto_previsto: { ...DELL_UNITA.riparto_previsto, passaggi: [], scelte: [] } };
const NOMINALE = { id: 13, tipo: 'nominale', anagrafica_id: 9, soggetto_nome: 'Bice Verdi', immobile_nome: 'Interno 7 (Int. 7)', ruolo: 'proprietario', importo: 5000, is_debito: true, immobile_id: 7, riparto_previsto: null };

let pagina: VueWrapper | null = null;

async function monta(saldi: unknown[], { inCaricamento = false } = {}) {
    axios.get.mockReset();
    axios.get.mockImplementation((url?: string) => (url?.includes('fetch-saldi-analitici') && inCaricamento
        ? new Promise(() => {})
        : Promise.resolve({ data: url?.includes('fetch-saldi-analitici') ? saldi.map((s) => JSON.parse(JSON.stringify(s))) : [] })));
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [ORDINARIA] as never, saldoInfo: { saldo: 29000, applicabile: true, has_movimenti: true, motivo: '' }, anagraficheDisponibili: [], hasPianoEsistente: false,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
    form().tipo = 'ordinario';
    form().gestione_id = ORDINARIA.id;
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
const vm = () => pagina!.vm as any;
const q = (sel: string) => document.body.querySelector(`[data-testid="${sel}"]`) as HTMLElement | null;
const testoDi = (sel: string) => q(sel)!.textContent!.replace(/\s+/g, ' ').trim();
const clic = async (el: Element) => { (el as HTMLElement).click(); await flushPromises(); };
const pulsante = (testo: string) => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.replace(/\s+/g, ' ').trim() === testo)!;
/** Il corpo che partirebbe con «Salva»: la funzione d'invio scrive `saldi_config` nel modulo prima del `post`, che qui non parte. */
const saldiConfigAllInvio = () => { form().transform = () => ({ post: () => {} }); vm().submit(); return JSON.parse(JSON.stringify(form().saldi_config)); };

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la riga arancio della pagina', () => {
    test('scena 5 — un pregresso dell\'unità tocca un passaggio: la riga lo dice con l\'unità e la cifra, a chi va, e il pulsante apre la finestra', async () => {
        await monta([DELL_UNITA, SENZA_PASSAGGI, NOMINALE]);

        expect(testoDi('pregressi-che-toccano')).toContain('1 pregresso dell\'unità tocca un passaggio: Interno 3 (Int. 3), € 180,00.')
            .toContain('Va a Ugo Rossi, titolare al 1 gennaio 2026. Controlla a chi va prima di generare.');
        await clic(q('pregressi-che-toccano')!.querySelector('button')!);
        expect(q('finestra-riparto-saldi')).not.toBeNull();
    });

    test('rilievo 4 — con la successione la riga dice a chi va con le parole della regola: gli eredi di Ugo, non «titolari al 1 gennaio»', async () => {
        const anna = { anagrafica_id: 3, nome: 'Anna Verdi', quota: 50, importo: 9000 };
        const bruno = { anagrafica_id: 4, nome: 'Bruno Verdi', quota: 50, importo: 9000 };
        await monta([{ ...DELL_UNITA, riparto_previsto: { ...DELL_UNITA.riparto_previsto, quote: [anna, bruno],
            successione: { defunti: ['Ugo Rossi'], data: '2026-04-01', titolari: ['Ugo Rossi'], arretrato: 'eredi' },
            passaggi: [{ data: '2026-04-01', tipo: 'successione', etichetta: 'Successione', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Anna Verdi', 'Bruno Verdi'] }],
            scelte: [{ alla_data: '2026-01-01', regola: true, etichetta: 'eredi di Ugo Rossi · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [anna, bruno] }] } }]);

        expect(testoDi('pregressi-che-toccano')).toContain('Va a Anna Verdi e Bruno Verdi, eredi di Ugo Rossi.').not.toContain('titolari al 1 gennaio');
    });

    test('rilievo 3 del secondo scettico — l\'erede già comproprietaria: la riga dice la successione con le parole della regola, non «titolari al 1 gennaio», e «ed» davanti a «Erede»', async () => {
        const elsa75 = { anagrafica_id: 2, nome: 'Elsa Bianchi', quota: 75, importo: 13500 };
        const bruno = { anagrafica_id: 4, nome: 'Erede Bruno', quota: 25, importo: 4500 };
        await monta([{ ...DELL_UNITA, riparto_previsto: { ...DELL_UNITA.riparto_previsto, quote: [elsa75, bruno],
            successione: { defunti: ['Ugo Rossi'], data: '2026-04-01', titolari: ['Ugo Rossi', 'Elsa Bianchi'], arretrato: 'eredi' },
            passaggi: [{ data: '2026-04-01', tipo: 'successione', etichetta: 'Successione', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi', 'Erede Bruno'] }],
            scelte: [{ alla_data: '2026-01-01', regola: true, etichetta: 'con l\'arretrato di Ugo Rossi agli eredi · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [elsa75, bruno] }] } }]);

        expect(testoDi('pregressi-che-toccano')).toContain('Va a Elsa Bianchi ed Erede Bruno, con l\'arretrato di Ugo Rossi agli eredi.').not.toContain('titolari al 1 gennaio');
    });

    test('senza passaggi nell\'arco la riga non c\'è', async () => {
        await monta([SENZA_PASSAGGI, NOMINALE]);
        expect(q('pregressi-che-toccano')).toBeNull();
    });

    // Rilievo 5 dello scettico: la riga arancio stava fra la catena `v-if / v-else-if` e il suo `v-else`, che si legava a lei, e il riquadro
    // «Nessun saldo pregresso» compariva accanto a «Saldi iniziali rilevati» e durante il caricamento.
    const nessunSaldo = () => document.body.textContent!.includes('Nessun saldo pregresso per questa gestione');

    test('rilievo 5 — con i saldi rilevati il riquadro «Nessun saldo pregresso» non c\'è, con o senza la riga arancio', async () => {
        await monta([SENZA_PASSAGGI, NOMINALE]);
        expect(document.body.textContent).toContain('Saldi iniziali rilevati per questa gestione');
        expect(nessunSaldo()).toBe(false);
        pagina!.unmount(); document.body.innerHTML = '';

        await monta([DELL_UNITA]);
        expect(q('pregressi-che-toccano')).not.toBeNull();
        expect(nessunSaldo()).toBe(false);
    });

    test('rilievo 5 — durante il caricamento non c\'è; senza saldi sì', async () => {
        await monta([DELL_UNITA], { inCaricamento: true });
        expect(document.body.textContent).toContain('Caricamento saldi per la gestione selezionata');
        expect(nessunSaldo()).toBe(false);
        pagina!.unmount(); document.body.innerHTML = '';

        await monta([]);
        expect(nessunSaldo()).toBe(true);
    });
});

describe('la finestra e ciò che parte con il piano', () => {
    test('scene 1 e 2 — il badge, il riepilogo in fondo con la regola; scelta Elsa e «Conferma il riparto»: il riepilogo e la riga lo dicono, e parte il giorno', async () => {
        await monta([DELL_UNITA, SENZA_PASSAGGI, NOMINALE]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();

        expect(document.body.querySelectorAll('[data-testid="tocca-un-passaggio"]')).toHaveLength(1);
        expect(testoDi('riepilogo-saldi')).toBe('3 saldi · 1 tocca un passaggio: va a Ugo Rossi, la regola.');
        // Con la regola non parte niente per quel saldo.
        expect(saldiConfigAllInvio()).toEqual([]);

        await clic(q('scelta-2026-04-01')!);
        expect(testoDi('riepilogo-saldi')).toBe('3 saldi · 1 tocca un passaggio: va a Elsa Bianchi, scelta tua.');
        await clic(q('conferma-il-riparto')!);

        expect(q('finestra-riparto-saldi')).toBeNull();
        expect(testoDi('pregressi-che-toccano')).toContain('Va a Elsa Bianchi, scelta tua.');
        expect(saldiConfigAllInvio()).toEqual([{ saldo_id: 11, alla_data: '2026-04-01' }]);
    });

    // Decisione 689 (1): chiudere la finestra non annulla niente, come prima della beta («Chiudi Modale» teneva tutto). Per tornare al
    // riparto automatico c'è la scelta dentro la finestra. La X, Esc e il clic fuori passano tutti da `update:open`.
    test('decisione 689 (1) — scelta Elsa e «Chiudi»: la scelta resta', async () => {
        await monta([DELL_UNITA]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();
        await clic(q('scelta-2026-04-01')!);
        await clic(pulsante('Chiudi'));

        expect(q('finestra-riparto-saldi')).toBeNull();
        expect(testoDi('pregressi-che-toccano')).toContain('Va a Elsa Bianchi, scelta tua.');
        expect(saldiConfigAllInvio()).toEqual([{ saldo_id: 11, alla_data: '2026-04-01' }]);
    });

    /** Il caso della decisione: Int. 3, «Divido a mano», Anna € 120,00 e Bruno € 60,00. */
    const dividiAMano = async () => {
        await clic(q('scelta-manuale')!);
        const saldo = vm().saldiDettaglio.find((x: { id: number }) => x.id === 11);
        saldo.ripartizioni_custom = [{ anagrafica_id: 21, importo: '120,00' }, { anagrafica_id: 22, importo: '60,00' }];
        await flushPromises();
    };
    const ilRipartoAMano = [{ saldo_id: 11, ripartizioni: [{ anagrafica_id: 21, importo: '120,00' }, { anagrafica_id: 22, importo: '60,00' }] }];

    test('decisione 689 (1) — Int. 3 diviso a mano, Anna € 120,00 e Bruno € 60,00, chiuso con la X: il riparto a mano resta e parte con il piano', async () => {
        await monta([DELL_UNITA]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();
        await dividiAMano();
        await clic(document.body.querySelector('[data-slot="dialog-close"]')!);

        expect(q('finestra-riparto-saldi')).toBeNull();
        expect(testoDi('pregressi-che-toccano')).toContain('Lo dividi a mano.');
        expect(saldiConfigAllInvio()).toEqual(ilRipartoAMano);
    });

    test('decisione 689 (1) — lo stesso chiuso con Esc', async () => {
        await monta([DELL_UNITA]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();
        await dividiAMano();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        q('finestra-riparto-saldi')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(q('finestra-riparto-saldi')).toBeNull();
        expect(saldiConfigAllInvio()).toEqual(ilRipartoAMano);
    });

    test('decisione 689 (1) — per tornare al riparto automatico si sceglie la regola dentro la finestra', async () => {
        await monta([DELL_UNITA]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();
        await dividiAMano();
        await clic(q('scelta-2026-01-01')!);
        await clic(document.body.querySelector('[data-slot="dialog-close"]')!);

        expect(saldiConfigAllInvio()).toEqual([]);
    });

    test('«Divido a mano» apre il riparto a mano di sempre, e parte con le righe', async () => {
        await monta([DELL_UNITA]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();
        await clic(q('scelta-manuale')!);

        expect(q('divido-a-mano')).not.toBeNull();
        expect(testoDi('riepilogo-saldi')).toBe('1 saldo · 1 tocca un passaggio: diviso a mano.');
        await clic(q('conferma-il-riparto')!);
        expect(saldiConfigAllInvio()).toEqual([{ saldo_id: 11, ripartizioni: [] }]);
    });

    test('un saldo dell\'unità senza passaggi ha il menu «Automatico / A mano» e l\'anteprima di chi paga; la finestra non ha scritte sotto i 12 px', async () => {
        await monta([SENZA_PASSAGGI]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();

        const finestra = q('finestra-riparto-saldi')!;
        expect(finestra.textContent).toContain('Come si ripartisce');
        expect(finestra.textContent).toContain('A carico di: Proprietario, titolare al 1 gennaio 2026');
        expect(finestra.querySelector('.v-select')).not.toBeNull();
        expect(finestra.innerHTML).not.toMatch(/text-\[(9|10|11)px\]/);
    });

    test('rilievo 8 — il cassetto «Come si ripartiscono i saldi» dice la regola della successione', async () => {
        await monta([SENZA_PASSAGGI]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();

        expect(testoDi('come-si-ripartiscono')).toContain('Con una successione: con l\'arretrato agli eredi vanno agli eredi, per le loro quote; con l\'arretrato a nome del defunto restano al defunto.');
    });

    test('rilievo 8 del secondo scettico — il cassetto dice anche il confine della decisione 691 (1): al defunto solo se è morto dall\'inizio dell\'esercizio prima', async () => {
        await monta([SENZA_PASSAGGI]);
        vm().apriConfigurazioneSaldi();
        await flushPromises();

        expect(testoDi('come-si-ripartiscono')).toContain('Al defunto solo se è morto dall\'inizio dell\'esercizio prima di quello del saldo: con una morte di prima, '
            + 'o con una successione registrata senza la scelta sull\'arretrato, vanno agli eredi.');
    });
});
