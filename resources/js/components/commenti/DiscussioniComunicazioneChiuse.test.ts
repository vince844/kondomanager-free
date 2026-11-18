// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Commenti alle comunicazioni: nel pannello, con più di tre discussioni, quelle senza commenti nascono chiuse
 * (Coda 559, giro di correzioni 3; decisione 287).
 *
 * Una comunicazione a tante persone mostrava un campo vuoto per ognuna. Con più di tre discussioni lo studio vede
 * aperte solo quelle che hanno già commenti; delle altre vede il nome e «Scrivi», e le apre con un clic.
 *
 * - Chiusa, una discussione non ha il modulo: ha il pulsante `button[data-apri-discussione="<chiave>"]`.
 * - Il clic apre quella sola, e resta aperta.
 * - Con tre discussioni o meno, e nel portale sempre, restano tutte aperte.
 * - Dove non si può scrivere non c'è il pulsante.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) =>
    `/${nome}/${JSON.stringify(parametri ?? null)}`;

const DiscussioniComunicazione = (await import('./DiscussioniComunicazione.vue')).default;

function commento(id: number, corpo: string) {
    return {
        id,
        corpo,
        stato: 'pubblicato',
        autore: { nome: 'Paolo Neri' },
        created_at: '2026-11-10T09:00:00.000Z',
        modificato: false,
        modificato_at: null,
        azioni: { modifica: false, elimina: false, approva: false, nascondi: false },
    };
}

function persona(id: number, titolo: string, commenti: unknown[] = [], puoScrivere = true) {
    return { chiave: `persona:${id}`, tipo: 'persona', titolo, puo_scrivere: puoScrivere, commenti };
}

function monta(discussioni: unknown[], porta: 'admin' | 'user' = 'admin') {
    return mount(DiscussioniComunicazione, {
        props: {
            comunicazioneId: 7,
            porta,
            discussioni,
            config: { can_comment: true, commenti_moderati: false, puo_impostare: porta === 'admin', puo_moderare: porta === 'admin' },
        },
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
        },
        attachTo: document.body,
    });
}

// Decisione 441: il modulo è `FormCommento`, che porta `data-discussione` sulla radice, chiuso o aperto.
const modulo = (chiave: string) => `[data-discussione="${chiave}"]`;
const apri = (chiave: string) => `button[data-apri-discussione="${chiave}"]`;

/** Quattro discussioni: una con un commento, tre senza. */
function quattro() {
    return [
        persona(11, 'Mario Rossi', [commento(1, 'Grazie dell\'avviso')]),
        persona(12, 'Lucia Verdi'),
        persona(13, 'Paolo Neri'),
        persona(14, 'Anna Gialli'),
    ];
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('Commenti alle comunicazioni: le discussioni chiuse nel pannello', () => {
    test('con più di tre discussioni, quelle senza commenti mostrano il nome e «Scrivi», senza il modulo', () => {
        const wrapper = monta(quattro());

        // Quella con un commento è aperta, com'è sempre stata.
        expect(wrapper.find(modulo('persona:11')).exists()).toBe(true);
        expect(wrapper.find(apri('persona:11')).exists()).toBe(false);
        expect(wrapper.text()).toContain('Grazie dell\'avviso');

        for (const [id, nome] of [[12, 'Lucia Verdi'], [13, 'Paolo Neri'], [14, 'Anna Gialli']] as const) {
            expect(wrapper.find(modulo(`persona:${id}`)).exists()).toBe(false);
            expect(wrapper.find(apri(`persona:${id}`)).exists()).toBe(true);
            expect(wrapper.find(apri(`persona:${id}`)).text()).toContain('commenti.scrivi');
            expect(wrapper.text()).toContain(nome);
        }
        // Decisione 441: anche la discussione aperta ha il modulo chiuso di serie (la riga tratteggiata), senza campo.
        expect(wrapper.findAll('textarea')).toHaveLength(0);
    });

    test('il clic su «Scrivi» apre quella discussione sola, e il pulsante sparisce', async () => {
        const wrapper = monta(quattro());

        await wrapper.find(apri('persona:13')).trigger('click');
        await flushPromises();

        expect(wrapper.find(modulo('persona:13')).exists()).toBe(true);
        // Dopo «Scrivi» il modulo nasce già aperto (non la riga tratteggiata), con il fuoco nel campo (decisione 441).
        const campo = wrapper.find(modulo('persona:13')).find('textarea');
        expect(campo.exists()).toBe(true);
        expect(document.activeElement).toBe(campo.element);
        expect(wrapper.find(apri('persona:13')).exists()).toBe(false);

        expect(wrapper.find(modulo('persona:12')).exists()).toBe(false);
        expect(wrapper.find(apri('persona:12')).exists()).toBe(true);
        expect(wrapper.find(modulo('persona:14')).exists()).toBe(false);
        expect(wrapper.find(apri('persona:14')).exists()).toBe(true);
    });

    test('una discussione aperta resta aperta quando la pagina si aggiorna con le stesse discussioni', async () => {
        const wrapper = monta(quattro());
        await wrapper.find(apri('persona:13')).trigger('click');

        // Dopo un invio Inertia rimanda le props: la discussione aperta a mano non si richiude.
        await wrapper.setProps({ discussioni: quattro() });

        expect(wrapper.find(modulo('persona:13')).exists()).toBe(true);
        expect(wrapper.find(modulo('persona:12')).exists()).toBe(false);
    });

    test('con tre discussioni restano tutte aperte, come sempre', () => {
        const wrapper = monta([persona(12, 'Lucia Verdi'), persona(13, 'Paolo Neri'), persona(14, 'Anna Gialli')]);

        expect(wrapper.findAll('[data-discussione]')).toHaveLength(3);
        expect(wrapper.find('button[data-apri-discussione]').exists()).toBe(false);
    });

    test('nel portale restano tutte aperte, quante che siano', () => {
        const wrapper = monta(quattro(), 'user');

        expect(wrapper.findAll('[data-discussione]')).toHaveLength(4);
        expect(wrapper.find('button[data-apri-discussione]').exists()).toBe(false);
    });

    test('dove non si può scrivere la discussione chiusa mostra il nome, senza «Scrivi» e senza modulo', () => {
        const wrapper = monta([
            persona(11, 'Mario Rossi', [commento(1, 'Grazie dell\'avviso')], false),
            persona(12, 'Lucia Verdi', [], false),
            persona(13, 'Paolo Neri', [], false),
            persona(14, 'Anna Gialli', [], false),
        ]);

        expect(wrapper.find('[data-discussione]').exists()).toBe(false);
        expect(wrapper.find('button[data-apri-discussione]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Lucia Verdi');
        expect(wrapper.text()).toContain('commenti.nessun_commento');
        expect(wrapper.text()).toContain('Grazie dell\'avviso');
    });

    test('una discussione chiusa non mostra la riga «La leggono solo questa persona e lo studio»', () => {
        const wrapper = monta(quattro());

        // Una sola discussione è aperta: la riga compare una volta, non quattro.
        const volte = wrapper.text().split('commenti.discussione_privata_pannello').length - 1;
        expect(volte).toBe(1);
    });
});
