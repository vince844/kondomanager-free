// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 462: su un commento in attesa «Approva» è un'icona accanto a «Modifica» ed «Elimina», e sul commento nascosto lo è
 * «Mostra di nuovo»; sotto il testo non resta la riga del pulsante. L'etichetta dello stato («In attesa», «Nascosto») sta dopo la
 * data, a 12 px.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), delete: vi.fn() },
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) => `/${nome}/${JSON.stringify(parametri ?? null)}`;

const Commento = (await import('./Commento.vue')).default;

function monta(stato: string, azioni: Record<string, boolean>) {
    return mount(Commento, {
        props: {
            commento: {
                id: 42,
                corpo: 'Un commento',
                stato,
                autore: { nome: 'Paolo Neri' },
                created_at: '2026-11-10T09:00:00.000Z',
                azioni: { modifica: true, elimina: true, approva: false, nascondi: false, mostra: false, ...azioni },
            },
            canModerate: true,
            currentUserId: 1,
            porta: 'admin',
        } as never,
    });
}

/** Vero se `prima` sta nel documento prima di `dopo`. */
function precede(prima: Element, dopo: Element): boolean {
    return Boolean(prima.compareDocumentPosition(dopo) & Node.DOCUMENT_POSITION_FOLLOWING);
}

describe('Commento — «Approva» e «Mostra di nuovo» come icone, lo stato dopo la data (decisione 462)', () => {
    beforeEach(() => vi.clearAllMocks());

    test('in attesa: «Approva» è un\'icona nel gruppo delle azioni, la prima, con il suo nome per chi non vede', async () => {
        const w = monta('in_attesa', { approva: true });

        const gruppo = w.get('[data-azioni-commento]');
        const approva = gruppo.get('[data-azione="approva"]');
        expect(approva.attributes('aria-label')).toBe('commenti.approva');
        expect(approva.text()).toBe('');
        expect(gruppo.findAll('button')[0].attributes('data-azione')).toBe('approva');

        await approva.trigger('click');
        expect(vi.mocked(router.post)).toHaveBeenCalledWith('/admin.commenti.approva/{"commento":42}', {}, expect.objectContaining({ preserveScroll: true }));
    });

    test('in attesa: sotto il testo non c\'è più un pulsante «Approva»', () => {
        const w = monta('in_attesa', { approva: true });

        expect(w.findAll('[data-azione="approva"]')).toHaveLength(1);
        expect(w.text()).not.toContain('commenti.approva');
    });

    test('in attesa: «In attesa» sta dopo la data, a 12 px', () => {
        const w = monta('in_attesa', { approva: true });

        const stato = w.get('[data-stato-commento]');
        expect(precede(w.get('[data-data-commento]').element, stato.element)).toBe(true);
        expect(stato.classes()).toContain('text-xs');
        expect(stato.html()).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
    });

    test('in attesa, visto dal suo autore che non approva: «In attesa» dopo la data, a 12 px', () => {
        const w = monta('in_attesa', { approva: false });

        const stato = w.get('[data-stato-commento]');
        expect(precede(w.get('[data-data-commento]').element, stato.element)).toBe(true);
        expect(stato.classes()).toContain('text-xs');
    });

    test('nascosto: «Mostra di nuovo» è un\'icona nel gruppo delle azioni, e «Nascosto» sta dopo la data', async () => {
        const w = monta('nascosto', { mostra: true });

        const mostra = w.get('[data-azioni-commento] [data-azione="mostra-di-nuovo"]');
        expect(mostra.attributes('aria-label')).toBe('commenti.mostra_di_nuovo');
        expect(mostra.text()).toBe('');
        expect(precede(w.get('[data-data-commento]').element, w.get('[data-stato-commento]').element)).toBe(true);

        await mostra.trigger('click');
        expect(vi.mocked(router.post)).toHaveBeenCalledWith('/admin.commenti.mostra/{"commento":42}', {}, expect.objectContaining({ preserveScroll: true }));
    });
});

// Il nome, la data e lo stato vanno a capo fra loro; le icone stanno fuori da quella parte, ferme a destra: prima, con una riga del nome
// lunga («Modificato il …»), le icone invisibili andavano a capo e lasciavano una riga vuota sopra il testo (Fase 2 della 1.12.0-beta.11).
describe('Commento — le icone non vanno a capo con il nome', () => {
    for (const [stato, azioni] of [['pubblicato', {}], ['in_attesa', { approva: true }], ['in_attesa', { approva: false }], ['nascosto', { mostra: true }]] as const) {
        test(`${stato}${'approva' in azioni ? (azioni.approva ? ', chi approva' : ', l\'autore') : ''}: il gruppo delle icone è accanto alla parte che va a capo, non dentro`, () => {
            const w = monta(stato, azioni);
            const intestazione = w.get('[data-intestazione-commento]');
            const gruppo = w.get('[data-azioni-commento]');

            expect(gruppo.element.parentElement).toBe(intestazione.element);
            expect(gruppo.classes()).toContain('shrink-0');
            const parteCheVaACapo = intestazione.element.querySelector('.flex-wrap');
            expect(parteCheVaACapo).not.toBeNull();
            expect(parteCheVaACapo!.contains(gruppo.element)).toBe(false);
            expect(parteCheVaACapo!.contains(w.get('[data-data-commento]').element)).toBe(true);
        });
    }
});

// Decisione 465: per chi modera il commento in attesa ha lo sfondo ambra chiaro con il bordo tratteggiato, il colore dell'etichetta «In
// attesa» e quello che l'autore vede già; il pubblicato resta azzurro, il nascosto grigio.
describe('Commento — lo sfondo del commento in attesa (decisione 465)', () => {
    test('in attesa, per chi approva: ambra chiaro tratteggiato', () => {
        const corpo = monta('in_attesa', { approva: true }).get('[data-corpo-commento]').classes();

        expect(corpo).toContain('bg-amber-50');
        expect(corpo).toContain('border-dashed');
        expect(corpo).not.toContain('bg-sky-50');
    });

    test('pubblicato: azzurro come prima', () => {
        const corpo = monta('pubblicato', {}).get('[data-corpo-commento]').classes();

        expect(corpo).toContain('bg-sky-50');
        expect(corpo).not.toContain('bg-amber-50');
    });
});

// Decisione 471: il commento è una nuvoletta, con la punta in alto verso il nome di chi scrive, nel colore del suo stato.
describe('Commento — la nuvoletta con la punta verso il nome (decisione 471)', () => {
    function punta(stato: string, azioni: Record<string, boolean>) {
        const w = monta(stato, azioni);
        const codina = w.get('[data-codina]');
        return { codina, interna: codina.get('span').classes() };
    }

    test('pubblicato: la punta azzurra, dentro una cornice che ne mostra solo la metà alta', () => {
        const { codina, interna } = punta('pubblicato', {});

        expect(codina.classes()).toEqual(expect.arrayContaining(['absolute', 'overflow-hidden']));
        expect(codina.attributes('aria-hidden')).toBe('true');
        expect(interna).toEqual(expect.arrayContaining(['rotate-45', 'border-t', 'border-l', 'bg-sky-50']));
    });

    test('in attesa, per chi approva: la punta ambra tratteggiata', () => {
        expect(punta('in_attesa', { approva: true }).interna).toEqual(expect.arrayContaining(['bg-amber-50', 'border-dashed']));
    });

    test('in attesa, per il suo autore: la punta ambra tratteggiata', () => {
        expect(punta('in_attesa', { approva: false }).interna).toEqual(expect.arrayContaining(['bg-amber-50', 'border-dashed']));
    });

    test('nascosto, per chi lo può rimettere: la punta grigia tratteggiata', () => {
        expect(punta('nascosto', { mostra: true }).interna).toEqual(expect.arrayContaining(['bg-slate-50', 'border-dashed']));
    });
});

// Decisione 472: la modifica si dice corta, «· modificato», sulla riga del nome; la data intera sta nel suggerimento (e per chi non vede).
describe('Commento — «· modificato» corto, la data nel suggerimento (decisione 472)', () => {
    test('un commento modificato dice «modificato» e porta la data nel nome accessibile', () => {
        const w = mount(Commento, {
            props: {
                commento: {
                    id: 42, corpo: 'Un commento', stato: 'pubblicato', autore: { nome: 'Paolo Neri' },
                    created_at: '2026-11-10T09:00:00.000Z', modificato: true, modificato_at: '2026-11-17T22:15:00.000Z',
                    azioni: { modifica: false, elimina: false, approva: false, nascondi: false, mostra: false },
                },
                canModerate: true, porta: 'admin',
            } as never,
        });
        const modificato = w.get('[data-modificato]');

        expect(modificato.text()).toContain('commenti.modificato_breve');
        expect(modificato.text()).not.toContain('2026');
        expect(modificato.attributes('aria-label')).toBe('commenti.modificato');
        // La data e «· modificato» stanno in un gruppo che non va a capo: se la riga è stretta vanno a capo insieme.
        const gruppo = modificato.element.parentElement!;
        expect(gruppo.contains(w.get('[data-data-commento]').element)).toBe(true);
        expect(gruppo.className).toMatch(/\bwhitespace-nowrap\b/);
    });
});

