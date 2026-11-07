// @vitest-environment jsdom

/**
 * La scelta dell'imputazione nel modulo del nuovo incasso (1.12.0-beta.3, decisioni 217 e 218). Prima di salvare il modulo chiede al server
 * se la distribuzione segue l'ordine delle scadenze (`movimenti-rate.ordine`); se no, sopra «Conferma incasso» dice la rata più vecchia che
 * resta aperta e chiede chi ha scelto, senza niente di già scelto: «Indicata da chi ha versato» o «Scelta da te», con la nota facoltativa
 * per tutte e due (decisione 219). Arrivando dal collegamento del portale «Indicata da chi ha versato» è già scelta. Una risposta per una distribuzione che
 * nel frattempo è cambiata non vale; se la verifica non riesce l'incasso si registra lo stesso e il server lo segna.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const server = vi.hoisted(() => ({ rate: [] as any[], ordine: [] as Array<() => Promise<any>>, chiamate: [] as any[] }));

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => ({ data: { rate: server.rate } })),
        post: vi.fn(async (url: string, dati: any) => {
            if (!url.includes('movimenti-rate.ordine')) throw new Error(`POST inatteso: ${url}`);
            server.chiamate.push(dati);
            const risposta = server.ordine.shift();
            if (!risposta) throw new Error('nessuna risposta preparata');
            return { data: await risposta() };
        }),
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
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

const UGO = 5;
const ORDINARIA = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };
const quota = (residuo: number) => ({ unita: 'Interno 3', anagrafica: 'Ugo', anagrafica_id: UGO, ruolo: 'P', residuo, residuo_originale: residuo, is_credito: false, componente_saldo: 0, componente_spesa: residuo });
const riga = (id: number, padre: number, descrizione: string, residuo: number, scadenza: string, ordine: number) => ({
    id, rata_padre_id: padre, descrizione, residuo, importo_totale: residuo, scadenza_human: scadenza.split('-').reverse().join('/'), scaduta: true, pregresso: false,
    ordine, gestione_id: ORDINARIA.id, gestione: ORDINARIA.nome, intestatario: 'Ugo', intestatari_full: 'Ugo', unita: 'Interno 3', tipologia: 'Aggregato',
    is_credito: false, is_emitted: true, is_published: true, dettaglio_quote: [quota(residuo)],
});
const RATE = [riga(601, 91, 'Rata n.1', 100, '2026-01-05', 1), riga(602, 92, 'Rata n.2', 100, '2026-02-05', 2)];

const FUORI = { esito: 'fuori_ordine', parte_in_piu: false, rata_aperta: { rata_id: 91, numero_rata: 1, descrizione: 'Rata n.1', scadenza: '2026-01-05', emessa: true, gestione: 'Ordinaria 2026', unita: 'Int. 3', resta: 10000 } };
const IN_ORDINE = { esito: 'ordine_scadenze', parte_in_piu: false, rata_aperta: null };
const subito = (r: any) => () => Promise.resolve(r);

async function apri(query = `?prefill_anagrafica_id=${UGO}`) {
    server.rate = RATE;
    window.history.replaceState({}, '', `/gestionale/incassi/nuovo${query}`);
    const wrapper = mount(IncassoRateNew, {
        props: { condominio: { id: 28, nome: 'Demo' }, risorse: [{ id: 2, nome: 'Banca', tipo: 'banca' }], condomini: [{ id: UGO, nome: 'Ugo' }],
            persone: [{ id: UGO, nome: 'Ugo', indirizzo: null, codice_fiscale: null, ha_rate: true }], immobili: [], gestioni: [ORDINARIA] },
        global: { stubs, mocks },
    });
    await flushPromises();
    await flushPromises();
    return wrapper;
}

const vm = (w: any) => w.vm as any;
const conferma = (w: any) => w.findAll('button').find((b: any) => b.text().includes('Conferma incasso'));
const blocco = (w: any) => w.find('[data-testid="scelta-imputazione"]');

/** Paga a mano la sola rata 2 (fuori ordine) e chiede l'esito come lo chiede il modulo, senza aspettare. */
async function pagaLaRata2(w: any) {
    vm(w).mode = 'manual';
    vm(w).rateList[1].da_pagare = 100;
    vm(w).rateList[1].selezionata = true;
    vm(w).form.importo_totale = 100;
    vm(w).syncForm();
    await flushPromises();
}

afterEach(() => {
    server.ordine = [];
    server.chiamate = [];
    vi.useRealTimers();
});

describe('la scelta quando la distribuzione si discosta', () => {
    test('il server dice fuori ordine: la rata che resta aperta, nessuna scelta fatta, e non si conferma', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        server.ordine.push(subito(FUORI));
        await vm(w).verificaOrdine();
        await flushPromises();

        expect(server.chiamate.at(-1).dettaglio_pagamenti).toEqual([{ rata_id: 602, importo: 100 }]);
        expect(blocco(w).exists()).toBe(true);
        expect(blocco(w).text().replace(/\u00a0/g, ' ')).toContain('resta aperta Rata n.1 (scadenza 05/01/2026, Int. 3, Ordinaria 2026) per € 100,00.');
        expect(vm(w).form.imputazione_scelta).toBeNull();
        expect(conferma(w).attributes('disabled')).toBeDefined();

        // «Scelta da te» basta da sola: la nota è facoltativa (decisione 219).
        await blocco(w).find('input[type="radio"][value="scelta_amministratore"]').setValue(true);
        expect(conferma(w).attributes('disabled')).toBeUndefined();
    });

    test('cambiata la scelta il campo della nota si svuota; sotto il campo, quale nota va sulla ricevuta', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        server.ordine.push(subito(FUORI));
        await vm(w).verificaOrdine();
        await flushPromises();

        await blocco(w).find('input[type="radio"][value="scelta_amministratore"]').setValue(true);
        expect(blocco(w).find('input[data-testid="nota-imputazione"]').attributes('placeholder')).toContain('non va sulla ricevuta');
        expect(blocco(w).text()).toContain('Resta qui: non va sulla ricevuta.');
        await blocco(w).find('input[data-testid="nota-imputazione"]').setValue('Lite in corso con il vicino');

        await blocco(w).find('input[type="radio"][value="indicata_dal_versante"]').setValue(true);
        await flushPromises();

        expect(vm(w).form.imputazione_nota).toBe('');
        expect((blocco(w).find('input[data-testid="nota-imputazione"]').element as HTMLInputElement).value).toBe('');
        expect(blocco(w).find('input[data-testid="nota-imputazione"]').attributes('placeholder')).toContain('va sulla ricevuta');
        expect(blocco(w).text()).toContain('Si stampa sulla ricevuta: scrivi le parole di chi ha versato, non le tue.');
    });

    test('la verifica non riesce: la scelta si mostra lo stesso, facoltativa, e la conferma resta possibile', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        server.ordine.push(() => Promise.reject(new Error('rete')));
        await vm(w).verificaOrdine();
        await flushPromises();

        expect(blocco(w).exists()).toBe(true);
        expect(vm(w).form.imputazione_scelta).toBeNull();
        expect(conferma(w).attributes('disabled')).toBeUndefined();
    });

    test('dal portale «Indicata» vale finché la rata segnalata riceve denaro: spostato il versamento su un\'altra rata, la scelta torna vuota', async () => {
        const w = await apri(`?prefill_anagrafica_id=${UGO}&related_task_id=77&prefill_rata_id=92&prefill_importo=100`);
        await pagaLaRata2(w);
        expect(vm(w).form.imputazione_scelta).toBe('indicata_dal_versante');

        vm(w).mode = 'manual';
        vm(w).rateList[1].da_pagare = 0;
        vm(w).rateList[1].selezionata = false;
        vm(w).rateList[0].da_pagare = 100;
        vm(w).rateList[0].selezionata = true;
        vm(w).syncForm();
        await flushPromises();

        expect(vm(w).form.imputazione_scelta).toBeNull();
        // La verifica che il cambio ha messo in attesa parte qui, non durante il test dopo.
        server.ordine.push(subito(IN_ORDINE));
        await vm(w).verificaOrdine();
    });

    test('secondo l\'ordine: niente da scegliere', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        server.ordine.push(subito(IN_ORDINE));
        await vm(w).verificaOrdine();
        await flushPromises();

        expect(blocco(w).exists()).toBe(false);
        expect(conferma(w).attributes('disabled')).toBeUndefined();
    });

    test('arrivando dal collegamento del portale «Indicata da chi ha versato» è già scelta, con il versamento sulla rata segnalata', async () => {
        const w = await apri(`?prefill_anagrafica_id=${UGO}&related_task_id=77&prefill_rata_id=92&prefill_importo=100`);
        // Il riparto automatico segue l'ordine (la rata 1); il versamento va sulla rata 2, quella che chi ha versato ha segnalato.
        await pagaLaRata2(w);
        server.ordine.push(subito(FUORI));
        await vm(w).verificaOrdine();
        await flushPromises();

        expect(vm(w).form.imputazione_scelta).toBe('indicata_dal_versante');
        expect(blocco(w).find('input[type="radio"][value="indicata_dal_versante"]').element.checked).toBe(true);
        expect(conferma(w).attributes('disabled')).toBeUndefined();
    });

    test('il modulo chiede l\'esito da solo poco dopo che la distribuzione cambia', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        const w = await apri();
        server.ordine.push(subito(FUORI));
        await pagaLaRata2(w);
        vi.advanceTimersByTime(1000);
        await flushPromises();

        expect(server.chiamate.length).toBe(1);
        expect(blocco(w).exists()).toBe(true);
    });

    test('una risposta arrivata per una distribuzione che nel frattempo è cambiata non vale', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        let rispondi: (v: any) => void = () => {};
        server.ordine.push(() => new Promise((r) => { rispondi = r; }));
        const prima = vm(w).verificaOrdine();
        // Cambia la distribuzione: anche la rata 1.
        vm(w).rateList[0].da_pagare = 100;
        vm(w).rateList[0].selezionata = true;
        vm(w).form.importo_totale = 200;
        vm(w).syncForm();
        server.ordine.push(subito(IN_ORDINE));
        await vm(w).verificaOrdine();
        rispondi(FUORI);
        await prima;
        await flushPromises();

        expect(blocco(w).exists()).toBe(false);
        expect(conferma(w).attributes('disabled')).toBeUndefined();
    });
});

describe('alla conferma', () => {
    function intercetta(w: any) {
        const form = vm(w).form;
        const inviati: any[] = [];
        let trasforma: (d: any) => any = (d) => d;
        form.transform = (cb: any) => { trasforma = cb; return form; };
        form.post = vi.fn(() => inviati.push(trasforma(form.data())));
        return inviati;
    }

    test('senza una risposta per la distribuzione a schermo, la chiede prima di inviare; fuori ordine e senza scelta non invia', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        const inviati = intercetta(w);
        server.ordine.push(subito(FUORI));

        await vm(w).submit();
        await flushPromises();

        expect(server.chiamate.length).toBe(1);
        expect(inviati).toEqual([]);
        expect(blocco(w).exists()).toBe(true);
    });

    test('con la scelta fatta invia la dichiarazione e la nota', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        const inviati = intercetta(w);
        server.ordine.push(subito(FUORI));
        await vm(w).verificaOrdine();
        vm(w).form.imputazione_scelta = 'indicata_dal_versante';
        vm(w).form.imputazione_nota = 'Causale: rata di febbraio';

        await vm(w).submit();

        expect(inviati.length).toBe(1);
        expect(inviati[0]).toMatchObject({ imputazione_scelta: 'indicata_dal_versante', imputazione_nota: 'Causale: rata di febbraio' });
    });

    test('secondo l\'ordine una scelta rimasta indietro non parte', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        const inviati = intercetta(w);
        vm(w).form.imputazione_scelta = 'scelta_amministratore';
        vm(w).form.imputazione_nota = 'Vecchia';
        server.ordine.push(subito(IN_ORDINE));

        await vm(w).submit();

        expect(inviati.length).toBe(1);
        expect(inviati[0]).toMatchObject({ imputazione_scelta: null, imputazione_nota: null });
    });

    test('se la verifica non riesce lo dice, e l\'incasso parte lo stesso: lo segna il server', async () => {
        const w = await apri();
        await pagaLaRata2(w);
        const inviati = intercetta(w);
        server.ordine.push(() => Promise.reject(new Error('rete')));

        await vm(w).submit();
        await flushPromises();

        expect(inviati.length).toBe(1);
        expect(w.text()).toContain('Non è stato possibile verificare l\'ordine delle scadenze');
    });
});
