// @vitest-environment jsdom

/**
 * La scheda «Documenti» di una persona (Coda 402).
 *
 * L'elenco si apre con la scheda della persona («Visualizza anagrafiche», `AnagraficaPolicy::view`). Caricare, modificare
 * ed eliminare un documento chiedono «Modifica anagrafiche» (`AnagraficaPolicy::update`, le rotte
 * `anagrafiche.documenti.create`, `edit`, `destroy`): chi ha la sola consultazione vede i documenti e non i comandi, e la
 * pagina non gli indica un pulsante che non c'è. I ruoli di serie che aprono la scheda hanno tutti e due i permessi.
 *
 * Le traduzioni non sono caricate.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({
        url: '/admin/anagrafiche/7/documenti',
        props: { flash: {}, auth: { user: { roles: [], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DocumentiList = (await import('./DocumentiList.vue')).default;

const passa = { template: '<div><slot /></div>' };

function monta(documenti: unknown[]) {
    return mount(DocumentiList, {
        props: {
            anagrafica: { id: 7, nome: "Mario D'Àngelo" },
            documenti,
            meta: { current_page: 1, last_page: 1, per_page: 10, total: documenti.length },
            limiteFile: '10 MB',
        } as never,
        global: {
            mocks: { route: (globalThis as Record<string, unknown>).route },
            stubs: {
                AppLayout: passa, AnagraficaLayout: passa, PageHeaderGuide: true, ConfirmDialog: true, Alert: true,
                DropdownMenu: { template: '<div data-menu><slot /></div>' }, DropdownMenuTrigger: passa, DropdownMenuContent: passa,
                DropdownMenuItem: passa, DropdownMenuLabel: passa,
            },
        },
    });
}

const carta = { id: 3, name: "Carta d'identità", description: 'Scade nel 2030', mime_type: 'PDF', created_at: 'un mese fa', is_published: false };

beforeEach(() => {
    chiGuarda.permessi = [];
});

describe('la scheda «Documenti» della persona — i comandi chiedono «Modifica anagrafiche» (Coda 402)', () => {
    test('con la sola consultazione si vedono i documenti, senza «Carica documento» e senza il menù della riga', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];
        const pagina = monta([carta]);

        expect(pagina.text()).toContain("Carta d'identità");
        expect(pagina.text()).not.toContain('Carica documento');
        expect(pagina.find('[data-menu]').exists()).toBe(false);
        expect(pagina.text()).not.toContain('Azioni');
    });

    test('con la sola consultazione l\'elenco vuoto non indica un pulsante che non c\'è', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];
        const pagina = monta([]);

        expect(pagina.text()).toContain('Nessun documento archiviato su questa persona.');
        expect(pagina.text()).not.toContain('Carica documento');
        expect(pagina.find('td').attributes('colspan')).toBe('4');
    });

    test('con «Modifica anagrafiche», come i ruoli di serie, ci sono «Carica documento» e il menù della riga', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche'];
        const pagina = monta([carta]);

        expect(pagina.find('a[href="/admin.anagrafiche.documenti.create"]').exists()).toBe(true);
        expect(pagina.find('[data-menu]').exists()).toBe(true);
        expect(pagina.text()).toContain('Modifica');
        expect(pagina.text()).toContain('Elimina');
    });

    test('con «Modifica anagrafiche» l\'elenco vuoto indica il pulsante', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche'];
        const pagina = monta([]);

        expect(pagina.text()).toContain('Il pulsante «Carica documento» qui sopra ne aggiunge uno.');
        expect(pagina.find('td').attributes('colspan')).toBe('5');
    });
});
