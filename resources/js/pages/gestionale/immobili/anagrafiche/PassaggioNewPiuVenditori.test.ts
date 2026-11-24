// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — «Registra passaggio» con più persone che escono: il modulo.
 *
 * Nella vendita (anche con la riserva d'usufrutto) «Chi esce» ha le caselle al posto dei pallini: con un titolare solo è già spuntato, sotto l'elenco
 * si legge quanti escono e quanta quota («Escono 2 persone, 100 % della proprietà») e il badge «2 passaggi insieme». Chi entra è una persona sola
 * (decisione 707): la quota è la somma, in sola lettura, con la frase che dice da dove viene, e non può essere fra chi esce. Una persona spuntata
 * va nel corpo come `riga_uscente_id`, come oggi; due o più come `righe_uscenti` (e `riga_uscente_id` nullo). Il server dice l'errore di ogni riga
 * (`righe_uscenti.<i>`) e il modulo lo mette sotto la riga giusta; i ruoli diversi si fermano nel modulo, con la strada («Registra due passaggi, uno per
 * ciascuna»). I motivi del cancello si leggono per persona; una spunta e una nota per tutte; il pulsante dice «Registra i 2 passaggi». Alla registrazione
 * parte anche l'impronta del pannello (`impronta_del_gruppo`): se il server la trova cambiata, il messaggio resta e il pannello si ricalcola.
 * Gli altri tipi (successione, usufrutto, locazione) restano con una persona sola e il pallino.
 *
 * La forma dei dati che il server manda è quella del §1.9 di `tappa7_progetto.md`. Si monta la pagina con `shallowMount` (il pannello è un segnaposto: si
 * leggono i suoi props); il debounce di 350 ms si fa avanzare con i timer finti.
 *
 * Cosa NON copre: il server (`Beta14PiuVenditoriTest`); il pannello (`AnteprimaGruppo.test.ts`); l'invio vero della registrazione (il corpo si legge da
 * `corpoRegistrazione`, che l'invio usa).
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
const AnteprimaGruppo = (await import('@/components/gestionale/immobili/AnteprimaGruppo.vue')).default;
const AnteprimaPassaggio = (await import('@/components/gestionale/immobili/AnteprimaPassaggio.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    post.mockReset();
});
afterEach(() => { vi.useRealTimers(); });

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const riga = (id: number, persona: number, nome: string, tipologia: string, quota = 50) => ({ id, anagrafica: { id: persona, nome, codice_fiscale: null }, tipologia, quota, data_inizio: '2019-03-03', data_fine: null });
const CECILIA = riga(10, 1, 'Cecilia Angeli', 'proprietario');
const MARTA = riga(11, 2, 'Marta Acciaioli', 'proprietario');
const MARTA_NUDA = riga(11, 2, 'Marta Acciaioli', 'nuda_proprietario');
const LUCA = { id: 9, nome: 'Luca Toscani', codice_fiscale: null, indirizzo: 'Via Roma 1' };
const CECILIA_P = { id: 1, nome: 'Cecilia Angeli', codice_fiscale: null, indirizzo: null };
const MARTA_P = { id: 2, nome: 'Marta Acciaioli', codice_fiscale: null, indirizzo: null };

const ORDINARIA_NO = { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null };

/** Il pannello di una persona sola, minimo. */
function pannelloSingolo() {
    return {
        nudi: null, riferimento: { uscente_fino_al: '2026-11-15', entrante_dal: '2026-11-16', frase: 'Cecilia Angeli risulterà titolare fino al 15 novembre 2026 compreso.' },
        anagrafica: { frasi: [], pertinenze: [] },
        rate: { stato: 'nessuno', emesse: [], piani_distinti: 0, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null, conguaglio: null, frasi: [] },
        obbligati: { frasi: [], copia_autentica_mancante: false }, invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] }, ordinaria: ORDINARIA_NO,
    };
}

/** L'anteprima dell'insieme (§1.9), con il cancello che chiede la spunta. */
function pannelloGruppo(extra: Record<string, unknown> = {}) {
    return {
        insieme: true,
        impronta: 'abc123',
        riferimento: { uscente_fino_al: '2026-11-15', entrante_dal: '2026-11-16', frase: 'Cecilia Angeli e Marta Acciaioli risulteranno titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.' },
        anagrafica: { frasi: ['Luca Toscani risulterà titolare dal 16 novembre 2026, come proprietario al 100 %.'], pertinenze: [] },
        parti: [
            { riga_id: 10, anagrafica_id: 1, nome: 'Cecilia Angeli', quota: 50, pertinenze: [], anteprima: pannelloSingolo() },
            { riga_id: 11, anagrafica_id: 2, nome: 'Marta Acciaioli', quota: 50, pertinenze: [], anteprima: pannelloSingolo() },
        ],
        totale: { quota_che_esce: 100, conguaglio_entrante: 4098, conguaglio_entrante_formattato: '€ 40,98', bozze: 2, frase: 'In tutto a Luca Toscani: € 40,98 di conguaglio a debito.' },
        obbligati: { frasi: [], copia_autentica_mancante: false }, invarianti: { frasi: [] }, ordinaria: ORDINARIA_NO,
        cancello: {
            richiesto: true,
            gruppi: [
                { nome: 'Cecilia Angeli', motivi: ['11 quote di rate già emesse a Cecilia Angeli su questa unità'] },
                { nome: 'Marta Acciaioli', motivi: ['11 quote di rate già emesse a Marta Acciaioli su questa unità'] },
            ],
            informazioni: [], avvisi: [],
        },
        ...extra,
    };
}

/** Come il server: con `righe_uscenti` torna l'insieme, altrimenti il pannello di una persona. */
function rispondeComeIlServer() {
    post.mockImplementation((_url: string, corpo: unknown) => Promise.resolve({ data: structuredClone((corpo as { righe_uscenti?: number[] }).righe_uscenti ? pannelloGruppo() : pannelloSingolo()) }));
}

function monta(props: Record<string, unknown> = {}): Pagina {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Int. 1A', interno: '1A' } as never,
            tipo: 'vendita',
            titolari: [CECILIA, MARTA],
            anagrafiche: [LUCA, CECILIA_P, MARTA_P],
            pertinenze: [],
            rateEmesseCount: 11,
            ...props,
        },
        global: { renderStubDefaultSlot: true, stubs: { FraseConImporti: false }, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

const modulo = (w: Pagina) => (w.vm as unknown as { form: Record<string, any> }).form;
const casella = (w: Pagina, riga: number) => w.find<HTMLInputElement>(`input[data-testid="uscente-${riga}"]`);
const testo = (w: Pagina) => w.text().replace(/\s+/g, ' ').replace(/ /g, ' ');

async function spunta(w: Pagina, ...righe: number[]) {
    for (const r of righe) await casella(w, r).setValue(true);
    await nextTick();
}

/** Chi entra e la data: il modulo è completo e parte l'anteprima. */
async function completa(w: Pagina) {
    w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id')!.vm.$emit('update:modelValue', 9);
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-11-16');
    await nextTick();
    await vi.advanceTimersByTimeAsync(350);
    await flushPromises();
}

const ultimoCorpo = () => post.mock.calls[post.mock.calls.length - 1][1] as Record<string, unknown>;
const pulsante = (w: Pagina) => w.findAll('[type="submit"]').find((b) => /Registra/.test(b.text()))!;
const spento = (w: Pagina) => { const d = pulsante(w).attributes('disabled'); return d !== undefined && d !== 'false'; };

describe('PassaggioNew — «Chi esce» con le caselle nella vendita (tappa 7)', () => {
    test('nella vendita le caselle al posto dei pallini, nessuna spuntata con due titolari', () => {
        const w = monta();

        expect(casella(w, 10).exists()).toBe(true);
        expect(casella(w, 11).exists()).toBe(true);
        expect(w.findAll('input[type="radio"]')).toHaveLength(0);
        expect(casella(w, 10).element.checked).toBe(false);
        expect(casella(w, 11).element.checked).toBe(false);
        expect(w.find('[data-testid="totale-uscenti"]').exists()).toBe(false);
    });

    test('con un titolare solo è già spuntato, come oggi, senza riga del totale e senza badge', async () => {
        const w = monta({ titolari: [{ ...CECILIA, quota: 100 }] });
        await flushPromises();

        expect(casella(w, 10).element.checked).toBe(true);
        expect(modulo(w).riga_uscente_id).toBe(10);
        expect(w.find('[data-testid="totale-uscenti"]').exists()).toBe(false);
        expect(w.find('[data-testid="badge-insieme-modulo"]').exists()).toBe(false);
    });

    test('la riga del menu (`?riga=`) arriva già spuntata', async () => {
        const w = monta({ rigaPreselezionata: 11 });
        await flushPromises();

        expect(casella(w, 11).element.checked).toBe(true);
        expect(casella(w, 10).element.checked).toBe(false);
        expect(modulo(w).riga_uscente_id).toBe(11);
    });

    test('una persona spuntata: nessun totale, e il corpo ha `riga_uscente_id` come oggi, senza `righe_uscenti`', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10);
        await completa(w);

        expect(w.find('[data-testid="totale-uscenti"]').exists()).toBe(false);
        expect(ultimoCorpo()).toMatchObject({ tipo: 'vendita', riga_uscente_id: 10, quota: '50' });
        expect(ultimoCorpo()).not.toHaveProperty('righe_uscenti');
        expect(w.findComponent(AnteprimaPassaggio).exists()).toBe(true);
        expect(w.findComponent(AnteprimaGruppo).exists()).toBe(false);
        expect(testo(w)).toContain('Registra passaggio');
    });

    test('due persone spuntate: «Escono 2 persone, 100 % della proprietà», il badge «2 passaggi insieme», la quota è la somma', async () => {
        const w = monta();
        await spunta(w, 10, 11);

        expect(w.find('[data-testid="totale-uscenti"]').text().replace(/\s+/g, ' ').replace(/ /g, ' ')).toContain('Escono 2 persone, 100 % della proprietà.');
        expect(w.find('[data-testid="badge-insieme-modulo"]').text()).toBe('2 passaggi insieme');
        expect(w.find('[data-testid="badge-insieme-modulo"]').classes()).toContain('rounded-md');
        expect(modulo(w).quota).toBe('100');
        expect(w.find<HTMLInputElement>('#quota').attributes('readonly')).toBeDefined();
        expect(testo(w)).toContain('La quota è la somma di chi esce: 50 % di Cecilia Angeli e 50 % di Marta Acciaioli.');
        expect(testo(w)).toContain('Il programma registra un passaggio per ciascuna, con la stessa data e lo stesso atto: il conguaglio e la posizione di ognuna restano separati.');
    });

    test('la somma è al centesimo di punto: 33,34 + 33,33 = 66,67', async () => {
        const w = monta({ titolari: [{ ...CECILIA, quota: 33.34 }, { ...MARTA, quota: 33.33 }, riga(12, 3, 'Tina Rossi', 'proprietario', 33.33)] });
        await spunta(w, 10, 11);

        expect(modulo(w).quota).toBe('66.67');
        expect(testo(w)).toContain('Escono 2 persone, 66,67 % della proprietà.');
    });

    test('due persone spuntate e il modulo completo: il corpo porta `righe_uscenti` e `riga_uscente_id` nullo, e il pannello è quello dell\'insieme', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);

        expect(ultimoCorpo()).toMatchObject({ tipo: 'vendita', righe_uscenti: [10, 11], riga_uscente_id: null, anagrafica_entrante_id: 9, quota: '100', tipologia: 'proprietario' });
        const pannello = w.findComponent(AnteprimaGruppo);
        expect(pannello.exists()).toBe(true);
        expect((pannello.props('dati') as { insieme: boolean }).insieme).toBe(true);
        expect(w.findComponent(AnteprimaPassaggio).exists()).toBe(false);
    });

    test('le righe nel corpo seguono l\'ordine dell\'elenco, non quello dei clic', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 11, 10);
        await completa(w);

        expect(ultimoCorpo()).toMatchObject({ righe_uscenti: [10, 11] });
    });

    test('togliendo una spunta si torna al passaggio di oggi', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);
        await casella(w, 11).setValue(false);
        await nextTick();
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();

        expect(ultimoCorpo()).toMatchObject({ riga_uscente_id: 10 });
        expect(ultimoCorpo()).not.toHaveProperty('righe_uscenti');
        expect(w.findComponent(AnteprimaPassaggio).exists()).toBe(true);
        expect(w.find('[data-testid="totale-uscenti"]').exists()).toBe(false);
    });
});

describe('PassaggioNew — chi entra, i ruoli e gli errori per riga (tappa 7)', () => {
    test('chi entra non può essere fra chi esce: le persone spuntate non sono fra le scelte', async () => {
        const w = monta();
        const scelte = () => (w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id')!.props('options') as { id: number }[]).map((o) => o.id).sort();

        expect(scelte()).toEqual([1, 2, 9]);
        await spunta(w, 10);
        expect(scelte()).toEqual([2, 9]);
        await spunta(w, 11);
        expect(scelte()).toEqual([9]);
    });

    test('ruoli diversi: l\'errore sotto l\'elenco con la strada, e nessuna richiesta al server', async () => {
        rispondeComeIlServer();
        const w = monta({ titolari: [CECILIA, MARTA_NUDA] });
        await spunta(w, 10, 11);
        await completa(w);

        expect(w.find('[data-testid="errore-righe-uscenti"]').text()).toBe('Chi esce insieme deve avere lo stesso ruolo: Cecilia Angeli ha la proprietà piena, Marta Acciaioli la nuda proprietà. Registra due passaggi, uno per persona.');
        expect(post).not.toHaveBeenCalled();
        expect(spento(w)).toBe(true);
    });

    test('l\'errore di una riga (`righe_uscenti.1`) sta sotto la riga giusta, con il nome in testa', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { errors: { 'righe_uscenti.1': ['Marta Acciaioli: la riga è stata chiusa da un altro passaggio.'] } } } });
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);

        expect(w.find('[data-testid="errore-riga-11"]').text()).toBe('Marta Acciaioli: la riga è stata chiusa da un altro passaggio.');
        expect(w.find('[data-testid="errore-riga-10"]').exists()).toBe(false);
    });

    test('la riserva d\'usufrutto vale per tutte: la casella c\'è, dice che ciascuna tiene l\'usufrutto sulla sua quota, e il ruolo di chi entra è nudo proprietario', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        (w.vm as unknown as { riserva: boolean }).riserva = true;
        await completa(w);

        expect(testo(w)).toContain('Chi vende o dona resta usufruttuario');
        expect(testo(w)).toContain('vale per tutte le persone spuntate: ciascuna tiene l\'usufrutto sulla sua quota');
        expect(modulo(w).tipologia).toBe('nuda_proprietario');
        expect(modulo(w).quota).toBe('100');
        expect(ultimoCorpo()).toMatchObject({ sottotipo: 'riserva_usufrutto', righe_uscenti: [10, 11], tipologia: 'nuda_proprietario' });
    });

    test('la riserva non si offre se chi esce insieme non è proprietario pieno', async () => {
        const w = monta({ titolari: [CECILIA, MARTA_NUDA] });
        await spunta(w, 10, 11);

        expect(testo(w)).not.toContain('Chi vende o dona resta usufruttuario');
    });

    test('gli altri tipi restano con il pallino e una persona sola (la successione)', () => {
        const w = monta({ tipo: 'successione' });

        expect(w.findAll('input[type="radio"]').length).toBeGreaterThanOrEqual(2);
        expect(w.find('input[data-testid="uscente-10"]').exists()).toBe(false);
        expect(w.find('[data-testid="totale-uscenti"]').exists()).toBe(false);
    });
});

describe('PassaggioNew — la spunta, la nota e il pulsante dell\'insieme (tappa 7)', () => {
    test('i motivi si leggono per persona, con il titolo della bozza; una spunta e una nota per tutte', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);

        expect(testo(w)).toContain('Questo passaggio tocca rate già emesse o cambia un destinatario');
        const gruppi = w.findAll('[data-testid="cancello-gruppo"]');
        expect(gruppi).toHaveLength(2);
        expect(gruppi[0].text()).toContain('Cecilia Angeli');
        expect(gruppi[0].text()).toContain('11 quote di rate già emesse a Cecilia Angeli su questa unità');
        expect(gruppi[1].text()).toContain('Marta Acciaioli');
        expect(w.findAll('input[type="checkbox"]').filter((c) => (c.element.closest('label')?.textContent ?? '').includes('Ho letto cosa cambierà'))).toHaveLength(1);
    });

    test('il pulsante dice «Registra i 2 passaggi», ed è spento finché non c\'è la spunta e la nota', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);

        expect(pulsante(w).text()).toContain('Registra i 2 passaggi');
        expect(spento(w)).toBe(true);
        modulo(w).nota_cancello = 'Rogito del notaio Verdi del 16/11/2026: vendono insieme le due sorelle.';
        await w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Ho letto cosa cambierà'))!.setValue(true);
        await nextTick();
        expect(spento(w)).toBe(false);
    });

    test('con una persona sola il pulsante resta «Registra passaggio»', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10);
        await completa(w);

        expect(pulsante(w).text()).toContain('Registra passaggio');
        expect(pulsante(w).text()).not.toContain('Registra i');
    });

    test('la registrazione porta l\'impronta del pannello, l\'elenco delle persone, la spunta e la nota', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);
        modulo(w).ho_letto = true;
        modulo(w).nota_cancello = 'Rogito del notaio Verdi del 16/11/2026.';
        const corpo = (w.vm as unknown as { corpoRegistrazione: () => Record<string, unknown> }).corpoRegistrazione();

        expect(corpo).toMatchObject({ righe_uscenti: [10, 11], riga_uscente_id: null, impronta_del_gruppo: 'abc123', ho_letto: true, nota_cancello: 'Rogito del notaio Verdi del 16/11/2026.' });
    });

    test('la registrazione di una persona sola non porta né l\'impronta del gruppo né l\'elenco', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10);
        await completa(w);
        const corpo = (w.vm as unknown as { corpoRegistrazione: () => Record<string, unknown> }).corpoRegistrazione();

        expect(corpo).toMatchObject({ riga_uscente_id: 10 });
        expect(corpo).not.toHaveProperty('righe_uscenti');
        expect(corpo).not.toHaveProperty('impronta_del_gruppo');
    });

    test('«Mentre registravi è cambiato qualcosa»: il pannello si ricalcola e il messaggio resta finché non si riprova', async () => {
        rispondeComeIlServer();
        const w = monta();
        await spunta(w, 10, 11);
        await completa(w);
        const richieste = post.mock.calls.length;
        const messaggio = 'Mentre registravi è cambiato qualcosa (la parte di Marta Acciaioli): il pannello è stato ricalcolato, ricontrolla e conferma.';

        (w.vm as unknown as { dopoErroreDiRegistrazione: (e: Record<string, string>) => void }).dopoErroreDiRegistrazione({ passaggio: messaggio });
        await flushPromises();

        expect(post.mock.calls.length).toBe(richieste + 1);
        expect(testo(w)).toContain(messaggio);
    });
});

describe('PassaggioNew — la catena dal modulo al pannello dell\'insieme (tappa 7)', () => {
    test('due persone spuntate, chi entra e la data: il corpo parte, il pannello vero mostra una parte per persona e il riquadro «In tutto», il pulsante dice quante', async () => {
        rispondeComeIlServer();
        const w = shallowMount(PassaggioNew, {
            props: {
                condominio: { id: 1, nome: 'Condominio Demo' } as never, immobile: { id: 7, nome: 'Int. 1A', interno: '1A' } as never, tipo: 'vendita',
                titolari: [CECILIA, MARTA], anagrafiche: [LUCA, CECILIA_P, MARTA_P], pertinenze: [], rateEmesseCount: 11,
            },
            // Il pannello dell'insieme è vero, con la sezione delle rate di ogni parte.
            global: { renderStubDefaultSlot: true, stubs: { FraseConImporti: false, AnteprimaGruppo: false, AnteprimaPassaggio: false }, mocks: { route: (name: string) => `/${name}` } },
        }) as Pagina;
        await spunta(w, 10, 11);
        await completa(w);

        expect(ultimoCorpo()).toMatchObject({ righe_uscenti: [10, 11], riga_uscente_id: null, anagrafica_entrante_id: 9, quota: '100', decorrenza: '2026-11-16' });
        expect(testo(w)).toContain('2 passaggi insieme');
        expect(testo(w)).toContain('La parte di Cecilia Angeli');
        expect(testo(w)).toContain('La parte di Marta Acciaioli');
        expect(w.find('[data-testid="in-tutto"]').text()).toContain('In tutto a Luca Toscani: € 40,98 di conguaglio a debito.');
        expect(testo(w)).toContain('Registra i 2 passaggi');
    });
});
