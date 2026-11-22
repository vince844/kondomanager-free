// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Una delega in sala» (rilievo C47, decisione 663): il menu «A chi» si apre fuori dal corpo della finestra, sopra tutto (`menuFuoriDallaFinestra`), e
 * scegliere una voce non chiude la finestra (`restaSeNelMenu`); poi «Registra la delega» segna il gesto `delega`.
 *
 * Che cosa non copre: il conto del quinto (`sala.test.ts`), le regole del server (`DelegheDellAssembleaTest`).
 */
import { afterEach, describe, expect, test } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { salaAperta } from './salaDiProva';
import FinestraDellaDelega from './FinestraDellaDelega.vue';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

function monta() {
    const sala = { ...salaAperta(), in_coda: [] };
    const segnati: { tipo: string; dati: unknown }[] = [];
    const chiusa = ref(false);
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => sala) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async (tipo: string, dati: unknown) => { segnati.push({ tipo, dati }); }, adesso: () => new Date('2026-12-15T18:40:00Z'),
                puoGestire: computed(() => true), dalFoglio: ref(false), oraDelFoglio: ref('19:40'), oraDelGesto: () => '19:40', nomeDi: () => '',
                scriviLaDiscussione: () => {},
            } as never);
            return () => h(FinestraDellaDelega as never, { open: true, delegante: { id: 4, etichetta: 'Esposito Luca' }, presenti: [1, 2],
                'onUpdate:open': (v: boolean) => { if (!v) chiusa.value = true; } });
        },
    });
    w = mount(Contenitore, { attachTo: document.body });

    return { segnati, chiusa };
}

describe('la finestra della delega', () => {
    test('il menu «A chi» si apre fuori dal corpo della finestra, fisso sopra tutto; scegliere una voce non chiude la finestra, e la delega si registra', async () => {
        const { segnati, chiusa } = monta();
        await flushPromises();
        await new Promise((r) => setTimeout(r, 0));
        const finestra = document.body.querySelector('[data-testid="finestra-delega"]') as HTMLElement;
        const toggle = finestra.querySelector('[data-testid="delegato"] .vs__dropdown-toggle') as HTMLElement;
        toggle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await flushPromises();
        const menu = document.body.querySelector('.vs__dropdown-menu') as HTMLElement;
        expect(menu).not.toBeNull();
        expect(finestra.contains(menu)).toBe(false);
        expect(menu.style.position).toBe('fixed');

        const voce = (Array.from(menu.querySelectorAll('.vs__dropdown-option')) as HTMLElement[]).find((v) => v.textContent?.includes('in sala'))!;
        expect(voce).toBeDefined();
        voce.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
        voce.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        voce.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();
        expect(chiusa.value).toBe(false);
        (document.body.querySelector('[data-testid="registra-delega"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(segnati.map((s) => s.tipo)).toEqual(['delega']);
        expect(segnati[0].dati).toMatchObject({ delegante_anagrafica_id: 4 });
    });
});
