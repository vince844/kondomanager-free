// @vitest-environment jsdom

/**
 * «Manca qualcuno?» nel modulo che lega una persona a un'unità (`gestionale/immobili/anagrafiche/AnagraficheNew.vue`) manda a
 * creare l'anagrafica solo chi lo può fare (decisione 137).
 *
 * Il collegamento «gestione anagrafiche» porta all'elenco del pannello, che chiede «Visualizza anagrafiche»; da lì «Crea
 * anagrafica» chiede «Crea anagrafiche» (`AnagraficaPolicy`). Le pagine del gestionale si aprono con il solo accesso al pannello
 * (decisione 138.2): il collegamento e «per crearlo» compaiono solo con tutti e due i permessi; senza, la frase dice soltanto
 * quali persone compaiono nella tendina, che è vero in ogni caso. I ruoli di serie hanno tutti e due, e per loro non cambia niente.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/gestionale/18/immobili/24/anagrafiche/create',
        props: { flash: {}, auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const AnagraficheNew = (await import('./AnagraficheNew.vue')).default;

const passa = { template: '<div><slot /></div>' };

function modulo() {
    return mount(AnagraficheNew, {
        props: {
            condominio: { id: 18, nome: 'Condominio Aurora' } as never,
            immobile: { id: 24, nome: 'Interno 3', interno: '3' } as never,
            anagrafiche: [],
        },
        global: {
            stubs: {
                GestionaleLayout: passa, ImmobileLayout: passa, PageHeaderGuide: true, RipartoUsufruttoGuide: true,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
                VueDatePicker: true,
                HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa,
            },
        },
    });
}

/** Il riquadro «Manca qualcuno?»: il testo e i collegamenti che contiene. */
function mancaQualcuno() {
    const riquadro = modulo().findAll('div.text-xs').find((d) => d.text().includes('Manca qualcuno?'));
    expect(riquadro, 'il riquadro «Manca qualcuno?» c\'è').toBeDefined();

    return {
        testo: riquadro!.text().replace(/\s+/g, ' ').trim(),
        collegamenti: riquadro!.findAll('a').map((a) => a.attributes('href')),
    };
}

beforeEach(() => {
    chiGuarda.ruoli = ['collaboratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche'];
});

describe('«Manca qualcuno?» nel modulo dell\'unità — il collegamento per creare l\'anagrafica (decisione 137)', () => {
    test('con «Visualizza» e «Crea anagrafiche», come i ruoli di serie, il collegamento porta alla gestione anagrafiche per crearlo, come prima', () => {
        const riquadro = mancaQualcuno();

        expect(riquadro.collegamenti).toEqual(['/admin/anagrafiche']);
        expect(riquadro.testo).toContain('per crearlo e associare il condominio');
    });

    test('con «Visualizza» e «Crea anagrafiche», senza «Modifica anagrafiche», il collegamento c\'è: «Crea anagrafica» chiede solo quei due', () => {
        chiGuarda.ruoli = ['Inserimento'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];

        expect(mancaQualcuno().collegamenti).toEqual(['/admin/anagrafiche']);
    });

    test.each([
        ['la sola consultazione', ['Accesso pannello amministratore', 'Visualizza anagrafiche']],
        ['«Visualizza» e «Modifica anagrafiche» senza «Crea anagrafiche»', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche']],
        ['«Crea anagrafiche» senza «Visualizza anagrafiche»', ['Accesso pannello amministratore', 'Crea anagrafiche', 'Modifica anagrafiche']],
        ['il solo accesso al pannello', ['Accesso pannello amministratore']],
    ])('decisione 137 — con %s nessun collegamento e nessun «per crearlo»: la frase dice solo chi compare nella tendina', (_caso, permessi) => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = permessi as string[];
        const riquadro = mancaQualcuno();

        expect(riquadro.collegamenti).toEqual([]);
        expect(riquadro.testo).not.toContain('per crearlo');
        expect(riquadro.testo).not.toContain('gestione anagrafiche');
        expect(riquadro.testo).toBe('Manca qualcuno? Qui compaiono solo le anagrafiche legate a questo condominio.');
    });
});
