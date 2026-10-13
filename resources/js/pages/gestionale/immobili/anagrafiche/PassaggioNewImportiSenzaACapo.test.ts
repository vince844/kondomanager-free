// @vitest-environment jsdom

/**
 * 1.11.0-beta.51, Coda 255 (locale) — le frasi del cancello di `PassaggioNew.vue` (motivi, informazioni, avvisi: «Questo passaggio tocca
 * conti già in corso», «Quote che questo passaggio non tocca», «Da sapere») hanno gli importi in uno span `whitespace-nowrap` che contiene la
 * sola cifra, senza il punto che segue. Il testo non cambia. Gemello di `components/gestionale/immobili/Beta51ImportiSenzaACapo.test.ts`, che
 * fa lo stesso per il pannello «Cosa cambierà» e per lo storico; la logica del controllo è la stessa (nessun nodo di testo con un importo
 * fuori da uno span nowrap), e `FraseConImporti.test.ts` prova il componente da solo.
 *
 * Si monta la pagina della fine di una locazione con `shallowMount` (i figli sono segnaposto; i `<li>` del cancello sono nella pagina), si scrive la
 * data dal segnaposto del calendario e si lascia rispondere l'anteprima: `axios.post` dà il JSON di prova (ogni frase del cancello ha «€ 127,04»)
 * dopo il debounce di 350 ms, che si salta con i timer finti.
 *
 * Che cosa NON copre: il pannello (`AnteprimaPassaggio.vue`, è un segnaposto qui); la registrazione; il modo scuro.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import VueDatePicker from '@vuepic/vue-datepicker';

const FRASE = 'Il conguaglio dà € 127,04 a Elsa e € 1.127,04 a Ugo, che restano a loro carico.';

const anteprima = {
    nudi: null,
    obbligati: { frasi: [], copia_autentica_mancante: false },
    invarianti: { frasi: [] },
    ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    riferimento: { uscente_fino_al: '2026-06-30', entrante_dal: '2026-07-01', frase: 'Ines risulterà inquilina fino al 30 giugno 2026.' },
    anagrafica: { frasi: ['Ines risulterà inquilina fino al 30 giugno 2026.'], pertinenze: [] },
    rate: {
        stato: 'calcolato', emesse: [], piani_distinti: 1, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
        conguaglio: { stato: 'calcolato', anagrafica_uscente_id: 2, anagrafica_entrante_id: null, quote: [], per_gestione: [], coppie: [], totale_entrante: 0, totale_entrante_formattato: '€ 0,00', totale_entrante_assoluto_formattato: '€ 0,00', riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [] },
        frasi: [], frasi_del_conguaglio: [], scelta_del_conguaglio: false, fermata_coinquilino: [], prospetto_esclude: [],
    },
    cancello: { richiesto: true, motivi: [FRASE], informazioni: [FRASE], avvisi: [FRASE] },
};

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn(async () => ({ data: anteprima })) } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const PassaggioNew = (await import('./PassaggioNew.vue')).default;

const IMPORTO = /€\s?-?\d{1,3}(?:\.\d{3})*,\d{2}/;
const SOLO_IMPORTO = /^\s*€\s?-?\d{1,3}(?:\.\d{3})*,\d{2}\s*$/;

/** I nodi di testo con un importo che non stanno da soli in un elemento `whitespace-nowrap` (le celle con il solo importo non contano). */
function importiAScapito(radice: Element): string[] {
    const fuori: string[] = [];
    const camminatore = document.createTreeWalker(radice, NodeFilter.SHOW_TEXT);
    for (let nodo = camminatore.nextNode(); nodo; nodo = camminatore.nextNode()) {
        const testo = nodo.nodeValue ?? '';
        if (!IMPORTO.test(testo)) continue;
        const elemento = (nodo as Text).parentElement as HTMLElement;
        const solo = SOLO_IMPORTO.test(testo);
        if (solo && elemento.closest('td, th')) continue;
        if (solo && elemento.classList.contains('whitespace-nowrap')) continue;
        fuori.push(testo.trim().slice(0, 100));
    }

    return fuori;
}

const UGO = { id: 10, anagrafica: { id: 1, nome: 'Ugo', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };
const INES = { id: 11, anagrafica: { id: 2, nome: 'Ines', codice_fiscale: null }, tipologia: 'inquilino', quota: 100, data_inizio: '2019-01-01', data_fine: null };

/** La pagina della fine locazione di Ines (riga 11), con la data scritta e l'anteprima arrivata. */
async function montaConIlCancello() {
    vi.useFakeTimers();
    try {
        const w = shallowMount(PassaggioNew, {
            props: {
                condominio: { id: 1, nome: 'Condominio Demo' } as never,
                immobile: { id: 7, nome: 'Interno 1' } as never,
                tipo: 'fine_locazione',
                rigaPreselezionata: 11,
                titolari: [UGO, INES],
                anagrafiche: [],
                pertinenze: [],
                rateEmesseCount: 6,
            },
            // Il componente che avvolge gli importi si monta vero: con `shallowMount` sarebbe un segnaposto, e il controllo guarda il DOM che produce.
            global: { renderStubDefaultSlot: true, stubs: { FraseConImporti: false }, mocks: { route: (name: string) => `/${name}` } },
        });
        w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-07-01');
        // La fine di una locazione è completa con la data e la casella «Nessuno — l'unità resta sfitta»: allora parte l'anteprima.
        const nessuno = w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Nessuno'));
        if (!nessuno) throw new Error('La casella «Nessuno» non c\'è.');
        await nessuno.setValue(true);
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        return w;
    } finally {
        vi.useRealTimers();
    }
}

describe('PassaggioNew — gli importi delle frasi del cancello non vanno a capo (Coda 255)', () => {
    test('controllo — con l\'anteprima arrivata il cancello mostra le tre frasi (motivi, informazioni, avvisi)', async () => {
        const w = await montaConIlCancello();

        expect(w.text().split(FRASE).length - 1).toBe(3);
    });

    test('ogni importo del cancello sta da solo in uno span whitespace-nowrap: nessun nodo di testo con un importo fuori', async () => {
        const w = await montaConIlCancello();

        expect(importiAScapito(w.element)).toEqual([]);
    });

    test('il testo del cancello non cambia, con il punto finale: wrapper.text() ha la frase intera', async () => {
        const w = await montaConIlCancello();

        expect(w.text()).toContain(FRASE);
    });

    test('lo span nowrap contiene la sola cifra: «€ 1.127,04» senza la virgola che segue', async () => {
        const w = await montaConIlCancello();
        const span = Array.from((w.element as Element).querySelectorAll('.whitespace-nowrap')).map((e) => (e.textContent ?? '').trim()).filter((t) => IMPORTO.test(t));

        expect(span).toContain('€ 1.127,04');
        expect(span.filter((t) => /[.,;:)]$/.test(t))).toEqual([]);
    });
});
