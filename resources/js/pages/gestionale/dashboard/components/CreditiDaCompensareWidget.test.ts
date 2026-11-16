// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Crediti da compensare» nella fila della tesoreria: «Compensa» apre «Nuovo incasso» puntato sulla rata, «Rimborsa» l'estratto conto; chi non ha niente da
 * compensare ha solo «Rimborsa». Niente scorrimento dentro la card, e la card non si allunga: fino a 8 persone tutte, oltre le prime 5 e «Mostra tutte», che apre una finestra con
 * l'elenco intero. Scritte da 12 px in su.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import CreditiDaCompensareWidget from './CreditiDaCompensareWidget.vue';

const credito = (n: number, azionabile = true) => ({
    anagrafica_id: n, nome: `Persona ${n}`, totale_formatted: '€ 50,00', compensabile_cents: azionabile ? 5000 : 0, azionabile, compensabile_formatted: '€ 50,00',
    copre: azionabile ? 'Copre la rata 3 del 05/12/2026.' : 'Nessuna rata aperta da coprire.', rata_bersaglio_id: azionabile ? 30 + n : null,
    url: `/incasso?prefill_anagrafica_id=${n}`, url_estratto_conto: `/estratto/${n}`,
});
const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.vue'), 'utf8');
const sorgenteDellaRiga = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/components/RigaCreditoDaCompensare.vue'), 'utf8');

describe('«Crediti da compensare»', () => {
    test('«Compensa» e «Rimborsa» con i loro indirizzi; senza niente da compensare, solo «Rimborsa»', () => {
        const w = mount(CreditiDaCompensareWidget, { props: { crediti: [credito(1), credito(2, false)] } });
        const righe = w.findAll('[data-testid="riga-credito"]');
        expect(righe[0].get('[data-testid="compensa"]').attributes('href')).toBe('/incasso?prefill_anagrafica_id=1');
        expect(righe[0].get('[data-testid="rimborsa"]').attributes('href')).toBe('/estratto/1');
        expect(righe[1].find('[data-testid="compensa"]').exists()).toBe(false);
        expect(righe[1].text()).toContain('Nessuna rata aperta da coprire.');
    });

    test('fino a 8 tutte; oltre le prime 5 e «Mostra tutte», che apre la finestra con l\'elenco intero: la card resta di 5 righe', async () => {
        expect(mount(CreditiDaCompensareWidget, { props: { crediti: Array.from({ length: 8 }, (_, i) => credito(i + 1)) } }).findAll('[data-testid="riga-credito"]')).toHaveLength(8);
        const w = mount(CreditiDaCompensareWidget, { attachTo: document.body, props: { crediti: Array.from({ length: 12 }, (_, i) => credito(i + 1)) } });
        const nellaCard = () => document.body.querySelector('[data-testid="crediti-da-compensare"]')!.querySelectorAll('[data-testid="riga-credito"]').length;
        expect(nellaCard()).toBe(5);
        expect(w.get('[data-testid="fondo-crediti"]').text()).toContain('Qui le prime 5; mancano 7 persone');
        expect(w.get('[data-testid="mostra-tutte-crediti"]').text()).toBe('Mostra tutte');
        await w.get('[data-testid="mostra-tutte-crediti"]').trigger('click');
        await flushPromises();
        const finestra = document.body.querySelector('[data-testid="finestra-crediti"]') as HTMLElement;
        expect(finestra).not.toBeNull();
        expect(finestra.querySelectorAll('[data-testid="riga-credito"]')).toHaveLength(12);
        expect(finestra.querySelector('[data-testid="riga-credito"] [data-testid="compensa"]')!.getAttribute('href')).toBe('/incasso?prefill_anagrafica_id=1');
        expect(finestra.textContent).toContain('Chiudi');
        expect(nellaCard()).toBe(5);
        w.unmount();
        document.body.innerHTML = '';
    });

    test('nessuno scorrimento dentro la card (la finestra dell\'elenco intero scorre lei), nessuna scritta sotto i 12 px', () => {
        expect(sorgente.slice(0, sorgente.indexOf('<Dialog'))).not.toMatch(/overflow-y-auto|max-h-\[/);
        expect(sorgente + sorgenteDellaRiga).not.toMatch(/text-\[(9|10|11)px\]/);
        expect(sorgenteDellaRiga).not.toMatch(/overflow-y-auto|max-h-\[/);
    });
});
