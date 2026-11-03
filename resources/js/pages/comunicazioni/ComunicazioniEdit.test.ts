// @vitest-environment jsdom

/**
 * La pagina di modifica di una comunicazione (pannello): la riga sotto la tendina «Pubblicata».
 *
 * La modifica non approva (decisione 127.2): su una comunicazione in moderazione «Pubblicata» da sola
 * non la mostra ai condòmini e non fa partire avvisi; la riga sotto la tendina lo dice, e dice dove si
 * approva. Su una approvata la riga non c'è. Aperta e mandata senza toccare niente, la pagina manda i
 * dati ricevuti.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import axios from 'axios';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
// I permessi di chi apre la pagina: `null` = tutti (l'amministratore), altrimenti l'elenco dei concessi.
const permessi = vi.hoisted(() => ({ concessi: null as string[] | null }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true,
        hasPermission: (chiesti: string[]) => permessi.concessi === null || chiesti.some((p) => permessi.concessi!.includes(p)),
    }),
}));
// Le anagrafiche che i palazzi hanno davvero (la pagina le chiede a `fetch-anagrafiche`); ogni test può
// cambiarle, per esempio con un destinatario salvato che nel frattempo non è più nel palazzo.
const palazzo = vi.hoisted(() => ({ anagrafiche: [] as { id: number; nome: string }[] }));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: palazzo.anagrafiche })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniEdit = (await import('./ComunicazioniEdit.vue')).default;

function comunicazione(campi: Record<string, unknown> = {}) {
    return {
        id: 11, subject: "Lavori sull'impianto — «urgenti»", description: "Prima riga\nseconda riga",
        priority: 'urgente', is_private: false, is_published: true, is_approved: false, is_featured: true, can_comment: false,
        condomini: { options: [{ value: 3, label: 'Condominio Aurora' }], full: [] },
        anagrafiche: [{ id: 41, nome: 'Anna Neri' }, { id: 42, nome: 'Bruno Galli' }],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(ComunicazioniEdit, {
        props: { condomini: [], anagrafiche: [], comunicazione: comunicazione(campi) as never },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

beforeEach(() => {
    permessi.concessi = null;
    palazzo.anagrafiche = [{ id: 41, nome: 'Anna Neri' }, { id: 42, nome: 'Bruno Galli' }];
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('ComunicazioniEdit — la riga sotto la tendina «Pubblicata» (decisione 127.2)', () => {
    test('su una comunicazione in moderazione la riga dice che serve l\'approvazione', () => {
        expect(monta({ is_approved: false }).text()).toContain('comunicazioni.publication.needs_approval');
    });

    test('su una comunicazione approvata la riga non c\'è', () => {
        expect(monta({ is_approved: true }).text()).not.toContain('comunicazioni.publication.needs_approval');
        expect(monta({ is_approved: 1, is_published: 0 }).text()).not.toContain('comunicazioni.publication.needs_approval');
    });

    test('aperta e mandata senza toccare niente, manda i dati ricevuti', async () => {
        const pagina = monta();
        await flushPromises();

        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/comunicazioni.update');
        expect(chiamata[1]).toEqual({
            subject: "Lavori sull'impianto — «urgenti»", description: "Prima riga\nseconda riga", priority: 'urgente',
            stato: '', condomini_ids: [3], can_comment: false, is_featured: true, is_published: true,
            anagrafiche: [41, 42], avvisa_destinatari: false,
        });
    });
});

describe('ComunicazioniEdit — la riga sotto la tendina dice dove si approva solo a chi può approvare', () => {
    // Lo scudo dell'elenco lo vede solo chi ha «Approva comunicazioni» (`components/comunicazioni/columns.ts`): a chi
    // non ce l'ha — il collaboratore di serie, per segnalazioni e comunicazioni — la riga dice chi
    // approva, come la frase dei residenti di una privata.
    test('chi approva legge lo scudo', () => {
        permessi.concessi = ['Approva comunicazioni'];
        const testo = monta({ is_approved: false }).text();
        expect(testo).toContain('comunicazioni.publication.needs_approval');
        expect(testo).not.toContain('comunicazioni.publication.waiting_approver');
    });

    test('chi non approva legge chi approva, non lo scudo', () => {
        permessi.concessi = [];
        for (const pagina of [monta({ is_approved: false }), monta({ is_approved: false, is_published: false })]) {
            const testo = pagina.text();
            expect(testo).toContain('comunicazioni.publication.waiting_approver');
            expect(testo).not.toContain('comunicazioni.publication.needs_approval');
        }
    });

    test('il permesso di approvare un\'altra entità non conta', () => {
        permessi.concessi = ['Approva segnalazioni'];
        expect(monta({ is_approved: false }).text()).toContain('comunicazioni.publication.waiting_approver');
    });

    test('su un oggetto approvato la riga non c\'è, con o senza il permesso', () => {
        for (const concessi of [[], ['Approva comunicazioni']]) {
            permessi.concessi = concessi;
            const testo = monta({ is_approved: true }).text();
            expect(testo).not.toContain('comunicazioni.publication.waiting_approver');
            expect(testo).not.toContain('comunicazioni.publication.needs_approval');
        }
    });
});

describe('ComunicazioniEdit — i destinatari che non sono più nel palazzo', () => {
    // Bruno era destinatario; poi l'amministratore ha staccato la sua anagrafica dal palazzo. Aprire la
    // modifica per correggere un refuso e salvare non deve toglierlo: chi lo toglie lo sceglie. Il filtro
    // sulle anagrafiche dei palazzi vale quando chi modifica cambia i palazzi.
    const ANNA = { id: 41, nome: 'Anna Neri' };
    const BRUNO = { id: 42, nome: 'Bruno Galli' };
    const CARLA = { id: 43, nome: "Carla D'Amico" };

    type Stato = { submit: () => void; form: Record<string, unknown>; anagraficheOptions: { id: number; nome: string }[] };
    const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
    const scomoda = {
        is_approved: true, is_published: false,
        condomini: { options: [{ value: 3, label: 'Condominio Aurora' }, { value: 4, label: "Residenza l'Ulivo" }], full: [] },
        anagrafiche: [ANNA, BRUNO, CARLA],
    };

    test('aperta e inviata senza toccare niente, il corpo è uguale ai dati ricevuti, anche con un destinatario uscito dal palazzo', async () => {
        palazzo.anagrafiche = [ANNA, CARLA];
        const pagina = monta(scomoda);
        await flushPromises();

        stato(pagina).submit();

        expect(vi.mocked(router.put).mock.calls.at(-1)![1]).toEqual({
            subject: "Lavori sull'impianto — «urgenti»", description: "Prima riga\nseconda riga", priority: 'urgente',
            stato: '', condomini_ids: [3, 4], can_comment: false, is_featured: true, is_published: false,
            anagrafiche: [41, 42, 43], avvisa_destinatari: false,
        });
    });

    test('nell\'elenco il destinatario uscito dal palazzo si vede come scelto', async () => {
        palazzo.anagrafiche = [ANNA, CARLA];
        const pagina = monta(scomoda);
        await flushPromises();

        expect(stato(pagina).anagraficheOptions).toContainEqual(BRUNO);
        expect(stato(pagina).anagraficheOptions).toContainEqual(ANNA);
    });

    test('quando il server rimanda la pagina con gli stessi palazzi, i destinatari restano', async () => {
        // È ciò che succede dopo un errore di validazione: la pagina riceve la comunicazione di nuovo.
        palazzo.anagrafiche = [ANNA, CARLA];
        const pagina = monta(scomoda);
        await flushPromises();

        await pagina.setProps({ comunicazione: comunicazione(scomoda) as never });
        await flushPromises();
        stato(pagina).submit();

        expect((vi.mocked(router.put).mock.calls.at(-1)![1] as { anagrafiche: number[] }).anagrafiche).toEqual([41, 42, 43]);
    });

    test('cambiando i palazzi, i destinatari che non sono fra le anagrafiche dei palazzi scelti si tolgono', async () => {
        palazzo.anagrafiche = [ANNA, CARLA];
        const pagina = monta(scomoda);
        await flushPromises();

        palazzo.anagrafiche = [ANNA];
        stato(pagina).form.condomini_ids = [3];
        await flushPromises();
        stato(pagina).submit();

        expect((vi.mocked(router.put).mock.calls.at(-1)![1] as { anagrafiche: number[] }).anagrafiche).toEqual([41]);
        expect(stato(pagina).anagraficheOptions).toEqual([ANNA]);
    });
});

describe('ComunicazioniEdit — dopo un salvataggio rifiutato a palazzi cambiati', () => {
    // Dopo un errore di validazione Inertia tiene lo stato: la pagina non si rimonta, riceve di nuovo la
    // comunicazione salvata e il modulo torna ai palazzi e ai destinatari salvati. Tornare ai palazzi
    // salvati non è sceglierne altri: i destinatari salvati restano, come all'apertura, anche chi nel
    // frattempo non è più nel palazzo. Chi è stato scelto in un palazzo poi tolto si toglie, come sempre.
    const ANNA = { id: 41, nome: 'Anna Neri' };
    const BRUNO = { id: 42, nome: 'Bruno Galli' };
    const CARLA = { id: 43, nome: "Carla D'Amico" };
    const ZENO = { id: 57, nome: "Zeno Dell'Orto" };
    // Le anagrafiche di ogni palazzo, come le restituisce `fetch-anagrafiche` per i palazzi chiesti.
    // Bruno era destinatario, ma non è più in nessun palazzo.
    const PER_PALAZZO: Record<number, { id: number; nome: string }[]> = { 3: [ANNA], 4: [CARLA], 5: [ZENO] };
    const rispostaDeiPalazzi = (ids: number[]) => ({ data: ids.flatMap((id) => PER_PALAZZO[id] ?? []) });
    const idsChiesti = (config: unknown) => ((config as { params?: { condomini_ids?: number[] } })?.params?.condomini_ids ?? []).map(Number);

    type Stato = { submit: () => void; form: Record<string, unknown>; anagraficheOptions: { id: number; nome: string }[] };
    const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
    const scomoda = {
        is_approved: true, is_published: false,
        condomini: { options: [{ value: 3, label: 'Condominio Aurora' }, { value: 4, label: "Residenza l'Ulivo" }], full: [] },
        anagrafiche: [ANNA, BRUNO, CARLA],
    };
    const corpo = () => vi.mocked(router.put).mock.calls.at(-1)![1] as Record<string, unknown>;

    beforeEach(() => {
        vi.mocked(axios.get).mockImplementation(async (_url: string, config?: unknown) => rispostaDeiPalazzi(idsChiesti(config)));
    });
    afterEach(() => {
        vi.mocked(axios.get).mockImplementation(async () => ({ data: palazzo.anagrafiche }));
    });

    /** Chi modifica toglie un palazzo; il salvataggio si rifiuta e il server rimanda la comunicazione salvata. */
    async function rifiutatoAPalazziCambiati() {
        const pagina = monta(scomoda);
        await flushPromises();

        stato(pagina).form.condomini_ids = [3];
        await flushPromises();
        expect(stato(pagina).form.anagrafiche).toEqual([41]);

        await pagina.setProps({ comunicazione: comunicazione(scomoda) as never });
        await flushPromises();

        return pagina;
    }

    test('il server rimanda la pagina salvata: inviata senza toccare niente, il corpo è uguale ai dati ricevuti', async () => {
        const pagina = await rifiutatoAPalazziCambiati();

        stato(pagina).submit();

        expect(corpo()).toEqual({
            subject: "Lavori sull'impianto — «urgenti»", description: "Prima riga\nseconda riga", priority: 'urgente',
            stato: '', condomini_ids: [3, 4], can_comment: false, is_featured: true, is_published: false,
            anagrafiche: [41, 42, 43], avvisa_destinatari: false,
        });
    });

    test('il server rimanda la pagina salvata: nell\'elenco il destinatario uscito dal palazzo si vede come scelto', async () => {
        const pagina = await rifiutatoAPalazziCambiati();

        expect(stato(pagina).anagraficheOptions).toEqual([ANNA, CARLA, BRUNO]);
    });

    test('tornando ai palazzi salvati, chi è stato scelto in un palazzo poi tolto si toglie; i destinatari salvati restano', async () => {
        const pagina = monta(scomoda);
        await flushPromises();

        // Aggiunge un palazzo (Bruno, che non è in nessuno dei tre, si toglie) e sceglie Zeno, di quel palazzo.
        stato(pagina).form.condomini_ids = [3, 4, 5];
        await flushPromises();
        expect(stato(pagina).form.anagrafiche).toEqual([41, 43]);
        stato(pagina).form.anagrafiche = [41, 43, 57];

        // Poi toglie il palazzo aggiunto: i palazzi sono di nuovo quelli salvati.
        stato(pagina).form.condomini_ids = [3, 4];
        await flushPromises();
        stato(pagina).submit();

        expect(corpo().anagrafiche).toEqual([41, 43]);
        expect(stato(pagina).anagraficheOptions).toEqual([ANNA, CARLA]);
    });

    test('la risposta dei palazzi di prima, arrivata dopo che il server ha rimandato la pagina, non toglie destinatari', async () => {
        // La richiesta per i palazzi scelti da chi modifica parte e resta in sospeso; intanto il salvataggio si
        // rifiuta e il server rimanda la pagina. Vale l'elenco dei palazzi di adesso, non quello di prima.
        const pagina = monta(scomoda);
        await flushPromises();

        let rispondi: (valore: unknown) => void = () => {};
        vi.mocked(axios.get).mockImplementationOnce(() => new Promise((resolve) => { rispondi = resolve; }) as never);
        stato(pagina).form.condomini_ids = [3];
        await flushPromises();

        await pagina.setProps({ comunicazione: comunicazione(scomoda) as never });
        await flushPromises();
        rispondi(rispostaDeiPalazzi([3]));
        await flushPromises();
        stato(pagina).submit();

        expect(corpo().condomini_ids).toEqual([3, 4]);
        expect(corpo().anagrafiche).toEqual([41, 42, 43]);
        expect(stato(pagina).anagraficheOptions).toEqual([ANNA, CARLA, BRUNO]);
    });
});
