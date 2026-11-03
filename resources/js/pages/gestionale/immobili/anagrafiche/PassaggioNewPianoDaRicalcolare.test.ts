// @vitest-environment jsdom

/**
 * 1.11.0-beta.51, §6.3 — il modulo «Registra passaggio» (`PassaggioNew.vue`) quando l'anteprima dice «Ricalcola prima il piano…».
 *
 * Il conguaglio che si scrive prenderebbe un piano stantio e fermo (un passaggio annullato dopo il ricalcolo, poi un incasso su una sua
 * quota): il server risponde all'anteprima con l'avviso `rate.piano_da_ricalcolare` e rifiuta la registrazione. Il pannello resta, con la sua
 * via d'uscita; il modulo deve tenerla raggiungibile e farla valere:
 *
 * - la vendita: la casella «Le parti hanno regolato il conguaglio fra loro» e la sua ragione. L'anteprima riparte con la rinuncia, ma solo
 *   quando la ragione ha almeno dieci caratteri (il server la pretende, e un corpo con la rinuncia senza ragione tornerebbe 422 e farebbe
 *   sparire il pannello): finché è più corta, la rinuncia non parte e l'avviso resta;
 * - la fine di una locazione: le due voci. «Scrivi» lascia l'avviso e il pannello, e il corpo continua a portare la scelta (nessuna richiesta
 *   in più a ogni risposta); «Non scriverlo» lo toglie;
 * - finché l'avviso c'è, «Registra passaggio» è spento, con la ragione scritta sotto.
 *
 * Si monta la pagina con `shallowMount`, ma il pannello è vero (`AnteprimaPassaggio`, con la casella e il campo della ragione): è lì che sta
 * la via d'uscita. Il debounce di 350 ms si fa avanzare con i timer finti; le risposte del server sono oggetti nuovi a ogni richiesta, e
 * dipendono dal corpo: l'avviso c'è se il conguaglio si scrive, non c'è se la rinuncia o «Non scriverlo» sono nel corpo.
 *
 * Cosa NON copre: il server (l'avviso, la registrazione che si rifiuta: `Beta51PianoRatePassaggioAnnullatoTest`); la successione e
 * l'usufrutto (stesso pannello, stessa regola del corpo); il modo scuro e le larghezze.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import axios from 'axios';
import VueDatePicker from '@vuepic/vue-datepicker';
import vSelect from 'vue-select';

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
const AnteprimaPassaggio = (await import('@/components/gestionale/immobili/AnteprimaPassaggio.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    // Solo i timer del debounce: `flushPromises` usa `setImmediate`, che resta vero.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    post.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const AVVISO = 'Ricalcola prima il piano «Preventivo 2026», poi registra questo passaggio: le sue quote sono state calcolate con la titolarità del passaggio di Ugo su Interno A, dal 1 maggio 2026, che poi è stato annullato, e il conguaglio di questo passaggio le prenderebbe così come sono, intestate a chi non è più titolare. Oggi il ricalcolo si rifiuta perché il piano ha un incasso su una sua quota. Per procedere: annulla quel movimento; ricalcola il piano; registra di nuovo quel movimento.';
const RAGIONE = 'Le parti hanno regolato il conguaglio fra loro nel rogito';

const riga = (id: number, persona: number, nome: string, tipologia: string) => ({ id, anagrafica: { id: persona, nome, codice_fiscale: null }, tipologia, quota: 100, data_inizio: '2019-01-01', data_fine: null });
const UGO = riga(10, 1, 'Ugo', 'proprietario');
const INES = riga(11, 2, 'Ines', 'inquilino');
const ELSA = { id: 4, nome: 'Elsa', codice_fiscale: null, indirizzo: 'Via Roma 1' };

/** Il pannello come lo manda il server: la coppia di € 1,78 a Elsa è proposta, e l'avviso c'è se il conguaglio si scrive. */
function pannello(avviso: string | null) {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: null, entrante_dal: '', frase: '' },
        anagrafica: { frasi: [], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse: [], piani_distinti: 1, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 1, anagrafica_entrante_id: 4, quote: [], per_gestione: [],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 7, esercizio_id: 1, importo: 178, importo_formattato: '€ 1,78', anagrafica_entrante_id: 4, entrante_nome: 'Elsa' }],
                totale_entrante: 178, totale_entrante_formattato: '€ 1,78', totale_entrante_assoluto_formattato: '€ 1,78', riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            piano_da_ricalcolare: avviso, frasi: [], frasi_del_conguaglio: [], scelta_del_conguaglio: false, fermata_coinquilino: [],
        },
        obbligati: { frasi: [], copia_autentica_mancante: false },
        invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    };
}

function monta(props: Record<string, unknown>): Pagina {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno A' } as never,
            tipo: 'vendita',
            rigaPreselezionata: 10,
            titolari: [UGO],
            anagrafiche: [ELSA],
            pertinenze: [],
            rateEmesseCount: 6,
            ...props,
        },
        // Il pannello è vero, con la casella e il campo della ragione: la via d'uscita sta lì.
        global: { renderStubDefaultSlot: true, stubs: { AnteprimaPassaggio: false, Checkbox: false, CheckboxRoot: false, CheckboxIndicator: false, Primitive: false, Input: false, FraseConImporti: false }, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

/** Il debounce scade e le risposte già date arrivano. */
async function lasciaRispondere(volte = 1) {
    for (let i = 0; i < volte; i++) {
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();
    }
}

function ultimoCorpo(): Record<string, unknown> {
    const chiamate = post.mock.calls;
    if (chiamate.length === 0) throw new Error('Il pannello non ha mandato nessuna richiesta.');

    return chiamate[chiamate.length - 1][1] as Record<string, unknown>;
}

function pulsanteSpento(w: Pagina): boolean {
    const pulsante = w.findAll('[type="submit"]').find((b) => b.text().includes('Registra passaggio'));
    if (!pulsante) throw new Error('Il pulsante «Registra passaggio» non c\'è.');
    const d = pulsante.attributes('disabled');

    return d !== undefined && d !== 'false';
}

/** Il pannello (la colonna a destra), non il resto della pagina. */
const pannelloDi = (w: Pagina) => w.findComponent(AnteprimaPassaggio);

/** La vendita di Ugo a Elsa del 1/6, completa: parte l'anteprima. */
async function vendita(): Promise<Pagina> {
    const w = monta({ tipo: 'vendita', rigaPreselezionata: 10 });
    w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id')!.vm.$emit('update:modelValue', 4);
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-06-01');
    await nextTick();
    await lasciaRispondere();

    return w;
}

/** La fine della locazione di Ines il 1/7, con «Nessuno» spuntato: parte l'anteprima. */
async function fineLocazione(): Promise<Pagina> {
    const w = monta({ tipo: 'fine_locazione', rigaPreselezionata: 11, titolari: [UGO, INES] });
    const nessuno = w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Nessuno'));
    if (!nessuno) throw new Error('La casella «Nessuno» non c\'è.');
    await nessuno.setValue(true);
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-07-01');
    await nextTick();
    await lasciaRispondere();

    return w;
}

const casellaDellaRinuncia = (w: Pagina) => pannelloDi(w).find('button[role="checkbox"]');
const campoDellaRagione = (w: Pagina) => pannelloDi(w).find('input[placeholder^="Es: regolato"]');
const voci = (w: Pagina) => pannelloDi(w).findAll('input[type="radio"]');

describe('PassaggioNew — la vendita con il piano da ricalcolare: la casella della rinuncia è la via d\'uscita', () => {
    beforeEach(() => {
        // Come il server: l'avviso c'è finché il conguaglio si scrive; con la rinuncia nel corpo non c'è.
        post.mockImplementation((_url: string, corpo: unknown) => Promise.resolve({ data: structuredClone(pannello((corpo as { rinuncia_conguaglio?: boolean }).rinuncia_conguaglio ? null : AVVISO)) }));
    });

    test('con l\'avviso il pannello c\'è, con la casella cliccabile; il pulsante è spento e sotto si dice perché', async () => {
        const w = await vendita();

        expect(w.text()).toContain(AVVISO);
        expect(pannelloDi(w).props('dati')).not.toBeNull();
        expect(casellaDellaRinuncia(w).exists()).toBe(true);
        expect(pulsanteSpento(w)).toBe(true);
        expect(w.text()).toContain('Il piano va ricalcolato prima di registrare, oppure scegli di non scrivere il conguaglio: nel pannello.');
        expect(w.text()).not.toContain('Un dato del modulo non è accettabile');
    });

    test('spuntata la casella e scritta la ragione, l\'anteprima riparte con la rinuncia, l\'avviso sparisce e il pulsante si accende', async () => {
        const w = await vendita();

        await casellaDellaRinuncia(w).trigger('click');
        await campoDellaRagione(w).setValue(RAGIONE);
        await lasciaRispondere();

        expect(ultimoCorpo()).toMatchObject({ tipo: 'vendita', rinuncia_conguaglio: true, nota_conguaglio: RAGIONE });
        expect(w.text()).not.toContain('Ricalcola prima il piano');
        expect(pulsanteSpento(w)).toBe(false);
    });

    test('con la ragione più corta di dieci caratteri la rinuncia non parte: l\'avviso resta e nessuna richiesta torna 422', async () => {
        const w = await vendita();

        await casellaDellaRinuncia(w).trigger('click');
        await campoDellaRagione(w).setValue('corta');
        await lasciaRispondere(2);

        expect(ultimoCorpo()).toMatchObject({ rinuncia_conguaglio: false, nota_conguaglio: null });
        expect(w.text()).toContain(AVVISO);
        expect(pulsanteSpento(w)).toBe(true);
        expect(post.mock.calls.every(([, corpo]) => (corpo as { rinuncia_conguaglio?: boolean }).rinuncia_conguaglio !== true)).toBe(true);
    });

    test('tolta la spunta, la rinuncia non parte più e l\'avviso torna', async () => {
        const w = await vendita();
        await casellaDellaRinuncia(w).trigger('click');
        await campoDellaRagione(w).setValue(RAGIONE);
        await lasciaRispondere();
        expect(w.text()).not.toContain('Ricalcola prima il piano');

        await casellaDellaRinuncia(w).trigger('click');
        await lasciaRispondere();

        expect(ultimoCorpo()).toMatchObject({ rinuncia_conguaglio: false, nota_conguaglio: null });
        expect(w.text()).toContain(AVVISO);
        expect(pulsanteSpento(w)).toBe(true);
    });
});

describe('PassaggioNew — la fine di una locazione con il piano da ricalcolare: «Non scriverlo» è la via d\'uscita', () => {
    beforeEach(() => {
        // Come il server: l'avviso c'è solo con «Scrivi»; finché la scelta manca, o con «Non scriverlo», non c'è.
        post.mockImplementation((_url: string, corpo: unknown) => {
            const dati = pannello((corpo as { conguaglio?: string | null }).conguaglio === 'scrivi' ? AVVISO : null);
            dati.rate.scelta_del_conguaglio = true;

            return Promise.resolve({ data: structuredClone(dati) });
        });
    });

    test('«Scrivi» mette l\'avviso sopra il pannello, che resta con le due voci; il corpo continua a portare la scelta, senza richieste in più', async () => {
        const w = await fineLocazione();
        expect(voci(w)).toHaveLength(2);
        expect(w.text()).not.toContain('Ricalcola prima il piano');

        await voci(w)[0].setValue(true);
        await lasciaRispondere();
        const richieste = post.mock.calls.length;

        expect(w.text()).toContain(AVVISO);
        expect(pannelloDi(w).props('dati')).not.toBeNull();
        expect(voci(w)).toHaveLength(2);
        expect((voci(w)[0].element as HTMLInputElement).checked).toBe(true);
        expect(ultimoCorpo()).toMatchObject({ conguaglio: 'scrivi' });
        expect(pulsanteSpento(w)).toBe(true);
        expect(w.text()).toContain('Il piano va ricalcolato prima di registrare, oppure scegli di non scrivere il conguaglio: nel pannello.');

        // Nessun va e vieni: lasciando passare altro tempo, il pannello non rifà la richiesta.
        await lasciaRispondere(4);
        expect(post.mock.calls.length).toBe(richieste);
    });

    test('«Non scriverlo» dopo «Scrivi»: l\'anteprima riparte con la scelta, l\'avviso sparisce e il pulsante si accende', async () => {
        const w = await fineLocazione();
        await voci(w)[0].setValue(true);
        await lasciaRispondere();
        expect(w.text()).toContain(AVVISO);

        await voci(w)[1].setValue(true);
        await lasciaRispondere();

        expect(ultimoCorpo()).toMatchObject({ conguaglio: 'non_scrivere' });
        expect(w.text()).not.toContain('Ricalcola prima il piano');
        expect(w.text()).toContain('conguaglio non scritto');
        expect(pulsanteSpento(w)).toBe(false);
    });
});
