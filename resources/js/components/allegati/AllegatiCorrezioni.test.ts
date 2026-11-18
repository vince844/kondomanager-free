// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Allegati, il giro di correzioni: ciò che il riquadro e il campo di caricamento mandano al server (Coda 460).
 *
 * I test Pest chiamano le rotte da soli, con i campi giusti: non vedono che cosa manda la pagina. Qui si monta il
 * componente vero e si guarda la richiesta.
 *
 * - «Rinomina» manda il campo `nome` (quello che la richiesta valida), alla rotta dell'allegato.
 * - Un errore del server sul nome si vede nel riquadro, e il campo resta aperto.
 * - Il suggerimento del campo di caricamento è una stringa sola con il plurale (`transChoice`), sul numero dei
 *   file che si possono ancora aggiungere.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) =>
    `/${nome}/${JSON.stringify(parametri ?? null)}`;

const AllegatiRiquadro = (await import('./AllegatiRiquadro.vue')).default;
const AllegatiCampoModuli = (await import('./AllegatiCampoModuli.vue')).default;

function montaRiquadro() {
    return mount(AllegatiRiquadro, {
        props: {
            tipo: 'segnalazioni',
            elementoId: 5,
            porta: 'user',
            allegati: [
                {
                    id: 10,
                    nome: 'foto-cancello.jpg',
                    mime_type: 'image/jpeg',
                    dimensione: 2048,
                    stato: 'pubblicato',
                    autore: 'Mario Rossi',
                    caricato_il: '2026-11-08T10:00:00.000Z',
                    url_apri: '/user/allegati/10/apri',
                    url_scarica: '/user/allegati/10/scarica',
                    puo_modificare: true,
                    puo_eliminare: false,
                    puo_approvare: false,
                },
            ],
            config: {
                accesso: 'aperti',
                moderati: true,
                puo_caricare: false,
                puo_impostare: false,
                massimo: 5,
                rimasti: 4,
                limite_file: '10 MB',
                formati: ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
            },
        },
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
        },
        attachTo: document.body,
    });
}

async function rinomina(wrapper: ReturnType<typeof montaRiquadro>, nuovo: string) {
    const pulsante = wrapper.findAll('button').find((b) => b.text().includes('allegati.actions.rename'));
    expect(pulsante, 'Il pulsante «Rinomina» non c\'è').toBeDefined();
    await pulsante!.trigger('click');
    await wrapper.find('input[type="text"]').setValue(nuovo);
    const salva = wrapper.findAll('button').find((b) => b.text().includes('allegati.actions.save_name'));
    expect(salva, 'Il pulsante «Salva» non c\'è').toBeDefined();
    await salva!.trigger('click');
    await flushPromises();
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('Allegati, il giro di correzioni (Coda 460)', () => {
    test('«Rinomina» manda il campo «nome» alla rotta dell\'allegato', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined as never);
        const wrapper = montaRiquadro();

        await rinomina(wrapper, 'cancello rotto.jpg');

        expect(patch).toHaveBeenCalledTimes(1);
        const [indirizzo, dati] = patch.mock.calls[0] as [string, Record<string, unknown>];
        expect(dati).toEqual({ nome: 'cancello rotto.jpg' });
        expect(String(indirizzo)).toContain('10');
    });

    test('un errore del server sul nome si vede nel riquadro, e il campo resta aperto', async () => {
        vi.spyOn(router, 'patch').mockImplementation(((_url: unknown, _dati: unknown, opzioni?: { onError?: (e: Record<string, string>) => void; onFinish?: () => void }) => {
            opzioni?.onError?.({ nome: 'Il nome non può superare i 255 caratteri.' });
            opzioni?.onFinish?.();
        }) as never);
        const wrapper = montaRiquadro();

        await rinomina(wrapper, 'x'.repeat(300) + '.jpg');

        expect(wrapper.text()).toContain('Il nome non può superare i 255 caratteri.');
        expect(wrapper.find('input[type="text"]').exists()).toBe(true);
    });

    test('il suggerimento del campo di caricamento ha il plurale sul numero dei file che si possono ancora aggiungere', () => {
        const wrapper = mount(AllegatiCampoModuli, {
            props: { modelValue: [], massimo: 5, rimasti: 1, limiteFile: '10 MB', formati: ['jpg', 'pdf'] },
        });

        expect(wrapper.text()).toContain('allegati.upload.hint#1');
    });
});

// Decisione 464: nel riquadro della scheda il caricamento chiude come «Scrivi un commento…» del riquadro accanto, una zona tratteggiata con
// «Aggiungi allegati» scritto una volta sola, e sotto i formati e i file che si possono ancora aggiungere. I moduli «Nuova …» restano come sono.
describe('Allegati — il fondo del riquadro come «Scrivi un commento…» (decisione 464)', () => {
    test('con aspetto «zona»: una zona tratteggiata che apre la scelta dei file, «Aggiungi allegati» una volta, sotto formati e posti', async () => {
        const scegli = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => undefined);
        const wrapper = mount(AllegatiCampoModuli, {
            props: { modelValue: [], massimo: 5, rimasti: 5, limiteFile: '20 MB', formati: ['jpg', 'pdf'], aspetto: 'zona' },
        });

        const zona = wrapper.get('[data-zona-allegati]');
        expect(zona.element.tagName).toBe('BUTTON');
        expect(zona.classes()).toContain('border-dashed');
        expect(zona.text()).toContain('allegati.upload.label');
        expect(wrapper.text().split('allegati.upload.label').length - 1).toBe(1);
        // Decisione 464 ➕: i formati e i posti stanno nel suggerimento della «i» dentro la zona, e per chi non vede nella descrizione
        // del pulsante; sotto la zona non c'è più la riga.
        expect(zona.get('[data-info-allegati]').exists()).toBe(true);
        const descrizione = wrapper.get(`#${zona.attributes('aria-describedby')}`);
        expect(descrizione.text()).toBe('allegati.upload.hint_zona#5');
        expect(descrizione.classes()).toContain('sr-only');
        expect(wrapper.findAll('p').filter((p) => p.text().includes('allegati.upload.hint_zona'))).toHaveLength(0);

        await zona.trigger('click');
        expect(scegli).toHaveBeenCalledTimes(1);
        scegli.mockRestore();
    });

    test('senza aspetto il campo dei moduli resta com\'è: l\'etichetta e il pulsante', () => {
        const wrapper = mount(AllegatiCampoModuli, { props: { modelValue: [], massimo: 5, rimasti: 5 } });

        expect(wrapper.find('[data-zona-allegati]').exists()).toBe(false);
        expect(wrapper.text().split('allegati.upload.label').length - 1).toBe(2);
    });

    test('il riquadro della scheda usa la zona', () => {
        const wrapper = mount(AllegatiRiquadro, {
            props: {
                tipo: 'segnalazioni', elementoId: 5, porta: 'admin', allegati: [],
                config: { accesso: 'aperti', moderati: false, puo_caricare: true, puo_impostare: false, massimo: 5, rimasti: 5, limite_file: '20 MB', formati: ['jpg'] },
            } as never,
        });

        expect(wrapper.findComponent(AllegatiCampoModuli).props('aspetto')).toBe('zona');
        // Senza la linea sopra, come il fondo del riquadro dei commenti.
        expect(wrapper.get('[data-caricamento]').classes()).not.toContain('border-t');
        // Decisione 469: una linea sottile sotto l'intestazione della card, come la card del testo, e 16 px sotto.
        expect(wrapper.get('[data-intestazione-allegati]').classes()).toEqual(expect.arrayContaining(['border-b', 'pb-4']));
    });
});

// Standard 16, la potenza di Inertia: un'azione sugli allegati della scheda chiede indietro solo gli allegati, le loro impostazioni, il
// messaggio e i numeri del menu, e tiene lo stato della pagina (prima rimandava e ricostruiva la pagina intera).
describe('Allegati — le azioni con una visita parziale', () => {
    test('«Rinomina» chiede indietro solo ciò che cambia', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined as never);
        const wrapper = montaRiquadro();

        await rinomina(wrapper, 'cancello rotto.jpg');

        expect(patch).toHaveBeenCalledWith(expect.any(String), { nome: 'cancello rotto.jpg' }, expect.objectContaining({
            preserveScroll: true,
            preserveState: true,
            only: ['allegati', 'allegati_config', 'flash', 'allegati_in_attesa', 'inbox_count'],
            showProgress: false,
        }));
    });
});

