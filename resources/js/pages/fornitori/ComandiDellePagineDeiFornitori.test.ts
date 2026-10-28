// @vitest-environment jsdom

/**
 * I comandi delle pagine dei fornitori si mostrano solo a chi la rotta li accetta (Coda 402).
 *
 * `FornitorePolicy` e `CategoriaFornitorePolicy` chiedono i permessi delle anagrafiche: un fornitore si crea con «Crea
 * anagrafiche»; si modifica, con i suoi referenti, i suoi documenti e i suoi debiti pregressi, con «Modifica anagrafiche»; una
 * categoria si crea con «Crea» o «Modifica anagrafiche» (anche dal «+» del modulo «Nuovo fornitore», che si apre con
 * «Crea») e si rinomina o si elimina con «Modifica anagrafiche». Un comando che risponderebbe con un rifiuto non compare:
 *
 * - elenco dei fornitori: «Crea fornitori» con «Crea anagrafiche»;
 * - scheda del fornitore: «Modifica dati» con «Modifica anagrafiche»;
 * - referenti: «Associa» e il menù con «Dissocia» con «Modifica anagrafiche»;
 * - documenti: «Crea» e il menù con «Modifica» ed «Elimina» con «Modifica anagrafiche»;
 * - categorie: «Nuova categoria» con «Crea» o «Modifica anagrafiche», il menù di riga con «Modifica anagrafiche».
 *
 * L'amministratore e il collaboratore di serie hanno i permessi in gioco, e vedono tutti i comandi. I permessi degli
 * account («Crea utenti») e dell'archivio («Crea documenti archivio») non c'entrano. La pagina dei debiti pregressi ha il
 * suo test (`FornitoreSituazioneDebitoria.test.ts`).
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        url: '/admin/fornitori/9',
        props: {
            flash: {}, fornitore: { id: 9, ragione_sociale: "Idraulica D'Àngelo S.r.l." },
            auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } },
        },
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));
vi.mock('@/composables/useTabellaServer', () => ({ useTabellaServer: () => ({ filtra: vi.fn() }) }));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri ? `/${Object.values(parametri).join('/')}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const ToolbarFornitori = (await import('@/components/fornitori/DataTableToolbar.vue')).default;
const ToolbarReferenti = (await import('@/components/fornitori/anagrafiche/DataTableToolbar.vue')).default;
const RigaReferente = (await import('@/components/fornitori/anagrafiche/DataTableRowActions.vue')).default;
const ToolbarDocumenti = (await import('@/components/fornitori/documenti/DataTableToolbar.vue')).default;
const RigaDocumento = (await import('@/components/fornitori/documenti/DataTableRowActions.vue')).default;
const SchedaFornitore = (await import('./FornitoriView.vue')).default;
const CategorieList = (await import('./categorie/CategorieList.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubMenu = {
    DropdownMenu: { template: '<div class="menu"><slot /></div>' },
    DropdownMenuContent: passa, DropdownMenuTrigger: passa, DropdownMenuLabel: passa,
    DropdownMenuItem: { template: '<div class="voce"><slot /></div>' },
    ConfirmDialog: true,
    Button: { template: '<button><slot /></button>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
};
const tabella = { getColumn: () => undefined, reset: vi.fn() } as never;

const COLLABORATORE = ['Accesso pannello amministratore', 'Crea utenti', 'Modifica utenti', 'Visualizza utenti', 'Crea anagrafiche', 'Modifica anagrafiche', 'Visualizza anagrafiche'];
const AMMINISTRATORE = [...COLLABORATORE, 'Elimina anagrafiche', 'Crea documenti archivio'];
const CONSULTAZIONE = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];
// I permessi che le rotte dei fornitori non chiedono: gli account e l'archivio dei documenti.
const ALTRI = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea utenti', 'Modifica utenti', 'Crea documenti archivio', 'Modifica documenti archivio'];

function come(ruolo: string, permessi: string[]) {
    chiGuarda.ruoli = [ruolo];
    chiGuarda.permessi = permessi;
}

beforeEach(() => {
    come('collaboratore', COLLABORATORE);
});

function linkVersoRotta(componente: unknown, props: Record<string, unknown>, rotta: string) {
    return mount(componente as never, { props: props as never, global: { mocks: { route }, stubs: { ...stubMenu, Input: true, DataTableFacetedFilter: true } } })
        .findAll('a')
        .filter((a) => a.attributes('href')?.startsWith(`/${rotta}`));
}

describe('Elenco dei fornitori — «Crea fornitori» chiede «Crea anagrafiche»', () => {
    const creaFornitori = () => linkVersoRotta(ToolbarFornitori, { table: tabella }, 'admin.fornitori.create');

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['l\'amministratore', 'amministratore', AMMINISTRATORE],
        ['«Crea anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
    ])('con %s il comando c\'è', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(creaFornitori()).toHaveLength(1);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['«Crea utenti» senza «Crea anagrafiche»', ALTRI],
        ['«Modifica anagrafiche» senza «Crea anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s il comando non c\'è', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(creaFornitori()).toHaveLength(0);
    });
});

describe('Scheda del fornitore — «Modifica dati» chiede «Modifica anagrafiche»', () => {
    const modifica = () => mount(SchedaFornitore as never, {
        props: { fornitore: { id: 9, ragione_sociale: "Idraulica D'Àngelo S.r.l.", stato: 'attivo' } as never },
        global: {
            mocks: { route },
            stubs: { ...stubMenu, AppLayout: passa, FornitoreLayout: passa, PageHeaderGuide: { template: '<div><slot name="actions" /></div>' } },
        },
    }).findAll('a').filter((a) => a.attributes('href')?.endsWith('/fornitori/9/edit'));

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s il comando c\'è', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(modifica()).toHaveLength(1);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['«Crea anagrafiche» senza «Modifica anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
        ['i permessi degli account', ALTRI],
    ])('con %s il comando non c\'è', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(modifica()).toHaveLength(0);
    });
});

describe('Referenti — «Associa» e «Dissocia» chiedono «Modifica anagrafiche»', () => {
    const associa = () => linkVersoRotta(ToolbarReferenti, { table: tabella }, 'admin.fornitori.anagrafiche.create');
    const dissocia = () => mount(RigaReferente as never, {
        props: { fornitore: { id: 9 } as never, anagrafica: { id: 4, nome: 'Mario Rossi' } as never },
        global: { mocks: { route }, stubs: stubMenu },
    }).findAll('.voce').map((v) => v.text().trim());

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s i due comandi ci sono', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(associa()).toHaveLength(1);
        expect(dissocia()).toEqual(['Dissocia']);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['«Crea anagrafiche» senza «Modifica anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
        ['«Crea utenti» senza «Modifica anagrafiche»', ALTRI],
    ])('con %s i due comandi non ci sono', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(associa()).toHaveLength(0);
        expect(dissocia()).toEqual([]);
    });
});

describe('Documenti del fornitore — «Crea», «Modifica» ed «Elimina» chiedono «Modifica anagrafiche»', () => {
    const crea = () => linkVersoRotta(ToolbarDocumenti, { table: tabella }, 'admin.fornitori.documenti.create');
    const menu = () => mount(RigaDocumento as never, {
        props: { documento: { id: 31, name: 'DURC 2026' } as never, fornitore: { id: 9 } as never },
        global: { mocks: { route }, stubs: stubMenu },
    }).findAll('.voce').map((v) => v.text().trim());

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s i comandi ci sono', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(crea()).toHaveLength(1);
        expect(menu()).toEqual(['Modifica', 'Elimina']);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['«Crea documenti archivio» e «Modifica documenti archivio» senza «Modifica anagrafiche»', ALTRI],
        ['«Crea anagrafiche» senza «Modifica anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
    ])('con %s i comandi non ci sono', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(crea()).toHaveLength(0);
        expect(menu()).toEqual([]);
    });
});

describe('Categorie dei fornitori — «Nuova categoria» chiede «Crea» o «Modifica anagrafiche», il menù di riga «Modifica anagrafiche»', () => {
    const categorie = [{ id: 2, name: 'Idraulico', description: null, fornitori_count: 0, fornitori: [] }];
    const pagina = () => mount(CategorieList as never, {
        props: { categorie },
        global: {
            mocks: { route },
            stubs: {
                ...stubMenu, AppLayout: passa, PageHeaderGuide: passa, Alert: true, AnagraficheStack: true, Input: true, Textarea: true, Label: passa,
                Table: passa, TableBody: passa, TableCell: passa, TableHead: passa, TableHeader: passa, TableRow: passa,
                Sheet: passa, SheetContent: passa, SheetDescription: passa, SheetHeader: passa, SheetTitle: passa,
                Dialog: passa, DialogContent: passa, DialogDescription: passa, DialogFooter: passa, DialogHeader: passa, DialogTitle: passa,
            },
        },
    });
    const nuova = () => pagina().findAll('button').filter((b) => b.text().trim() === 'fornitori.categorie.new');
    const menuDiRiga = () => pagina().findAll('.voce').map((v) => v.text().trim());

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['«Crea anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s «Nuova categoria» c\'è', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(nuova()).toHaveLength(1);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['i permessi degli account', ALTRI],
    ])('con %s «Nuova categoria» non c\'è', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(nuova()).toHaveLength(0);
    });

    test.each([
        ['il collaboratore di serie', 'collaboratore', COLLABORATORE],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
    ])('con %s il menù di riga offre «Modifica» ed «Elimina»', (_chi, ruolo, permessi) => {
        come(ruolo, permessi);

        expect(menuDiRiga()).toEqual(['fornitori.categorie.edit', 'fornitori.categorie.delete']);
    });

    test.each([
        ['la sola consultazione', CONSULTAZIONE],
        ['«Crea anagrafiche» senza «Modifica anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
    ])('con %s il menù di riga non compare', (_caso, permessi) => {
        come('Su misura', permessi);

        expect(menuDiRiga()).toEqual([]);
    });
});
