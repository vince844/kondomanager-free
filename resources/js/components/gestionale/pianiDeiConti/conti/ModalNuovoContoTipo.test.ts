// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il modulo «Nuova voce» e il rifiuto del tipo sotto il capitolo: una voce di entrata sotto un capitolo di spesa (o il contrario) si rifiuta nel
 * salvataggio, con la frase sul campo `tipo` (`CreateContoRequest`, `BloccoDellaVoce::rifiutoDelTipoSottoIlCapitolo`, `Beta15CapitoloMistoTest`).
 * La frase si vede sotto il tipo, e il modulo tiene acceso «È un sotto-conto» con il capitolo scelto: l'amministratore cambia il tipo o il capitolo
 * e salva di nuovo.
 *
 * Cosa resta scoperto: il server (il test Pest citato).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive, ref } from 'vue';

const FRASE = 'Una voce di entrata va sotto un capitolo di entrata, e «Proventi» è un capitolo di spesa: cambia il tipo del capitolo in «Entrata» con «Modifica» dalla sua scheda, o crea la voce fuori dal capitolo.';
const errori = ref<Record<string, string>>({ tipo: FRASE });

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => {
        const form = reactive({
            ...dati, errors: {} as Record<string, string>, processing: false, clearErrors: vi.fn(), reset: vi.fn(),
            post: (_url: string, opzioni: { onError?: (e: Record<string, string>) => void }) => {
                form.errors = { ...errori.value };
                opzioni.onError?.(form.errors);
            },
        });
        return form;
    },
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', props: ['options', 'selectable'], template: '<div class="vselect-finto" />' } }));
vi.mock('@/composables/useTabelle', () => ({ useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }) }));
vi.mock('@/composables/useCapitoliConti', () => ({ useCapitoliConti: () => ({ capitoli: ref([{ id: 3, nome: 'Proventi', blocco: null }]), isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
vi.spyOn(console, 'error').mockImplementation(() => {});

const ModalNuovoConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue')).default;

let finestra: VueWrapper | null = null;
afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

async function salvaSottoIlCapitolo() {
    finestra = mount(ModalNuovoConto, { attachTo: document.body, props: { show: true, condominioId: 1, esercizioId: 1, pianoContoId: 1, fornitori: [] } });
    await flushPromises();
    (document.body.querySelector('#isSottoConto') as HTMLButtonElement).click();
    await flushPromises();
    document.body.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const sottoContoAcceso = () => document.body.querySelector('#isSottoConto')?.getAttribute('data-state') === 'checked';

describe('il rifiuto del tipo sotto il capitolo', () => {
    test('la frase del server sul tipo si vede nel modulo, e «È un sotto-conto» resta acceso con l\'elenco dei capitoli', async () => {
        errori.value = { tipo: FRASE };
        await salvaSottoIlCapitolo();

        expect(testo()).toContain(FRASE);
        expect(sottoContoAcceso()).toBe(true);
        expect(testo()).toContain('Capitolo padre');
    });

    test('controprova: un errore su un altro campo spegne «È un sotto-conto» come prima', async () => {
        errori.value = { nome: 'Il nome è obbligatorio.' };
        await salvaSottoIlCapitolo();

        expect(testo()).toContain('Il nome è obbligatorio.');
        expect(sottoContoAcceso()).toBe(false);
    });
});
