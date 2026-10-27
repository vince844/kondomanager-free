// @vitest-environment jsdom

/**
 * La scheda di un utente, montata con i dati che il server le manda (Coda 258, decisioni 134.1 e 136.1).
 *
 * Il server manda fra le opzioni del ruolo quelle che chi apre la scheda può assegnare **più il ruolo che
 * l'utente ha già**, così la tendina mostra quel ruolo con i suoi permessi. La scheda di un account fuori dalla
 * portata la apre solo l'amministratore (decisione 136.1), quindi la pagina arriva con `gestibile` vero; il ramo
 * con `gestibile` falso resta come difesa e si prova qui sotto. Aperta e mandata senza toccare niente, la pagina
 * manda i dati ricevuti; un errore del server sul campo dei permessi aggiuntivi si vede sotto la sua tendina.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ModificaUtente = (await import('./ModificaUtente.vue')).default;

const ruoloContabile = { id: 5, name: 'Contabile', permissions: [{ id: 2, name: 'Gestisci impostazioni generali' }] };

/** Un account con un ruolo su misura e un permesso aggiuntivo (id 9) che il ruolo non dà. */
const contabile = {
    id: '7',
    name: "D'Àngelo «Contabile»",
    email: 'contabile@example.com',
    suspended_at: '',
    email_verified_at: '2026-10-01',
    anagrafica: { id: 31, nome: 'Studio Rossi' },
    roles: [ruoloContabile],
    permissions: [{ id: 9, name: 'Visualizza utenti', description: 'x' }],
};

/** Le opzioni del ruolo come le manda il server all'amministratore: tutti i ruoli, compreso quello dell'utente. */
const opzioniDellAmministratore = [
    { id: 1, name: 'Amministratore', permissions: [] },
    { id: 3, name: 'Collaboratore', permissions: [] },
    ruoloContabile,
    { id: 4, name: 'Fornitore', permissions: [] },
    { id: 2, name: 'Utente', permissions: [] },
];

/** Un campo è bloccato? I componenti sono finti: il valore della prop arriva come attributo. */
function bloccato(campo: { attributes: (nome: string) => string | undefined }): boolean {
    return campo.attributes('disabled') === 'true';
}

function monta(gestibile: boolean, utente: Record<string, unknown> = contabile) {
    return shallowMount(ModificaUtente, {
        props: {
            user: utente as never,
            permissions: [{ id: 9, name: 'Visualizza utenti' }, { id: 12, name: 'Visualizza comunicazioni' }] as never,
            anagrafiche: [{ id: 31, nome: 'Studio Rossi' }] as never,
            roles: opzioniDellAmministratore as never,
            gestibile,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type StatoDellaPagina = { submit: () => void; form: { errors: Record<string, string> } };
const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as { $: { setupState: StatoDellaPagina } }).$.setupState;

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('ModificaUtente — con i dati che il server manda (Coda 258, decisione 136.1)', () => {
    test('aperta e mandata senza toccare niente, manda i dati ricevuti', () => {
        const pagina = monta(true);

        stato(pagina).submit();

        const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/utenti.update');
        expect(chiamata[1]).toEqual({
            name: "D'Àngelo «Contabile»",
            email: 'contabile@example.com',
            roles: 5,
            permissions: [9],
            anagrafica: 31,
        });
    });

    test('la tendina mostra il ruolo dell\'utente, e la pagina i permessi che il ruolo porta', () => {
        const pagina = monta(true);

        expect(pagina.find('[label="name"]').attributes('modelvalue')).toBe('5');
        expect(pagina.text()).toContain('Gestisci impostazioni generali');
        expect(pagina.text()).not.toContain('users.empty_state.inherited_permissions');
    });

    test('un errore del server sui permessi aggiuntivi si vede sotto la loro tendina', async () => {
        // Un permesso aggiuntivo (id 2) che il ruolo dà già: la pagina lo mostra fra gli ereditati.
        const pagina = monta(true, { ...contabile, permissions: [{ id: 2, name: 'Gestisci impostazioni generali', description: 'x' }] });

        stato(pagina).form.errors = { permissions: 'Solo un amministratore può cambiare i permessi aggiuntivi.' };
        await pagina.vm.$nextTick();

        expect(pagina.html()).toContain('Solo un amministratore può cambiare i permessi aggiuntivi.');
    });

    test('nella portata la pagina non mostra la nota e lascia cambiare tutto', () => {
        const pagina = monta(true);

        expect(pagina.find('[data-test="fuori-portata"]').exists()).toBe(false);
        expect(bloccato(pagina.find('#email'))).toBe(false);
        pagina.findAll('[label="name"]').forEach((tendina) => expect(bloccato(tendina)).toBe(false));
        expect(pagina.findAllComponents({ name: 'Link' })).toHaveLength(1);
    });
});

describe('ModificaUtente — la difesa per un account fuori portata (Coda 258, decisione 134.1)', () => {
    test('la pagina dice perché, e non lascia cambiare email, ruolo e permessi aggiuntivi', () => {
        const pagina = monta(false);

        expect(pagina.find('[data-test="fuori-portata"]').text()).toContain('users.label.fuori_portata');
        expect(bloccato(pagina.find('#email'))).toBe(true);
        expect(bloccato(pagina.find('#name'))).toBe(false);

        // Le due tendine con `label="name"` sono il ruolo e i permessi aggiuntivi; quella dell'anagrafica no.
        const tendine = pagina.findAll('[label="name"]');
        expect(tendine).toHaveLength(2);
        tendine.forEach((tendina) => expect(bloccato(tendina)).toBe(true));
        expect(bloccato(pagina.find('[label="nome"]'))).toBe(false);
    });

    test('la pagina non offre la revoca dei permessi aggiuntivi', () => {
        expect(monta(false).findAllComponents({ name: 'Link' })).toHaveLength(0);
    });
});
