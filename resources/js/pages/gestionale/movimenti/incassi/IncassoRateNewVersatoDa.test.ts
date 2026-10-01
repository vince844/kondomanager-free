// @vitest-environment jsdom

/**
 * «Versato da» nel modulo del nuovo incasso (Coda 167, 1.11.0-beta.40, decisioni 30.7 e 30.8).
 *
 * Due scelte che il programma non può fare da solo, perché dipendono da un accordo fra le persone:
 *
 * - **il credito della posizione** (30.7): Ugo deve € 300 e ha € 100 di credito, Elsa versa per lui. Il credito resta a
 *   Ugo (prima i soldi versati) o si usa adesso (prima il credito, e la parte in più va a Elsa)? La domanda compare appena
 *   Ugo ha un credito, senza niente di già scelto; arrivando da un link il credito non entra più da solo.
 * - **la rata della parte in più** (30.8): su quale rata di Elsa va. Proposta la prima ancora da pagare della gestione
 *   dell'incasso; un'altra gestione solo scegliendola; senza rate lì, niente di già scelto.
 *
 * Nasce dal reperto R1 della Fase 1-bis: arrivando da un link il modulo usava il credito per primo e prometteva la parte
 * in più a Elsa, mentre il server — prima il contante — la lasciava a Ugo. Il montaggio segue
 * `IncassoRateNewCompensazioneMista.test.ts`: il denaro è in euro decimali, come lo manda il server.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const server = vi.hoisted(() => ({ rate: [] as any[], rateVersatoDa: [] as any[] }));

// Due richieste: la situazione debitoria della posizione e le rate di chi ha versato.
vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url: string) => ({
            data: { rate: url.includes('rate-di-chi-ha-versato') ? server.rateVersatoDa : server.rate },
        })),
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import IncassoRateNew from './IncassoRateNew.vue';

(globalThis as any).route = (name: string) => `/${name}`;
const mocks = { route: (name: string) => `/${name}` };

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const ORDINARIA = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };
const UGO = 5;
const ELSA = 9;
const PAOLO = 11;

const quota = (anagrafica_id: number, residuo: number) => ({
    unita: 'Interno 3', anagrafica: 'Ugo Venditore', anagrafica_id, ruolo: 'P', residuo, residuo_originale: residuo,
    is_credito: residuo < 0, componente_saldo: residuo < 0 ? residuo : 0, componente_spesa: residuo > 0 ? residuo : 0,
});

const riga = (id: number, padre: number, descrizione: string, residuo: number, scadenza: string) => ({
    id, rata_padre_id: padre, descrizione, residuo, importo_totale: Math.abs(residuo), data_scadenza: scadenza,
    scadenza_human: scadenza, gestione_id: ORDINARIA.id, gestione: ORDINARIA.nome, intestatario: 'Ugo Venditore',
    intestatari_full: 'Ugo Venditore', unita: 'Interno 3', tipologia: 'Appartamento', is_credito: residuo < 0,
    is_emitted: true, is_published: true, dettaglio_quote: [quota(UGO, residuo)],
});

/** Il credito di Ugo (€ 100) e il suo debito sulla rata 3 (€ 300), nella stessa gestione. */
const CREDITO_UGO = riga(501, 90, 'Saldo iniziale', -100, '2026-01-01');
const DEBITO_UGO = riga(601, 91, 'Rata n.3', 300, '2026-03-31');

const rataDiElsa = (id: number, numero: number, scadenza: string, residuo: number, gestione = ORDINARIA) => ({
    id, numero_rata: numero, piano: 'Piano 2026', gestione_id: gestione.id, gestione: gestione.nome, scadenza,
    residuo, pagata: residuo === 0,
});

const PERSONE = [
    { id: UGO, nome: 'Ugo Venditore', indirizzo: null, codice_fiscale: null, ha_rate: true },
    { id: ELSA, nome: 'Elsa Acquirente', indirizzo: null, codice_fiscale: null, ha_rate: true },
    { id: PAOLO, nome: 'Paolo Figlio', indirizzo: null, codice_fiscale: null, ha_rate: false },
];

async function apri(rate: any[], query: string, rateVersatoDa: any[] = []) {
    server.rate = rate;
    server.rateVersatoDa = rateVersatoDa;
    window.history.replaceState({}, '', `/gestionale/incassi/nuovo${query}`);

    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo' },
            risorse: [{ id: 2, nome: 'Banca', tipo: 'banca' }],
            condomini: [{ id: UGO, nome: 'Ugo Venditore' }],
            persone: PERSONE,
            immobili: [],
            gestioni: [ORDINARIA],
        },
        global: { stubs, mocks },
    });

    await flushPromises();
    await flushPromises();

    return wrapper;
}

async function versatoDa(wrapper: any, id: number | null) {
    (wrapper.vm as any).form.versato_da_id = id;
    await flushPromises();
    await flushPromises();
}

const form = (wrapper: any) => (wrapper.vm as any).form;
const conferma = (wrapper: any) => wrapper.findAll('button').find((b: any) => b.text().includes('Conferma incasso'));
const bottone = (wrapper: any, testo: string) => {
    const b = wrapper.findAll('button').find((x: any) => x.text().includes(testo));
    if (!b) throw new Error(`Nessun pulsante «${testo}».`);
    return b;
};

const DAL_LINK = `?prefill_anagrafica_id=${UGO}&prefill_rata_id=91&prefill_importo=300`;

describe('il credito della posizione con «Versato da» (30.7)', () => {
    test('arrivando dal link il credito non entra da solo: si aspetta la scelta, e non si conferma', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK);
        await versatoDa(wrapper, ELSA);

        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(false);
        expect(form(wrapper).credito_prima).toBeNull();
        expect(wrapper.text()).toContain('Resta a Ugo Venditore');
        expect(wrapper.text()).toContain('Si usa adesso');
        // Nessuna promessa sulla parte in più finché la scelta manca: con i soldi prima, non ce n'è.
        expect(wrapper.text()).not.toContain('La parte in più');
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();
    });

    test('«Si usa adesso»: prima il credito, il resto coi soldi versati, e la parte in più va su una rata di Elsa', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK, [
            rataDiElsa(701, 1, '31/01/2026', 0),
            rataDiElsa(705, 5, '31/05/2026', 300),
            rataDiElsa(707, 7, '31/07/2026', 300),
        ]);
        await versatoDa(wrapper, ELSA);

        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).credito_prima).toBe(true);
        expect(form(wrapper).dettaglio_pagamenti).toEqual([
            { rata_id: 501, importo: -100 },
            { rata_id: 601, importo: 300 },
        ]);
        expect(form(wrapper).eccedenza).toBe(100);
        // La prima ancora da pagare della gestione dell'incasso, non la più lontana.
        expect(form(wrapper).quota_parte_in_piu_id).toBe(705);
        expect(wrapper.text()).toContain('La parte in più');
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('«Resta a Ugo»: il debito si paga coi soldi versati, il credito non si tocca, niente parte in più', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);

        await bottone(wrapper, 'Resta a Ugo Venditore').trigger('click');
        await flushPromises();

        expect(form(wrapper).credito_prima).toBe(false);
        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }]);
        expect(form(wrapper).eccedenza).toBe(0);
        expect(form(wrapper).quota_parte_in_piu_id).toBeNull();
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('senza «Versato da» il link usa il credito come sempre', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK);

        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.rata_id === 501 && p.importo === -100)).toBe(true);
        expect(wrapper.text()).not.toContain('Si usa adesso');
    });

    test('cambiando chi ha versato la scelta torna da fare', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        await versatoDa(wrapper, PAOLO);

        expect(form(wrapper).credito_prima).toBeNull();
        expect(form(wrapper).quota_parte_in_piu_id).toBeNull();
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();
    });
});

describe('«si usa adesso» fuori dal link (30.7)', () => {
    const SENZA_LINK = `?prefill_anagrafica_id=${UGO}&prefill_importo=300`;
    const RATE_ELSA = [rataDiElsa(705, 5, '31/05/2026', 300)];

    test('in automatico il credito della stessa gestione entra da sé, prima dei soldi versati', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], SENZA_LINK, RATE_ELSA);
        await versatoDa(wrapper, ELSA);
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(false);

        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([
            { rata_id: 501, importo: -100 },
            { rata_id: 601, importo: 300 },
        ]);
        expect(form(wrapper).eccedenza).toBe(100);
        expect(form(wrapper).quota_parte_in_piu_id).toBe(705);
    });

    test('tornando a «Resta» il credito incluso da «si usa adesso» esce, anche se c\'è altro debito da coprire', async () => {
        // Trovato a video il 01/10/2026 sul condominio 159: Ugo aveva altre rate da pagare oltre a quella coperta dai soldi
        // di Elsa, e dopo «si usa adesso» → «resta» il credito restava applicato su quelle. «Resta a Ugo» deve dire il
        // vero: il credito si usa solo se l'amministratore lo clicca.
        const DEBITO_UGO_4 = riga(604, 94, 'Rata n.4', 300, '2026-04-30');
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], SENZA_LINK, RATE_ELSA);
        await versatoDa(wrapper, ELSA);

        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.rata_id === 501 && p.importo === -100)).toBe(true);

        await bottone(wrapper, 'Resta a Ugo Venditore').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(false);
        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }]);
    });

    test('il credito cliccato a mano con «Usa credito» resta una scelta dell\'amministratore, anche con «Resta»', async () => {
        const DEBITO_UGO_4 = riga(604, 94, 'Rata n.4', 300, '2026-04-30');
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], SENZA_LINK, RATE_ELSA);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Resta a Ugo Venditore').trigger('click');
        await flushPromises();

        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();

        // Prima i soldi versati, poi il credito sullo scoperto: è il motore di sempre.
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.rata_id === 501 && p.importo === -100)).toBe(true);
        expect(form(wrapper).credito_prima).toBe(false);
    });

    test('il credito di un\'altra gestione non entra da sé: resta a clic, con la sua conferma', async () => {
        const creditoAltrove = { ...CREDITO_UGO, gestione_id: 7, gestione: 'Tetto 2026' };
        const wrapper = await apri([creditoAltrove, DEBITO_UGO], SENZA_LINK, RATE_ELSA);
        await versatoDa(wrapper, ELSA);

        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }]);
        expect(form(wrapper).eccedenza).toBe(0);
    });

    test('in manuale, con «si usa adesso», «Usa credito» usa il credito anche se i soldi versati bastano', async () => {
        // Senza la scelta, quel clic direbbe «il contante copre già tutto» e non farebbe niente: con «si usa adesso»
        // l'amministratore ha appena detto il contrario.
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], SENZA_LINK, RATE_ELSA);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        const interruttore = wrapper.findAll('div').find((d: any) => d.classes().includes('cursor-pointer') && d.text().includes('Manuale'));
        await interruttore!.trigger('click');
        await flushPromises();
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(false);

        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.rata_id === 501 && p.importo === -100)).toBe(true);
        expect(form(wrapper).eccedenza).toBe(100);
    });
});

describe('la rata della parte in più (30.8)', () => {
    const SOLO_DEBITO = `?prefill_anagrafica_id=${UGO}&prefill_importo=350`;

    test('chi ha versato senza rate qui: si avvisa e non si conferma', async () => {
        const wrapper = await apri([DEBITO_UGO], SOLO_DEBITO);
        await versatoDa(wrapper, PAOLO);

        expect(form(wrapper).eccedenza).toBe(50);
        expect(wrapper.text()).toContain('Paolo Figlio non ha rate emesse in questo condominio');
        // Spento per la ragione giusta: non c'è una rata da scegliere, c'è da registrare solo il debito.
        expect(wrapper.text()).toContain('Registra solo il debito: Paolo Figlio non ha rate qui');
        expect(wrapper.text()).not.toContain('Scegli su quale rata di Paolo Figlio');
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();
    });

    test('con rate solo in un\'altra gestione niente è già scelto; scegliendone una si conferma, e lo si dice', async () => {
        const tetto = { id: 7, nome: 'Tetto 2026', tipo: 'straordinaria' };
        const wrapper = await apri([DEBITO_UGO], SOLO_DEBITO, [rataDiElsa(731, 1, '30/09/2026', 500, tetto)]);
        await versatoDa(wrapper, ELSA);

        expect(form(wrapper).quota_parte_in_piu_id).toBeNull();
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();

        form(wrapper).quota_parte_in_piu_id = 731;
        await flushPromises();

        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('È una rata della gestione Tetto 2026: la parte in più passa a quella gestione.');
    });
});

describe('senza denaro non c\'è chi ha versato (R13)', () => {
    test('con importo zero il modulo non promette nessuna traccia, e il campo non parte', async () => {
        const wrapper = await apri([DEBITO_UGO], `?prefill_anagrafica_id=${UGO}`);
        await versatoDa(wrapper, ELSA);

        expect(wrapper.text()).not.toContain('diranno chi ha versato');
    });
});

/*
 * Rigiro della Fase 1-bis (01/10/2026): S4, S5, S6 e le decisioni 30.11–30.13 nel modulo.
 */
const TETTO = { id: 7, nome: 'Tetto 2026', tipo: 'straordinaria' };
const rigaDi = (gestione: { id: number; nome: string }, id: number, padre: number, descrizione: string, residuo: number, scadenza: string) =>
    ({ ...riga(id, padre, descrizione, residuo, scadenza), gestione_id: gestione.id, gestione: gestione.nome });

describe('S4: dal link, con «Versato da», si paga dalla rata più vecchia e non solo quella del link', () => {
    const DEBITO_UGO_4 = riga(602, 92, 'Rata n.4', 200, '2026-04-30');
    const LINK_500 = `?prefill_anagrafica_id=${UGO}&prefill_rata_id=91&prefill_importo=500`;

    test('«Si usa adesso»: credito per primo, poi la rata 3 e la rata 4, e la parte in più è solo quello che avanza', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], LINK_500, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([
            { rata_id: 501, importo: -100 },
            { rata_id: 601, importo: 300 },
            { rata_id: 602, importo: 200 },
        ]);
        expect(form(wrapper).eccedenza).toBe(100);
        expect(form(wrapper).quota_parte_in_piu_id).toBe(705);
    });

    test('«Resta» poi «Usa credito»: i soldi bastano, il credito non si usa e nessuna parte in più (30.13)', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], LINK_500, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Resta a Ugo Venditore').trigger('click');
        await flushPromises();
        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }, { rata_id: 602, importo: 200 }]);
        expect(form(wrapper).eccedenza).toBe(0);
        expect(form(wrapper).quota_parte_in_piu_id).toBeNull();
    });
});

describe('S5 e 30.11: il credito per gestione', () => {
    const SENZA_LINK_300 = `?prefill_anagrafica_id=${UGO}&prefill_importo=300`;

    test('S5: la prima rata è del tetto, ma il credito ordinario copre l\'ordinaria con «Si usa adesso»', async () => {
        const tetto50 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 50, '2026-01-31');
        const wrapper = await apri([CREDITO_UGO, tetto50, DEBITO_UGO], SENZA_LINK_300, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([
            { rata_id: 501, importo: -100 },
            { rata_id: 901, importo: 50 },
            { rata_id: 601, importo: 300 },
        ]);
        expect(form(wrapper).eccedenza).toBe(50);
        expect(wrapper.text()).not.toContain('Il credito passa di gestione?');
    });

    test('30.11: il credito dell\'ordinaria potrebbe coprire il tetto: la scelta compare, senza niente di già scelto', async () => {
        const ord50 = riga(601, 91, 'Rata n.3', 50, '2026-03-31');
        const tetto300 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 300, '2026-06-30');
        const wrapper = await apri([CREDITO_UGO, ord50, tetto300], SENZA_LINK_300, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();
        // La gestione dell'incasso va scelta anche lei: le rate pagate sono di due gestioni (30.12).
        form(wrapper).gestione_incasso_id = ORDINARIA.id;
        await flushPromises();

        expect(wrapper.text()).toContain('Il credito passa di gestione?');
        expect(form(wrapper).credito_fra_gestioni).toBeNull();
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();

        await bottone(wrapper, 'Solo sulla sua gestione').trigger('click');
        await flushPromises();
        expect(form(wrapper).dettaglio_pagamenti).toContainEqual({ rata_id: 501, importo: -50 });
        expect(form(wrapper).eccedenza).toBe(0);
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();

        await bottone(wrapper, 'Anche sulle altre gestioni').trigger('click');
        await flushPromises();
        expect(form(wrapper).dettaglio_pagamenti).toContainEqual({ rata_id: 501, importo: -100 });
        expect(form(wrapper).eccedenza).toBe(50);
        expect(form(wrapper).credito_fra_gestioni).toBe(true);
    });
});

describe('30.12: la gestione dell\'incasso', () => {
    test('rate di due gestioni e filtro vuoto: la gestione si sceglie, e finché manca non si conferma', async () => {
        const tetto300 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 300, '2026-06-30');
        const wrapper = await apri([DEBITO_UGO, tetto300], `?prefill_anagrafica_id=${UGO}&prefill_importo=600`);

        expect(wrapper.text()).toContain('Gestione dell\'incasso');
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Questo incasso paga rate di più gestioni: scegli a quale gestione va.');
        // Il filtro vuoto non è più «automatico» (T9): con rate di più gestioni la gestione si sceglie.
        expect(wrapper.html()).toContain('placeholder="Tutte"');

        form(wrapper).gestione_incasso_id = TETTO.id;
        await flushPromises();

        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('la gestione scelta parte al server, e la proposta della parte in più la segue (30.12, 30.8)', async () => {
        const tetto300 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 300, '2026-06-30');
        const wrapper = await apri([DEBITO_UGO, tetto300], `?prefill_anagrafica_id=${UGO}&prefill_importo=650`, [
            rataDiElsa(705, 5, '31/05/2026', 300),
            rataDiElsa(731, 1, '30/09/2026', 500, TETTO),
        ]);
        await versatoDa(wrapper, ELSA);
        expect((wrapper.vm as any).gestioneDaMandare).toBeNull();

        form(wrapper).gestione_incasso_id = TETTO.id;
        await flushPromises();

        expect((wrapper.vm as any).gestioneDaMandare).toBe(TETTO.id);
        expect(form(wrapper).quota_parte_in_piu_id).toBe(731);
    });

    test('denaro sull\'ordinaria e credito del tetto sulla rata del tetto: contano anche le rate del credito, si chiede (T4)', async () => {
        // La gestione dell'incasso intesta anche la compensazione: una rata coperta solo dal credito conta come le
        // altre. Senza «Versato da», con «Usa credito» sul credito del tetto.
        const creditoTetto = rigaDi(TETTO, 801, 94, 'Saldo iniziale tetto', -100, '2026-01-01');
        const tetto100 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 100, '2026-06-30');
        const wrapper = await apri([creditoTetto, DEBITO_UGO, tetto100], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);
        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([
            { rata_id: 801, importo: -100 }, { rata_id: 601, importo: 300 }, { rata_id: 901, importo: 100 },
        ]);
        expect((wrapper.vm as any).gestioniPagate.map((g: any) => g.nome)).toEqual([ORDINARIA.nome, TETTO.nome]);
        expect(wrapper.text()).toContain('Gestione dell\'incasso');
        expect(wrapper.text()).toContain('Questo incasso paga rate di più gestioni: scegli a quale gestione va.');
        expect(conferma(wrapper).attributes('disabled')).toBeDefined();

        form(wrapper).gestione_incasso_id = TETTO.id;
        await flushPromises();

        expect((wrapper.vm as any).gestioneDaMandare).toBe(TETTO.id);
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('rate di una gestione sola: nessuna domanda', async () => {
        const wrapper = await apri([DEBITO_UGO], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);

        expect(wrapper.text()).not.toContain('Gestione dell\'incasso');
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });
});

describe('S6: centesimi interi', () => {
    test('credito € 32,10, debito € 100,14, versati € 68,04 con «Si usa adesso»: nessuna parte in più fantasma', async () => {
        const credito = riga(501, 90, 'Saldo iniziale', -32.1, '2026-01-01');
        const debito = riga(601, 91, 'Rata n.3', 100.14, '2026-03-31');
        const wrapper = await apri([credito, debito], `?prefill_anagrafica_id=${UGO}&prefill_rata_id=91&prefill_importo=68.04`);
        await versatoDa(wrapper, PAOLO);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 501, importo: -32.1 }, { rata_id: 601, importo: 100.14 }]);
        expect(form(wrapper).eccedenza).toBe(0);
        expect(wrapper.text()).not.toContain('Registra solo il debito');
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });
});

describe('dal link senza credito, il comportamento di sempre', () => {
    test('i soldi oltre la rata del link coprono le altre rate, non diventano parte in più', async () => {
        const DEBITO_UGO_4 = riga(602, 92, 'Rata n.4', 200, '2026-04-30');
        const wrapper = await apri([DEBITO_UGO, DEBITO_UGO_4], `?prefill_anagrafica_id=${UGO}&prefill_rata_id=91&prefill_importo=500`);

        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }, { rata_id: 602, importo: 200 }]);
        expect(form(wrapper).eccedenza).toBe(0);
    });
});

describe('T5 e T6: le scelte non restano appese', () => {
    test('T5: togliendo «Versato da» il credito incluso da «Si usa adesso» esce', async () => {
        const DEBITO_UGO_4 = riga(602, 92, 'Rata n.4', 300, '2026-04-30');
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(true);

        await versatoDa(wrapper, null);

        expect(form(wrapper).dettaglio_pagamenti).toEqual([{ rata_id: 601, importo: 300 }]);
        expect(wrapper.text()).not.toContain('Credito applicato');
    });

    test('T5, dal link: togliendo «Versato da» si torna al modulo di apertura', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], DAL_LINK, [rataDiElsa(705, 5, '31/05/2026', 300)]);
        const apertura = JSON.parse(JSON.stringify(form(wrapper).dettaglio_pagamenti));
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Si usa adesso').trigger('click');
        await flushPromises();

        await versatoDa(wrapper, null);

        expect(form(wrapper).dettaglio_pagamenti).toEqual(apertura);
    });

    test('T5, il controcaso: il credito cliccato a mano con «Usa credito» resta, togliendo «Versato da»', async () => {
        const DEBITO_UGO_4 = riga(602, 92, 'Rata n.4', 300, '2026-04-30');
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO, DEBITO_UGO_4], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);
        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();
        const conCredito = JSON.parse(JSON.stringify(form(wrapper).dettaglio_pagamenti));
        expect(conCredito.some((p: any) => p.importo < 0)).toBe(true);

        await versatoDa(wrapper, ELSA);
        await versatoDa(wrapper, null);

        expect(form(wrapper).dettaglio_pagamenti).toEqual(conCredito);
    });

    test('T6: in manuale la domanda fra gestioni non resta appesa, e la conferma si accende', async () => {
        const ord50 = riga(601, 91, 'Rata n.3', 50, '2026-03-31');
        const tetto300 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 300, '2026-06-30');
        const wrapper = await apri([CREDITO_UGO, ord50, tetto300], `?prefill_anagrafica_id=${UGO}&prefill_importo=200`);
        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Il credito passa di gestione?');
        await bottone(wrapper, 'Anche sulle altre').trigger('click');
        await flushPromises();
        expect(form(wrapper).credito_fra_gestioni).toBe(true);

        const interruttore = wrapper.findAll('div').find((d: any) => d.classes().includes('cursor-pointer') && d.text().includes('Manuale'));
        await interruttore!.trigger('click');
        await flushPromises();
        form(wrapper).gestione_incasso_id = ORDINARIA.id;
        await flushPromises();

        expect(wrapper.text()).not.toContain('Il credito passa di gestione?');
        expect(form(wrapper).credito_fra_gestioni).toBeNull();
        expect(form(wrapper).dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(false);
        expect(conferma(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('T6: in manuale, cambiando persona verso chi non ha crediti, la domanda fra gestioni non la segue', async () => {
        const ord50 = riga(601, 91, 'Rata n.3', 50, '2026-03-31');
        const tetto300 = rigaDi(TETTO, 901, 95, 'Tetto rata 1', 300, '2026-06-30');
        const wrapper = await apri([CREDITO_UGO, ord50, tetto300], `?prefill_anagrafica_id=${UGO}&prefill_importo=200`);
        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Il credito passa di gestione?');
        const interruttore = wrapper.findAll('div').find((d: any) => d.classes().includes('cursor-pointer') && d.text().includes('Manuale'));
        await interruttore!.trigger('click');
        await flushPromises();

        server.rate = [{ ...riga(701, 91, 'Rata n.3', 200, '2026-03-31'), intestatario: 'Elsa Acquirente', dettaglio_quote: [quota(ELSA, 200)] }];
        form(wrapper).pagante_id = ELSA;
        await flushPromises();
        await flushPromises();

        expect(wrapper.text()).not.toContain('Il credito passa di gestione?');
        expect(wrapper.text()).not.toContain('Scegli se il credito resta sulla sua gestione');
        expect(form(wrapper).credito_fra_gestioni).toBeNull();
    });
});

describe('T8 e T10: i testi dicono il vero', () => {
    test('T8: con credito anche in una gestione senza rate in elenco, la domanda dice quanto entra con «Si usa adesso»', async () => {
        const creditoTetto = rigaDi(TETTO, 801, 94, 'Saldo iniziale tetto', -200, '2026-01-01');
        const wrapper = await apri([CREDITO_UGO, creditoTetto, DEBITO_UGO], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);
        await versatoDa(wrapper, ELSA);

        expect(wrapper.text()).toMatch(/€\s300,00 di credito/);
        expect(wrapper.text()).toMatch(/«Si usa adesso» include solo il credito delle gestioni che hanno rate in elenco: €\s100,00\. Il resto resta suo\./);
    });

    test('T8: con il credito tutto nelle gestioni con rate, nessuna riga in più', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);
        await versatoDa(wrapper, ELSA);

        expect(wrapper.text()).toContain('si usa adesso o resta suo?');
        expect(wrapper.text()).not.toContain('include solo il credito');
    });

    test('T10: con «Versato da» «Nulla da compensare» indica «Si usa adesso», non di abbassare i soldi versati', async () => {
        const wrapper = await apri([CREDITO_UGO, DEBITO_UGO], `?prefill_anagrafica_id=${UGO}&prefill_importo=300`);
        await versatoDa(wrapper, ELSA);
        await bottone(wrapper, 'Resta a Ugo').trigger('click');
        await flushPromises();
        const interruttore = wrapper.findAll('div').find((d: any) => d.classes().includes('cursor-pointer') && d.text().includes('Manuale'));
        await interruttore!.trigger('click');
        await flushPromises();

        await bottone(wrapper, 'Usa credito').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Nulla da compensare');
        expect(wrapper.text()).toContain('Per usarlo prima dei soldi versati scegli «Si usa adesso».');
        expect(wrapper.text()).not.toContain('Abbassa l\'importo versato');
    });
});
