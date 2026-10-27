// @vitest-environment jsdom

/**
 * La pagina di modifica di un documento d'archivio: che cosa manda e che cosa dice.
 *
 * - **Coda 307, i destinatari con un file nuovo.** Con un file il modulo parte come FormData
 *   (`objectToFormData` di `@inertiajs/core`), e un elenco vuoto lì non si scrive affatto: «tolti tutti
 *   i destinatari» arrivava al server uguale a «campo non mandato», che dalla Coda 307 vuol dire «non
 *   toccare». La pagina manda `anagrafiche_nel_modulo`, il segno che il campo c'era; qui si guarda il
 *   corpo che viaggia davvero, passato dalla stessa conversione del router.
 * - **La riga sotto la tendina** (decisione 127.2): su un documento non approvato «Pubblicato» da solo
 *   non lo mostra ai condòmini, e la pagina lo dice.
 * - Aperto e mandato senza toccare niente, il modulo manda i dati ricevuti.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave, e si leggono le chiavi.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';
import axios from 'axios';
import { objectToFormData, router } from '@inertiajs/core';

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
// Le anagrafiche che i palazzi hanno davvero: la pagina le chiede solo quando chi modifica cambia i palazzi.
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [{ id: 41, nome: 'Anna Neri' }] })), post: vi.fn() } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DocumentiEdit = (await import('./DocumentiEdit.vue')).default;

const ANNA = { id: 41, nome: 'Anna Neri' };
const BRUNO = { id: 42, nome: 'Bruno Galli' };

function documento(campi: Record<string, unknown> = {}) {
    return {
        id: 7, name: "Verbale dell'assemblea «straordinaria»", description: "Prima riga\nseconda riga",
        is_published: false, is_approved: true, mime_type: 'PDF', path: 'documenti/vecchio.pdf', file_size: 10,
        condomini: { options: [{ value: 3, label: 'Condominio Aurora' }, { value: 4, label: 'Condominio Borea' }], full: [] },
        anagrafiche: [ANNA, BRUNO],
        categorie: [{ id: 5, name: 'Verbali' }, { id: 6, name: 'Contratti' }],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(DocumentiEdit, {
        props: { limiteFile: '2 MB', documento: documento(campi), condomini: [], categories: [], anagrafiche: [ANNA, BRUNO] as never },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Vm = { $: { setupState: { form: Record<string, unknown>; submit: () => void } } };
const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as Vm).$.setupState;

/** Il corpo che parte: i dati che il modulo dà al router, convertiti come li converte il router se c'è un file. */
function corpoInviato(): { url: string; dati: Record<string, unknown> } {
    const chiamata = vi.mocked(router.put).mock.calls.at(-1);
    expect(chiamata, 'il modulo non ha chiamato router.put').toBeDefined();

    return { url: String(chiamata![0]), dati: chiamata![1] as Record<string, unknown> };
}

beforeEach(() => {
    permessi.concessi = null;
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('DocumentiEdit — i destinatari con un file nuovo (Coda 307)', () => {
    test('con un file nuovo e i destinatari tolti, il corpo FormData porta il segno del campo e nessun destinatario', () => {
        const pagina = monta();
        const s = stato(pagina);
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });
        s.form.anagrafiche = [];

        s.submit();

        const { dati } = corpoInviato();
        const corpo = objectToFormData(dati as never);
        const chiavi = [...corpo.keys()];
        expect(chiavi.some((k) => k.startsWith('anagrafiche[') || k === 'anagrafiche'), chiavi.join(', ')).toBe(false);
        expect(corpo.get('anagrafiche_nel_modulo')).toBe('1');
        expect(corpo.get('file')).toBeInstanceOf(File);
    });

    test('con un file nuovo e i destinatari tenuti, il corpo porta i destinatari e il segno', () => {
        const pagina = monta();
        const s = stato(pagina);
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });

        s.submit();

        const corpo = objectToFormData(corpoInviato().dati as never);
        expect(corpo.getAll('anagrafiche[]')).toEqual(['41', '42']);
        expect(corpo.get('anagrafiche_nel_modulo')).toBe('1');
    });

    test('con un file nuovo e il palazzo tolto, nel corpo non ci sono né i palazzi né i destinatari, e c\'è il segno', async () => {
        // Togliere il palazzo svuota anche i destinatari (il `watch` dei condomìni). È il corpo con cui
        // `tests/Feature/Portale/DocumentoFileEDestinatariTest.php` prova che il server lo rifiuta come
        // senza file: con il segno, un elenco assente vale vuoto.
        const pagina = monta();
        const s = stato(pagina);
        s.form.condomini_ids = [];
        await nextTick();
        expect(s.form.anagrafiche).toEqual([]);
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });

        s.submit();

        const corpo = objectToFormData(corpoInviato().dati as never);
        const chiavi = [...corpo.keys()];
        expect(chiavi.some((k) => k.startsWith('condomini_ids')), chiavi.join(', ')).toBe(false);
        expect(chiavi.some((k) => k.startsWith('anagrafiche[') || k === 'anagrafiche'), chiavi.join(', ')).toBe(false);
        expect(corpo.get('anagrafiche_nel_modulo')).toBe('1');
    });

    test('con un file nuovo e le categorie tolte, nel corpo non ci sono le categorie, e c\'è il segno', () => {
        const pagina = monta();
        const s = stato(pagina);
        s.form.categorie = [];
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });

        s.submit();

        const corpo = objectToFormData(corpoInviato().dati as never);
        const chiavi = [...corpo.keys()];
        expect(chiavi.some((k) => k.startsWith('categorie')), chiavi.join(', ')).toBe(false);
        expect(corpo.getAll('condomini_ids[]')).toEqual(['3', '4']);
        expect(corpo.get('anagrafiche_nel_modulo')).toBe('1');
    });

    test('aperto e mandato senza toccare niente, il modulo manda i dati ricevuti', () => {
        const pagina = monta();

        stato(pagina).submit();

        const { url, dati } = corpoInviato();
        expect(url).toBe('/documenti.update');
        expect(dati).toEqual({
            name: "Verbale dell'assemblea «straordinaria»",
            description: "Prima riga\nseconda riga",
            condomini_ids: [3, 4],
            is_published: false,
            anagrafiche: [41, 42],
            categorie: [5, 6],
            file: null,
            avvisa_destinatari: false,
            anagrafiche_nel_modulo: true,
        });
    });
});

describe('DocumentiEdit — la riga sotto la tendina «Pubblicato» (decisione 127.2)', () => {
    test('su un documento non approvato la riga dice che serve l\'approvazione', () => {
        expect(monta({ is_approved: false, is_published: true }).text()).toContain('documenti.publication.needs_approval');
        expect(monta({ is_approved: 0, is_published: 0 }).text()).toContain('documenti.publication.needs_approval');
    });

    test('su un documento approvato la riga non c\'è', () => {
        expect(monta({ is_approved: true }).text()).not.toContain('documenti.publication.needs_approval');
        expect(monta({ is_approved: 1, is_published: 0 }).text()).not.toContain('documenti.publication.needs_approval');
    });
});

describe('DocumentiEdit — la riga sotto la tendina dice dove si approva solo a chi può approvare', () => {
    // Lo scudo dell'elenco lo vede solo chi ha «Approva documenti archivio» (`components/documenti/columns.ts`): a chi
    // non ce l'ha — il collaboratore di serie, per segnalazioni e comunicazioni — la riga dice chi
    // approva, come la frase dei residenti di una privata.
    test('chi approva legge lo scudo', () => {
        permessi.concessi = ['Approva documenti archivio'];
        const testo = monta({ is_approved: false, is_published: true }).text();
        expect(testo).toContain('documenti.publication.needs_approval');
        expect(testo).not.toContain('documenti.publication.waiting_approver');
    });

    test('chi non approva legge chi approva, non lo scudo', () => {
        permessi.concessi = [];
        for (const pagina of [monta({ is_approved: false, is_published: true }), monta({ is_approved: 0, is_published: 0 })]) {
            const testo = pagina.text();
            expect(testo).toContain('documenti.publication.waiting_approver');
            expect(testo).not.toContain('documenti.publication.needs_approval');
        }
    });

    test('il permesso di approvare un\'altra entità non conta', () => {
        permessi.concessi = ['Approva segnalazioni'];
        expect(monta({ is_approved: false, is_published: true }).text()).toContain('documenti.publication.waiting_approver');
    });

    test('su un oggetto approvato la riga non c\'è, con o senza il permesso', () => {
        for (const concessi of [[], ['Approva documenti archivio']]) {
            permessi.concessi = concessi;
            const testo = monta({ is_approved: true }).text();
            expect(testo).not.toContain('documenti.publication.waiting_approver');
            expect(testo).not.toContain('documenti.publication.needs_approval');
        }
    });
});

describe('DocumentiEdit — un destinatario che non è più nel palazzo', () => {
    // La stessa forma di `ComunicazioniEdit`: aperto e inviato senza toccare niente, il documento resta
    // indirizzato a chi lo era, anche a Bruno, che nel frattempo non è più fra le anagrafiche del palazzo
    // (la risposta finta ha solo Anna). All'apertura la pagina non chiede le anagrafiche dei palazzi e
    // non filtra: è la guardia.
    test('aperto e inviato senza toccare niente, con o senza un file nuovo, il corpo porta tutti i destinatari ricevuti', async () => {
        const pagina = monta();
        await flushPromises();

        stato(pagina).submit();
        expect(corpoInviato().dati.anagrafiche).toEqual([41, 42]);
        expect(vi.mocked(axios.get)).not.toHaveBeenCalled();

        stato(pagina).form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });
        stato(pagina).submit();
        expect(objectToFormData(corpoInviato().dati as never).getAll('anagrafiche[]')).toEqual(['41', '42']);
    });

    test('dopo un salvataggio rifiutato a palazzi cambiati, il ritorno della pagina non tocca il modulo', async () => {
        // La stessa forma di `ComunicazioniEdit`: qui la pagina non riscrive il modulo quando il server la
        // rimanda, quindi resta ciò che chi modifica ha scelto (cambiare i palazzi svuota i destinatari, a
        // vista) e niente si toglie dopo. È la guardia.
        const pagina = monta();
        await flushPromises();

        stato(pagina).form.condomini_ids = [3];
        await flushPromises();
        stato(pagina).submit();
        const primaDelRitorno = JSON.parse(JSON.stringify(corpoInviato().dati));
        vi.mocked(axios.get).mockClear();

        await pagina.setProps({ documento: documento() });
        await flushPromises();
        stato(pagina).submit();

        expect(corpoInviato().dati).toEqual(primaDelRitorno);
        expect(corpoInviato().dati.condomini_ids).toEqual([3]);
        expect(vi.mocked(axios.get)).not.toHaveBeenCalled();
    });
});
