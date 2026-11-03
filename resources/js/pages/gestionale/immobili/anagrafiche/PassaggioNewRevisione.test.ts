// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, Fase 1-bis — il modulo «Registra passaggio» (`PassaggioNew.vue`) nella locazione: i rilievi R13, R16, R17 e R21.
 *
 * - R13 (decisione 83.4): la scheda delle pertinenze («Applica lo stesso passaggio anche a: ☐ Box 12») compare anche nella fine e
 *   nell'inizio della locazione, come nella vendita, e la spunta entra nel passaggio insieme a una scelta sola sul conguaglio
 *   (decisione 77, punto 1). La strada del server è in `RevisioneBeta50InterfacciaTest.php`.
 * - R16: Ines e Dora inquiline al 50 %, Dora uscita il 31/8; il 12/10 si registra in ritardo la fine di Ines al 1/7. Il pannello del
 *   server dice «Dal 1 luglio 2026 resta inquilino Dora, al 50 %.»; il modulo deve dire lo stesso nella casella «Nessuno», nella
 *   card «Chi entra» e in ciò che manca. La pagina riceve `titolari`, le sole righe in corso oggi: Dora non c'è. Il test le passa,
 *   come il server della correzione, le righe d'inquilino anche chiuse in `righeDellaLocazione` (sul modello di `righeDellEstinzione`;
 *   il controllo del server è in `RevisioneBeta50InterfacciaTest.php`), e il modulo le guarda alla data scritta.
 * - R17: la scelta sul conguaglio resta fatta quando cambia la data e con lei chi riceve la coppia, o torna già spuntata dopo un
 *   pannello senza coppie. Decisione 77, «nessuna già scelta»: se cambiano le persone della coppia, nessuna delle due voci resta scelta.
 *   Con la scheda della R13 le persone cambiano anche spuntando un box di un altro proprietario: c'è un test per questo, nato rosso
 *   perché la scheda mancava e poi perché la scelta non si rifaceva anche lì. Le risposte del pannello finto sono oggetti nuovi a ogni
 *   richiesta, come quelle del server.
 * - R21: una risposta del pannello che arriva quando il modulo è già cambiato (la richiesta nuova aspetta il debounce) riaccende
 *   «Registra passaggio». Finché il pannello non ha letto il modulo di adesso, il pulsante resta spento.
 *
 * Le cifre dei pannelli finti sono quelle del server, fatte a mano con la regola della coppia (a ognuno la parte intera, il centesimo
 * al resto più grande). «Inquilino» € 360,00 sull'Interno 1; fine di Ines il 1/7: 184 giorni dopo l'uscita, 36000 × 184/365 =
 * 18147,95 → la coppia 18148; con Ugo proprietario fino al 31/8 (62 giorni) ed Elsa dal 1/9 (122): 18148 × 62/184 = 6115,09 e
 * 18148 × 122/184 = 12032,91 → Ugo 6115, Elsa 12033. Fine il 1/10: 92 giorni dopo, 36000 × 92/365 = 9073,97 → la coppia 9074, tutta
 * a Elsa (€ 90,74).
 *
 * Si monta la pagina con `shallowMount` (i figli sono segnaposto), come in `PassaggioNewLocazione.test.ts`; il debounce di 350 ms si
 * fa avanzare con i timer finti, e le risposte del pannello si danno a mano.
 *
 * Cosa NON copre: il pannello `AnteprimaPassaggio.vue` (qui è un segnaposto) e le sue frasi; la registrazione (il `post` di Inertia):
 * il corpo che parte è quello dell'anteprima (`corpoAnteprima`), e si guarda quello; l'avviso quando chi esce è inquilino anche di
 * una pertinenza non spuntata (non deciso); il testo della scheda nella locazione (la nota sull'art. 818 c.c. parla del titolo, non
 * del contratto: non deciso); la successione e l'usufrutto.
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

// ---------------------------------------------------------------------------------------------------------------------
// R13, decisione 83.4: la scheda delle pertinenze nella locazione
// ---------------------------------------------------------------------------------------------------------------------

describe('PassaggioNew — R13, la scheda delle pertinenze nella fine e nell\'inizio della locazione (decisione 83.4)', () => {
    test('R13, il controllo — nella vendita la scheda c\'è, e la spunta del box entra nel passaggio che il pannello calcola', async () => {
        const w = monta({ tipo: 'vendita', rigaPreselezionata: 10, pertinenze: [BOX], anagrafiche: [LUCA] });

        const scheda = w.findComponent(PassaggioPertinenzeCard);
        expect(scheda.exists()).toBe(true);
        scheda.vm.$emit('update:modelValue', [20]);
        w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id')!.vm.$emit('update:modelValue', 5);
        await scriviData(w, '2026-07-01');
        post.mockResolvedValue({ data: pannello([]) });
        await lasciaRispondere();

        expect(ultimoCorpo()).toMatchObject({ tipo: 'vendita', decorrenza: '2026-07-01', pertinenze: [20] });
    });

    test('R13: nella fine di Ines con il Box 12 collegato il modulo mostra la scheda, e la spunta del box parte con una scelta sola sul conguaglio', async () => {
        const w = monta({ pertinenze: [BOX] });

        // Controllo: la pagina della fine è montata, con la casella «Nessuno» e il pannello.
        expect(casellaNessuno(w)).toBeDefined();
        expect(w.findComponent(AnteprimaPassaggio).exists()).toBe(true);

        const scheda = w.findComponent(PassaggioPertinenzeCard);
        expect(scheda.exists(), 'nella fine locazione la scheda delle pertinenze non c\'è').toBe(true);

        // Spuntato il box, il pannello propone le due coppie (Interno 1 € 163,33 e Box 12 € 18,15 a Ugo, le cifre del server): una scelta
        // sola, e il corpo che parte porta il box e la scelta insieme.
        scheda.vm.$emit('update:modelValue', [20]);
        await casellaNessuno(w)!.setValue(true);
        await scriviData(w, '2026-07-01');
        post.mockImplementation(() => Promise.resolve({ data: pannello([coppia('Ugo', 1, 16333), coppia('Ugo', 1, 1815, 20)]) }));
        await lasciaRispondere();
        w.findComponent(AnteprimaPassaggio).vm.$emit('update:sceltaConguaglio', 'scrivi');
        await lasciaRispondere();

        expect(ultimoCorpo()).toMatchObject({ tipo: 'fine_locazione', decorrenza: '2026-07-01', anagrafica_entrante_id: null, pertinenze: [20], conguaglio: 'scrivi' });
        expect(w.findAllComponents(AnteprimaPassaggio)).toHaveLength(1);
    });

    test('R13: nell\'inizio della locazione di Luca con il Box 12 collegato il modulo mostra la scheda, e la spunta del box entra nel passaggio', async () => {
        const w = monta({ tipo: 'inizio_locazione', rigaPreselezionata: null, titolari: [UGO], pertinenze: [{ ...BOX, titolari: [{ nome: 'Ugo', tipologia: 'proprietario' }] }], anagrafiche: [LUCA] });

        // Controllo: la pagina dell'inizio è montata, con la scelta di chi entra.
        const chiEntra = w.findAllComponents(vSelect).find((s) => s.attributes('id') === 'anagrafica_entrante_id');
        expect(chiEntra).toBeDefined();

        const scheda = w.findComponent(PassaggioPertinenzeCard);
        expect(scheda.exists(), 'nell\'inizio della locazione la scheda delle pertinenze non c\'è').toBe(true);

        scheda.vm.$emit('update:modelValue', [20]);
        chiEntra!.vm.$emit('update:modelValue', 5);
        await scriviData(w, '2026-09-01');
        post.mockResolvedValue({ data: pannello([]) });
        await lasciaRispondere();

        // Nell'inizio la scelta sul conguaglio non si chiede: non parte.
        expect(ultimoCorpo()).toMatchObject({ tipo: 'inizio_locazione', anagrafica_entrante_id: 5, decorrenza: '2026-09-01', pertinenze: [20], conguaglio: null });
    });
});

// ---------------------------------------------------------------------------------------------------------------------
// R16: la fine retrodatata con la coinquilina uscita prima di oggi
// ---------------------------------------------------------------------------------------------------------------------

describe('PassaggioNew — R16, la fine di Ines al 1/7 registrata il 12/10, con Dora uscita il 31/8', () => {
    // Come la pagina le riceve il 12/10: `titolari` in corso oggi (Ugo e Ines), e le righe d'inquilino anche chiuse.
    const INES_50 = riga(11, 2, 'Ines', 'inquilino', 50);
    const DORA_USCITA = riga(12, 3, 'Dora', 'inquilino', 50, '2019-01-01', '2026-08-31');
    const props = { titolari: [UGO, INES_50], righeDellaLocazione: [INES_50, DORA_USCITA], oggi: '2026-10-12' };

    test('R16, il controllo — se la pagina vedesse Dora fra i titolari (come prima del 31/8), con il 1/7 direbbe già «resta inquilino Dora»: le parole ci sono, manca la riga', async () => {
        const w = monta({ titolari: [UGO, INES_50, { ...DORA_USCITA, data_fine: null }], oggi: '2026-08-15' });
        await scriviData(w, '2026-07-01');

        expect(testi(w)).toContain('Nessuno — resta inquilino Dora');
        expect(testi(w)).toContain('Un nuovo inquilino, oppure nessuno: resta inquilino Dora.');
    });

    test('R16, il controllo — la stessa fine con la data dopo l\'uscita di Dora (15/9): l\'unità resta davvero sfitta, e il modulo lo dice', async () => {
        const w = monta(props);
        await scriviData(w, '2026-09-15');

        expect(w.text()).toContain('Nessuno — l\'unità resta sfitta');
        expect(w.text()).toContain('Un nuovo inquilino, oppure nessuno: l\'unità resta sfitta.');
    });

    test('R16: con la data 1/7, quando Dora era inquilina, la casella, la card e ciò che manca dicono «resta inquilino Dora», come il pannello del server', async () => {
        const w = monta(props);
        await scriviData(w, '2026-07-01');

        // Controllo: la casella per dire che nessuno entra c'è, e la data è partita (in ciò che manca non c'è più).
        expect(casellaNessuno(w)).toBeDefined();
        expect(w.findComponent(AnteprimaPassaggio).props('mancante')).not.toContain('la data dell\'atto');

        expect(testi(w)).toContain('Nessuno — resta inquilino Dora');
        expect(testi(w)).toContain('Un nuovo inquilino, oppure nessuno: resta inquilino Dora.');
        expect(testi(w)).not.toContain('resta sfitta');
    });
});

// ---------------------------------------------------------------------------------------------------------------------
// R17: la scelta sul conguaglio quando cambia la data
// ---------------------------------------------------------------------------------------------------------------------

describe('PassaggioNew — R17, la scelta sul conguaglio quando la data cambia chi riceve la coppia (decisione 77, «nessuna già scelta»)', () => {
    // Il 12/10: Elsa proprietaria dal 1/9 (Ugo ha venduto, la sua riga è chiusa), Ines inquilina.
    const ELSA = riga(13, 4, 'Elsa', 'proprietario', 100, '2026-09-01');
    /** Le risposte del server per data: il 1/7 Ugo 6115 ed Elsa 12033; il 1/10 Elsa 9074; il 1/1/2027, fuori dall'esercizio, nessuna coppia. */
    const RISPOSTE: Record<string, ReturnType<typeof pannello>> = {
        '2026-07-01': pannello([coppia('Ugo', 1, 6115), coppia('Elsa', 4, 12033)]),
        '2026-10-01': pannello([coppia('Elsa', 4, 9074)]),
        '2027-01-01': pannello([]),
    };

    async function fineConScrivi() {
        // Ogni risposta è un oggetto nuovo, come quella del server: con lo stesso oggetto una correzione che azzera la scelta a ogni
        // risposta non si vedrebbe, perché il pannello non cambierebbe.
        post.mockImplementation((_url: string, corpo: unknown) => Promise.resolve({ data: structuredClone(RISPOSTE[(corpo as { decorrenza: string }).decorrenza]) }));
        const w = monta({ titolari: [ELSA, INES], oggi: '2026-10-12' });
        await casellaNessuno(w)!.setValue(true);
        await scriviData(w, '2026-07-01');
        await lasciaRispondere();
        w.findComponent(AnteprimaPassaggio).vm.$emit('update:sceltaConguaglio', 'scrivi');
        await lasciaRispondere();

        // Controllo: «Scrivi» è scelta, il pannello del 1/7 ha le coppie di Ugo e di Elsa, e la scelta è partita.
        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBe('scrivi');
        expect(ultimoCorpo()).toMatchObject({ decorrenza: '2026-07-01', conguaglio: 'scrivi' });

        return w;
    }

    function nomiDelleCoppie(w: Pagina): string[] {
        return ((w.findComponent(AnteprimaPassaggio).props('dati') as ReturnType<typeof pannello> | null)?.rate.conguaglio?.coppie ?? []).map((c) => c.entrante_nome);
    }

    test('R17, il controllo — una nota scritta dopo la scelta rifà il pannello con le stesse persone, e la scelta resta', async () => {
        const w = await fineConScrivi();
        const chiamate = post.mock.calls.length;

        // La nota è un segnaposto: si scrive dal suo evento.
        w.findAllComponents(Textarea).find((t) => t.attributes('id') === 'nota')!.vm.$emit('update:modelValue', 'Disdetta ricevuta il 2/6');
        await lasciaRispondere(2);

        expect(post.mock.calls.length).toBeGreaterThan(chiamate);
        expect(ultimoCorpo()).toMatchObject({ nota: 'Disdetta ricevuta il 2/6', conguaglio: 'scrivi' });
        expect(nomiDelleCoppie(w)).toEqual(['Ugo', 'Elsa']);
        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBe('scrivi');
    });

    test('R17: scelto «Scrivi» con il 1/7 (coppie a Ugo e a Elsa), la data corretta al 1/10 (coppia solo a Elsa): nessuna delle due voci resta scelta', async () => {
        const w = await fineConScrivi();

        await scriviData(w, '2026-10-01');
        await lasciaRispondere(3);

        // Controllo: il pannello ha letto il 1/10, e ora la coppia è solo di Elsa.
        expect(ultimoCorpo()).toMatchObject({ decorrenza: '2026-10-01' });
        expect(nomiDelleCoppie(w)).toEqual(['Elsa']);

        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBeNull();
    });

    test('R17: scelto «Scrivi», poi una data senza coppie (1/1/2027) e di nuovo il 1/7: la voce non torna già scelta', async () => {
        const w = await fineConScrivi();

        await scriviData(w, '2027-01-01');
        await lasciaRispondere(3);
        // Controllo: con il 1/1/2027 il pannello non propone coppie.
        expect(nomiDelleCoppie(w)).toEqual([]);

        await scriviData(w, '2026-07-01');
        await lasciaRispondere(3);
        // Controllo: le coppie del 1/7 sono tornate.
        expect(nomiDelleCoppie(w)).toEqual(['Ugo', 'Elsa']);

        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBeNull();
    });

    test('R17 con la scheda della R13: scelto «Scrivi» per l\'Interno 1 (coppia a Ugo), poi spuntato il Box 12 di Rita (coppia anche a Rita): nessuna delle due voci resta scelta', async () => {
        // Con la decisione 83.4 la scheda delle pertinenze entra nella fine, e spuntare un box cambia chi riceve la coppia come la data.
        // Le cifre sono quelle del server (`RevisioneBeta50InterfacciaTest.php`, il box di Rita): Interno 1 a 900 millesimi, «Inquilino»
        // 32400, 32400 × 184/365 = 16333,15 → 16333 a Ugo; Box 12 a 100, «Inquilino» 3600, 3600 × 184/365 = 1814,79 → 1815 a Rita.
        post.mockImplementation((_url: string, corpo: unknown) => Promise.resolve({
            data: (corpo as { pertinenze: number[] }).pertinenze.includes(20)
                ? pannello([coppia('Ugo', 1, 16333), coppia('Rita', 6, 1815, 20)])
                : pannello([coppia('Ugo', 1, 16333)]),
        }));
        const w = monta({ pertinenze: [{ ...BOX, titolari: [{ nome: 'Rita', tipologia: 'proprietario' }, { nome: 'Ines', tipologia: 'inquilino' }] }] });

        // R13: la scheda delle pertinenze c'è anche nella fine locazione.
        const scheda = w.findComponent(PassaggioPertinenzeCard);
        expect(scheda.exists(), 'nella fine locazione la scheda delle pertinenze non c\'è (R13)').toBe(true);

        await casellaNessuno(w)!.setValue(true);
        await scriviData(w, '2026-07-01');
        await lasciaRispondere();
        w.findComponent(AnteprimaPassaggio).vm.$emit('update:sceltaConguaglio', 'scrivi');
        await lasciaRispondere();
        // Controllo: «Scrivi» è scelta per la sola coppia di Ugo, ed è partita.
        expect(nomiDelleCoppie(w)).toEqual(['Ugo']);
        expect(ultimoCorpo()).toMatchObject({ pertinenze: [], conguaglio: 'scrivi' });

        scheda.vm.$emit('update:modelValue', [20]);
        await lasciaRispondere(3);
        // Controllo: il box è nel passaggio, e la coppia va anche a Rita.
        expect(ultimoCorpo()).toMatchObject({ pertinenze: [20] });
        expect(nomiDelleCoppie(w)).toEqual(['Ugo', 'Rita']);

        expect(w.findComponent(AnteprimaPassaggio).props('sceltaConguaglio')).toBeNull();
    });
});

// ---------------------------------------------------------------------------------------------------------------------
// R21: una risposta vecchia del pannello
// ---------------------------------------------------------------------------------------------------------------------

describe('PassaggioNew — R21, la risposta del pannello per un modulo che non c\'è più', () => {
    /** Le richieste del pannello restano in volo finché il test non le fa rispondere. */
    function inVolo() {
        const attese: { corpo: Record<string, unknown>; rispondi: () => void }[] = [];
        post.mockImplementation((_url: string, corpo: unknown) => new Promise((resolve) => {
            attese.push({ corpo: corpo as Record<string, unknown>, rispondi: () => resolve({ data: pannello([]) }) });
        }));

        return attese;
    }

    async function fineDiInesIl(w: Pagina, data: string) {
        await casellaNessuno(w)!.setValue(true);
        await scriviData(w, data);
    }

    test('R21, il controllo — senza cambiare il modulo, la risposta accende il pulsante: «Niente viene scritto finché non confermi.»', async () => {
        const attese = inVolo();
        const w = monta({});
        await fineDiInesIl(w, '2026-07-01');
        await vi.advanceTimersByTimeAsync(350);
        expect(attese).toHaveLength(1);
        expect(w.text()).toContain('Il pannello sta rileggendo il modulo.');
        expect(pulsanteSpento(w)).toBe(true);

        attese[0].rispondi();
        await flushPromises();

        expect(w.text()).toContain('Niente viene scritto finché non confermi.');
        expect(pulsanteSpento(w)).toBe(false);
    });

    test('R21: la risposta del 1/7 arriva dopo che la data è diventata 1/10, prima che parta la richiesta nuova: il pulsante resta spento e il pannello dice che sta rileggendo il modulo', async () => {
        const attese = inVolo();
        const w = monta({});
        await fineDiInesIl(w, '2026-07-01');
        await vi.advanceTimersByTimeAsync(350);
        await scriviData(w, '2026-10-01');
        await vi.advanceTimersByTimeAsync(200);
        attese[0].rispondi();
        await flushPromises();

        // Controlli: è partita una richiesta sola, quella del 1/7; quella del 1/10 aspetta ancora il debounce.
        expect(attese).toHaveLength(1);
        expect(attese[0].corpo).toMatchObject({ decorrenza: '2026-07-01' });

        expect(w.text()).toContain('Il pannello sta rileggendo il modulo.');
        expect(w.findComponent(AnteprimaPassaggio).props('inCorso')).toBe(true);
        expect(pulsanteSpento(w)).toBe(true);

        // Dopo il debounce parte il 1/10, e con la sua risposta il pulsante si accende.
        await vi.advanceTimersByTimeAsync(150);
        expect(attese).toHaveLength(2);
        expect(attese[1].corpo).toMatchObject({ decorrenza: '2026-10-01' });
        attese[1].rispondi();
        await flushPromises();
        expect(w.text()).toContain('Niente viene scritto finché non confermi.');
    });
});
