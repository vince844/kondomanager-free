// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra del tasso legale di un anno che il programma non ha ancora: l'anno scelto fra quelli che si possono scrivere, il tasso, la fonte (il
 * decreto e la Gazzetta Ufficiale); il pulsante spento dice che cosa manca; un anno già scritto a mano si corregge, e la finestra lo dice. Gli errori
 * del server arrivano nel loro gruppo, separati da quelli della regola. L'anno si sceglie con il menu del programma (`v-select`), non con quello del
 * browser: si apre dentro la finestra.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
const pagina: { props: Record<string, unknown> } = { props: {} };
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a: unknown[]) => post(...a) }, usePage: () => pagina }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown>) => `/${nome}/${p.condominio}`;

const Finestra = (await import('./TassoLegaleAManoDialog.vue')).default;

const FONTE = 'D.M. 10/12/2026, G.U. n. 290 del 14/12/2026';
const scritto = { anno: 2027, tasso: '2,25 %', tasso_annuo: '2,25', fonte: FONTE, scritto_da: 'Mario Rossi', scritto_il: '2026-12-16' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); pagina.props = {}; document.body.innerHTML = ''; });
const corpo = () => document.body;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const campo = (id: string) => corpo().querySelector<HTMLInputElement>(`[data-testid="${id}"]`)!;
const scrivi = async (id: string, valore: string) => {
    const c = campo(id);
    c.value = valore;
    c.dispatchEvent(new Event('input'));
    await flushPromises();
};
/** Il menu dell'anno: la voce scelta, e aperto le voci, dentro la finestra. */
const menuAnno = () => corpo().querySelector('[role="dialog"] [data-testid="anno-tasso"] .v-select') as HTMLElement;
const annoScelto = () => pulito(menuAnno().querySelector('.vs__selected')?.textContent);
async function apriAnno() {
    menuAnno().querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
}
const vociAnno = () => [...menuAnno().querySelectorAll('.vs__dropdown-option')].map((o) => pulito(o.textContent));
const monta = (anno: number, anni: number[], aMano: unknown[] = []) => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, anno, anni, aMano } });

    return w;
};

describe('il tasso legale scritto a mano', () => {
    test('l\'anno scelto; il pulsante dice che cosa manca; registra l\'anno, il tasso e la fonte nel loro gruppo di errori', async () => {
        monta(2027, [2027, 2028]);
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="titolo-tasso"]')?.textContent)).toBe('Il tasso legale di un anno');
        expect(pulito(corpo().querySelector('[data-testid="spiegazione-tasso"]')?.textContent))
            .toBe('Vale per tutti i condomini, finché il programma non ha il tasso di quell\'anno. Se poi il programma ne ha uno diverso, vale quello del programma.');
        expect(corpo().querySelector('[role="dialog"] select')).toBeNull();
        expect(annoScelto()).toBe('2027');
        await apriAnno();
        expect(vociAnno()).toEqual(['2027', '2028']);
        const pulsante = () => corpo().querySelector<HTMLButtonElement>('[data-testid="registra-tasso"]')!;
        const manca = () => corpo().querySelector('[data-testid="cosa-manca-tasso"]');
        expect(pulsante().disabled).toBe(true);
        expect(pulito(manca()?.textContent)).toBe('Scrivi il tasso');
        expect(campo('tasso-legale').getAttribute('placeholder')).toBeNull();
        expect(pulito(corpo().querySelector('[data-testid="spiegazione-fonte"]')?.textContent))
            .toBe('Il decreto del Ministero dell\'economia e la Gazzetta Ufficiale che lo pubblica, con le loro date.');

        await scrivi('tasso-legale', '2,25');
        expect(pulito(manca()?.textContent)).toBe('Scrivi la fonte');
        await scrivi('fonte-legale', FONTE);
        expect(pulsante().disabled).toBe(false);
        expect(manca()).toBeNull();
        pulsante().click();
        await flushPromises();
        expect(post.mock.calls[0][0]).toBe('/gestionale.recupero-crediti.tasso-legale.store/3');
        expect(post.mock.calls[0][1]).toEqual({ anno: 2027, tasso: '2,25', fonte: FONTE });
        expect(post.mock.calls[0][2]).toMatchObject({ errorBag: 'tassoLegale', preserveScroll: true });
    });

    test('un anno già scritto a mano: la finestra lo dice e parte dal tasso e dalla fonte di prima', async () => {
        monta(2027, [2027], [scritto]);
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="gia-scritto"]')?.textContent))
            .toBe('Il 2027 ha già 2,25 % scritto a mano da Mario Rossi il 16/12/2026: registrando lo correggi.');
        expect(campo('tasso-legale').value).toBe('2,25');
        expect(campo('fonte-legale').value).toBe(FONTE);
    });

    test('il menu dell\'anno è quello del programma: l\'etichetta lo nomina, si apre dentro la finestra, e scegliere un anno già scritto lo dice', async () => {
        monta(2028, [2027, 2028], [scritto]);
        await flushPromises();
        expect(menuAnno()).not.toBeNull();
        expect(corpo().querySelector('label[for="anno-tasso"]')?.textContent?.trim()).toBe('Anno');
        expect(menuAnno().querySelector('input#anno-tasso')).not.toBeNull();
        expect(annoScelto()).toBe('2028');
        expect(corpo().querySelector('[data-testid="gia-scritto"]')).toBeNull();

        await apriAnno();
        const menu = menuAnno().querySelector('.vs__dropdown-menu');
        expect(menu).not.toBeNull();
        expect(corpo().querySelector('[role="dialog"]')!.contains(menu)).toBe(true);
        ([...menuAnno().querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === '2027') as HTMLElement).click();
        await flushPromises();
        expect(annoScelto()).toBe('2027');
        expect(pulito(corpo().querySelector('[data-testid="gia-scritto"]')?.textContent))
            .toBe('Il 2027 ha già 2,25 % scritto a mano da Mario Rossi il 16/12/2026: registrando lo correggi.');
        expect(campo('tasso-legale').value).toBe('2,25');
    });

    test('la finestra si apre con il menu dell\'anno chiuso e il cursore nel tasso, il campo da scrivere', async () => {
        monta(2027, [2027, 2028]);
        await flushPromises();
        await new Promise((r) => setTimeout(r, 0));
        await flushPromises();
        expect(menuAnno().classList.contains('vs--open')).toBe(false);
        expect(menuAnno().querySelector('.vs__dropdown-menu')).toBeNull();
        expect(document.activeElement).toBe(campo('tasso-legale'));
    });

    test('gli errori del server, nel loro gruppo', async () => {
        pagina.props = { errors: { tassoLegale: { tasso: 'Scrivi il tasso come un numero, per esempio 2 o 2,5: più di zero, al più 100, con al più quattro decimali.' } } };
        monta(2027, [2027]);
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="errori-tasso"]')?.textContent))
            .toBe('Scrivi il tasso come un numero, per esempio 2 o 2,5: più di zero, al più 100, con al più quattro decimali.');
    });
});
