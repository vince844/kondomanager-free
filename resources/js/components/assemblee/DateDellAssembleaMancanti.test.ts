// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le date dell'assemblea: un campo che manca non è un errore finché non si prova a preparare l'assemblea. «Manca la prima convocazione» compare solo con
 * `mostra-mancanti` (la pagina lo accende al clic su «Prepara l'assemblea»); i controlli sulle date già scritte (la seconda nello stesso giorno, oltre dieci
 * giorni) restano immediati, mentre si scrive.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { FRASE_MANCA_LA_PRIMA, FRASE_STESSO_GIORNO } from '@/lib/assemblee/date';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));

const Date = (await import('./DateDellAssemblea.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
    maggioranze_proposte: {} };
const modulo = (prima: string, seconda: string) => ({ tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'presenza', piattaforma: '',
    consenso_videoconferenza_il: '', consenso_videoconferenza_modo: '', sedute: [
        { convocazione: 'prima', numero: 1, giorno: prima, ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: seconda, ora: '18:30' }] });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });
const controllo = () => w!.find('[data-testid="controllo-date"]');

describe('le date mancanti si dicono al clic, non prima', () => {
    test('senza date e senza il clic su «Prepara l\'assemblea» nessun errore rosso', () => {
        w = mount(Date as never, { props: { modelValue: modulo('', ''), scelte, mostraMancanti: false } as never });
        expect(controllo().exists()).toBe(false);
    });

    test('dopo il clic «Manca la prima convocazione.» compare', () => {
        w = mount(Date as never, { props: { modelValue: modulo('', ''), scelte, mostraMancanti: true } as never });
        expect(controllo().text()).toBe(FRASE_MANCA_LA_PRIMA);
    });

    test('un errore su date già scritte resta immediato anche prima del clic', () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-12-14', '2026-12-14'), scelte, mostraMancanti: false } as never });
        expect(controllo().text()).toBe(FRASE_STESSO_GIORNO);
    });

    test('nelle altre pagine (senza la prop) il controllo resta com\'era', () => {
        w = mount(Date as never, { props: { modelValue: modulo('', ''), scelte } as never });
        expect(controllo().text()).toBe(FRASE_MANCA_LA_PRIMA);
    });

    test('come negli altri moduli: l\'errore è rosso sotto i campi, senza icona; con le date giuste nessuna riga', () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-12-14', '2026-12-14'), scelte } as never });
        expect(controllo().classes()).toEqual(expect.arrayContaining(['text-sm', 'text-red-600']));
        expect(controllo().find('svg').exists()).toBe(false);
        w.unmount();
        w = mount(Date as never, { props: { modelValue: modulo('2026-12-14', '2026-12-21'), scelte } as never });
        expect(controllo().exists()).toBe(false);
    });
});
