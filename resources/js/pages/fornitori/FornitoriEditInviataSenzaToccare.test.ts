// @vitest-environment jsdom

/**
 * La scheda del fornitore aperta e inviata senza toccare niente manda gli stessi dati che ha ricevuto.
 *
 * Coda 325: la pagina cambia il testo della casella della dichiarazione delle provvigioni, che resta un'annotazione; il
 * corpo del salvataggio non deve cambiare. I dati sono scomodi apposta: un fornitore a provvigioni con la base ridotta,
 * la dichiarazione spuntata e datata, un ente non commerciale (Coda 316), un apostrofo nel nome, le percentuali come le
 * manda il server (`decimal`, stringhe).
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

const inviato = vi.hoisted(() => ({ corpo: null as Record<string, unknown> | null, volte: 0 }));

// Il modulo finto tiene i campi che la pagina gli dà e fotografa, al salvataggio, quelli che il `put` manderebbe.
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: { render: () => null },
        usePage: () => ({
            url: '/admin/fornitori/9/edit',
            props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } },
        }),
        useForm: (dati: Record<string, unknown>) => {
            const chiavi = Object.keys(dati);
            const form: Record<string, any> = reactive({
                ...dati, errors: {}, processing: false, hasErrors: false, clearErrors: vi.fn(), reset: vi.fn(),
                data: () => Object.fromEntries(chiavi.map((k) => [k, form[k]])),
                put: vi.fn(() => {
                    inviato.volte += 1;
                    inviato.corpo = Object.fromEntries(chiavi.map((k) => [k, form[k]]));
                }),
            });

            return form;
        },
    };
});
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const FornitoriEdit = (await import('./FornitoriEdit.vue')).default;

const ricevuto = {
    id: 9,
    ragione_sociale: "Mediazioni D'Àngelo S.a.s.",
    indirizzo: 'Via Roma 1',
    comune: 'Roma',
    provincia: 'RM',
    cap: '00100',
    nazione: 'Italia',
    partita_iva: '01234567897',
    codice_fiscale: '01234567897',
    email: 'mediazioni@example.test',
    pec: 'mediazioni@pec.example.test',
    telefono: '06 5550101',
    cellulare: '333 5550101',
    fax: '06 5550102',
    sito_web: 'example.test',
    categoria_id: 3,
    note: 'Agenzia "di quartiere"',
    stato: 'attivo',
    iscrizione_cciaa: 'RM-123456',
    codice_ateco: '68.31.00',
    data_iscrizione_cciaa: '2010-03-01',
    capitale_sociale: '10.000,00',
    certificazione_iso: false,
    numero_iscrizione_ordine: 'n. 42',
    soggetto_ritenuta: true,
    perc_ritenuta: '23.00',
    perc_imponibile_ritenuta: '20.00',
    codice_tributo: '1040',
    giorni_scadenza: 60,
    modalita_pagamento_default: 'bonifico',
    tipo_ritenuta: 'provvigioni_base_20',
    natura_percipiente: 'ente_non_commerciale',
    residente_fiscale: true,
    regime_forfetario: false,
    forfetario_dichiarato_il: '2025-01-15',
    forfetario_riferimento: 'Prot. 7/2025',
    provvigioni_base_ridotta: true,
    provvigioni_dichiarazione_il: '2026-05-04T00:00:00.000000Z',
    iban_principale: 'IT60X0542811101000000123456',
    created_at: '2026-01-01T10:00:00.000000Z',
    updated_at: '2026-10-30T10:00:00.000000Z',
};

describe('FornitoriEdit', () => {
    test('Coda 325 — aperta e inviata senza toccare niente, il corpo è uguale ai dati ricevuti (provvigioni a base ridotta, con la dichiarazione)', async () => {
        const w = shallowMount(FornitoriEdit, {
            props: {
                fornitore: ricevuto as any,
                categorie: [{ id: 3, name: 'Agenzie' }] as any,
                tipiRitenuta: [{ value: 'provvigioni_base_20', label: 'Provvigioni — base ridotta al 20% con la dichiarazione del percipiente, 4,6%' }],
                natureRecipiente: [{ value: 'ente_non_commerciale', label: 'Ente non commerciale (IRES)' }],
            },
            // I componenti figli sono finti (nessuno cambia un campo da sé), ma disegnano il loro contenuto: il modulo sta
            // dentro il layout e le schede.
            global: { renderStubDefaultSlot: true, mocks: { route: (nome: string) => `/${nome}` } },
        });

        await w.find('form').trigger('submit');
        await flushPromises();

        expect(inviato.volte).toBe(1);
        const corpo = inviato.corpo as Record<string, unknown>;

        // Ogni campo del modulo esce com'è entrato; i campi della risorsa che il modulo non manda (id, date di sistema) restano fuori.
        const attese = Object.fromEntries(Object.keys(corpo).map((k) => [k, (ricevuto as Record<string, unknown>)[k]]));
        expect(corpo).toEqual(attese);
        expect(Object.keys(corpo)).toContain('provvigioni_base_ridotta');
        expect(Object.keys(corpo)).toContain('provvigioni_dichiarazione_il');
        expect(Object.keys(corpo)).not.toContain('id');
    });
});
