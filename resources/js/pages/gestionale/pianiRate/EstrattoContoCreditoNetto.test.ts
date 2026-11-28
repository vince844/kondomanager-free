// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Credito disponibile» dell'estratto conto dice il netto della persona, e il rimborso a chi ha da versare chiede una conferma.
 *
 * Bice ha € 980,00 da versare sulle rate emesse e € 120,00 di credito sulle sue quote, anche di rate in bozza: il riquadro dice «€ 980,00 da
 * versare» e, sotto in piccolo, il credito lordo; non dice «Credito disponibile € 120,00» come cifra grande. Il rimborso a chi ha da versare
 * chiede «Bice ha € 980,00 da versare. Rimborsi lo stesso?» prima di partire: decide l'amministratore. Senza niente da versare parte subito.
 *
 * Con credito e debito su gestioni diverse il riquadro dice una riga per gestione (il credito di una gestione non toglie dal debito di un'altra).
 *
 * Le cifre del server le provano `CreditoDisponibileNettoTest.php` e `EstrattoContoCreditoPerGestioneTest.php`. Si monta la pagina con `shallowMount`, come in `EstrattoContoCreditoInSaldi.test.ts`.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const inviato = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const vero = await importOriginal<typeof import('@inertiajs/vue3')>();
    return {
        ...vero,
        Head: { template: '<span />' },
        usePage: () => ({ url: '/admin/gestionale/28/anagrafiche/7/estratto-conto', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
        useForm: (dati: Record<string, unknown>) => {
            const form = vero.useForm(dati);
            form.post = inviato as never;
            return form;
        },
    };
});
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

const QUOTA = { id: 11, rata: 1, piano: 'Preventivo 2026', gestione: 'Ordinaria', immobile: 'Box D', origine: 'saldi', conto: 'crediti_condomini', a_giornale: true,
    credito_cents: 1000, credito_formattato: '€ 10,00', credito_euro: '10,00' };

function monta(credito: Record<string, unknown>) {
    return shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Bice', codice_fiscale: null, immobili: [] } as never, timeline: [],
            stats: { totale_addebiti: '€ 1.000,00', totale_versamenti: '€ 0,00', saldo_finale: '€ 980,00', saldo_raw: 98000, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null, ...credito },
            rimborso: { quote: [QUOTA], casse: [{ id: 3, nome: 'Banca', tipo: 'banca', saldo_cents: 100000, saldo_formattato: '€ 1.000,00' }], credito_in_saldi: 0, rate_da_pagare: 98000 },
            addebiti: [] as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}
const testo = (w: ReturnType<typeof monta>) => w.text().replace(/\s+/g, ' ');
const BICE = { credito_da_versare: '€ 980,00', credito_da_versare_raw: 98000, credito_lordo: '€ 120,00', credito_lordo_raw: 12000 };
const conferma = (w: ReturnType<typeof monta>) => w.findAllComponents({ name: 'ConfirmDialog' }).find((c) => c.props('title') === 'Rimborsi lo stesso?')!;

describe('il riquadro del credito dice il netto della persona', () => {
    test('con € 980,00 da versare la cifra grande è «€ 980,00 da versare», e il credito lordo sta sotto in piccolo', () => {
        const w = monta(BICE);

        expect(w.get('[data-testid="credito-da-versare"]').text().replace(/\s+/g, ' ')).toBe('€ 980,00 da versare');
        expect(w.get('[data-testid="credito-lordo"]').text().replace(/\s+/g, ' ')).toBe('€ 120,00 di credito sulle sue quote, anche di rate non ancora emesse: sulle rate emesse è già tolto dal debito della stessa gestione.');
        expect(w.get('[data-testid="note-del-credito"]').classes()).toContain('text-xs');
        expect(testo(w)).not.toContain('Nessun credito attivo');
    });

    test('con un credito netto la cifra grande è il credito, e non c\'è niente da versare', () => {
        const w = monta({ credito_disponibile: '€ 20,00', credito_disponibile_raw: 2000, credito_da_versare: '€ 0,00', credito_da_versare_raw: 0, credito_lordo: '€ 120,00', credito_lordo_raw: 12000 });

        expect(w.find('[data-testid="credito-da-versare"]').exists()).toBe(false);
        expect(testo(w)).toContain('€ 20,00');
        expect(w.find('[data-testid="credito-lordo"]').exists()).toBe(true);
    });

    test('senza credito il riquadro dice «Nessun credito attivo», come prima', () => {
        const w = monta({ credito_da_versare: '€ 980,00', credito_da_versare_raw: 98000, credito_lordo: '€ 0,00', credito_lordo_raw: 0 });

        expect(testo(w)).toContain('Nessun credito attivo');
        expect(w.get('[data-testid="note-del-credito"]').text()).toContain('Nessun credito attivo');
        expect(w.find('[data-testid="credito-da-versare"]').exists()).toBe(false);
    });
});

describe('con credito e debito su gestioni diverse il riquadro li dice separati', () => {
    test('Bice: € 120,00 di credito sull\'ordinaria e € 200,00 da versare sui lavori, una riga per gestione e non «€ 80,00 da versare»', () => {
        const w = monta({ credito_disponibile: '€ 120,00', credito_disponibile_raw: 12000, credito_da_versare: '€ 200,00', credito_da_versare_raw: 20000,
            credito_lordo: '€ 120,00', credito_lordo_raw: 12000, credito_per_gestione: [
                { gestione_id: 2, gestione_nome: 'Lavori straordinari', importo_cents: 0, importo_formatted: '€ 0,00', credito_cents: 0, da_versare_cents: 20000, frase: '€ 200,00 da versare' },
                { gestione_id: 1, gestione_nome: 'Gestione ordinaria', importo_cents: 12000, importo_formatted: '€ 120,00', credito_cents: 12000, da_versare_cents: 0, frase: '€ 120,00 di credito' },
            ] });

        const righe = w.get('[data-testid="credito-per-gestione"]');
        expect(righe.findAll('div').map((r) => r.findAll('span').map((x) => x.text()))).toEqual([
            ['Lavori straordinari', '€ 200,00 da versare'], ['Gestione ordinaria', '€ 120,00 di credito']]);
        expect(w.get('[data-testid="note-del-credito"] p').text()).toBe('Il credito di una gestione copre le rate della stessa gestione.');
        expect(righe.findAll('.text-xs')).not.toHaveLength(0);
        expect(w.find('[data-testid="credito-da-versare"]').exists()).toBe(false);
        expect(testo(w)).not.toContain('€ 80,00');
    });

    test('sotto le righe le note stanno in un blocco solo, alla stessa grandezza, con «Compensa» dentro e «Rimborsa il credito» dopo', () => {
        const w = monta({ credito_disponibile: '€ 120,00', credito_disponibile_raw: 12000, credito_da_versare: '€ 200,00', credito_da_versare_raw: 20000,
            credito_lordo: '€ 150,00', credito_lordo_raw: 15000, compensabile_frase: 'Puoi compensare € 120,00 sulle rate da pagare.', compensabile_rata_id: 9, credito_per_gestione: [
                { gestione_id: 2, gestione_nome: 'Lavori straordinari', importo_cents: 0, importo_formatted: '€ 0,00', credito_cents: 0, da_versare_cents: 20000, frase: '€ 200,00 da versare' },
                { gestione_id: 1, gestione_nome: 'Gestione ordinaria', importo_cents: 12000, importo_formatted: '€ 120,00', credito_cents: 12000, da_versare_cents: 0, frase: '€ 120,00 di credito' },
            ] });

        // La cifra di una gestione resta su una riga: a capo, se serve, va il nome della gestione.
        for (const riga of w.get('[data-testid="credito-per-gestione"]').findAll('div')) {
            expect(riga.findAll('span')[1].classes()).toEqual(expect.arrayContaining(['shrink-0', 'whitespace-nowrap']));
            expect(riga.findAll('span')[0].classes()).toContain('min-w-0');
        }
        const note = w.get('[data-testid="note-del-credito"]');
        expect(note.classes()).toEqual(expect.arrayContaining(['text-xs', 'text-muted-foreground', 'space-y-1']));
        expect(note.findAll('p').map((p) => p.text().replace(/\s+/g, ' '))).toEqual([
            'Il credito di una gestione copre le rate della stessa gestione.',
            '€ 150,00 di credito sulle sue quote, anche di rate non ancora emesse: sulle rate emesse è già tolto dal debito della stessa gestione.',
            'Puoi compensare € 120,00 sulle rate da pagare. Compensa',
        ]);
        // Nessuna nota ha una grandezza sua: è quella del blocco.
        for (const p of note.findAll('p')) {
            expect(p.classes().filter((c) => /^text-(\[|xs|sm|base)/.test(c))).toEqual([]);
        }
        expect(note.get('a').text()).toBe('Compensa');
        expect(note.get('a').attributes('href')).toContain('prefill_rata_id=9');
        expect(note.element.compareDocumentPosition(w.findAll('button').find((b) => b.text().includes('Rimborsa il credito'))!.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(note.element.previousElementSibling!.getAttribute('data-testid')).toBe('credito-per-gestione');
    });

    test('con una gestione sola non ci sono righe per gestione', () => {
        const w = monta({ ...BICE, credito_per_gestione: [{ gestione_id: 1, gestione_nome: 'Gestione ordinaria', importo_cents: 0, importo_formatted: '€ 0,00', credito_cents: 0, da_versare_cents: 98000, frase: '€ 980,00 da versare' }] });

        expect(w.find('[data-testid="credito-per-gestione"]').exists()).toBe(false);
    });
});

describe('il rimborso a chi ha da versare chiede una conferma', () => {
    test('con € 980,00 da versare il modulo non parte: si apre «Bice ha € 980,00 da versare. Rimborsi lo stesso?», e parte con «Rimborsa»', async () => {
        inviato.mockClear();
        const w = monta(BICE);

        await w.get('form').trigger('submit');

        expect(inviato).not.toHaveBeenCalled();
        expect(conferma(w).props('modelValue')).toBe(true);
        expect(w.get('[data-testid="conferma-rimborso"]').text().replace(/\s+/g, ' ')).toBe('Bice ha € 980,00 da versare. Rimborsi lo stesso?');

        conferma(w).vm.$emit('confirm');
        await w.vm.$nextTick();

        expect(inviato).toHaveBeenCalledTimes(1);
        expect(conferma(w).props('modelValue')).toBe(false);
    });

    test('senza niente da versare il rimborso parte subito, senza la domanda', async () => {
        inviato.mockClear();
        const w = monta({ credito_disponibile: '€ 20,00', credito_disponibile_raw: 2000, credito_da_versare_raw: 0, credito_lordo_raw: 2000, credito_lordo: '€ 20,00' });

        await w.get('form').trigger('submit');

        expect(inviato).toHaveBeenCalledTimes(1);
        expect(conferma(w).props('modelValue')).toBe(false);
    });
});
