// @vitest-environment jsdom

/**
 * La scheda «Documenti» di un'unità: scaricare e approvare un documento passano dalle rotte dell'unità.
 *
 * Le rotte dell'archivio (`admin.documenti.download`, `admin.documenti.toggle-approval`) lavorano solo sui documenti
 * dell'archivio, quelli senza un proprietario. Il nome del documento scarica il file da
 * `admin.gestionale.immobili.documenti.download`, con il condominio, l'unità e il documento; il pallino
 * dell'approvazione, con «Approva documenti archivio», chiama `admin.gestionale.immobili.documenti.toggle-approval`.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({ put: vi.fn() }));
const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: inertia.put, get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: { roles: ['collaboratore'], get permissions() { return chiGuarda.permessi; } } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(parametri as Record<string, string>).toString()}`;

const { createColumns } = await import('./columns');

const condominio = { id: 18, nome: 'Condominio Aurora' };
const immobile = { id: 24, nome: 'Interno 7' };
const rogito = { id: 31, name: 'Titolo di provenienza', description: null, is_approved: false, is_published: false, mime_type: 'PDF', file_size: 20 };

/** La cella del nome, montata come la monta la tabella. */
function cellaDelNome() {
    const cella = createColumns(condominio as never, immobile as never)[0].cell as (contesto: unknown) => ReturnType<typeof h>;

    return mount(defineComponent({
        render: () => cella({ row: { original: { ...rogito }, index: 0 }, table: { options: { meta: { updateData: vi.fn() } } } }),
    }));
}

beforeEach(() => {
    inertia.put.mockClear();
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza documenti archivio', 'Approva documenti archivio'];
});

describe('i documenti dell\'unità si scaricano e si approvano dalla scheda dell\'unità', () => {
    test('il nome del documento scarica il file dalla rotta dell\'unità', () => {
        const nome = cellaDelNome().find('a');

        expect(nome.text()).toBe('Titolo di provenienza');
        expect(nome.attributes('href')).toBe('/admin.gestionale.immobili.documenti.download?condominio=18&immobile=24&documento=31');
    });

    test('il pallino dell\'approvazione chiama la rotta dell\'unità, con il condominio, l\'unità e il documento', async () => {
        await cellaDelNome().find('button').trigger('click');

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/admin.gestionale.immobili.documenti.toggle-approval?condominio=18&immobile=24&documento=31');
    });
});
