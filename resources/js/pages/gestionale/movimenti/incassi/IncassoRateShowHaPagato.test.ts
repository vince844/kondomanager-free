// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 538: il dettaglio dell'incasso come nella bozza approvata. A video, per il versamento di Cecilia sul debito ereditato da Massimo Bagnara, la pagina
 * diceva «Soggetto pagante: Massimo Bagnara — Versato da Cecilia Angeli», con le etichette in maiuscolo a 10 px e un riquadro del totale alto e quasi vuoto.
 *
 * - «Ha pagato» è chi ha pagato davvero: lo compone il server (`ha_pagato`); per l'erede il suo nome e sotto «erede, sul debito di …», per gli altri la
 *   posizione con il ruolo e «Versato da» (anche senza la proprietà, come prima);
 * - il riquadro del totale in alto a destra: il totale, lo spaccato «Sul debito ereditato» / «Sulle sue rate» solo se il server lo manda, e lo stato
 *   («Registrato», «Annullato») in un badge `rounded-md`;
 * - le etichette in parole normali («Data del versamento», «Conto di accredito», «Imputazione», «Causale»), una riga per voce.
 *
 * Che cosa NON copre: chi ha pagato e le cifre, che sono del server (`DettaglioIncassoDellEredeTest.php`); il disegno a 390 px e il tema scuro (a video);
 * la tabella delle rate coperte (`IncassoRateShow.test.ts`).
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateShow from './IncassoRateShow.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    RicevutaDialog: true,
    ConfirmDialog: true,
};

const INCASSO = { id: 7, numero_protocollo: 'INC-2026-00031', stato: 'registrata', causale: 'Bonifico di Cecilia', data_competenza: '2026-11-18T00:00:00.000000Z', updated_at: '2026-11-18T22:00:00Z' };

function apri(props: Record<string, unknown> = {}) {
    return mount(IncassoRateShow, {
        props: {
            condominio: { data: { id: 16, nome: 'Via roma' } },
            esercizio: { id: 1 },
            condomini: [],
            incasso: INCASSO,
            incassoFormatted: {
                data_competenza: '2026-11-18',
                dettagli_rate: [{ numero: 1, scadenza: '31/01/2026', immobile: '1B', importo_formatted: '€ 32,79', tipo: 'contanti', credito_di: null }],
                pagante: { principale: 'Massimo Bagnara', altri_count: 0, lista_completa: 'Massimo Bagnara', ruolo: 'Proprietario', versato_da: 'Cecilia Angeli' },
                cassa_nome: 'Banca Intesa', cassa_tipo_label: 'Conto corrente', importo_totale_formatted: '€ 32,79', importo_totale_cents: 3279, gestione_nome: 'Ordinaria',
            },
            utenteCreatore: 'Vincenzo',
            utenteStornatore: null,
            imputazione: { codice: 'ordine_scadenze', testo: 'Secondo l\'ordine delle scadenze', nota: null },
            storno: null,
            ...props,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

const testo = (el: { text: () => string }) => el.text().replace(/[\s\u00a0]+/g, ' ').trim();
/** Il testo di un riquadro pezzo per pezzo (gli elementi senza figli), separato da spazi: fra due elementi la pagina non ne mette. */
const pezzi = (el: { element: Element }) => Array.from(el.element.querySelectorAll('*')).filter((n) => n.children.length === 0)
    .map((n) => (n.textContent ?? '').replace(/[\s\u00a0]+/g, ' ').trim()).filter(Boolean).join(' ');

describe('«Ha pagato» è chi ha pagato davvero', () => {
    test('il versamento di Cecilia sul debito ereditato: lei, con sotto «erede, sul debito di Massimo Bagnara»; niente «Versato da» né il defunto come pagante', () => {
        const w = apri({ ha_pagato: { nome: 'Cecilia Angeli', sotto: 'erede, sul debito di Massimo Bagnara', versato_da: null }, spaccato: { sul_debito_ereditato: 3279, sulle_sue_rate: 0 } });
        const blocco = w.find('[data-testid="ha-pagato"]');

        expect(pezzi(blocco)).toBe('Ha pagato Cecilia Angeli erede, sul debito di Massimo Bagnara');
        expect(testo(w)).not.toContain('Soggetto');
        expect(testo(w)).not.toContain('Versato da');
    });

    test('un versamento senza la lettura del server: la posizione come prima, con il ruolo e «Versato da»', () => {
        const w = apri();

        expect(pezzi(w.find('[data-testid="ha-pagato"]'))).toBe('Ha pagato Massimo Bagnara Proprietario Versato da Cecilia Angeli');
    });
});

describe('il riquadro del totale', () => {
    test('con una parte sul debito ereditato: il totale, lo spaccato e lo stato «Registrato» in un badge rounded-md', () => {
        const w = apri({ ha_pagato: { nome: 'Cecilia Angeli', sotto: 'erede, sul debito di Massimo Bagnara', versato_da: null }, spaccato: { sul_debito_ereditato: 3279, sulle_sue_rate: 0 } });
        const totale = w.find('[data-testid="totale-versamento"]');
        const stato = w.find('[data-testid="stato-incasso"]');

        expect(pezzi(totale)).toBe('Totale del versamento Registrato € 32,79 Sul debito ereditato € 32,79 Sulle sue rate € 0,00');
        expect(stato.classes()).toContain('rounded-md');
        expect(stato.classes()).not.toContain('rounded-full');
        // Un riquadro solo: quello alto e vuoto di prima non c'è più.
        expect(w.findAll('[data-testid="totale-versamento"]')).toHaveLength(1);
        expect(testo(w)).not.toContain('Totale Versamento');
    });

    test('senza una parte sul debito ereditato il totale soltanto; annullato, il badge dice «Annullato»', () => {
        const w = apri({ incasso: { ...INCASSO, stato: 'annullata' }, spaccato: null,
            storno: { stornato_il: '2026-11-18', vale_dal: '2026-11-18', protocollo: 'RET-1', natura: 'errore_registrazione', natura_testo: 'Registrazione sbagliata' } });

        expect(w.find('[data-testid="spaccato"]').exists()).toBe(false);
        expect(testo(w.find('[data-testid="stato-incasso"]'))).toBe('Annullato');
        expect(pezzi(w.find('[data-testid="totale-versamento"]'))).toBe('Totale del versamento Annullato € 32,79');
    });
});

describe('le etichette in parole normali, una riga per voce', () => {
    test('«Data del versamento», «Conto di accredito», «Imputazione», «Causale», senza maiuscolo né scritte sotto i 12 px', () => {
        const w = apri();
        const etichette = w.findAll('dl dt').map((d) => testo(d));

        expect(etichette).toEqual(expect.arrayContaining(['Data del versamento', 'Conto di accredito', 'Imputazione', 'Causale']));
        const voce = (nome: string) => w.findAll('dl > div').find((d) => testo(d.find('dt')) === nome);
        expect(testo(voce('Data del versamento')!.find('dd'))).toBe('18/11/2026');
        expect(testo(voce('Conto di accredito')!.find('dd'))).toBe('Banca Intesa');
        expect(testo(voce('Causale')!.find('dd'))).toBe('Bonifico di Cecilia');
        expect(w.html()).not.toMatch(/\buppercase\b/);
        expect(w.html()).not.toMatch(/text-\[(9|10|11)px\]/);
    });
});
