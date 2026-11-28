// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il modulo di MODIFICA mostra la voce scelta sulla riga addebitata a una sola unità.
 *
 * La riga di un'unità ha la voce comune vuota (`conto_id`) e la voce scelta nel modulo in `conto_addebito_id` (il server la tiene fuori dal preventivo
 * comune, `FatturaPassivaService`). Il modulo la mostra nel menu «Capitolo di spesa», dove l'amministratore l'ha scelta, e la rimanda al salvataggio. Come nel
 * modulo di registrazione, la riga di un'unità non consuma il budget della voce e non la sfora.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server (`tests/Feature/Gestionale/AddebitoDirettoVoceRicordataTest.php`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })) }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const FatturaRegisterEdit = (await import('@/pages/gestionale/movimenti/fatture/FatturaRegisterEdit.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

// Il budget della voce è più piccolo della riga: una riga comune la sforerebbe.
const VOCE = { id: 55, nome: 'Spese generali', tipo: 'spesa', residuo_budget: 1000, is_capiente: true, parent_nome: null };
const UNITA = { id: 5, label: 'Int. 1 — Venditore Ugo' };

const riga = (over: Record<string, unknown>) => ({
    id: 300, descrizione: 'Rifacimento balcone interno 1', conto_id: null, conto_addebito_id: null, immobile_id: UNITA.id,
    importo_imponibile: 36500, importo_iva: 0, aliquota_iva: '0.00', concorre_base_ritenuta: true, ...over,
});

const fattura = (righe: Record<string, unknown>[]) => ({
    id: 91, fornitore_id: 10, fornitore: { id: 10, ragione_sociale: 'Manutenzioni Srl', soggetto_ritenuta: false, regime_forfetario: false },
    esercizio_id: 41, gestione_id: 35, tipo_documento: 'fattura', is_pregresso: false, numero_documento: 'FT-AVR-1', numero_protocollo: 'FTP-2026-00009',
    data_documento: '2026-01-02', data_scadenza: '2026-02-01', conto_corrente_id: null, modalita_pagamento: 'bonifico', iban_fornitore: null, stato_approvazione: 'approvata',
    importo_imponibile: 36500, importo_iva: 0, importo_ritenuta: 0, totale_documento: 36500, netto_a_pagare: 36500,
    dati_extra: { fiscal: {}, competenza: null, override_budget: null },
    righe, documenti: [], coperture: [],
});

let pagina: VueWrapper | null = null;

async function monta(righe: Record<string, unknown>[]) {
    const f = fattura(righe);
    pagina = mount(FatturaRegisterEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [{ id: 28, nome: 'Condominio Demo KM' }], esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }], gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }] as never, fornitori: [f.fornitore],
            conti: [VOCE] as never, banche: [], immobili: [UNITA] as never, debiti_patrimoniali: [], fatture_pregresse_registrate: [], fondi_riserva: [],
            capienza_rata_zero: 0, incassato_rata_zero: 0, nota_storno_originale: null, fattura: f,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
const vm = () => pagina!.vm as any;
/** Il testo scelto nei menu della pagina (la gestione, poi il capitolo e l'unità della riga). */
const scelte = () => [...document.body.querySelectorAll('.v-select .vs__selected')].map((s) => (s.textContent ?? '').replace(/\s+/g, ' ').trim());

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la voce dell\'addebito nel modulo di modifica', () => {
    test('la riga di un\'unità mostra la voce scelta alla registrazione, e il salvataggio la rimanda', async () => {
        await monta([riga({ conto_addebito_id: VOCE.id })]);

        // Prima: il menu del capitolo restava vuoto («Cerca capitolo...»), e il salvataggio toglieva la voce.
        expect(form().righe[0].conto_id).toBe(VOCE.id);
        expect(scelte()).toEqual(['Gestione ordinaria 2026', 'Spese generali', 'Int. 1 — Venditore Ugo']);
    });

    test('la riga di un\'unità non consuma il budget della voce e non la sfora, come nella registrazione', async () => {
        await monta([riga({ conto_addebito_id: VOCE.id })]);

        expect(vm().budgetImpacts).toEqual([]);
        expect(vm().rigaInSforo(0, form().righe[0])).toBe(false);
        expect(vm().transactionStatus).not.toBe('CRITICAL_BUDGET');
    });

    test('controprova — la riga comune sulla stessa voce la sfora, come prima', async () => {
        await monta([riga({ conto_id: VOCE.id, immobile_id: null })]);

        expect(form().righe[0].conto_id).toBe(VOCE.id);
        expect(vm().rigaInSforo(0, form().righe[0])).toBe(true);
    });

    test('controprova — la riga di un\'unità senza voce resta senza voce', async () => {
        await monta([riga({})]);

        expect(form().righe[0].conto_id).toBeNull();
        expect(scelte()).toEqual(['Gestione ordinaria 2026', 'Int. 1 — Venditore Ugo']);
    });
});
