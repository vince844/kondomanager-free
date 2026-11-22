// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le date dell'assemblea con i cinque giorni dell'art. 66 c. 3 (decisione 662): con la prima convocazione troppo vicina, sotto i campi compare la nota con i
 * due giorni; non blocca, non è l'errore rosso delle date, e sparisce quando la data ha il tempo. Senza i termini (le altre pagine) non c'è.
 *
 * Che cosa non copre: il calcolo dei giorni (il server); il tema scuro e i 375 pixel (la prova a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));

const Date = (await import('./DateDellAssemblea.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
    maggioranze_proposte: {} };
const modulo = (prima: string, seconda: string) => ({ tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'presenza', piattaforma: '',
    consenso_videoconferenza_il: '', consenso_videoconferenza_modo: '', sedute: [
        { convocazione: 'prima', numero: 1, giorno: prima, ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: seconda, ora: '18:30' }] });
const termini = { oggi: '2026-11-26', dal_a_mano: '2026-12-01', dal_raccomandata: '2026-12-06' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });
const nota = () => w!.find('[data-testid="cinque-giorni"]');

describe('i cinque giorni mentre si scrivono le date', () => {
    test('la prima convocazione troppo vicina: la nota con i due giorni, arancio, senza l\'errore rosso', () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-11-28', '2026-11-30'), scelte, termini } as never });
        expect(nota().text()).toContain('Con la prima convocazione del 28/11 non c\'è il tempo dei cinque giorni (art. 66 c. 3): per raccomandata può essere dal 06/12, per PEC o a mano dal 01/12.');
        expect(nota().text()).toContain('Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).');
        expect(nota().classes().join(' ')).toContain('amber');
        expect(w!.find('[data-testid="controllo-date"]').exists()).toBe(false);
    });
    test('con il tempo, senza il giorno o senza i termini non c\'è', async () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-12-14', '2026-12-21'), scelte, termini } as never });
        expect(nota().exists()).toBe(false);
        await w.setProps({ modelValue: modulo('', ''), mostraMancanti: false } as never);
        expect(nota().exists()).toBe(false);
        await w.setProps({ modelValue: modulo('2026-11-28', '2026-11-30'), termini: null } as never);
        expect(nota().exists()).toBe(false);
    });
    test('cambiando la data la nota segue', async () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-11-28', '2026-11-30'), scelte, termini } as never });
        expect(nota().exists()).toBe(true);
        await w.setProps({ modelValue: modulo('2026-12-14', '2026-12-16') } as never);
        expect(nota().exists()).toBe(false);
    });
    test('una nota non nasconde l\'errore vero delle date, che resta rosso', () => {
        w = mount(Date as never, { props: { modelValue: modulo('2026-11-28', '2026-11-28'), scelte, termini } as never });
        expect(w!.find('[data-testid="controllo-date"]').exists()).toBe(true);
        expect(nota().exists()).toBe(true);
    });
});
