// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 689, il riquadro «Le rate» della pagina della fattura (la scena 9 della bozza approvata): «€ 5.500,00 ancora fuori dai piani della voce» con «Metti a
 * rate questa spesa», che apre la scheda giusta; tutta nei piani, «Nei piani della voce» con «Apri il piano» e nessun pulsante. Il menu della riga
 * dell'elenco offre la voce per una fattura vera, non stornata né pregressa.
 *
 * Che cosa non copre: il server (`MettiARateQuestaSpesaTest`).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import LeRateDellaFattura from './LeRateDellaFattura.vue';
import { siMetteARateDallElenco } from '@/lib/gestionale/fatture/mettiARate';

const Link = { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' };

describe('«Le rate» della fattura', () => {
    test('resta qualcosa da mettere a rate: la frase e «Metti a rate questa spesa» verso la scheda giusta', () => {
        const w = mount(LeRateDellaFattura, { props: { spesa: { fuori: 550000, frase: '€ 5.500,00 ancora fuori dai piani della voce', scheda: 'dalle_voci',
            url: '/admin/gestionale/3/esercizi/1/piani-rate/create?gestione_id=4&conto_id=9', piano: null } }, global: { stubs: { Link } } });
        expect(w.get('[data-testid="frase-delle-rate"]').text()).toBe('€ 5.500,00 ancora fuori dai piani della voce');
        expect(w.get('[data-testid="metti-a-rate"]').attributes('href')).toBe('/admin/gestionale/3/esercizi/1/piani-rate/create?gestione_id=4&conto_id=9');
        expect(w.find('[data-testid="apri-il-piano"]').exists()).toBe(false);
    });

    // Vincenzo, 21/11/2026 sera («visto che ci sei sistema questo layout»): il pulsante scuro sotto la frase. Come le altre teste (decisione 652): la frase a
    // sinistra, a destra sulla stessa riga il pulsante piccolo e chiaro; al telefono sotto, a tutta riga.
    test('la frase a sinistra e il pulsante piccolo e chiaro a destra, sulla stessa riga', () => {
        const w = mount(LeRateDellaFattura, { props: { spesa: { fuori: 120000, frase: '€ 1.200,00 ancora fuori dai piani della voce', scheda: 'dalle_voci',
            url: '/piano/nuovo', piano: null } }, global: { stubs: { Link } } });
        expect(w.get('[data-testid="le-rate-della-fattura"]').classes()).toContain('sm:flex-row');
        const b = w.get('[data-testid="metti-a-rate"]');
        expect(b.classes()).toContain('h-7');
        expect(b.classes()).not.toContain('bg-slate-900');
    });

    test('tutta nei piani: «Nei piani della voce» con «Apri il piano», nessun pulsante', () => {
        const w = mount(LeRateDellaFattura, { props: { spesa: { fuori: 0, frase: 'Nei piani della voce', scheda: 'dalle_voci', url: null,
            piano: { nome: 'Portone — rate', url: '/piano/8' } } }, global: { stubs: { Link } } });
        expect(w.find('[data-testid="metti-a-rate"]').exists()).toBe(false);
        expect(w.get('[data-testid="apri-il-piano"]').text()).toBe('Apri il piano «Portone — rate»');
    });

    test('il menu della riga offre la voce per una fattura vera, non stornata né pregressa', () => {
        expect(siMetteARateDallElenco({ tipo_documento: 'fattura', stato_pagamento: 'aperta', is_pregresso: false })).toBe(true);
        expect(siMetteARateDallElenco({ tipo_documento: 'nota_credito', stato_pagamento: 'aperta' })).toBe(false);
        expect(siMetteARateDallElenco({ tipo_documento: 'fattura', stato_pagamento: 'stornata' })).toBe(false);
        expect(siMetteARateDallElenco({ tipo_documento: 'fattura', is_pregresso: true })).toBe(false);
        expect(siMetteARateDallElenco({ tipo_documento: 'fattura', dati_extra: { is_stornata: true } })).toBe(false);
    });
});
