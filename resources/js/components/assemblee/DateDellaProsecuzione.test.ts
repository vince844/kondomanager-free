// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le date di prosecuzione nella pagina (art. 66 c. 5 disp. att.; decisione 614): ogni riga con `numero > 1` si controlla sempre, anche senza il giorno.
 *
 * - «Aggiungi una data» lasciata senza giorno: la riga rossa «Manca il giorno di una data di prosecuzione», e «Prepara» resta fermo (prima la riga
 *   spariva al salvataggio, e l'avviso partiva senza la data che l'amministratore credeva di aver messo).
 * - Una prosecuzione senza la seconda convocazione (prima della prima, o sedici giorni dopo): la riga rossa, mai «Solo la prima convocazione».
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { FRASE_PROSECUZIONE_SENZA_GIORNO, FRASE_PROSECUZIONE_SENZA_SECONDA } from '@/lib/assemblee/date';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
const DateDellAssemblea = (await import('./DateDellAssemblea.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
    maggioranze_proposte: {} };
const modulo = (sedute: unknown[]) => ({ tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'presenza', piattaforma: '',
    consenso_videoconferenza_il: '', consenso_videoconferenza_modo: '', sedute });
const riga = (sedute: unknown[]) => {
    const w = mount(DateDellAssemblea as never, { props: { modelValue: modulo(sedute), scelte } as never });
    const r = w.find('[data-testid="controllo-date"]');
    return r.exists() ? r.text() : null;
};

describe('le date di prosecuzione', () => {
    test('una prosecuzione senza giorno: la riga rossa', () => {
        expect(riga([{ convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' },
            { convocazione: 'seconda', numero: 2, giorno: '', ora: '18:30' }])).toBe(FRASE_PROSECUZIONE_SENZA_GIORNO);
    });

    test('una prosecuzione senza la seconda convocazione: la riga rossa, prima della prima e sedici giorni dopo', () => {
        for (const giorno of ['2026-12-10', '2026-12-30']) {
            expect(riga([{ convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '', ora: '18:30' },
                { convocazione: 'seconda', numero: 2, giorno, ora: '18:30' }]), giorno).toBe(FRASE_PROSECUZIONE_SENZA_SECONDA);
        }
    });
});
