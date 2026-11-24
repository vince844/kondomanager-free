// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
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
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: { render: () => null },
        router: { visit: vi.fn(), post: vi.fn() },
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

function montaScheda(stato: string, esercizioNonCorrente: string | null, extra: { cfDelega?: string | null; cfContribuente?: string | null; avvisi?: string[] } = {}) {
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
                cf_contribuente: extra.cfDelega === undefined ? '80012345678' : extra.cfDelega,
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
            codice_fiscale_contribuente: extra.cfContribuente === undefined ? '80012345678' : extra.cfContribuente,
            esercizio_non_corrente: esercizioNonCorrente,
            avvisi_ritenute_gia_versate: extra.avvisi ?? [],
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

describe('F24Show — il codice fiscale del contribuente (Coda 754 (10))', () => {
    test('una bozza nata senza codice fiscale, con il condominio che lo ha preso dopo: nessun avviso, e il codice si legge nella scheda', () => {
        const pagina = testo(montaScheda('bozza', null, { cfDelega: null, cfContribuente: '80012345678' }));

        expect(pagina).not.toContain('Manca il codice fiscale del condominio');
        expect(pagina).toContain('80012345678');
    });

    test('senza codice fiscale né sulla delega né sul condominio l\'avviso manda all\'anagrafica e a ricaricare la pagina, non a «Aggiorna scadenze»', () => {
        const pagina = testo(montaScheda('bozza', null, { cfDelega: null, cfContribuente: null }));

        expect(pagina).toContain('Manca il codice fiscale del condominio');
        expect(pagina).toContain('Inseriscilo nell\'anagrafica del condominio e ricarica questa pagina');
        expect(pagina).not.toContain('premi «Aggiorna scadenze»');
    });
});

describe('F24Show — «Conferma la delega» e «Riporta in bozza» (Coda 754 (10), D6)', () => {
    test('in bozza c\'è «Conferma la delega» e non «Riporta in bozza»', () => {
        const pagina = testo(montaScheda('bozza', null));

        expect(pagina).toContain('Conferma la delega');
        expect(pagina).not.toContain('Riporta in bozza');
    });

    test('confermata c\'è «Riporta in bozza» e non «Conferma la delega»; il versamento resta possibile', () => {
        const pagina = testo(montaScheda('confermata', null));

        expect(pagina).toContain('Riporta in bozza');
        expect(pagina).not.toContain('Conferma la delega');
        expect(pagina).toContain('Registra versamento');
    });

    test.each(['versata', 'stornata', 'annullata'])('su una delega %s non ci sono né l\'uno né l\'altro', (stato) => {
        const pagina = testo(montaScheda(stato, null));

        expect(pagina).not.toContain('Conferma la delega');
        expect(pagina).not.toContain('Riporta in bozza');
    });

    test('il pulsante manda la richiesta alla rotta giusta, senza dati', async () => {
        const w = montaScheda('bozza', null);
        const pulsante = w.findAll('button, button-stub').find((b) => b.text().includes('Conferma la delega'));
        expect(pulsante).toBeTruthy();

        await pulsante!.trigger('click');
        expect(router.post).toHaveBeenCalledWith('/admin.gestionale.f24.conferma-delega', {}, expect.objectContaining({ preserveScroll: true }));

        const w2 = montaScheda('confermata', null);
        await w2.findAll('button, button-stub').find((b) => b.text().includes('Riporta in bozza'))!.trigger('click');
        expect(router.post).toHaveBeenCalledWith('/admin.gestionale.f24.riporta-in-bozza', {}, expect.objectContaining({ preserveScroll: true }));
    });
});

describe('F24Show — la ritenuta già versata con un\'altra delega (Coda 754 (10), decisione 704)', () => {
    const avviso = 'La ritenuta della fattura n. 12 di Edilnord è già stata versata con la delega del 16/12/2026 (il pagamento è stato poi stornato): non versarla di nuovo.';

    test.each(['bozza', 'confermata'])('su una delega %s con la ritenuta di una fattura già versata, la scheda lo dice con la frase intera', (stato) => {
        const pagina = testo(montaScheda(stato, null, { avvisi: [avviso] }));

        expect(pagina).toContain('Ritenuta già versata con un\'altra delega');
        expect(pagina).toContain(avviso);
    });

    test('senza avvisi il riquadro non c\'è', () => {
        expect(testo(montaScheda('bozza', null))).not.toContain('Ritenuta già versata con un\'altra delega');
    });
});
