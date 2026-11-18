// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Commenti in moderazione» la cambia solo chi modera (Fase 1-bis della 1.12.0-beta.11): il server ignora il campo di chi
 * non può approvare, e la finestra delle impostazioni dei commenti della segnalazione non glielo offre. «Consenti
 * commenti» resta.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({
        url: '/admin/segnalazioni/5',
        props: { auth: { user: { id: 1, name: 'Anna', roles: ['collaboratore'], permissions: ['Accesso pannello amministratore'] } }, flash: {} },
    }),
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ListaCommenti = (await import('./ListaCommenti.vue')).default;

function monta(puoImpostareModerazione: boolean) {
    return mount(ListaCommenti, {
        props: {
            segnalazioneId: 5,
            commenti: [],
            commentiInAttesa: [],
            commentiConfig: {
                can_comment: true,
                can_create: true,
                can_moderate: puoImpostareModerazione,
                can_publish: true,
                commenti_moderati: true,
                puo_impostare: true,
                puo_impostare_moderazione: puoImpostareModerazione,
            },
            currentUserId: 1,
        },
        global: { mocks: { route: (globalThis as unknown as { route: unknown }).route } },
        attachTo: document.body,
    });
}

async function apriImpostazioni(wrapper: ReturnType<typeof monta>) {
    await wrapper.find('button[title="segnalazioni.settings.comments_title"]').trigger('click');
    await flushPromises();
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('Impostazioni dei commenti: la moderazione solo a chi modera', () => {
    test('chi modifica la segnalazione senza approvare i commenti vede «Consenti commenti», non «Commenti in moderazione»', async () => {
        const wrapper = monta(false);
        await apriImpostazioni(wrapper);

        expect(document.body.querySelector('#modal_segnalazione_can_comment')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="scelta-moderazione"]')).toBeNull();
        expect(document.body.querySelector('#modal_segnalazione_commenti_moderati')).toBeNull();
        wrapper.unmount();
    });

    test('chi modera vede tutte e due le scelte', async () => {
        const wrapper = monta(true);
        await apriImpostazioni(wrapper);

        expect(document.body.querySelector('#modal_segnalazione_can_comment')).not.toBeNull();
        expect(document.body.querySelector('#modal_segnalazione_commenti_moderati')).not.toBeNull();
        wrapper.unmount();
    });
});
