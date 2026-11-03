// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, verifica a video — il pannello «Cosa cambierà» resta fermo se nella fine della locazione si scrive la data e, dopo
 * il debounce, si spunta «Nessuno».
 *
 * La spunta non cambia il corpo dell'anteprima (chi entra era già vuoto), e la pagina chiedeva il pannello solo quando il corpo
 * cambiava: il primo calcolo, con la data sola, trovava il modulo incompleto e si fermava; la spunta lo completava senza far
 * ripartire niente. Il pannello diceva «Il pannello sta leggendo il modulo.» per sempre e «Registra passaggio» restava spento. È
 * l'ordine naturale del modulo (prima la data, poi chi entra). Visto a video sul condominio di prova 169 il 12/10/2026: con la
 * spunta prima della data il pannello si calcolava, con la data prima no. C'era già nella .49.
 *
 * Cosa NON copre: il pannello vero (qui è un segnaposto) e le altre caselle che completano il modulo senza cambiare il corpo (oggi
 * nessun'altra: la data della copia autentica, gli eredi, l'arretrato e chi entra entrano tutti nel corpo).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import axios from 'axios';
import VueDatePicker from '@vuepic/vue-datepicker';
import vSelect from 'vue-select';
import { Textarea } from '@/components/ui/textarea';

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
const PassaggioPertinenzeCard = (await import('@/components/gestionale/immobili/PassaggioPertinenzeCard.vue')).default;

const post = vi.mocked(axios.post);

beforeEach(() => {
    // Solo i timer del debounce: `flushPromises` usa `setImmediate`, che resta vero.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    post.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

type Riga = { id: number; anagrafica: { id: number; nome: string; codice_fiscale: null }; tipologia: string; quota: number; data_inizio: string | null; data_fine: string | null };

function riga(id: number, persona: number, nome: string, tipologia: string, quota: number, dal = '2019-01-01', al: string | null = null): Riga {
    return { id, anagrafica: { id: persona, nome, codice_fiscale: null }, tipologia, quota, data_inizio: dal, data_fine: al };
}

const UGO = riga(10, 1, 'Ugo', 'proprietario', 100);
const INES = riga(11, 2, 'Ines', 'inquilino', 100);
const BOX = { id: 20, nome: 'Box 12', etichetta: 'Box 12', titolari: [{ nome: 'Ugo', tipologia: 'proprietario' }, { nome: 'Ines', tipologia: 'inquilino' }] };
const LUCA = { id: 5, nome: 'Luca', codice_fiscale: null, indirizzo: 'Via Roma 1' };

function monta(props: Record<string, unknown>) {
    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo: 'fine_locazione',
            rigaPreselezionata: 11,
            titolari: [UGO, INES],
            anagrafiche: [],
            pertinenze: [],
            rateEmesseCount: 6,
            ...props,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

/** La data del passaggio, scritta nel primo calendario (quello della decorrenza). */
async function scriviData(w: Pagina, data: string) {
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', data);
    await nextTick();
}

/** La casella di chi non entra: «Nessuno — …». */
function casellaNessuno(w: Pagina) {
    return w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Nessun'));
}

/** Il debounce scade e le risposte già date arrivano. */
async function lasciaRispondere(volte = 1) {
    for (let i = 0; i < volte; i++) {
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();
    }
}

/** Il corpo dell'ultima richiesta del pannello. */
function ultimoCorpo(): Record<string, unknown> {
    const chiamate = post.mock.calls;
    if (chiamate.length === 0) throw new Error('Il pannello non ha mandato nessuna richiesta.');

    return chiamate[chiamate.length - 1][1] as Record<string, unknown>;
}

/** Il testo della pagina e ciò che manca (il pannello lo mostra), insieme. */
function testi(w: Pagina): string {
    return [w.text(), ...((w.findComponent(AnteprimaPassaggio).props('mancante') as string[]) ?? [])].join(' | ');
}

/** Una coppia del pannello: sull'Interno 1 (o sull'unità data), alla persona data. */
function coppia(nome: string, persona: number, importo: number, immobile = 7) {
    return { gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: immobile, esercizio_id: 1, importo, anagrafica_entrante_id: persona, entrante_nome: nome };
}

/** Un pannello «Cosa cambierà» con i soli campi che la pagina legge: le coppie date, nessun cancello. */
function pannello(coppie: ReturnType<typeof coppia>[]) {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: null, entrante_dal: '', frase: '' },
        anagrafica: { frasi: [], pertinenze: [] },
        rate: {
            stato: coppie.length ? 'calcolato' : 'nessuno', emesse: [], piani_distinti: 0, totale_emesso: 0, totale_emesso_formattato: '€ 0,00',
            altre: { quote: 0, intestatari: [] }, morosita: null, frasi: [], scelta_del_conguaglio: coppie.length > 0,
            conguaglio: coppie.length ? { stato: 'calcolato', anagrafica_uscente_id: 2, anagrafica_entrante_id: null, quote: [], per_gestione: [], coppie, totale_entrante: 0,
                totale_entrante_formattato: '', totale_entrante_assoluto_formattato: '', riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [] } : null,
        },
        obbligati: { frasi: [], copia_autentica_mancante: false },
        invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    };
}

/** Il pulsante «Registra passaggio» è spento. */
function pulsanteSpento(w: Pagina): boolean {
    const pulsante = w.findAll('[type="submit"]').find((b) => b.text().includes('Registra passaggio'));
    if (!pulsante) throw new Error('Il pulsante «Registra passaggio» non c\'è.');
    const d = pulsante.attributes('disabled');

    return d !== undefined && d !== 'false';
}

describe('PassaggioNew — il pannello riparte quando il modulo diventa completo', () => {
    test('fine di Ines: la data, il debounce scaduto, poi «Nessuno» — il pannello chiede il calcolo', async () => {
        const w = monta({});
        post.mockResolvedValue({ data: pannello([]) });

        await scriviData(w, '2026-07-01');
        await lasciaRispondere();
        // Controllo: con la sola data il modulo non è completo e il pannello non chiede niente.
        expect(post).not.toHaveBeenCalled();
        expect(testi(w)).toContain('Nessuno');

        await casellaNessuno(w)!.setValue(true);
        await lasciaRispondere();

        expect(post, 'spuntata «Nessuno» dopo la data, il pannello non è ripartito').toHaveBeenCalledTimes(1);
        expect(ultimoCorpo()).toMatchObject({ tipo: 'fine_locazione', decorrenza: '2026-07-01', anagrafica_entrante_id: null });
    });

    test('il controllo — «Nessuno» prima della data: il pannello parte con la data, una volta sola', async () => {
        const w = monta({});
        post.mockResolvedValue({ data: pannello([]) });

        await casellaNessuno(w)!.setValue(true);
        await scriviData(w, '2026-07-01');
        await lasciaRispondere();

        expect(post).toHaveBeenCalledTimes(1);
        expect(ultimoCorpo()).toMatchObject({ decorrenza: '2026-07-01' });
    });
});
