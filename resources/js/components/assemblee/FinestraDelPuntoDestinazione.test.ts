// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra del punto per la modifica della destinazione d'uso (art. 1117-ter, Coda 679): le parti comuni che cambiano e la nuova destinazione, che a
 * pena di nullità l'avviso deve dire, si scrivono nel punto e vanno al server in `dati`; per un punto di un'altra materia i due campi non ci sono e `dati`
 * non parte. Sotto la frase per la bacheca la finestra dice che, senza, in bacheca va solo la materia.
 *
 * Che cosa non copre: il server, che unisce i due campi agli altri dati del punto (`DestinazioneDUsoDallaPaginaTest`); i menu `v-select` aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const patch = vi.fn();
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), patch: (...a: unknown[]) => patch(...a), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'destinazione_uso', nome: 'Modifica della destinazione d\'uso' }, { valore: 'lavori', nome: 'Lavori' }],
    maggioranze: [], esiti: [], a_chi: [], unita: 26, palazzine: [], scale: [], tabelle: [], maggioranze_proposte: {} };
const punto = (extra: Record<string, unknown> = {}) => ({
    id: 8, numero: 6, testo: 'Il locale delle biciclette diventa deposito dei passeggini', testo_pubblico: null, materia: 'destinazione_uso', materia_parola: '',
    maggioranza: null, maggioranza_parola: null, maggioranza_libera: null, avviso: null, amministrazione: 'straordinaria', conduttore: null, conduttore_parola: null,
    ambito: null, palazzina_id: null, scala_id: null, perimetro_parola: null, tabella_id: null, tabella_nome: null, tabella_controllo: null, esercizio_id: null,
    importo: null, dati: { parti_comuni: 'il locale delle biciclette', nuova_destinazione: null }, fonte: 'a_mano', chiave_fonte: null, da_memo: false, delibere: [],
    ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; patch.mockReset(); document.body.innerHTML = ''; });
async function monta(p: Record<string, unknown>) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea ordinaria del 15/12/2026', tabellaDellAssemblea: null, punto: p, scelte } as never });
    await flushPromises();
}

describe('la finestra del punto della destinazione d\'uso', () => {
    test('i due campi partono dai dati del punto e vanno al server in `dati`', async () => {
        await monta(punto());
        const parti = document.body.querySelector<HTMLInputElement>('[data-testid="parti-comuni"]')!;
        const nuova = document.body.querySelector<HTMLInputElement>('[data-testid="nuova-destinazione"]')!;
        expect(parti.value).toBe('il locale delle biciclette');
        expect(nuova.value).toBe('');
        nuova.value = 'deposito dei passeggini';
        nuova.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch.mock.calls[0][1]).toMatchObject({ materia: 'destinazione_uso', dati: { parti_comuni: 'il locale delle biciclette', nuova_destinazione: 'deposito dei passeggini' } });
    });

    test('per un punto di un\'altra materia i due campi non ci sono e `dati` non parte; la bacheca senza frase dice che cosa scrive', async () => {
        await monta(punto({ materia: 'lavori', testo: 'Sostituzione del portone', dati: null }));
        expect(document.body.querySelector('[data-testid="destinazione-uso-punto"]')).toBeNull();
        expect(document.body.textContent).toContain('Spento: in bacheca va solo di che cosa parla il punto, mai la frase dell\'avviso.');
        document.body.querySelector<HTMLButtonElement>('[data-testid="salva-punto"]')!.click();
        expect(patch.mock.calls[0][1]).not.toHaveProperty('dati');
    });
});
