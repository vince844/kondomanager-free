// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La conferma data, nel portale: «Hai confermato la presa visione il … alle …» con la spunta verde, che si vede (Coda 594, nota E6 della decisione 313;
 * la scena 2 della bozza approvata `beta14_letture_bozza.html`). Prima la stessa frase era grigia e a 12 px, come la nota sull'apertura.
 *
 * - `[data-lettura="presa-visione"]` ha l'icona della spunta (nascosta ai lettori di schermo: la frase dice già tutto) e il verde, non il grigio;
 * - la frase è più grande della nota sull'apertura, che resta piccola e grigia sotto;
 * - prima della conferma il riquadro verde non c'è.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { vi } from 'vitest';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const PresaVisione = (await import('./PresaVisione.vue')).default;

function monta(lettura: Record<string, unknown>) {
    return mount(PresaVisione, {
        props: { comunicazioneId: 7, lettura: { apertura: null, puo_confermare: false, presa_visione: null, ...lettura } } as never,
    });
}

const confermata = { apertura: { il: '23/11/2026', alle: '10:14' }, presa_visione: { il: '23/11/2026', alle: '10:15' } };

describe('la conferma data — con la spunta verde', () => {
    test('la frase ha la spunta, nascosta ai lettori di schermo', () => {
        const conferma = monta(confermata).find('[data-lettura="presa-visione"]');

        expect(conferma.find('svg').exists()).toBe(true);
        expect(conferma.find('svg').attributes('aria-hidden')).toBe('true');
        expect(conferma.text()).toContain('comunicazioni.readings.confirmed_on');
        expect(conferma.text()).toContain('10:15');
    });

    test('è verde, non grigia, e più grande della nota sull\'apertura', () => {
        const w = monta(confermata);
        const classiConferma = w.find('[data-lettura="presa-visione"]').classes();
        const classiApertura = w.find('[data-lettura="apertura"]').classes();

        expect(classiConferma.some((c) => c.startsWith('text-emerald-'))).toBe(true);
        expect(classiConferma.some((c) => c.startsWith('bg-emerald-'))).toBe(true);
        expect(classiConferma).not.toContain('text-muted-foreground');
        expect(classiConferma).not.toContain('text-xs');
        expect(classiConferma).toContain('text-sm');
        expect(classiApertura).toContain('text-xs');
        expect(classiApertura).toContain('text-muted-foreground');
    });

    test('prima della conferma il riquadro verde non c\'è, c\'è il pulsante', () => {
        const w = monta({ puo_confermare: true, apertura: { il: '23/11/2026', alle: '10:14' } });

        expect(w.find('[data-lettura="presa-visione"]').exists()).toBe(false);
        expect(w.find('button[data-azione="presa-visione"]').exists()).toBe(true);
        expect(w.find('.bg-emerald-50').exists()).toBe(false);
    });
});
