// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La card delle persone della sala (decisione 664 (1) e (2), rilievi C48 e C49):
 *
 * - **prima di «Apri l'assemblea»** è l'elenco dei convocati: «Convocati (26 teste)», «Unità», «Millesimi», «Convocato con» (il mezzo che il server aggiunge
 *   alla lettura della sala), niente «Assente» né «Mostra solo chi è o è stato in sala», nessun gesto; in fondo «Le presenze si segnano dopo «Apri
 *   l'assemblea».»;
 * - **aperta** ogni persona sta su una riga: lo stato con l'ora («Presente dalle 18:35», «Per delega a Bianchi Giorgio», «Non in sala»), poi i gesti: «Entra»
 *   piccolo e scuro e il «⋯» con «In collegamento» e «Delega» per chi non è in sala, «Esce» piccolo e chiaro per chi c'è; le unità non vanno a capo.
 *
 * Il menu «⋯» è quello del programma: nella prova si apre da sé (i suoi pezzi passano il contenuto), come nella prova della pagina.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { PERSONE, salaAperta, salaDaAprire } from './salaDiProva';
import PresenzeDellaSala from './PresenzeDellaSala.vue';
import type { SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return { default: dc({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => hh('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const passa = { template: '<div><slot /></div>' };
const stubs = {
    DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: { template: '<div data-menu-aperto><slot /></div>' },
    DropdownMenuItem: { emits: ['select'], template: '<button type="button" v-bind="$attrs" @click="$emit(\'select\')"><slot /></button>' },
};

const segnati: { tipo: string; dati: Record<string, unknown> }[] = [];
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; segnati.length = 0; document.body.innerHTML = ''; });

function monta(sala: SalaDellAssemblea) {
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => ({ ...sala, in_coda: [] })) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async (tipo: string, dati: Record<string, unknown>) => { segnati.push({ tipo, dati }); }, adesso: () => new Date('2026-12-15T17:47:00Z'),
                puoGestire: computed(() => true), dalFoglio: ref(false), oraDelFoglio: ref('18:47'), oraDelGesto: () => '18:47',
                nomeDi: (id: number | null) => (id === null ? '' : PERSONE[id] ?? `Condomino ${id}`), scriviLaDiscussione: () => {},
            } as never);
            return () => h(PresenzeDellaSala as never, { presenze: sala.presenze.map((p) => ({ ...p, inCoda: false })), presenti: sala.barra?.presenti ?? [] });
        },
    });
    w = mount(Contenitore, { attachTo: document.body, global: { stubs } });
    return w;
}
const testo = (el: Element | null | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
const riga = (chiave: string) => document.body.querySelector(`[data-testid="presenze-tabella"] [data-testa="${chiave}"]`) as HTMLElement;

describe('prima di «Apri l\'assemblea»: l\'elenco dei convocati (C48)', () => {
    function daAprire() {
        const s = salaDaAprire();
        s.teste = s.teste.map((t) => ({ ...t, convocato_con: t.chiave === 'p-6-7' ? 'raccomandata, una a testa' : t.chiave === 'p-10' ? 'PEC' : 'raccomandata' }));
        return s;
    }

    test('le colonne dei convocati, il mezzo, tutte le teste; niente «Assente», niente «Mostra solo…», nessun gesto; la riga grigia in fondo', async () => {
        monta(daAprire());
        await flushPromises();
        const intestazioni = Array.from(document.body.querySelectorAll('[data-testid="presenze-tabella"] th')).map((th) => testo(th));
        expect(intestazioni).toEqual(['Convocati (26 teste)', 'Unità', 'Millesimi', 'Convocato con']);
        expect(document.body.querySelectorAll('[data-testid="presenze-tabella"] [data-testa]')).toHaveLength(26);
        expect(testo(riga('p-6-7'))).toContain('raccomandata, una a testa');
        expect(testo(riga('p-10'))).toContain('PEC');
        expect(document.body.textContent).not.toContain('Assente');
        expect(document.body.querySelector('[data-testid="mostra-tutti"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="entra"]')).toBeNull();
        expect(testo(document.body.querySelector('[data-testid="presenze-dopo-l-apertura"]'))).toBe('Le presenze si segnano dopo «Apri l\'assemblea».');
        // Al telefono la stessa cosa, una scheda per testa.
        expect(testo(document.body.querySelector('[data-testid="presenze-card"] [data-testa="p-6-7"]'))).toContain('raccomandata, una a testa');
    });
});

describe('a sala aperta ogni persona su una riga (C49)', () => {
    test('lo stato con l\'ora; per chi non è in sala «Entra» piccolo e scuro e il «⋯» con «In collegamento» e «Delega»; per chi c\'è «Esce» piccolo e chiaro', async () => {
        monta(salaAperta());
        await flushPromises();
        // Rossi è entrato alle 18:35 (ora di Roma): «Presente dalle 18:35», e «Esce» piccolo.
        expect(testo(riga('p-1').querySelector('[data-testid="stato-della-persona"]'))).toBe('Presente dalle 18:35');
        const esce = riga('p-1').querySelector('[data-testid="esce"]') as HTMLElement;
        expect(esce.classList.contains('h-7')).toBe(true);
        expect(esce.classList.contains('bg-slate-900')).toBe(false);
        expect(riga('p-1').querySelector('[data-testid="entra"]')).toBeNull();
        // Costa ha delegato Bianchi, che è in sala.
        expect(testo(riga('p-3').querySelector('[data-testid="stato-della-persona"]'))).toBe('Per delega a Bianchi Giorgio');

        (document.body.querySelector('[data-testid="mostra-tutti"]') as HTMLElement).click();
        await flushPromises();
        expect(testo(riga('p-15').querySelector('[data-testid="stato-della-persona"]'))).toBe('Non in sala');
        expect(document.body.textContent).not.toContain('Assente');
        const entra = riga('p-15').querySelector('[data-testid="entra"]') as HTMLElement;
        expect(entra.classList.contains('h-7')).toBe(true);
        expect(entra.classList.contains('bg-slate-900')).toBe(true);
        // «In collegamento» e «Delega» stanno nel «⋯».
        const menu = riga('p-15').querySelector('[data-testid="altri-gesti"]') as HTMLElement;
        expect(menu).not.toBeNull();
        expect(menu.querySelector('[data-testid="in-collegamento"]')).not.toBeNull();
        expect(menu.querySelector('[data-testid="delega"]')).not.toBeNull();
        // Niente va a capo: l'unità e i gesti stanno su una riga.
        expect(riga('p-15').querySelector('[data-testid="unita-della-persona"]')!.className).toContain('whitespace-nowrap');
        expect(riga('p-15').querySelector('[data-testid="gesti-della-persona"]')!.className).toContain('flex-nowrap');

        entra.click();
        await flushPromises();
        expect(segnati.at(-1)).toMatchObject({ tipo: 'entra', dati: { persona: { anagrafica_id: 15 }, come: 'in_sala' } });
    });

    test('al telefono una scheda per persona, con i gesti in fondo', async () => {
        monta(salaAperta());
        await flushPromises();
        const scheda = document.body.querySelector('[data-testid="presenze-card"] [data-testa="p-1"]') as HTMLElement;
        expect(testo(scheda)).toContain('Presente dalle 18:35');
        const gesti = scheda.querySelector('[data-testid="gesti-della-persona"]') as HTMLElement;
        expect(gesti).toBe(scheda.lastElementChild);
        expect(gesti.querySelector('[data-testid="esce"]')).not.toBeNull();
    });
});
