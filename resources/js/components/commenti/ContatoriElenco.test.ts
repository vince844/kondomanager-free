// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I contatori di commenti e allegati nelle righe degli elenchi delle comunicazioni e delle segnalazioni (decisioni 441 e 453).
 *
 * - Mostra i due numeri che il server manda, senza contare niente da sé.
 * - Uno zero è in grigio più chiaro; le cifre sono `tabular-nums`; la scritta non scende sotto `text-xs`.
 * - «N commenti da approvare» e «N allegati da approvare» compaiono solo dove lo si chiede (il pannello) e solo se il numero non è
 *   zero (decisione 463, che ha diviso il «N in attesa» delle decisioni 441 e 453).
 * - Senza contatori (una riga che nessuno ha calcolato) non mostra niente.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

const Contatori = (await import('./ContatoriElenco.vue')).default;

describe('ContatoriElenco', () => {
    test('mostra i numeri del server, nelle parole dell\'amministratore', () => {
        const wrapper = mount(Contatori, { props: { contatori: { commenti: 3, allegati: 1, in_attesa: 0 } } });

        expect(wrapper.find('[data-contatore="commenti"]').text()).toContain('commenti.conta_commenti#3');
        expect(wrapper.find('[data-contatore="allegati"]').text()).toContain('commenti.conta_allegati#1');
        expect(wrapper.classes()).toContain('tabular-nums');
        expect(wrapper.classes()).toContain('text-xs');
    });

    test('uno zero è in grigio più chiaro di un numero, e leggibile nel tema scuro', () => {
        const wrapper = mount(Contatori, { props: { contatori: { commenti: 0, allegati: 2, in_attesa: 0 } } });
        const zero = wrapper.find('[data-contatore="commenti"]').classes();
        const pieno = wrapper.find('[data-contatore="allegati"]').classes();

        expect(zero).toContain('text-gray-400');
        expect(zero.some((c) => c.startsWith('dark:text-'))).toBe(true);
        expect(pieno).toContain('text-gray-700');
        // Nel tema scuro il numero è più chiaro dello zero: lo zero resta il più spento anche lì.
        expect(pieno).toContain('dark:text-slate-200');
        expect(zero).toContain('dark:text-slate-500');
        expect(pieno.some((c) => c.startsWith('dark:text-'))).toBe(true);
    });

    // Decisione 463: ciò che aspetta dice che cosa è, commenti e allegati separati, in un'etichetta piccola (la scritta resta a 12 px).
    test('«N commenti da approvare» e «N allegati da approvare» solo dove lo si chiede e solo se il numero non è zero', () => {
        const dati = { commenti: 4, allegati: 1, in_attesa: 3, commenti_in_attesa: 2, allegati_in_attesa: 1 };

        const portale = mount(Contatori, { props: { contatori: dati } });
        expect(portale.find('[data-contatore="commenti_da_approvare"]').exists()).toBe(false);
        expect(portale.find('[data-contatore="allegati_da_approvare"]').exists()).toBe(false);

        const pannello = mount(Contatori, { props: { contatori: dati, mostraInAttesa: true } });
        expect(pannello.get('[data-contatore="commenti_da_approvare"]').text()).toBe('commenti.commenti_da_approvare#2');
        expect(pannello.get('[data-contatore="allegati_da_approvare"]').text()).toBe('commenti.allegati_da_approvare#1');
        expect(pannello.find('[data-contatore="in_attesa"]').exists()).toBe(false);

        const soloCommenti = mount(Contatori, { props: { contatori: { ...dati, in_attesa: 2, allegati_in_attesa: 0 }, mostraInAttesa: true } });
        expect(soloCommenti.find('[data-contatore="commenti_da_approvare"]').exists()).toBe(true);
        expect(soloCommenti.find('[data-contatore="allegati_da_approvare"]').exists()).toBe(false);
    });

    test('l\'etichetta è piccola: angoli rounded-md, senza bordo, poco spazio intorno, la scritta a 12 px', () => {
        const pannello = mount(Contatori, { props: { contatori: { commenti: 1, allegati: 0, in_attesa: 1, commenti_in_attesa: 1, allegati_in_attesa: 0 }, mostraInAttesa: true } });
        const etichetta = pannello.get('[data-contatore="commenti_da_approvare"]').classes();

        expect(etichetta).toContain('rounded-md');
        expect(etichetta.some((c) => c === 'border' || c.startsWith('border-'))).toBe(false);
        expect(etichetta).toContain('py-0');
        expect(pannello.html()).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
    });

    test('accanto agli allegati l\'icona della voce «Allegati» del menu, non la graffetta', () => {
        const html = mount(Contatori, { props: { contatori: { commenti: 0, allegati: 2, in_attesa: 0 } } }).get('[data-contatore="allegati"]').html();

        expect(html).toContain('lucide-files');
        expect(html).not.toContain('lucide-paperclip');
    });

    test('senza contatori non mostra niente', () => {
        expect(mount(Contatori, { props: {} }).html()).toBe('<!--v-if-->');
    });
});
