// @vitest-environment jsdom

/**
 * La frase accanto a «Dai accesso al layout amministratore» nelle pagine «Crea nuovo ruolo» e «Modifica ruolo»
 * (decisione 138.2).
 *
 * L'accesso al pannello non è solo il menù: alcune pagine del pannello non hanno permessi propri e si aprono con il
 * solo accesso (il gestionale di ogni condominio, i fornitori), altre chiedono anche i loro permessi. La frase lo dice
 * nelle due pagine; che sia vera lo fissa dalla rotta `tests/Feature/Users/AccessoAlPannelloDelRuoloTest.php`.
 *
 * Le due pagine sono scritte in italiano nel codice, e la frase con loro. I test cercano i pezzi della frase, non la
 * frase intera.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const NuovoRuolo = (await import('./NuovoRuolo.vue')).default;
const ModificaRuolo = (await import('./ModificaRuolo.vue')).default;

const permessi = [{ id: 12, name: 'Visualizza comunicazioni', description: 'Vede le comunicazioni' }];

function testoDi(pagina: 'NuovoRuolo' | 'ModificaRuolo'): string {
    const opzioni = { global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } } };
    const w = pagina === 'NuovoRuolo'
        ? shallowMount(NuovoRuolo, { ...opzioni, props: { permissions: permessi as never } })
        : shallowMount(ModificaRuolo, {
            ...opzioni,
            props: {
                role: { id: 7, name: 'Segreteria «Aurora»', description: 'Ruolo su misura', permissions: [] } as never,
                permissions: permessi as never,
            },
        });

    return w.text().replace(/\s+/g, ' ');
}

describe.each(['NuovoRuolo', 'ModificaRuolo'] as const)('%s — che cosa apre l\'accesso al pannello (decisione 138.2)', (pagina) => {
    test('dice che alcune pagine del pannello si aprono con il solo accesso: il gestionale di ogni condominio e i fornitori', () => {
        const testo = testoDi(pagina);

        expect(testo).toContain('Alcune pagine del pannello non hanno permessi propri e si aprono con il solo accesso');
        expect(testo).toContain('il gestionale di ogni condominio (contabilità, unità, titolari e i loro dati) e i fornitori');
    });

    test('dice che altre sezioni chiedono anche i loro permessi, nel campo «Permessi» della pagina', () => {
        const testo = testoDi(pagina);

        expect(testo).toContain('Altre sezioni, come comunicazioni, segnalazioni, agenda e l\'elenco delle anagrafiche, chiedono anche i loro permessi');
        expect(testo).toContain('da scegliere in «Permessi»');
        expect(testo).toContain('Permessi');
    });

    test('non dice più che la casella mostra soltanto il layout', () => {
        expect(testoDi(pagina)).not.toContain('permette di mostrare il layout amministratore con funzioni avanzate');
    });
});

describe('ModificaRuolo — la pagina toccata, aperta e inviata', () => {
    test('aperta e inviata senza toccare niente, manda i dati ricevuti: nome e descrizione, i permessi del ruolo e la casella del pannello', async () => {
        vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const ruolo = {
            id: 7, name: 'Segreteria «Aurora» & C.', description: "Ruolo d'ufficio <b>",
            permissions: [
                { id: 1, name: 'Accesso pannello amministratore', description: 'x' },
                { id: 12, name: 'Visualizza comunicazioni', description: 'x' },
                { id: 15, name: 'Visualizza segnalazioni', description: 'x' },
            ],
        };
        const w = shallowMount(ModificaRuolo, {
            props: { role: ruolo as never, permissions: permessi as never },
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });
        await w.vm.$nextTick();

        (w.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/ruoli.update');
        expect(chiamata[1]).toEqual({
            name: 'Segreteria «Aurora» & C.',
            description: "Ruolo d'ufficio <b>",
            permissions: [12, 15],
            accessAdmin: true,
        });
    });
});
