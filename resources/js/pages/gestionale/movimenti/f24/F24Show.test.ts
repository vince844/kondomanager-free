// @vitest-environment jsdom

/**
 * La scheda di una delega F24 che appartiene a un esercizio diverso dal corrente (Coda 314).
 *
 * Lo scadenzario raccoglie le deleghe di tutti gli esercizi e la scrittura del versamento sta nell'esercizio della delega
 * (`ConfermaVersamentoF24Action`, e la scrittura di storno `StornaVersamentoF24Action`), anche se quell'esercizio è chiuso.
 * La frase lo dice dove c'è ancora qualcosa da registrare, cioè su una delega in bozza o confermata (il versamento) e su una
 * versata (lo storno); su una stornata o annullata dice solo di che esercizio è.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: { render: () => null },
        router: { visit: vi.fn() },
        usePage: () => ({
            url: '/admin/gestionale/1/f24/7',
            props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } },
        }),
        useForm: (dati: Record<string, unknown>) => reactive({
            ...dati, errors: {}, processing: false, hasErrors: false, clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn(),
        }),
    };
});

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const F24Show = (await import('./F24Show.vue')).default;

function montaScheda(stato: string, esercizioNonCorrente: string | null) {
    return shallowMount(F24Show, {
        props: {
            condominio: { id: 1, nome: 'Condominio Test' } as any,
            condomini: [],
            delega: {
                id: 7,
                stato,
                plafond: 'soglia_500_tre_finestre',
                data_scadenza: '2027-01-18',
                data_versamento: stato === 'versata' ? '2027-01-18' : null,
                denominazione_contribuente: 'Condominio Test',
                cf_contribuente: '80012345678',
                totale_debito: 24000,
                motivo_codice: null,
                note: null,
                righe: [{
                    id: 1, ordine: 1, codice_tributo: '1019', rateazione_mese_rif: '0012', anno_riferimento: '2026',
                    importo_debito: 24000, pagamenti: [],
                }],
            },
            banche: [],
            motivo_blocco_modifica: null,
            campi_mancanti: [],
            esercizio_non_corrente: esercizioNonCorrente,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (nome: string) => `/${nome}` } },
    });
}

const testo = (w: ReturnType<typeof montaScheda>) => w.text().replace(/\s+/g, ' ');

describe('F24Show — la delega di un esercizio che non è il corrente', () => {
    test.each(['bozza', 'confermata'])(
        'Coda 314 — su una delega %s dice che la scrittura del versamento va in quell\'esercizio, anche se è chiuso',
        (stato) => {
            expect(testo(montaScheda(stato, 'Esercizio 2026'))).toContain(
                'Delega dell\'esercizio «Esercizio 2026», che non è quello corrente: la scrittura del versamento va in quell\'esercizio, anche se è chiuso.',
            );
        },
    );

    test('Coda 314 — su una delega versata dice che lo storno del versamento scrive in quell\'esercizio, e non parla di un versamento da registrare', () => {
        const pagina = testo(montaScheda('versata', 'Esercizio 2026'));

        expect(pagina).toContain(
            'Delega dell\'esercizio «Esercizio 2026», che non è quello corrente: se ne storni il versamento, la scrittura di storno va in quell\'esercizio, anche se è chiuso.',
        );
        expect(pagina).not.toContain('la scrittura del versamento va in quell\'esercizio');
    });

    test.each(['stornata', 'annullata'])(
        'Coda 314 — su una delega %s dice solo di che esercizio è: non c\'è più un versamento da registrare',
        (stato) => {
            const pagina = testo(montaScheda(stato, 'Esercizio 2026'));

            expect(pagina).toContain('Delega dell\'esercizio «Esercizio 2026», che non è quello corrente.');
            expect(pagina).not.toContain('va in quell\'esercizio');
        },
    );

    test('Coda 314 — su una delega dell\'esercizio corrente la frase non c\'è', () => {
        expect(testo(montaScheda('bozza', null))).not.toContain('che non è quello corrente');
    });
});
