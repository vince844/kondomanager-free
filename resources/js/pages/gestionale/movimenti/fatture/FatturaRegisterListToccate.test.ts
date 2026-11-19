// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 687 (decisione 507): l'elenco delle fatture mostra quelle che una modifica, fino alla 1.12.0-beta.11, ha spostato su un'altra gestione o spogliato
 * di «Fuori preventivo», con la correzione proposta; «Riporta com'era» la fa per una fattura sola, su comando, con una visita parziale.
 *
 * Il server manda `fattureToccateDallaModifica` (`FattureToccateDallaModifica::perCondominio`, `FattureToccateDallaModificaTest`). Una fattura che non si
 * corregge da qui (pagata, o con le voci di più gestioni) ha il motivo al posto della proposta, e niente pulsante.
 *
 * Cosa resta scoperto: la correzione vera, che fa il server; il disegno a 375 px e il tema scuro, provati a video.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const router = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router,
    usePage: () => ({
        props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: ['Accesso pannello amministratore'] } } },
    }),
}));
vi.mock('@/components/gestionale/movimenti/fatture/columns', () => ({ createColumns: () => [] }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.condominio ?? ''}/${p.fattura ?? ''}`;

const FatturaRegisterList = (await import('./FatturaRegisterList.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa, MovimentiLayout: passa, PageHeaderGuide: true, FattureGuide: true, DataTable: true, Alert: true,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
};

const SPOSTATA = { id: 11, numero_documento: 'FT-ROB', data_documento: '20/10/2026', fornitore: 'Idraulica Srl', totale_testo: '€ 1.220,00', voci: ['Riparazione colonna'],
    gestione_attuale: 'Ordinaria 2026', gestione_delle_voci: 'Spesa fuori bilancio',
    proposta: 'Riportare la fattura sulla gestione «Spesa fuori bilancio», quella delle sue voci: la scrittura di competenza passa lì, con le stesse righe e gli stessi importi.',
    correggibile: true, motivo: null };
const PAGATA = { ...SPOSTATA, id: 12, numero_documento: 'FT-PAGATA', correggibile: false,
    motivo: 'La fattura ha già un pagamento, che sta anch\'esso sulla gestione «Ordinaria 2026»: si corregge con lo storno del pagamento e della fattura, e una registrazione nuova sulla gestione giusta.' };
const SPOGLIATA = { id: 13, numero_documento: 'FT-FUORI', data_documento: '21/10/2026', fornitore: 'Idraulica Srl', totale_testo: '€ 610,00', voci: ['Perdita improvvisa'],
    proposta: 'Rimettere il segno «Fuori preventivo» sulla riga: la fattura torna nel carrello di «Spese fuori preventivo». Nessuna scrittura cambia.', correggibile: true, motivo: null };

function pagina(toccate: { spostate: unknown[]; spogliate: unknown[] } | undefined) {
    return mount(FatturaRegisterList, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], fatture: { data: [], meta: {} },
            stats: { totale_aperte: 0, totale_sfori: 0, importo_da_pagare: 0 }, filters: {}, regolazioniImmediate: 0, regolazioniSuVoceDiEntrata: [],
            fattureToccateDallaModifica: toccate, esercizio: { id: 41 },
        } as never,
        global: { stubs },
    });
}

const righe = (w: ReturnType<typeof pagina>) => w.findAll('[data-blocco="fatture-toccate"] [data-riga="fattura-toccata"]');

describe('Coda 687 — le fatture toccate da una modifica', () => {
    test('il blocco elenca la spostata, la pagata e la spogliata, ognuna con la sua proposta o il suo motivo', () => {
        const w = pagina({ spostate: [SPOSTATA, PAGATA], spogliate: [SPOGLIATA] });

        expect(w.find('[data-blocco="fatture-toccate"] [data-testo="titolo"]').text()).toBe('3 fatture sono state cambiate da una modifica');
        const [prima, seconda, terza] = righe(w).map((r) => r.text().replace(/\s+/g, ' '));
        expect(prima).toContain('Idraulica Srl, n. FT-ROB del 20/10/2026, € 1.220,00');
        expect(prima).toContain('È sulla gestione «Ordinaria 2026», con le righe sulla voce «Riparazione colonna» di «Spesa fuori bilancio».');
        expect(prima).toContain(SPOSTATA.proposta);
        expect(seconda).toContain(PAGATA.motivo);
        expect(terza).toContain('La riga sulla voce «Perdita improvvisa» ha perso il segno «Fuori preventivo», e la fattura non si mette più a rate.');
        expect(righe(w).map((r) => r.find('[data-azione="riporta-come-era"]').exists())).toEqual([true, false, true]);
    });

    test('«Riporta com\'era» manda la correzione di quella fattura sola, con una visita parziale', async () => {
        const w = pagina({ spostate: [SPOSTATA], spogliate: [] });

        await righe(w)[0].find('[data-azione="riporta-come-era"]').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, dati, opzioni] = router.post.mock.calls[0];
        expect(url).toBe('/admin.gestionale.fatture.riporta-come-era/28/11');
        expect(dati).toEqual({});
        expect(opzioni).toMatchObject({ preserveScroll: true, preserveState: true, only: ['fattureToccateDallaModifica', 'fatture', 'flash'] });
    });

    test('controprova: senza fatture toccate il blocco non c\'è', () => {
        expect(pagina({ spostate: [], spogliate: [] }).find('[data-blocco="fatture-toccate"]').exists()).toBe(false);
        expect(pagina(undefined).find('[data-blocco="fatture-toccate"]').exists()).toBe(false);
    });
});
