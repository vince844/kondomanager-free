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
