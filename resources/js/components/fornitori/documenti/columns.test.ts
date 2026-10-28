// @vitest-environment jsdom

/**
 * La scheda «Documenti» del fornitore: scaricare e approvare un documento passano dalle rotte del fornitore.
 *
 * Le rotte dell'archivio (`admin.documenti.download`, `admin.documenti.toggle-approval`) lavorano solo sui documenti
 * dell'archivio, quelli senza un proprietario. Il nome del documento scarica il file da
 * `admin.fornitori.documenti.download`, con il fornitore e il documento; lo scudo dell'approvazione chiama
 * `admin.fornitori.documenti.toggle-approval`, e c'è con «Modifica anagrafiche» (la sua rotta, `FornitorePolicy::update`)
 * e «Approva documenti archivio», il permesso dell'interruttore. I ruoli di serie che aprono la scheda li hanno tutti e due.
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

const fornitore = { id: 7, ragione_sociale: "Idraulica D'Àngelo S.r.l." };
const durc = { id: 31, name: 'DURC 2026', description: 'Scade a dicembre', is_approved: true, is_published: true, file_size: 20 };

/** La cella del nome, montata come la monta la tabella. */
function cellaDelNome() {
    const cella = createColumns(fornitore as never)[0].cell as (contesto: unknown) => ReturnType<typeof h>;

    return mount(defineComponent({
        render: () => cella({ row: { original: { ...durc }, index: 0 }, table: { options: { meta: { updateData: vi.fn() } } } }),
    }));
}

beforeEach(() => {
    inertia.put.mockClear();
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche', 'Approva documenti archivio'];
});

describe('i documenti del fornitore si scaricano e si approvano dalla scheda del fornitore', () => {
    test('il nome del documento scarica il file dalla rotta del fornitore', () => {
        const nome = cellaDelNome().find('a');

        expect(nome.text()).toBe('DURC 2026');
        expect(nome.attributes('href')).toBe('/admin.fornitori.documenti.download?fornitore=7&documento=31');
    });

    test('lo scudo chiama la rotta del fornitore, con il fornitore e il documento', async () => {
        await cellaDelNome().find('[title]').trigger('click');

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/admin.fornitori.documenti.toggle-approval?fornitore=7&documento=31');
    });

    test('senza «Modifica anagrafiche» lo scudo non c\'è, anche con «Approva documenti archivio»', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Approva documenti archivio'];

        expect(cellaDelNome().find('[title="Approvato - clicca per rimuovere approvazione"]').exists()).toBe(false);
    });

    test('senza «Approva documenti archivio» lo scudo non c\'è, anche con «Modifica anagrafiche»', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche'];

        expect(cellaDelNome().find('[title="Approvato - clicca per rimuovere approvazione"]').exists()).toBe(false);
    });
});
