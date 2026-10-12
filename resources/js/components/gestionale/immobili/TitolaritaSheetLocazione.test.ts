// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — lo storico dell'unità («Chi ha avuto questa unità») per la fine di una locazione: la fermata dopo «Scrivi il
 * conguaglio» e quanto ne resta dopo un inizio, dal registro (`fermate`, decisione 77, punto 3), e la strada «annulla il passaggio e
 * registralo di nuovo con «Non scriverlo»» accanto ad «annulla il conguaglio» anche nella fine locazione, non solo nella successione.
 * Lo stesso montaggio di `TitolaritaSheet.test.ts`.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string) => `/${n}`;

function passaggio(extra: Record<string, unknown> = {}): any {
    return {
        id: 9, tipo_passaggio: 'fine_locazione', sottotipo: null, decorrenza: '2026-07-01', decorrenza_a_parole: '1 luglio 2026', registrato_il: '11 ottobre 2026',
        uscente: 'Ines', entrante: null, estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 18148, importo_formattato: '€ 181,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null },
        obbligati: ['Le rate già emesse a Ines restano sue.'], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: [] },
        ...extra,
    };
}

function monta(p: any) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p] },
        },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

const FERMATA = 'Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 90,74 dei giorni dal 1 ottobre 2026 sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.';

describe('TitolaritaSheet — la fine di una locazione (1.11.0-beta.50)', () => {
    test('la fermata dal registro si legge nello storico del passaggio', () => {
        const w = monta(passaggio({ tipo_passaggio: 'vendita', uscente: 'Ugo', entrante: 'Elsa', fermate: [FERMATA] }));
        expect(w.text()).toContain(FERMATA);
    });

    test('accanto ad «annulla il conguaglio» della fine locazione c\'è l\'altra strada: annullare il passaggio e registrarlo con «Non scriverlo»', () => {
        expect(monta(passaggio()).text()).toContain('Se invece il conguaglio non andava scritto, annulla il passaggio e registralo di nuovo con «Non scriverlo».');
        // Controllo: nella vendita la strada non c'è (la vendita tiene la casella).
        expect(monta(passaggio({ tipo_passaggio: 'vendita' })).text()).not.toContain('registralo di nuovo con «Non scriverlo»');
    });
});
