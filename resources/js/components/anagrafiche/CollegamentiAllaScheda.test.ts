// @vitest-environment jsdom

/**
 * Le altre pagine del pannello portano alla scheda di un'anagrafica solo chi la può aprire (decisioni 137 e 138.1).
 *
 * La scheda chiede «Visualizza anagrafiche» (`AnagraficaPolicy::view`). Nell'elenco dei fornitori i referenti, e
 * nell'elenco dei documenti le persone a cui un documento è indirizzato, si aprono nel pannello laterale con il nome:
 * con il permesso ogni nome porta alla scheda; senza, il nome resta, come testo. I ruoli di serie del pannello hanno
 * il permesso, e per loro non cambia niente.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string, parametri?: Record<string, unknown>) =>
    `/${nome}${parametri?.anagrafica ? `/${parametri.anagrafica}` : ''}`;

const { columns: colonneFornitori } = await import('@/components/fornitori/columns');
const { columns: colonneDocumenti } = await import('@/components/documenti/columns');
const AnagraficheStack = (await import('@/components/AnagraficheStack.vue')).default;

type Colonna = { accessorKey?: string; cell?: unknown };

/** Le persone che la cella passa al pannello laterale, montata come la monta la tabella. */
function personeNellaCella(colonne: Colonna[], chiave: string, riga: Record<string, unknown>): Array<{ nome: string; url?: string }> {
    const colonna = colonne.find((c) => c.accessorKey === chiave);
    const cella = colonna?.cell as (contesto: unknown) => ReturnType<typeof h>;
    const montata = mount(defineComponent({ render: () => cella({ row: { original: riga, index: 0 }, table: { options: { meta: {} } } }) }), {
        global: { stubs: { AnagraficheStack: true } },
    });

    return montata.findComponent(AnagraficheStack).props('anagrafiche') as Array<{ nome: string; url?: string }>;
}

const REFERENTI = { id: 3, ragione_sociale: 'Idraulica Rossi', referenti: [{ id: 7, nome: 'Mario Rossi', indirizzo: 'Via Roma 1' }] };
const DOCUMENTO = { id: 4, name: 'Verbale', anagrafiche: [{ id: 8, nome: 'Anna Bianchi', indirizzo: 'Via Po 2' }], condomini: [] };

beforeEach(() => {
    chiGuarda.ruoli = ['collaboratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Visualizza documenti archivio'];
});

describe('i collegamenti alla scheda di un\'anagrafica dalle altre pagine (decisioni 137 e 138.1)', () => {
    test('con «Visualizza anagrafiche» i referenti di un fornitore portano alla loro scheda, come prima', () => {
        expect(personeNellaCella(colonneFornitori as Colonna[], 'referenti', REFERENTI))
            .toEqual([expect.objectContaining({ nome: 'Mario Rossi', url: '/admin.anagrafiche.show/7' })]);
    });

    test('decisione 138 — senza «Visualizza anagrafiche» i referenti di un fornitore restano nomi, senza collegamento', () => {
        chiGuarda.ruoli = ['Fornitori'];
        chiGuarda.permessi = ['Accesso pannello amministratore'];
        const persone = personeNellaCella(colonneFornitori as Colonna[], 'referenti', REFERENTI);

        expect(persone.map((p) => p.nome)).toEqual(['Mario Rossi']);
        expect(persone[0].url).toBeUndefined();
    });

    test('con «Visualizza anagrafiche» le persone di un documento portano alla loro scheda, come prima', () => {
        expect(personeNellaCella(colonneDocumenti as Colonna[], 'anagrafiche', DOCUMENTO))
            .toEqual([expect.objectContaining({ nome: 'Anna Bianchi', url: '/admin.anagrafiche.show/8' })]);
    });

    test('decisione 138 — «Modifica», «Crea» ed «Elimina anagrafiche» senza «Visualizza anagrafiche» non bastano: referenti e persone dei documenti restano nomi', () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Modifica anagrafiche', 'Crea anagrafiche', 'Elimina anagrafiche', 'Visualizza documenti archivio'];

        expect(personeNellaCella(colonneFornitori as Colonna[], 'referenti', REFERENTI)[0].url).toBeUndefined();
        expect(personeNellaCella(colonneDocumenti as Colonna[], 'anagrafiche', DOCUMENTO)[0].url).toBeUndefined();
    });

    test('decisione 138 — senza «Visualizza anagrafiche» le persone di un documento restano nomi, senza collegamento', () => {
        chiGuarda.ruoli = ['Archivio'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza documenti archivio'];
        const persone = personeNellaCella(colonneDocumenti as Colonna[], 'anagrafiche', DOCUMENTO);

        expect(persone.map((p) => p.nome)).toEqual(['Anna Bianchi']);
        expect(persone[0].url).toBeUndefined();
    });
});
