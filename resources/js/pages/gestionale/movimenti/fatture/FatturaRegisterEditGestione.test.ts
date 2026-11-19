// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 687 (decisione 507): la pagina di modifica di una fattura parte dalla gestione della fattura e tiene il segno «Fuori preventivo».
 *
 * Fino alla 1.12.0-beta.11 la pagina partiva da `props.fattura.gestione_id`, che non esisteva, e il controllo dell'esercizio all'apertura metteva l'ordinaria;
 * le righe si ricaricavano con il segno spento. Salvando, anche solo per la scadenza, la fattura della straordinaria finiva sull'ordinaria (prova B4) e
 * quella «Fuori preventivo» perdeva il segno (prova B1). Ora il server manda la gestione della fattura (la colonna), la pagina parte da quella, le voci
 * offerte nelle righe sono della gestione scelta, e la riga «Fuori preventivo» si vede con il suo segno.
 *
 * Si monta la pagina vera, con i menu veri (`vue-select`), di cui si leggono le voci offerte.
 *
 * Cosa resta scoperto: il server (`SpesaNonPrevistaARateTest`: la modifica tiene gestione e segno, e rifiuta una voce di un'altra gestione); il cambio
 * di gestione a mano, che ripropone le voci della gestione nuova (letto, non provato qui); la pagina di registrazione, che filtra le voci allo stesso modo.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })) }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import vSelect from 'vue-select';
import FatturaRegisterEdit from './FatturaRegisterEdit.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const ORDINARIA = { id: 35, nome: 'Ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] };
const STRAORDINARIA = { id: 36, nome: 'Spesa fuori bilancio', tipo: 'straordinaria', esercizio_ids: [41] };
const PULIZIE = { id: 55, nome: 'Pulizie', tipo: 'spesa', gestione_id: 35, residuo_budget: 100_000, is_capiente: true };
const COLONNA = { id: 56, nome: 'Riparazione colonna', tipo: 'spesa', gestione_id: 36, residuo_budget: 122_000, is_capiente: true };
const TECNICA = { id: 57, nome: 'Riparazione colonna', tipo: 'spesa', gestione_id: 36, parent_nome: 'Integrazioni Straordinarie (Scudo Legale)', residuo_budget: 0, is_capiente: true };

function fattura(riga: Record<string, unknown>) {
    return {
        id: 91, fornitore_id: 10, fornitore: { id: 10, ragione_sociale: 'Idraulica Srl', soggetto_ritenuta: false, regime_forfetario: false },
        esercizio_id: 41, gestione_id: 36, tipo_documento: 'fattura', is_pregresso: false, numero_documento: 'FT-ROB', numero_protocollo: 'FTP-2026-00009',
        data_documento: '2026-10-20', data_scadenza: '2026-11-20', conto_corrente_id: null, modalita_pagamento: 'bonifico', iban_fornitore: null,
        stato_approvazione: 'approvata', importo_imponibile: 100_000, importo_iva: 22_000, importo_ritenuta: 0, totale_documento: 122_000, netto_a_pagare: 122_000,
        dati_extra: { fiscal: {}, override_budget: null },
        righe: [{ id: 300, descrizione: 'Riparazione colonna', immobile_id: null, importo_imponibile: 100_000, importo_iva: 22_000, aliquota_iva: '22.00',
            concorre_base_ritenuta: true, ...riga }],
        documenti: [], coperture: [],
    };
}

function render(f: Record<string, unknown>) {
    return mount(FatturaRegisterEdit, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' }, esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [ORDINARIA, STRAORDINARIA], fornitori: [f.fornitore], conti: [PULIZIE, COLONNA, TECNICA], banche: [], immobili: [],
            debiti_patrimoniali: [], fatture_pregresse_registrate: [], fondi_riserva: [], capienza_rata_zero: 0, incassato_rata_zero: 0, fattura: f, nota_storno_originale: null,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

const form = (w: ReturnType<typeof render>) => (w.vm as any).form;
/** Il menu delle voci della prima riga: il primo `v-select` con le voci fra le opzioni. */
const menuDelleVoci = (w: ReturnType<typeof render>) =>
    w.findAllComponents(vSelect as any).find((c) => (c.props('options') as any[] | undefined)?.some((o) => o?.id === COLONNA.id || o?.id === PULIZIE.id))!;

describe('la modifica parte dalla gestione della fattura (B4)', () => {
    test('la fattura della straordinaria si apre sulla straordinaria, non sull\'ordinaria', () => {
        const w = render(fattura({ conto_id: COLONNA.id, is_sopravvenienza: false }));

        expect(form(w).gestione_id).toBe(STRAORDINARIA.id);
    });

    test('il menu delle voci offre quelle della gestione della fattura', () => {
        const w = render(fattura({ conto_id: COLONNA.id, is_sopravvenienza: false }));

        expect((menuDelleVoci(w).props('options') as any[]).map((o) => o.id)).toEqual([COLONNA.id, TECNICA.id]);
    });

    test('controprova: una fattura dell\'ordinaria si apre sull\'ordinaria, con le voci dell\'ordinaria', () => {
        const f = { ...fattura({ conto_id: PULIZIE.id, is_sopravvenienza: false }), gestione_id: ORDINARIA.id };
        const w = render(f);

        expect(form(w).gestione_id).toBe(ORDINARIA.id);
        expect((menuDelleVoci(w).props('options') as any[]).map((o) => o.id)).toEqual([PULIZIE.id]);
    });
});

describe('la modifica tiene il segno «Fuori preventivo» (B1)', () => {
    test('la riga «Fuori preventivo» si ricarica con il segno, e lo dice accanto alla voce, che non si cambia', () => {
        const w = render(fattura({ conto_id: TECNICA.id, is_sopravvenienza: true }));

        expect(form(w).righe[0].is_sopravvenienza).toBe(true);
        expect(w.find('[data-segno="fuori-preventivo"]').exists()).toBe(true);
        expect(menuDelleVoci(w).props('disabled')).toBe(true);
    });

    test('controprova: una riga sulla voce resta senza il segno', () => {
        const w = render(fattura({ conto_id: COLONNA.id, is_sopravvenienza: false }));

        expect(form(w).righe[0].is_sopravvenienza).toBe(false);
        expect(w.find('[data-segno="fuori-preventivo"]').exists()).toBe(false);
    });
});
