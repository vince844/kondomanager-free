// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La card «Delibere da eseguire» della dashboard del condominio (la bozza approvata del seguito, scena 3): le cose da fare aperte delle delibere, una per riga,
 * con la data, l'assemblea e il punto, la persona e l'azione mirata; il numero in testa. La dashboard la mostra solo con delle righe, differita, e solo a chi
 * vede le assemblee (lo dice il server).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { cosa } from './seguitoDiProva';
import DelibereDaEseguire from './DelibereDaEseguire.vue';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

describe('la card «Delibere da eseguire»', () => {
    test('le cose aperte una per riga, con la data, l\'assemblea e il punto, la persona e l\'azione', async () => {
        w = mount(DelibereDaEseguire, { props: { condominioNome: 'Via Roma 12', righe: [
            { ...cosa({}), delibera: 'Delibera del 15/12/2026, punto 4 (verbale n. 42)', assemblea_id: 9 },
            { ...cosa({ id: 32, tipo: 'fuori_dal_programma', testo: 'Firma con Serramenti Marino', entro_il: null, chi: 'Marco Galli',
                azione: { testo: 'Segna eseguita', url: '/admin/gestionale/3/assemblee/9?scheda=delibere&delibera=4' } }),
            delibera: 'Delibera del 15/12/2026, punto 4 (verbale n. 42)', assemblea_id: 9 },
        ] } });
        await flushPromises();
        const t = w.text().replace(/\s+/g, ' ');
        expect(t).toContain('Delibere da eseguire');
        expect(t).toContain('Le delibere di Via Roma 12 che aspettano ancora qualcosa.');
        expect(w.get('[data-testid="quante"]').text()).toBe('2');
        const righe = w.findAll('[data-testid="riga-da-eseguire"]');
        expect(righe[0].text().replace(/\s+/g, ' ')).toContain('Crea il piano straordinario (€ 14.500,00)');
        expect(righe[0].text().replace(/\s+/g, ' ')).toContain('entro il 15/01/2027 · Delibera del 15/12/2026, punto 4 (verbale n. 42) · Andrea Costa');
        expect(righe[0].get('a').attributes('href')).toBe('/admin/gestionale/3/assemblee/9?scheda=delibere&cosa=31');
        expect(righe[0].get('a').text()).toBe('Crea il piano straordinario');
        expect(righe[1].text().replace(/\s+/g, ' ')).toContain('Delibera del 15/12/2026, punto 4 (verbale n. 42) · Marco Galli');
    });
});
