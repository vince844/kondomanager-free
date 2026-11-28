// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Esercizio del piano: 2026 · Cambia» della pagina del piano: «Cambia» apre il menu degli esercizi aperti della gestione e «Sposta il piano»
 * manda la scelta (`PianoRateController::updateEsercizio`, prova `CambiaEsercizioDelPianoTest`). Un piano con rate emesse o incassi non offre «Cambia»: il
 * riquadro dice perché, su una riga sua.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const put = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), put: (...a: unknown[]) => put(...a), post: vi.fn(), get: vi.fn(), reload: vi.fn((o?: { onFinish?: () => void }) => o?.onFinish?.()) },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
// Il menu vero (vSelect) al posto di un elenco di pulsanti: uno per esercizio, che sceglie il suo id.
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options', 'selectable'], emits: ['update:modelValue'],
    template: '<div data-testid="menu-esercizi"><button v-for="o in options" :key="o.id" type="button" class="voce-del-menu" :disabled="selectable ? !selectable(o) : false" @click="$emit(\'update:modelValue\', o.id)">{{ o.nome }}</button></div>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(cambio: { attuale: { id: number; nome: string } | null; scelte: Array<{ id: number; nome: string; perche?: string | null }>; fermo: string | null }) {
    const capitolo = { id: 7, nome: 'Spese generali', importo: 120000, importo_originale: 120000, is_parent: false, tipo: 'spesa' };
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: 'Esercizio 2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, totale_spese: 120000, totale_entrate: 0, totale_da_ripartire: 120000,
                budget_movements: [], capitoli: [{ is_frazionato: false, note: null, figli_names: '', ...capitolo }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
            cambio_esercizio: cambio,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } },
            mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` } },
    });
    await flushPromises();
}

const riga = () => document.body.querySelector('[data-testid="riga-esercizio"]');
const pulsante = (testo: string) => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === testo) as HTMLButtonElement | undefined;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
    put.mockReset();
});

describe('la riga dell\'esercizio del piano', () => {
    test('«Esercizio del piano: Esercizio 2026» in un riquadro, con «Cambia»; «Cambia» apre il menu e «Sposta il piano» manda l\'esercizio scelto', async () => {
        await monta({ attuale: { id: 1, nome: 'Esercizio 2026' }, scelte: [{ id: 2, nome: 'Esercizio 2027' }], fermo: null });
        // Gli spazi fra gli elementi li mette la pagina con la distanza.
        expect(riga()?.textContent?.replace(/\s+/g, '')).toBe('Eserciziodelpiano:Esercizio2026Cambia');
        expect([...riga()!.classList]).toEqual(expect.arrayContaining(['rounded-lg', 'border', 'shadow-sm']));

        pulsante('Cambia')!.click();
        await flushPromises();
        const voci = [...document.body.querySelectorAll('[data-testid="menu-esercizi"] .voce-del-menu')] as HTMLButtonElement[];
        expect(voci.map((v) => v.textContent)).toEqual(['Esercizio 2027']);
        voci[0].click();
        await flushPromises();
        pulsante('Sposta il piano')!.click();

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toContain('gestionale.esercizi.piani-rate.update-esercizio');
        expect(put.mock.calls[0][1]).toEqual({ esercizio_id: 2 });
    });

    test('il piano emesso non offre «Cambia», e la riga dice perché', async () => {
        await monta({ attuale: { id: 1, nome: 'Esercizio 2026' }, scelte: [{ id: 2, nome: 'Esercizio 2027' }], fermo: 'Il piano ha rate emesse, incassi o il conguaglio di un passaggio: non cambia esercizio, si rifà.' });

        expect(pulsante('Cambia')).toBeUndefined();
        const perche = [...riga()!.querySelectorAll('span')].find((s) => s.textContent?.startsWith('Il piano ha rate emesse'))!;
        expect(perche.textContent).toBe('Il piano ha rate emesse, incassi o il conguaglio di un passaggio: non cambia esercizio, si rifà.');
        expect([...perche.classList]).toContain('w-full');
    });

    test('l\'esercizio dove le voci del piano sono già chieste per intero non si sceglie, e sotto il menu c\'è il perché', async () => {
        const perche = 'la voce «Spese generali» vale € 1.200,00 ed è già chiesta dal piano «Preventivo 2027» (€ 1.200,00), restano € 0,00 da chiedere e questo piano ne chiede € 1.200,00.';
        await monta({ attuale: { id: 1, nome: 'Esercizio 2026' }, scelte: [{ id: 2, nome: 'Esercizio 2027', perche }, { id: 3, nome: 'Esercizio 2028', perche: null }], fermo: null });

        pulsante('Cambia')!.click();
        await flushPromises();
        const voci = [...document.body.querySelectorAll('[data-testid="menu-esercizi"] .voce-del-menu')] as HTMLButtonElement[];
        expect(voci.map((v) => [v.textContent, v.disabled])).toEqual([['Esercizio 2027', true], ['Esercizio 2028', false]]);
        expect(document.body.querySelector('[data-testid="esercizi-non-scelti"]')?.textContent?.trim()).toBe(`«Esercizio 2027» non si può scegliere: ${perche}`);
    });

    test('controprova: senza altri esercizi aperti la riga dice solo l\'esercizio', async () => {
        await monta({ attuale: { id: 1, nome: 'Esercizio 2026' }, scelte: [], fermo: null });

        expect(riga()?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Esercizio del piano: Esercizio 2026');
        expect(pulsante('Cambia')).toBeUndefined();
    });
});
