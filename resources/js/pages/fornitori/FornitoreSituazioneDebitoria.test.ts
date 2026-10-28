// @vitest-environment jsdom

/**
 * La pagina dei debiti pregressi di un fornitore (Coda 409 e Coda 402).
 *
 * - «Elimina» chiama la rotta `admin.fornitori.situazione-debitoria.destroy` con i parametri che la rotta dichiara: in
 *   `routes/admin.php` la risorsa `fornitori.situazione-debitoria` chiama il debito `saldo` (`->parameters([...])`), e la
 *   pagina glielo passa con quel nome. Il test legge i nomi dal file delle rotte e risolve l'indirizzo come Ziggy: un
 *   parametro che la rotta richiede e la pagina non manda è un errore, e il pulsante non parte.
 * - «Aggiungi pendenza» e «Elimina» chiedono «Modifica anagrafiche», il permesso con cui `FornitorePolicy::update` apre le
 *   due rotte (`store` e `destroy`): chi ha la sola consultazione non li vede. I ruoli di serie hanno il permesso.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import fs from 'node:fs';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/fornitori/9/situazione-debitoria',
        props: { flash: {}, auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }));

/** I nomi dei parametri della risorsa, come `routes/admin.php` li dichiara: il fornitore e il debito. */
const PARAMETRI = (() => {
    const rotte = fs.readFileSync('routes/admin.php', 'utf8');
    const blocco = /Route::resource\('fornitori\.situazione-debitoria'[\s\S]*?->only\(/.exec(rotte)?.[0] ?? '';

    return [...blocco.matchAll(/=>\s*'(\w+)'/g)].map((m) => m[1]);
})();

/** Come Ziggy: ogni parametro della rotta si manda per nome, e uno che manca è un errore. */
const route = vi.fn((nome: string, parametri: Record<string, unknown> = {}) => {
    const richiesti = nome.endsWith('situazione-debitoria.destroy') ? PARAMETRI : nome.includes('situazione-debitoria') ? PARAMETRI.slice(0, 1) : [];
    for (const richiesto of richiesti) {
        if (!(richiesto in parametri)) {
            throw new Error(`Ziggy error: '${richiesto}' parameter is required for route '${nome}'.`);
        }
    }

    return `/${nome}/${richiesti.map((r) => parametri[r]).join('/')}`;
});
(globalThis as Record<string, unknown>).route = route;

const FornitoreSituazioneDebitoria = (await import('./FornitoreSituazioneDebitoria.vue')).default;

const passa = { template: '<div><slot /></div>' };

function monta() {
    return mount(FornitoreSituazioneDebitoria, {
        props: {
            fornitore: { id: 9, ragione_sociale: "Idraulica D'Àngelo S.r.l." } as never,
            debiti: [{ id: 5, condominio: 'Condominio Aurora', esercizio: '2026', descrizione: 'Fattura idraulico 2025', importo: 38000, data_inserimento: '01/10/2026' }],
            condomini: [{ id: 3, nome: 'Condominio Aurora' }],
        },
        global: {
            mocks: { route },
            stubs: {
                AppLayout: passa, FornitoreLayout: passa, MoneyInput: true,
                PageHeaderGuide: { template: '<div><slot name="actions" /></div>' },
            },
        },
    });
}

function comandi(pagina: ReturnType<typeof monta>) {
    return {
        aggiungi: pagina.findAll('button').filter((b) => b.text().includes('Aggiungi pendenza')),
        elimina: pagina.findAll('tbody tr').flatMap((riga) => riga.findAll('button')),
    };
}

beforeEach(() => {
    chiGuarda.ruoli = ['collaboratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche'];
    route.mockClear();
    vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
    vi.mocked(router.delete).mockClear();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

describe('FornitoreSituazioneDebitoria — «Elimina» passa alla rotta i parametri che la rotta dichiara (Coda 409)', () => {
    test('le rotte dichiarano il fornitore e il debito, e il debito si chiama «saldo»', () => {
        expect(PARAMETRI).toEqual(['fornitore', 'saldo']);
    });

    test('«Elimina» chiama la rotta con tutti i parametri per nome e il debito è quello della riga', () => {
        const elimina = comandi(monta()).elimina;
        expect(elimina).toHaveLength(1);

        elimina[0].trigger('click');

        const chiamata = route.mock.calls.find(([nome]) => nome === 'admin.fornitori.situazione-debitoria.destroy')!;
        expect(chiamata, 'la pagina chiama la rotta').toBeDefined();
        expect(Object.keys(chiamata[1] as Record<string, unknown>).sort()).toEqual([...PARAMETRI].sort());
        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.delete).mock.calls[0][0]).toBe('/admin.fornitori.situazione-debitoria.destroy/9/5');
    });

    test('senza la conferma non parte niente', () => {
        vi.mocked(window.confirm).mockReturnValue(false);

        comandi(monta()).elimina[0].trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
    });
});

describe('FornitoreSituazioneDebitoria — i comandi chiedono il permesso delle rotte (Coda 402)', () => {
    test.each([
        ['il collaboratore di serie', 'collaboratore', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche']],
        ['l\'amministratore', 'amministratore', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche']],
        ['«Modifica anagrafiche» da solo', 'Su misura', ['Accesso pannello amministratore', 'Modifica anagrafiche']],
    ])('con %s «Aggiungi pendenza» e «Elimina» ci sono', (_chi, ruolo, permessi) => {
        chiGuarda.ruoli = [ruolo];
        chiGuarda.permessi = permessi;
        const { aggiungi, elimina } = comandi(monta());

        expect(aggiungi).toHaveLength(1);
        expect(elimina).toHaveLength(1);
    });

    test.each([
        ['la sola consultazione', ['Accesso pannello amministratore', 'Visualizza anagrafiche']],
        ['«Crea anagrafiche» senza «Modifica anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche']],
        ['«Modifica utenti» e «Crea utenti»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica utenti', 'Crea utenti']],
    ])('con %s «Aggiungi pendenza» e «Elimina» non ci sono', (_caso, permessi) => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = permessi;
        const { aggiungi, elimina } = comandi(monta());

        expect(aggiungi).toHaveLength(0);
        expect(elimina).toHaveLength(0);
    });
});
