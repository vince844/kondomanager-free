// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il server (`Beta52UsufruttoVoceDiEntrataTest.php` prova `voci[].tipo`, l'impronta e il registro,
 * `Beta52UsufruttoPannelloFrasiTest.php` le frasi); il pannello `AnteprimaPassaggio.vue` (qui un segnaposto); la scheda dell'unità; il tema
 * scuro e le larghezze.
 *
 * Il modulo «Registra passaggio» (`PassaggioNew.vue`), riquadro «Chi paga l'ordinaria dal giorno dell'atto»: nell'elenco delle voci che
 * passano dal «Proprietario» all'«Usufruttuario» un'entrata ha l'etichetta «Entrata» e una frase che dice perché passa: se è un frutto civile,
 * come un affitto o un canone, l'art. 984 c.c. lo dà all'usufruttuario (se il titolo dell'usufrutto dice altro, vale fra le parti) (una voce di entrata del programma non è sempre un
 * affitto o un canone: un rimborso non è un frutto civile). Una spesa non cambia. Per un'entrata non si scrive l'importo dell'ultimo piano
 * sulle altre unità in usufrutto: è un credito, e il segno meno della cifra non dice niente a chi legge.
 *
 * La registrazione che trova l'elenco delle voci cambiato (un'entrata che nel frattempo è diventata una spesa, una voce nuova) risponde con
 * un errore su `ordinaria_impronta`. Il modulo mostra il messaggio, chiede di nuovo il pannello e lascia registrare di nuovo con l'impronta
 * nuova: è la via d'uscita di quel rifiuto, ed è provata dalla registrazione.
 *
 * Si monta la pagina con `shallowMount`, come in `PassaggioNewPianoDaRicalcolare.test.ts`; il pannello del server è finto e porta il blocco
 * `ordinaria` che il server scrive (`voci[].tipo` è il campo nuovo). Il debounce di 350 ms si fa avanzare con i timer finti; la registrazione
 * passa dal `router` di Inertia, che qui si ferma: il test legge il corpo che parte e risponde con l'errore che il server dà.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/core';
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

const post = vi.mocked(axios.post);

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    post.mockReset();
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

type Pagina = VueWrapper<InstanceType<typeof PassaggioNew>>;

const riga = (id: number, persona: number, nome: string, tipologia: string) => ({ id, anagrafica: { id: persona, nome, codice_fiscale: null }, tipologia, quota: 100, data_inizio: '2019-01-01', data_fine: null });
const UGO = riga(10, 1, 'Ugo', 'proprietario');
const ELSA = { id: 4, nome: 'Elsa', codice_fiscale: null, indirizzo: 'Via Roma 1' };

const voce = (id: number, conto: string, tipo: 'spesa' | 'entrata', extra: Record<string, unknown> = {}) => ({
    id, conto_id: id, conto, tabella: 'Proprietà', gestione: 'Ordinaria 2026', percentuale: 100, bloccata: false, bloccata_da: null, piani_bloccanti: [], altre_unita: [], spostata: true, tipo, ...extra,
});

const ALTRA_UNITA = { immobile_id: 9, immobile: 'Interno 2', usufruttuari: 'Nina', nudi: 'Oreste' };

/** Il pannello come lo manda il server per una costituzione d'usufrutto con una spesa e un'entrata sul «Proprietario». */
function pannello(voci: unknown[], impronta = 'abc') {
    return {
        nudi: null, riferimento: { uscente_fino_al: null, entrante_dal: '', frase: '' }, anagrafica: { frasi: [], pertinenze: [] },
        rate: { stato: 'senza_rate', emesse: [], piani_distinti: 0, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: null, piano_da_ricalcolare: null, frasi: [], frasi_del_conguaglio: [], scelta_del_conguaglio: false, fermata_coinquilino: [] },
        obbligati: { frasi: [], copia_autentica_mancante: false }, invarianti: { frasi: [] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: true, scelta: 'usufruttuario', usufruttuario: 'Elsa', nudo: 'Ugo', voci, frasi: ['Dal 1 maggio 2026 le spese ordinarie sono di Elsa, usufruttuario (art. 1004 c.c.): per le voci di oggi, nei piani generati o ricalcolati dopo.'],
            frasi_bloccate: [], frasi_altri_usufrutti: [], impronta, ereditata: null },
    };
}

function monta(): Pagina {
    return shallowMount(PassaggioNew, {
        props: { condominio: { id: 1, nome: 'Condominio Demo' } as never, immobile: { id: 7, nome: 'Interno A' } as never, tipo: 'usufrutto', rigaPreselezionata: 10, titolari: [UGO], anagrafiche: [ELSA], pertinenze: [], rateEmesseCount: 0 },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    }) as Pagina;
}

async function lasciaRispondere(volte = 1) {
    for (let i = 0; i < volte; i++) {
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();
    }
}

async function costituzione(voci: unknown[], impronta = 'abc'): Promise<Pagina> {
    post.mockImplementation(() => Promise.resolve({ data: structuredClone(pannello(voci, impronta)) }));
    const w = monta();
    w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id')!.vm.$emit('update:modelValue', 4);
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-05-01');
    await nextTick();
    await lasciaRispondere(2);

    return w;
}

/** La riga dell'elenco di una voce: l'etichetta che contiene il suo nome. */
const rigaDi = (w: Pagina, conto: string) => {
    const r = w.findAll('label').find((l) => l.find('input[type="checkbox"]').exists() && l.text().includes(conto));
    if (!r) throw new Error(`La voce «${conto}» non è nell'elenco.`);

    return r;
};
const testoDi = (w: Pagina, conto: string) => rigaDi(w, conto).text().replace(/\s+/g, ' ');

const FRASE_ENTRATA = 'Se è un frutto civile, come un affitto o un canone, l\'art. 984 c.c. lo dà all\'usufruttuario (se il titolo dell\'usufrutto dice altro, vale fra le parti): l\'entrata passa all\'«Usufruttuario». Togli la spunta se deve restare al nudo proprietario.';

describe('PassaggioNew — le voci di entrata fra quelle che passano all\'«Usufruttuario»', () => {
    test('l\'entrata ha l\'etichetta «Entrata» accanto al nome; la spesa no', async () => {
        const w = await costituzione([voce(2, 'Affitto antenna', 'entrata'), voce(1, 'Spese generali', 'spesa')]);

        expect(testoDi(w, 'Affitto antenna')).toContain('Entrata');
        expect(testoDi(w, 'Spese generali')).not.toContain('Entrata');
    });

    test('l\'entrata dice che, se è un frutto civile, passa per l\'art. 984 c.c.; la spesa non cita l\'articolo', async () => {
        const w = await costituzione([voce(2, 'Affitto antenna', 'entrata'), voce(1, 'Spese generali', 'spesa')]);

        expect(testoDi(w, 'Affitto antenna')).toContain(FRASE_ENTRATA);
        expect(testoDi(w, 'Spese generali')).not.toContain('984');
    });

    test('la frase non dice che ogni entrata è un frutto civile: lo dice solo di un affitto o di un canone', async () => {
        const w = await costituzione([voce(2, 'Rimborso assicurazione', 'entrata')]);

        expect(testoDi(w, 'Rimborso assicurazione')).toContain('Se è un frutto civile, come un affitto o un canone');
        expect(testoDi(w, 'Rimborso assicurazione')).not.toContain('Per l\'art. 984 c.c. i frutti civili');
    });

    test('togliendo la spunta all\'entrata resta sul «Proprietario» e la frase dell\'art. 984 c.c. non c\'è più', async () => {
        const w = await costituzione([voce(2, 'Affitto antenna', 'entrata'), voce(1, 'Spese generali', 'spesa')]);
        await rigaDi(w, 'Affitto antenna').find('input[type="checkbox"]').setValue(false);

        expect(testoDi(w, 'Affitto antenna')).toContain('Resta sul «Proprietario»: nei piani che verranno andrà al nudo proprietario, Ugo.');
        expect(testoDi(w, 'Affitto antenna')).not.toContain('art. 984');
        expect(testoDi(w, 'Affitto antenna')).toContain('Entrata');
    });

    test('le altre unità in usufrutto: la spesa dice l\'importo dell\'ultimo piano, l\'entrata no', async () => {
        const w = await costituzione([
            voce(2, 'Affitto antenna', 'entrata', { altre_unita: [{ ...ALTRA_UNITA, importo: -10000, importo_formattato: '€ -100,00' }] }),
            voce(1, 'Spese generali', 'spesa', { altre_unita: [{ ...ALTRA_UNITA, importo: 60000, importo_formattato: '€ 600,00' }] }),
        ]);

        expect(testoDi(w, 'Spese generali')).toContain('Interno 2 (da Oreste a Nina, € 600,00 nell\'ultimo piano)');
        expect(testoDi(w, 'Affitto antenna')).toContain('Interno 2 (da Oreste a Nina)');
        expect(testoDi(w, 'Affitto antenna')).not.toContain('€ -100,00');
    });

    test('controprova — una voce senza `tipo` (un pannello di prima) si legge come una spesa', async () => {
        const senzaTipo = voce(1, 'Spese generali', 'spesa');
        delete (senzaTipo as Record<string, unknown>).tipo;
        const w = await costituzione([senzaTipo]);

        expect(testoDi(w, 'Spese generali')).not.toContain('Entrata');
        expect(testoDi(w, 'Spese generali')).toContain('Spese generali');
    });

    test('controprova — una voce senza `tipo` con un\'altra unità in usufrutto dice l\'importo dell\'ultimo piano, come una spesa', async () => {
        const senzaTipo = voce(1, 'Spese generali', 'spesa', { altre_unita: [{ ...ALTRA_UNITA, importo: 60000, importo_formattato: '€ 600,00' }] });
        delete (senzaTipo as Record<string, unknown>).tipo;
        const w = await costituzione([senzaTipo]);

        expect(testoDi(w, 'Spese generali')).toContain('Interno 2 (da Oreste a Nina, € 600,00 nell\'ultimo piano)');
        expect(testoDi(w, 'Spese generali')).not.toContain('Entrata');
    });
});

describe('PassaggioNew — la registrazione trova l\'elenco delle voci cambiato', () => {
    const MESSAGGIO = 'Le voci da spostare sono cambiate da quando il pannello le ha mostrate: il pannello è stato ricalcolato, ricontrolla l\'elenco e conferma.';

    /** La registrazione: il `router` di Inertia si ferma e restituisce le opzioni della chiamata (il corpo e il rimando dell'errore). */
    function fermaLaRegistrazione() {
        const chiamate: { corpo: Record<string, unknown>; opzioni: Record<string, any> }[] = [];
        vi.spyOn(router, 'post').mockImplementation(((_url: string, corpo: Record<string, unknown>, opzioni: Record<string, any>) => {
            chiamate.push({ corpo, opzioni });
        }) as never);

        return chiamate;
    }

    const pulsanteSpento = (w: Pagina) => {
        const pulsante = w.findAll('[type="submit"]').find((b) => b.text().includes('Registra passaggio'));
        if (!pulsante) throw new Error('Il pulsante «Registra passaggio» non c\'è.');
        const d = pulsante.attributes('disabled');

        return d !== undefined && d !== 'false';
    };

    test('controprova — la registrazione parte con l\'impronta del pannello mostrato', async () => {
        const w = await costituzione([voce(2, 'Affitto antenna', 'entrata'), voce(1, 'Spese generali', 'spesa')], 'abc');
        const chiamate = fermaLaRegistrazione();

        await w.find('form').trigger('submit');

        expect(chiamate.length).toBe(1);
        expect(chiamate[0].corpo.ordinaria_impronta).toBe('abc');
    });

    test('controprova — con l\'errore su `ordinaria_impronta` compare il messaggio, il pannello è richiesto di nuovo e si può registrare di nuovo con l\'impronta nuova', async () => {
        const w = await costituzione([voce(2, 'Affitto antenna', 'entrata'), voce(1, 'Spese generali', 'spesa')], 'abc');
        const chiamate = fermaLaRegistrazione();
        await w.find('form').trigger('submit');
        const richiesteDelPannello = post.mock.calls.length;

        // Nel frattempo è nata una voce di entrata: il server ricalcola il pannello, con un'altra impronta.
        post.mockImplementation(() => Promise.resolve({ data: structuredClone(pannello([voce(2, 'Affitto antenna', 'entrata'), voce(3, 'Canone parcheggio', 'entrata'), voce(1, 'Spese generali', 'spesa')], 'xyz')) }));
        chiamate[0].opzioni.onError({ ordinaria_impronta: MESSAGGIO });
        await flushPromises();

        expect(w.text()).toContain(MESSAGGIO);
        expect(post.mock.calls.length).toBe(richiesteDelPannello + 1);
        expect(testoDi(w, 'Canone parcheggio')).toContain('Canone parcheggio');
        expect(pulsanteSpento(w)).toBe(false);

        await w.find('form').trigger('submit');

        expect(chiamate.length).toBe(2);
        expect(chiamate[1].corpo.ordinaria_impronta).toBe('xyz');
        expect(w.text()).not.toContain(MESSAGGIO);
    });
});
