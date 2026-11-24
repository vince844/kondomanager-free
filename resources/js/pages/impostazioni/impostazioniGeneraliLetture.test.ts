// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le impostazioni generali: «Registra quando una comunicazione viene aperta nel portale», e il pulsante che cancella
 * le aperture registrate (Coda 594, decisioni 310 e 311).
 *
 * - L'interruttore sta in un riquadro con `data-impostazione="comunicazioni_registra_aperture"`, con il titolo, la
 *   spiegazione (che dice anche la differenza con «Chiedi la presa visione») e un `Switch` legato al campo del modulo
 *   con lo stesso nome. Dentro il riquadro, `[data-nota="privacy-aperture"]` è la riga per l'amministratore.
 * - Sotto, `[data-riquadro="aperture-registrate"]` dice quante aperture sono registrate (`aperture_registrate`) e ha
 *   il pulsante `[data-azione="cancella-aperture"]`, spento se non ce ne sono. Il primo clic non cancella: mostra la
 *   conferma, nella stessa riga, con `[data-azione="conferma-cancella-aperture"]` e
 *   `[data-azione="annulla-cancella-aperture"]`. La conferma chiama
 *   `router.delete(route('impostazioni.generali.aperture.destroy'), { preserveScroll: true })`: non salva il modulo.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave seguita dai valori passati.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniGenerali = (await import('./impostazioniGenerali.vue')).default;

const NOME = 'comunicazioni_registra_aperture';

function monta(valori: Record<string, unknown> = {}) {
    pagina.props = {
        flash: {},
        can_register: true,
        language: 'it',
        app_name: 'Studio Bianchi',
        open_condominio_on_login: false,
        default_condominio_id: null,
        condomini: [],
        default_user_role: 'utente',
        commenti_segnalazioni_moderati_di_serie: false,
        commenti_comunicazioni_moderati_di_serie: true,
        default_per_page: 30,
        per_page_disponibili: [10, 30, 50],
        roles: [{ id: 1, name: 'utente' }, { id: 2, name: 'amministratore' }],
        ruolo_registrazione_modificabile: true,
        ...valori,
    };

    return shallowMount(ImpostazioniGenerali, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Pagina = ReturnType<typeof monta>;
const riquadro = (w: Pagina) => w.find(`[data-impostazione="${NOME}"]`);
const interruttore = (w: Pagina) => riquadro(w).findComponent({ name: 'Switch' });
const aperture = (w: Pagina) => w.find('[data-riquadro="aperture-registrate"]');
const cancella = (w: Pagina) => w.find('[data-azione="cancella-aperture"]');
const conferma = (w: Pagina) => w.find('[data-azione="conferma-cancella-aperture"]');
const annulla = (w: Pagina) => w.find('[data-azione="annulla-cancella-aperture"]');
// Il pulsante può essere un `<button>` o il componente `Button`, che nel test è sostituito: lì `disabled` spento arriva
// come la stringa `'false'`.
const spento = (pulsante: ReturnType<Pagina['find']>) => ![undefined, 'false'].includes(pulsante.attributes('disabled'));

function mandato(w: Pagina): Record<string, unknown> {
    (w.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

    return vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
});

describe('impostazioniGenerali — «Registra quando una comunicazione viene aperta nel portale»', () => {
    test('ha il suo riquadro, con titolo, spiegazione, riga sulla privacy e interruttore', () => {
        const w = monta();

        expect(riquadro(w).exists()).toBe(true);
        expect(riquadro(w).text()).toContain(`impostazioni.dialogs.${NOME}_title`);
        expect(riquadro(w).text()).toContain(`impostazioni.dialogs.${NOME}_description`);
        expect(riquadro(w).find('[data-nota="privacy-aperture"]').text()).toContain(`impostazioni.dialogs.${NOME}_privacy`);
        expect(interruttore(w).exists()).toBe(true);
    });

    test('senza il valore l\'interruttore è spento, e la pagina manda spento', () => {
        const w = monta();

        expect(interruttore(w).props('modelValue')).toBe(false);
        expect(mandato(w)).toMatchObject({ [NOME]: false, app_name: 'Studio Bianchi' });
    });

    test('la pagina si apre sul valore ricevuto, anche come 0 e 1, e lo rimanda', () => {
        expect(interruttore(monta({ [NOME]: true })).props('modelValue')).toBe(true);
        expect(mandato(monta({ [NOME]: true }))).toMatchObject({ [NOME]: true });
        expect(mandato(monta({ [NOME]: 1 }))).toMatchObject({ [NOME]: true });
        expect(mandato(monta({ [NOME]: 0 }))).toMatchObject({ [NOME]: false });
    });

    test('acceso dalla pagina, parte acceso', async () => {
        const w = monta();

        interruttore(w).vm.$emit('update:modelValue', true);
        await w.vm.$nextTick();

        expect(mandato(w)).toMatchObject({ [NOME]: true });
    });
});

describe('impostazioniGenerali — «Cancella le aperture registrate»', () => {
    test('il riquadro dice quante aperture sono registrate', () => {
        const w = monta({ aperture_registrate: 37 });

        expect(aperture(w).text()).toContain('impostazioni.dialogs.comunicazioni_aperture_registrate');
        expect(aperture(w).text()).toContain('"count":37');
        expect(cancella(w).text()).toContain('impostazioni.dialogs.comunicazioni_cancella_aperture');
        expect(spento(cancella(w))).toBe(false);
        // Sta dentro il modulo delle impostazioni: senza `type="button"` il clic salverebbe il modulo.
        expect(cancella(w).attributes('type')).toBe('button');
    });

    test('senza aperture registrate il pulsante è spento', () => {
        for (const valori of [{ aperture_registrate: 0 }, {}]) {
            const w = monta(valori);

            expect(aperture(w).text()).toContain('"count":0');
            expect(spento(cancella(w))).toBe(true);
        }
    });

    test('il primo clic non cancella: mostra la conferma, con le sue parole', async () => {
        const w = monta({ aperture_registrate: 37 });
        expect(conferma(w).exists()).toBe(false);
        expect(annulla(w).exists()).toBe(false);

        await cancella(w).trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(conferma(w).exists()).toBe(true);
        expect(annulla(w).exists()).toBe(true);
        expect(conferma(w).attributes('type')).toBe('button');
        expect(annulla(w).attributes('type')).toBe('button');
        expect(aperture(w).text()).toContain('impostazioni.dialogs.comunicazioni_cancella_aperture_conferma');
        expect(conferma(w).text()).toContain('impostazioni.dialogs.comunicazioni_cancella_aperture_si');
        expect(annulla(w).text()).toContain('impostazioni.dialogs.comunicazioni_cancella_aperture_no');
    });

    test('«Annulla» richiude la conferma senza mandare niente', async () => {
        const w = monta({ aperture_registrate: 37 });
        await cancella(w).trigger('click');

        await annulla(w).trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(conferma(w).exists()).toBe(false);
        expect(cancella(w).exists()).toBe(true);
    });

    test('la conferma chiama la rotta che cancella le aperture, senza salvare il modulo', async () => {
        const w = monta({ aperture_registrate: 37 });
        await cancella(w).trigger('click');

        await conferma(w).trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        const chiamata = vi.mocked(router.delete).mock.calls[0];
        expect(chiamata[0]).toBe('/impostazioni.generali.aperture.destroy');
        expect(chiamata[1]).toMatchObject({ preserveScroll: true });
        expect(router.post).not.toHaveBeenCalled();
    });
});
