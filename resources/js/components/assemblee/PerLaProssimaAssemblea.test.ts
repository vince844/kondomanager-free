// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La card «Per la prossima assemblea» della dashboard del condominio (§3 P9 del progetto della 1.12.0-beta.13; scena 3 della bozza approvata con la
 * decisione 501): i punti con il badge dell'origine (`rounded-md`) e la riga sotto, il totale, «e altri N» oltre gli otto, «Prepara l'assemblea» o
 * l'assemblea già in preparazione; senza «Gestisce le assemblee» niente pulsante.
 *
 * Che cosa non copre: quando la card c'è (la pagina la mostra solo con dei punti: lo prova `PagineDelleAssembleeTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const permessi = { gestisce: true };
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => permessi.gestisce }) }));
const visite = { post: vi.fn(), reload: vi.fn() };
vi.mock('@inertiajs/vue3', async (originale) => ({
    ...(await originale<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => visite.post(...a), reload: (...a: unknown[]) => visite.reload(...a) },
}));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Card = (await import('./PerLaProssimaAssemblea.vue')).default;
const punti = [
    { testo: 'Ratifica della spesa oltre il preventivo di € 1.800,00 (fattura n. 45)', origine: 'Da te', gruppo: 'da_te', chiave_fonte: 'fattura:45', memo_id: 3, frase: 'Memo del 18/11 · fattura n. 45' },
    { testo: 'Approvazione del rendiconto 2025 e dello stato di riparto', origine: 'Di serie', gruppo: 'di_serie', chiave_fonte: 'rendiconto:7', memo_id: null, frase: null },
];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; permessi.gestisce = true; });
const monta = (dati: Record<string, unknown>) => {
    w = mount(Card, { props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti, totale: 2, altre: 0, in_preparazione: null, ...dati } } });
};

describe('la card «Per la prossima assemblea»', () => {
    test('i punti con l\'origine e la riga sotto, il totale, «Prepara l\'assemblea»', () => {
        monta({});
        const t = w!.text().replace(/\s+/g, ' ');
        expect(t).toContain('I punti che aspettano un\'assemblea di Via Roma 12.');
        expect(t).toContain('Da te');
        expect(t).toContain('Memo del 18/11 · fattura n. 45');
        expect(w!.get('[data-testid="totale-punti"]').text()).toBe('2');
        expect(w!.findAll('[data-testid="punto-card"]')).toHaveLength(2);
        expect(w!.find('[data-testid="prepara-assemblea"]').exists()).toBe(true);
        expect(w!.html()).not.toContain('rounded-full');
    });

    test('oltre gli otto «e altri N»; con un\'assemblea in preparazione, il suo collegamento; senza il permesso niente pulsante', () => {
        monta({ totale: 11, altre: 3, in_preparazione: { id: 9, nome: 'Ordinaria del 15/12/2026' } });
        expect(w!.get('[data-testid="altre-punti"]').text()).toContain('e altri 3');
        expect(w!.get('[data-testid="apri-in-preparazione"]').text()).toBe('Apri l\'ordinaria del 15/12/2026');
        w!.unmount();
        permessi.gestisce = false;
        monta({});
        expect(w!.find('[data-testid="prepara-assemblea"]').exists()).toBe(false);
    });
});

const memoLibero = { id: 4, testo: 'Il portone', nota: null, assemblea_id: null, ricordami_dal: null, a_chi: 'me', assegnato_a: null, origine_tipo: null, origine_id: null };
const conIMenu = [
    { ...punti[0], apri: { voce: 'Apri la fattura n. 45', url: '/admin/gestionale/3/fatture/45' }, memo: { ...memoLibero, id: 3, origine_tipo: 'fattura_passiva', origine_id: 45 } },
    { ...punti[1], apri: { voce: 'Apri il rendiconto', url: '/admin/gestionale/3/esercizi/7/rendiconto' }, memo: null },
    { testo: 'Il portone', origine: 'Da te', gruppo: 'da_te', chiave_fonte: 'memo:4', memo_id: 4, frase: null, apri: null, memo: memoLibero },
    { testo: 'Un punto della nota', origine: 'Dalla nota sintetica', gruppo: 'dalla_nota', chiave_fonte: 'nota:7:riga:x', memo_id: null, frase: null, apri: null, memo: null },
];
// I menu montati aperti: le voci di ogni punto come le legge chi guarda.
const contenitore = { template: '<div><slot /></div>' };
const voce = { emits: ['select'], template: '<div role="menuitem" @click="$emit(\'select\', $event)"><slot /></div>' };
const MENU = { DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: contenitore, DropdownMenuItem: voce };
/** Le voci del menu ⋯ del punto `indice` fra quelli che hanno il menu. */
async function vociDelMenu(indice: number): Promise<string[]> {
    await flushPromises();
    const menu = w!.findAll('[data-testid="menu-del-punto"]')[indice];
    return menu.findAll('[role="menuitem"]').map((v) => v.text().replace(/\s+/g, ' ').trim());
}

describe('il menu ⋯ di ogni punto e «Aggiungi un punto» (decisione 574)', () => {
    test('il memo: «Apri …» la sua scheda, «Modifica», «Non serve più»; il punto del programma: «Apri …»; senza una pagina, nessun menu', async () => {
        w = mount(Card, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti: conIMenu, totale: 4, altre: 0, in_preparazione: null } },
            global: { stubs: { FinestraDelMemo: true, ...MENU } } });
        expect(w.findAll('[data-testid="menu-punto"]')).toHaveLength(3);
        expect(await vociDelMenu(0)).toEqual(['Apri la fattura n. 45', 'Modifica', 'Non serve più']);
        expect(await vociDelMenu(1)).toEqual(['Apri il rendiconto']);
        expect(await vociDelMenu(2)).toEqual(['Modifica', 'Non serve più']);
    });

    test('«Aggiungi un punto» accanto a «Prepara l\'assemblea» apre la finestra del memo; senza il permesso, il menu ha solo «Apri»', async () => {
        w = mount(Card, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti: conIMenu, totale: 4, altre: 0, in_preparazione: null } },
            global: { stubs: { ...MENU, FinestraDelMemo: { props: ['open', 'memo'], template: '<div data-testid="finestra-stub" :data-aperta="open" :data-memo="memo ? memo.id : 0" />' } } } });
        const aggiungi = w.get('[data-testid="aggiungi-un-punto"]');
        expect(aggiungi.text()).toBe('Aggiungi un punto');
        await aggiungi.trigger('click');
        expect(w.get('[data-testid="finestra-stub"]').attributes('data-aperta')).toBe('true');
        expect(w.get('[data-testid="finestra-stub"]').attributes('data-memo')).toBe('0');
        w.unmount();
        permessi.gestisce = false;
        w = mount(Card, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti: conIMenu, totale: 4, altre: 0, in_preparazione: null } },
            global: { stubs: { FinestraDelMemo: true, ...MENU } } });
        expect(w.find('[data-testid="aggiungi-un-punto"]').exists()).toBe(false);
        expect(w.findAll('[data-testid="menu-punto"]')).toHaveLength(2);
        expect(await vociDelMenu(0)).toEqual(['Apri la fattura n. 45']);
    });
});

describe('la nota sintetica che può aggiungere punti', () => {
    test('con una nota del rendiconto da approvare, sotto l\'elenco la riga che rimanda a «Prepara l\'assemblea»; senza, niente', () => {
        w = mount(Card, { props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti, totale: 2, altre: 0, in_preparazione: null, con_la_nota: true } },
            global: { stubs: { FinestraDelMemo: true } } });
        expect(w.get('[data-testid="punti-della-nota"]').text().replace(/\s+/g, ' '))
            .toBe('La nota sintetica può aggiungere altri punti: li vedi in «Prepara l\'assemblea».');
        w.unmount();
        w = mount(Card, { props: { condominioId: 3, condominioNome: 'Via Roma 12', dati: { punti, totale: 2, altre: 0, in_preparazione: null, con_la_nota: false } },
            global: { stubs: { FinestraDelMemo: true } } });
        expect(w.find('[data-testid="punti-della-nota"]').exists()).toBe(false);
    });
});
