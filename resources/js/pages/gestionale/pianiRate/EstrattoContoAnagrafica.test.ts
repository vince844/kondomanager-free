// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — la card «Addebiti» dell'estratto conto dice che cosa entra davvero in «Totale addebiti» (correzione rimasta
 * dalla .49, verbale `docs/piano_esecutivo_beta49.md`, «Chiusura», «Da fare nella .50, dalla Fase 5»).
 *
 * La card della guida in testa alla pagina diceva «Tutte le rate emesse a carico del condòmino e gli eventuali ripristini di
 * debito». Dalla .49 (decisione 75.5) una scrittura stornata e la sua rettifica restano nell'elenco ma non nei totali
 * (`EstrattoContoAnagraficaController.php`, `$neiTotali`): i ripristini di uno storno non entrano più in «Totale addebiti». Ci
 * entrano invece i rimborsi del credito, che sono in DARE sulla posizione del condòmino (`rimborso_condomino`), e la card non li
 * nominava.
 *
 * Perché un test di pagina e non di un modulo: il testo vive nella pagina (`pageGuides` di `EstrattoContoAnagrafica.vue`) e
 * arriva alla card come prop di `PageHeaderGuide`. Si monta la pagina con `shallowMount` e si legge la prop che la pagina passa
 * alla card: è ciò che la card stampa, e il test resta valido anche se il testo si sposta in un modulo.
 *
 * Che cosa copre: la descrizione della card «Addebiti» non promette i ripristini e nomina i rimborsi del credito; le altre due
 * card restano (controllo).
 *
 * Che cosa NON copre: il calcolo di «Totale addebiti» (lo coprono i test PHP della .49, `CreditiStornoTest` e
 * `CreditiEmissioneEstrattoContoTest`); il PDF dell'estratto, che non ha la card; il modo scuro e le larghezze.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

// `route()` di Ziggy va su globalThis: la pagina lo chiama anche fuori dal template (il ritorno e il link «Compensa»).
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

function guide(): Array<{ title: string; description: string }> {
    const w = shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Giuseppe Rossi', codice_fiscale: null, immobili: [] } as never,
            timeline: [],
            stats: {
                totale_addebiti: '€ 2.290,88', totale_versamenti: '€ 0,00', saldo_finale: '€ 2.290,88', saldo_raw: 229088,
                saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0, credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [],
                compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '', compensabile_rata_id: null,
            },
            rimborso: { quote: [], casse: [], credito_in_saldi: 0 },
        },
        // Il layout è un segnaposto che rende il suo contenuto: la card sta dentro il layout.
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });

    return w.findComponent(PageHeaderGuide).props('guides') as Array<{ title: string; description: string }>;
}

describe('la card «Addebiti» dell\'estratto conto', () => {
    test('non promette i ripristini di uno storno, che dalla .49 non entrano in «Totale addebiti», e nomina i rimborsi del credito, che ci entrano', () => {
        const addebiti = guide().find((g) => g.title === 'Addebiti');

        expect(addebiti).toBeDefined();
        expect(addebiti!.description).not.toMatch(/ripristin/i);
        expect(addebiti!.description).toMatch(/rimbors/i);
        // Controllo: restano le rate emesse, che sono il grosso del totale.
        expect(addebiti!.description).toMatch(/rate emesse/i);
    });

    test('controllo: le altre due card ci sono ancora', () => {
        expect(guide().map((g) => g.title)).toEqual(['Saldo Iniziale', 'Addebiti', 'Pagamenti']);
    });
});

// Aggiunto con il giro di mutazioni sulle correzioni della .50 (M154): la card dice che lo storno resta fuori dal totale.
describe('la card «Addebiti» e gli storni', () => {
    test('un incasso stornato e il suo storno restano nell\'elenco, non nel totale', () => {
        const addebiti = guide().find((g) => g.title === 'Addebiti');
        expect(addebiti!.description).toContain('Un incasso stornato e il suo storno restano nell\'elenco, non nel totale.');
    });
});
