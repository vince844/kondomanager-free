// @vitest-environment jsdom

/**
 * La pagina di modifica di un documento d'archivio dal portale: che cosa manda.
 *
 * - **Coda 307, le categorie con un file nuovo.** Con un file il modulo parte come FormData
 *   (`objectToFormData` di `@inertiajs/core`), e un elenco vuoto lì non si scrive affatto: «tolte tutte
 *   le categorie» arrivava al server uguale a «campo non mandato», e il server teneva le categorie. La
 *   pagina manda `categorie_nel_modulo`, il segno che il campo c'era; qui si guarda il corpo che viaggia
 *   davvero, passato dalla stessa conversione del router. Il server:
 *   `tests/Feature/Portale/CategorieDalPortaleConFileTest.php`.
 * - Aperta e mandata senza toccare niente, il modulo manda i dati ricevuti.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { objectToFormData, router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 2, roles: ['utente'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/user/${p}`, generateRoute: (n: string) => `user.${n}`, hasRole: () => false, hasPermission: () => false,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DocumentiEdit = (await import('./DocumentiEdit.vue')).default;

function documento(campi: Record<string, unknown> = {}) {
    return {
        id: 7, name: "Verbale dell'assemblea «straordinaria»", description: "Prima riga\nseconda riga — con l'apostrofo",
        is_published: true, is_approved: true, mime_type: 'PDF', file_size: 10,
        categorie: [{ id: 5, name: 'Verbali' }, { id: 6, name: "Contratti d'appalto" }],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(DocumentiEdit, {
        props: { limiteFile: '2 MB', documento: documento(campi) as never, categories: [] },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Vm = { $: { setupState: { form: Record<string, unknown>; submit: () => void } } };
const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as Vm).$.setupState;

/** Il corpo che parte: i dati che il modulo dà al router (la pagina salva con `form.post`). */
function corpoInviato(): { url: string; dati: Record<string, unknown> } {
    const chiamata = vi.mocked(router.post).mock.calls.at(-1);
    expect(chiamata, 'il modulo non ha chiamato router.post').toBeDefined();

    return { url: String(chiamata![0]), dati: chiamata![1] as Record<string, unknown> };
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('DocumentiEdit del portale — le categorie con un file nuovo (Coda 307)', () => {
    test('aperta e mandata senza toccare niente, il modulo manda i dati ricevuti e il segno', () => {
        const pagina = monta();

        stato(pagina).submit();

        const { url, dati } = corpoInviato();
        expect(url).toBe('/user.documenti.update');
        expect(dati).toEqual({
            name: "Verbale dell'assemblea «straordinaria»",
            description: "Prima riga\nseconda riga — con l'apostrofo",
            is_published: true,
            categorie: [5, 6],
            file: null,
            categorie_nel_modulo: true,
        });
    });

    test('con un file nuovo e le categorie tolte, nel corpo FormData non ci sono le categorie, e c\'è il segno', () => {
        const pagina = monta();
        const s = stato(pagina);
        s.form.categorie = [];
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });

        s.submit();

        const corpo = objectToFormData(corpoInviato().dati as never);
        const chiavi = [...corpo.keys()];
        expect(chiavi.some((k) => k.startsWith('categorie[') || k === 'categorie'), chiavi.join(', ')).toBe(false);
        expect(corpo.get('categorie_nel_modulo')).toBe('1');
        expect(corpo.get('file')).toBeInstanceOf(File);
    });

    test('con un file nuovo e le categorie tenute, il corpo porta le categorie e il segno', () => {
        const pagina = monta();
        const s = stato(pagina);
        s.form.file = new File(['%PDF-1.4'], 'nuovo.pdf', { type: 'application/pdf' });

        s.submit();

        const corpo = objectToFormData(corpoInviato().dati as never);
        expect(corpo.getAll('categorie[]')).toEqual(['5', '6']);
        expect(corpo.get('categorie_nel_modulo')).toBe('1');
        expect(corpo.get('is_published')).toBe('1');
    });
});
