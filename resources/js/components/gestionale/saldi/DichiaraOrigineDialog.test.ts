// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Dichiara l'origine» del pregresso: la frase accanto al campo («se il saldo contiene debiti di più esercizi, dichiara il più vecchio»), la
 * voce senza una risposta già scelta, la scelta di uno o di tutti i saldi, e la richiesta che parte con i saldi scelti e la voce. Le voci e la
 * lettura dell'origine di un saldo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a: unknown[]) => post(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Dialogo = (await import('./DichiaraOrigineDialog.vue')).default;
const { daScelta, etichettaOrigine, opzioniOrigine } = await import('@/lib/gestionale/debiti/originePregresso');

const FRASE = 'Se il saldo contiene debiti di più esercizi, dichiara il più vecchio: l\'elenco conta da quello.';
const esercizi = [{ id: 5, nome: 'Esercizio 2025' }, { id: 4, nome: 'Esercizio 2023' }];
const saldi = [
    { id: 11, etichetta: 'Int. 1 · Ugo · Ordinaria', importo: 50000, origine_dichiarata: null, esercizio_origine_id: null },
    { id: 12, etichetta: 'Int. 2 · Bice · Ordinaria', importo: 20000, origine_dichiarata: 'esercizio', esercizio_origine_id: 5 },
];

let w: VueWrapper | null = null;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
/** Il menu «Origine» (`v-select`): si apre e si fa clic sulla voce con quel testo. */
const menu = () => document.body.querySelector('[data-testid="voce-origine"] .v-select') as HTMLElement;
const vociDelMenu = async () => {
    menu().querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    return [...menu().querySelectorAll('.vs__dropdown-option')].map((o) => pulito(o.textContent));
};
const nelMenu = async (testo: string) => {
    await vociDelMenu();
    ([...menu().querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};
afterEach(() => { w?.unmount(); w = null; post.mockReset(); document.body.innerHTML = ''; });

async function apri(partenza: number[] = []) {
    w = mount(Dialogo, { attachTo: document.body, props: { open: false, condominioId: 3, saldi, esercizi, fraseOrigine: FRASE, partenza } });
    await w.setProps({ open: true });
    await flushPromises();
}

describe('la finestra', () => {
    test('la frase accanto al campo, e nessuna voce già scelta: il pulsante è fermo finché non si sceglie', async () => {
        await apri([11]);
        expect(document.body.querySelector('[data-testid="frase-origine"]')?.textContent).toBe(FRASE);
        // Il menu è un `v-select`, non il menu del browser; all'apertura della finestra non si apre da solo, e nessuna voce è scelta.
        expect(document.body.querySelector('[data-testid="voce-origine"] select')).toBeNull();
        expect(menu()).not.toBeNull();
        expect(menu().classList.contains('vs--open')).toBe(false);
        expect(document.activeElement?.classList.contains('vs__search')).toBe(false);
        expect(menu().querySelector('.vs__selected')).toBeNull();
        expect((menu().querySelector('.vs__search') as HTMLInputElement).placeholder).toBe('Scegli…');
        expect((document.body.querySelector('[data-testid="conferma-origine"]') as HTMLButtonElement).disabled).toBe(true);
    });

    test('scelto l\'esercizio 2023 per il saldo di partenza, la richiesta porta il saldo, la voce e l\'esercizio', async () => {
        await apri([11]);
        await nelMenu('Esercizio 2023');
        expect(pulito(menu().querySelector('.vs__selected')?.textContent)).toBe('Esercizio 2023');
        (document.body.querySelector('[data-testid="conferma-origine"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/gestionale.saldi.origine');
        expect(post.mock.calls[0][1]).toEqual({ saldi: [11], origine: 'esercizio', esercizio_origine_id: 4 });
    });

    test('«Scegli tutti» prende tutti i saldi; ogni saldo dice la sua origine di oggi', async () => {
        await apri();
        const testo = document.body.textContent ?? '';
        expect(testo).toContain('origine non dichiarata');
        expect(testo).toContain('origine: Esercizio 2025');
        const tutti = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Scegli tutti i 2'));
        expect(tutti).toBeTruthy();
    });
});

describe('il menu «Origine»', () => {
    test('le voci: gli esercizi, «Dovuto con questo piano», «Prima del primo esercizio», «Togli l\'origine (non dichiarata)»; con l\'ultima il pulsante toglie', async () => {
        await apri([11, 12]);
        expect(await vociDelMenu()).toEqual(['Esercizio 2025', 'Esercizio 2023', 'Dovuto con questo piano', 'Prima del primo esercizio', 'Togli l\'origine (non dichiarata)']);
        await nelMenu('Togli l\'origine (non dichiarata)');
        expect(pulito(document.body.querySelector('[data-testid="conferma-origine"]')?.textContent)).toBe('Togli l\'origine da 2 saldi');
        (document.body.querySelector('[data-testid="conferma-origine"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(post.mock.calls[0][1]).toEqual({ saldi: [11, 12], origine: 'non_dichiarata' });
    });

    test('il menu ha l\'aspetto dei menu dei moduli (`menu-campo`)', async () => {
        await apri();
        expect(document.body.querySelector('[data-testid="voce-origine"]')!.classList.contains('menu-campo')).toBe(true);
    });
});

describe('le voci', () => {
    test('gli esercizi, «dovuto con questo piano», «prima del primo esercizio»; la scelta come la vuole il server', () => {
        expect(opzioniOrigine(esercizi).map((o) => o.text)).toEqual(['Esercizio 2025', 'Esercizio 2023', 'Dovuto con questo piano', 'Prima del primo esercizio']);
        expect(daScelta('esercizio:5')).toEqual({ origine: 'esercizio', esercizio_origine_id: 5 });
        expect(daScelta('con_il_piano')).toEqual({ origine: 'con_il_piano', esercizio_origine_id: null });
        expect(daScelta('')).toEqual({ origine: null, esercizio_origine_id: null });
        expect(etichettaOrigine({ origine_dichiarata: 'prima_del_primo' }, esercizi)).toBe('origine: prima del primo esercizio');
        expect(etichettaOrigine({ origine_dichiarata: 'esercizio', esercizio_origine_id: null }, esercizi)).toBe('origine: un esercizio che non c\'è più');
    });
});
