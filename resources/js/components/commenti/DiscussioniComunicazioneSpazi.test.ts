// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Commenti alle comunicazioni: fra due discussioni chiuse di fila non resta lo spazio delle discussioni aperte (rilievo H2
 * della quarta revisione, decisione 288).
 *
 * L'elenco delle discussioni è un `space-y-8`, e in Tailwind 4 lo spazio sta in fondo a ogni discussione tranne l'ultima
 * (margine in fondo, non in testa): per togliere lo spazio fra due righe chiuse lo si toglie alla prima delle due, con
 * `!mb-0`. Un margine tolto in testa alla seconda non cambia niente a video (misurato il 17/11/2026: 73 px fra due righe
 * chiuse con la classe in testa, 41 con quella in fondo).
 *
 * Non prova la misura in un browser: jsdom non calcola gli stili. Prova a quale riga va la classe.
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

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) =>
    `/${nome}/${JSON.stringify(parametri ?? null)}`;

const DiscussioniComunicazione = (await import('./DiscussioniComunicazione.vue')).default;

function commento(id: number) {
    return {
        id,
        corpo: 'Grazie',
        stato: 'pubblicato',
        autore: { nome: 'Paolo Neri' },
        created_at: '2026-11-10T09:00:00.000Z',
        modificato: false,
        modificato_at: null,
        azioni: { modifica: false, elimina: false, approva: false, nascondi: false },
    };
}

function persona(id: number, commenti: unknown[] = []) {
    return { chiave: `persona:${id}`, tipo: 'persona', titolo: `Persona ${id}`, puo_scrivere: true, commenti };
}

function monta(discussioni: unknown[]) {
    return mount(DiscussioniComunicazione, {
        props: {
            comunicazioneId: 7,
            porta: 'admin',
            discussioni,
            config: { can_comment: true, commenti_moderati: false, puo_impostare: true, puo_moderare: true },
        },
        global: { mocks: { route: (globalThis as unknown as { route: unknown }).route } },
        attachTo: document.body,
    });
}

/** Le discussioni, nell'ordine, come gli elementi del `v-for` (i figli dell'elenco con `space-y-4`). */
function righe(wrapper: ReturnType<typeof monta>) {
    return wrapper.findAll('.space-y-8 > .space-y-4');
}

describe('lo spazio fra le discussioni chiuse', () => {
    test('una chiusa seguita da una chiusa perde lo spazio in fondo; la chiusa in fondo all\'elenco e l\'aperta no', () => {
        // Mario ha un commento: aperta; le altre tre sono chiuse (più di tre discussioni, nel pannello).
        const wrapper = monta([persona(11, [commento(1)]), persona(12), persona(13), persona(14)]);
        const classi = righe(wrapper).map((r) => r.classes().includes('!mb-0'));

        expect(classi).toEqual([false, true, true, false]);
        wrapper.unmount();
    });

    test('una chiusa seguita da un\'aperta tiene lo spazio', () => {
        const wrapper = monta([persona(11), persona(12), persona(13, [commento(1)]), persona(14)]);
        const classi = righe(wrapper).map((r) => r.classes().includes('!mb-0'));

        expect(classi).toEqual([true, false, false, false]);
        wrapper.unmount();
    });

    test('nessuna riga porta più il margine in testa, che a video non toglieva niente', () => {
        const wrapper = monta([persona(11), persona(12), persona(13), persona(14)]);

        expect(righe(wrapper).some((r) => r.classes().includes('!mt-1'))).toBe(false);
        wrapper.unmount();
    });
});

// Fase 1-bis della 1.12.0-beta.11: il margine del modulo lo decide chi lo mette. La radice di `FormCommento` aveva `mt-2` e il genitore
// passava `mt-4`: con tutte e due, quale vince lo decideva l'ordine del CSS generato.
describe('Commenti alle comunicazioni: il margine del modulo', () => {
    test('il modulo ha il margine che gli dà la discussione, uno solo', () => {
        const wrapper = monta([persona(11, [commento(1)])]);
        const classi = wrapper.get('[data-discussione="persona:11"]').classes();

        expect(classi).toContain('mt-4');
        expect(classi).not.toContain('mt-2');
        wrapper.unmount();
    });
});

// Decisione 457 (1.12.0-beta.11): il numero fra parentesi della discussione conta i pubblicati e gli in attesa, come il contatore dell'elenco
// («N commenti»), non i nascosti, che chi modera vede in grigio.
describe('Commenti alle comunicazioni: il numero della discussione', () => {
    test('un commento nascosto non entra nel numero fra parentesi', () => {
        const nascosto = { ...commento(3), stato: 'nascosto' };
        const inAttesa = { ...commento(4), stato: 'in_attesa' };
        const wrapper = monta([persona(11, [commento(1), commento(2), nascosto, inAttesa])]);

        expect(wrapper.get('[data-numero-commenti]').text()).toBe('(3)');
        wrapper.unmount();
    });

    test('con il solo commento nascosto il numero non c\'è', () => {
        const wrapper = monta([persona(11, [{ ...commento(3), stato: 'nascosto' }])]);

        expect(wrapper.find('[data-numero-commenti]').exists()).toBe(false);
        wrapper.unmount();
    });
});
