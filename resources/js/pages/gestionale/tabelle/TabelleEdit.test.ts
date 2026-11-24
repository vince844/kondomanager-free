// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tabella di un gruppo nella pagina della tabella (Coda 734, scena 1 della bozza approvata): nel riquadro «Per le assemblee», sotto l'interruttore della
 * tabella di proprietà generale, «È la tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue unità», salvato quando lo cambi (la rotta del
 * segno, rileggendo solo il riquadro); la riga «Adesso: «Box» ha 8 unità su 26…» spento e acceso; il perché in un cassetto, a schema. Il riquadro c'è solo
 * per chi gestisce le assemblee. Nell'elenco delle tabelle il badge «Di un gruppo» accanto al nome.
 *
 * Che cosa non copre: il segno salvato e la riga del registro (`CondominioParzialeTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: inertia,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore'] } } } }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const TabelleEdit = (await import('./TabelleEdit.vue')).default;
const TabelleList = (await import('./TabelleList.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa, PageHeaderGuide: true,
    Switch: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
        template: '<button type="button" role="switch" :aria-checked="String(modelValue)" v-bind="$attrs" @click="$emit(\'update:modelValue\', !modelValue)" />' },
};
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); });
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const box = { id: 5, condominio_id: 3, nome: 'Box', tipo: 'standard', quota: 'millesimi', numero_decimali: 2, descrizione: '', note: '', attiva: true, created_at: '' };

async function monta(gruppo: { questa: boolean; unita: number; del_condominio: number } | undefined, siSceglie = true) {
    w = mount(TabelleEdit as never, { attachTo: document.body, global: { stubs, mocks: { route: (globalThis as unknown as { route: unknown }).route } },
        props: { condominio: { id: 3, nome: 'Via Roma 12' }, tabella: box, proprieta: { questa: false, attuale: 'Proprietà', si_sceglie: siSceglie }, gruppo } as never });
    await flushPromises();
}

describe('la tabella di un gruppo nella pagina della tabella', () => {
    test('l\'interruttore sotto quello della proprietà generale, con la riga «Adesso» spento: le altre unità contano come teste a zero millesimi', async () => {
        await monta({ questa: false, unita: 8, del_condominio: 26 });
        expect(testo('[data-testid="tabella-di-un-gruppo"]')).toContain('È la tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue unità');
        expect(testo('[data-testid="adesso-gruppo"]')).toBe('Adesso: «Box» ha 8 unità su 26. Senza il segno, su un punto votato con questa tabella le altre 18 contano '
            + 'come teste a zero millesimi.');
        expect(document.body.querySelector('#di-un-gruppo')!.getAttribute('aria-checked')).toBe('false');
    });

    test('acceso: votano solo i loro condòmini, con l\'articolo; il cambio va alla rotta del segno e rilegge solo il riquadro', async () => {
        await monta({ questa: false, unita: 8, del_condominio: 26 });
        (document.body.querySelector('#di-un-gruppo') as HTMLElement).click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, dati, opzioni] = inertia.post.mock.calls[0];
        expect(url).toBe('/admin.gestionale.tabella-di-un-gruppo');
        expect(dati).toEqual({ tabella_id: 5, di_un_gruppo: true });
        expect(opzioni.only).toEqual(['gruppo', 'flash']);
        expect(testo('[data-testid="adesso-gruppo"]')).toBe('Adesso: «Box» ha 8 unità su 26. Su un punto votato con questa tabella votano solo i loro condòmini, e '
            + 'teste, millesimi e costituzione si contano fra loro (art. 1123 c. 3 c.c.).');
    });

    test('il perché in un cassetto, a schema: la tabella d\'uso con degli zeri non è di un gruppo; il programma non lo decide da solo', async () => {
        await monta({ questa: true, unita: 8, del_condominio: 26 });
        expect(testo('[data-testid="cassetto-gruppo"] summary')).toBe('Quando è la tabella di un gruppo');
        const voci = [...document.body.querySelectorAll('[data-testid="cassetto-gruppo"] li')].map((li) => (li.textContent ?? '').replace(/\s+/g, ' ').trim());
        expect(voci).toEqual([
            'La cosa serve solo alcune unità: i box, la scala B, l\'ascensore di una palazzina.',
            'Chi non ha una quota nella tabella non ne è proprietario: sul punto non vota e non conta.',
            'Una tabella d\'uso con degli zeri (il piano terra che non usa l\'ascensore ma ne è proprietario) non è di un gruppo: lascia spento.',
            'Il programma non lo decide da solo: lo sai tu.',
        ]);
    });

    test('il segno che non si toglie con l\'assemblea dei box aperta: l\'interruttore torna acceso e la pagina dice perché; al cambio dopo, la frase sparisce', async () => {
        const perche = 'La tabella «Box» è il gruppo dell\'assemblea del 15/12/2026: togli il segno quando è chiusa.';
        inertia.post.mockImplementationOnce((_u: string, _d: unknown, o: { onError?: (e: Record<string, string>) => void; onFinish?: () => void }) => {
            o.onError?.({ di_un_gruppo: perche });
            o.onFinish?.();
        });
        await monta({ questa: true, unita: 8, del_condominio: 26 });
        (document.body.querySelector('#di-un-gruppo') as HTMLElement).click();
        await flushPromises();
        expect(document.body.querySelector('#di-un-gruppo')!.getAttribute('aria-checked')).toBe('true');
        expect(testo('[data-testid="errore-gruppo"]')).toBe(perche);

        inertia.post.mockImplementationOnce((_u: string, _d: unknown, o: { onSuccess?: () => void; onFinish?: () => void }) => {
            o.onSuccess?.();
            o.onFinish?.();
        });
        (document.body.querySelector('#di-un-gruppo') as HTMLElement).click();
        await flushPromises();
        expect(testo('[data-testid="errore-gruppo"]')).toBe('');
    });

    test('il riquadro c\'è solo per chi gestisce le assemblee', async () => {
        await monta({ questa: false, unita: 8, del_condominio: 26 }, false);
        expect(document.body.querySelector('[data-testid="tabella-di-un-gruppo"]')).toBeNull();
    });

    test('nell\'elenco delle tabelle il badge «Di un gruppo» accanto al nome della tabella segnata, e solo lì', async () => {
        w = mount(TabelleList as never, { attachTo: document.body, global: { stubs: { GestionaleLayout: passa, PageHeaderGuide: true, Alert: true, DataTableToolbar: true } },
            props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 2 },
                tabelle: [box, { ...box, id: 6, nome: 'Proprietà' }], gruppi: [5] } as never });
        await flushPromises();
        const badge = [...document.body.querySelectorAll('[data-testid="badge-di-un-gruppo"]')];
        expect(badge.map((b) => (b.textContent ?? '').trim())).toEqual(['Di un gruppo']);
        expect(badge[0].className).toContain('rounded-md');
        expect(badge[0].closest('tr')?.textContent).toContain('Box');
    });
});
