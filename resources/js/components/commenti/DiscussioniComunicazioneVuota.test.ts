// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Commenti alle comunicazioni: una comunicazione senza discussioni (Coda 559, giro di correzioni 2).
 *
 * Una comunicazione può non avere nessuna discussione: privata dello studio senza destinatari, persone senza un
 * account, nessun palazzo. Il riquadro non deve dire «Scrivi il primo commento per questa segnalazione»: non è una
 * segnalazione, e non c'è dove scrivere.
 *
 * - Nel pannello dice che non c'è nessuna discussione, con la sua spiegazione.
 * - Nel portale il componente non mostra niente.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

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

function monta(porta: 'admin' | 'user') {
    return mount(DiscussioniComunicazione, {
        props: {
            comunicazioneId: 7,
            porta,
            discussioni: [],
            config: { can_comment: true, commenti_moderati: true, puo_impostare: porta === 'admin', puo_moderare: porta === 'admin' },
        },
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
        },
        attachTo: document.body,
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('Commenti alle comunicazioni: nessuna discussione', () => {
    test('nel pannello dice che non c\'è nessuna discussione, e non parla di segnalazioni', () => {
        const testo = monta('admin').text();

        expect(testo).toContain('commenti.nessuna_discussione');
        expect(testo).toContain('commenti.nessuna_discussione_descrizione');
        expect(testo).not.toContain('commenti.nessun_commento_descrizione');
    });

    test('nel portale non mostra niente', () => {
        const wrapper = monta('user');

        expect(wrapper.text().trim()).toBe('');
        expect(wrapper.find('form').exists()).toBe(false);
    });
});

// Decisione 468: una discussione sola senza commenti ha il riquadro vuoto delle segnalazioni e degli allegati (l'icona, il titolo e la
// frase), con la frase della comunicazione: aperta, «scrivi il primo commento»; chiusa, «i commenti sono chiusi».
describe('Commenti alle comunicazioni: una discussione senza commenti (decisione 468)', () => {
    function montaUna(porta: 'admin' | 'user', puoScrivere: boolean) {
        return mount(DiscussioniComunicazione, {
            props: {
                comunicazioneId: 7,
                porta,
                discussioni: [{ chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: puoScrivere, commenti: [] }],
                config: { can_comment: puoScrivere, commenti_moderati: true, puo_impostare: porta === 'admin', puo_moderare: porta === 'admin' },
            },
            global: { mocks: { route: (globalThis as unknown as { route: unknown }).route } },
            attachTo: document.body,
        });
    }

    test('commenti chiusi: il riquadro vuoto dice che sono chiusi, non c\'è il modulo', () => {
        const w = montaUna('admin', false);
        const vuoto = w.get('[data-discussione-vuota]');

        expect(vuoto.text()).toContain('commenti.nessun_commento');
        expect(vuoto.text()).toContain('commenti.comunicazione_commenti_chiusi');
        expect(vuoto.text()).not.toContain('commenti.nessun_commento_descrizione');
        expect(w.find('form').exists()).toBe(false);
    });

    test('commenti aperti: il riquadro vuoto invita a scrivere, e sotto c\'è il modulo', () => {
        const w = montaUna('user', true);

        expect(w.get('[data-discussione-vuota]').text()).toContain('commenti.comunicazione_primo_commento');
        expect(w.find('[data-discussione="tutti"]').exists()).toBe(true);
    });

    test('con un commento il riquadro vuoto non c\'è', () => {
        const w = mount(DiscussioniComunicazione, {
            props: {
                comunicazioneId: 7, porta: 'admin',
                discussioni: [{ chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true, commenti: [{ id: 1, corpo: 'Ciao', stato: 'pubblicato', autore: { nome: 'Mario' }, created_at: '2026-11-10T09:00:00.000Z' }] }],
                config: { can_comment: true, commenti_moderati: false, puo_impostare: true, puo_moderare: true },
            },
            global: { mocks: { route: (globalThis as unknown as { route: unknown }).route } },
        });

        expect(w.find('[data-discussione-vuota]').exists()).toBe(false);
    });
});

