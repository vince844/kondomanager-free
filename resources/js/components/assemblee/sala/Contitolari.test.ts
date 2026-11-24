// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I contitolari presenti tutti e due (decisione 664 (6), rilievo C53; art. 67 c. 2 disp. att.: l'unità in comproprietà ha un solo rappresentante):
 *
 * - «Chi entra in sala per l'Int. 5?»: «Chi rappresenta l'unità (vota)» e, per l'altra persona della testa, la spunta «Anche Russo Anna è in sala, senza
 *   voto»; il gesto `entra` la porta in `con`;
 * - la riga della persona dice chi è entrato insieme («con Russo Anna, senza voto»), e la sala in sola lettura lo nomina.
 *
 * Che cosa non copre: le regole del server e il verbale (`SalaApertaTest`); il conto sul posto (`conti.test.ts`, `senzaRete/sala.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { PERSONE, salaAperta, salaChiusa } from './salaDiProva';
import FinestraDellEntrata from './FinestraDellEntrata.vue';
import PresenzeDellaSala from './PresenzeDellaSala.vue';
import SalaInSolaLettura from './SalaInSolaLettura.vue';
import type { SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return {
        default: dc({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => hh('div', { 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: Record<string, unknown>, i: number) => hh('button', {
                    type: 'button', 'data-opzione': i, onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o) }, String(o[p.label ?? 'label']))));
            },
        }),
    };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('«Chi entra in sala per l\'Int. 5?»', () => {
    test('chi rappresenta l\'unità (vota) e la spunta per l\'altra persona della testa; il gesto la porta', async () => {
        const entrate: unknown[][] = [];
        w = mount(FinestraDellEntrata as never, { attachTo: document.body, props: { open: true, come: 'in_sala', serveIlMotivo: false, unita: 'Int. 5',
            persone: [6, 7], candidati: [{ id: 6, etichetta: 'Russo Anna' }, { id: 7, etichetta: 'Russo Marco' }], onEntra: (...a: unknown[]) => entrate.push(a) } });
        await flushPromises();
        expect(testo('[data-testid="finestra-entrata"] h2')).toBe('Chi entra in sala per l\'Int. 5?');
        expect(testo('[data-testid="finestra-entrata"]')).toContain('Chi rappresenta l\'unità (vota)');
        // Sceglie Russo Marco: la spunta è per Russo Anna.
        (document.body.querySelector('[data-testid="chi-entra"] [data-opzione="1"]') as HTMLElement).click();
        await flushPromises();
        expect(testo('[data-testid="anche-6"]')).toBe('Anche Russo Anna è in sala, senza voto');
        expect(document.body.querySelector('[data-testid="anche-7"]')).toBeNull();
        (document.body.querySelector('[data-testid="anche-6"] button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        (document.body.querySelector('[data-testid="conferma-entrata"]') as HTMLElement).click();
        await flushPromises();
        expect(entrate).toEqual([[7, '', [6]]]);
    });

    test('in collegamento la spunta dice «in collegamento», nella forma senza genere (rilievo C59)', async () => {
        w = mount(FinestraDellEntrata as never, { attachTo: document.body, props: { open: true, come: 'in_collegamento', serveIlMotivo: false, unita: 'Int. 5',
            persone: [6, 7], candidati: [{ id: 6, etichetta: 'Russo Anna' }, { id: 7, etichetta: 'Russo Marco' }] } });
        await flushPromises();
        (document.body.querySelector('[data-testid="chi-entra"] [data-opzione="1"]') as HTMLElement).click();
        await flushPromises();
        expect(testo('[data-testid="anche-6"]')).toBe('Anche Russo Anna è in collegamento, senza voto');
    });

    test('niente spunta per chi ha anche un\'unità sua: entra da sé, con la sua testa (revisione del 22/11)', async () => {
        w = mount(FinestraDellEntrata as never, { attachTo: document.body, props: { open: true, come: 'in_sala', serveIlMotivo: false, unita: 'Int. 5',
            persone: [6, 7], conTestaSua: [6], candidati: [{ id: 6, etichetta: 'Russo Anna' }, { id: 7, etichetta: 'Russo Marco' }] } });
        await flushPromises();
        (document.body.querySelector('[data-testid="chi-entra"] [data-opzione="1"]') as HTMLElement).click();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="anche-6"]')).toBeNull();
    });
});

function conLaSala(sala: SalaDellAssemblea, figlio: unknown, props: Record<string, unknown> = {}) {
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => ({ ...sala, in_coda: [] })) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async () => {}, adesso: () => new Date('2026-12-15T17:47:00Z'), puoGestire: computed(() => true), dalFoglio: ref(false),
                oraDelFoglio: ref('18:47'), oraDelGesto: () => '18:47', nomeDi: (id: number | null) => (id === null ? '' : PERSONE[id] ?? ''), scriviLaDiscussione: () => {},
            } as never);
            return () => h(figlio as never, props);
        },
    });
    w = mount(Contenitore, { attachTo: document.body, global: { stubs: { DropdownMenu: true, DropdownMenuTrigger: true, DropdownMenuContent: true, DropdownMenuItem: true } } });
}

describe('la riga e la sala in sola lettura dicono chi è entrato insieme', () => {
    test('a sala aperta: «con Russo Marco, senza voto» sotto la testa dei Russo', async () => {
        const s = salaAperta();
        s.presenze = s.presenze.map((p) => (p.anagrafica_id === 6 ? { ...p, insieme: [{ anagrafica_id: 7, nome: 'Russo Marco' }] } : p));
        conLaSala(s, PresenzeDellaSala, { presenze: s.presenze.map((p) => ({ ...p, inCoda: false })), presenti: s.barra!.presenti });
        await flushPromises();
        expect(testo('[data-testid="presenze-tabella"] [data-testa="p-6-7"]')).toContain('con Russo Marco, senza voto');
        // Chi vota per l'unità con il nome intero: una parola sola dava «rappresenta Verdi» per «Luca Verdi» (e il nome per «Russo Anna»).
        expect(testo('[data-testid="presenze-tabella"] [data-testa="p-6-7"]')).toContain('comproprietari · vota Russo Anna');
    });

    test('a sala chiusa: la riga di chi rappresenta l\'unità nomina il contitolare presente senza voto', async () => {
        const s = salaChiusa();
        s.ultima_seduta!.presenze = s.ultima_seduta!.presenze.map((p) => (p.anagrafica_id === 6 ? { ...p, insieme: [{ anagrafica_id: 7, nome: 'Russo Marco' }] } : p));
        conLaSala(s, SalaInSolaLettura);
        await flushPromises();
        expect(testo('[data-testid="lettura-presenze-tabella"]')).toContain('con Russo Marco, in comproprietà, presente senza voto');
        expect(testo('[data-testid="lettura-presenze-tabella"]')).not.toContain('contitolare');
    });
});
