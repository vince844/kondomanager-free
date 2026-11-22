// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La ricerca sopra le presenze della sala: Via Roma 12, seconda convocazione, 26 teste; alle 18:47 entra Colombo Rita (Int. 12), che non è fra le righe
 * di serie (le teste che sono o sono state in sala). «Colombo», «colo», «12» o il nome del suo conduttore lasciano la sua riga sola, nella tabella e nelle
 * card del telefono; la × svuota il campo e torna la lista di prima; una ricerca senza risultati lo dice. **Senza rete**: la ricerca filtra ciò che la
 * pagina ha già, non chiede niente al server (nessun `fetch`, nessuna richiesta, nessun gesto della sala).
 *
 * Che cosa non copre: la regola delle parole (`lib/assemblee/sala/cerca.test.ts`); i gesti «Entra» ed «Esce» (`pages/gestionale/assemblee/Sala.test.ts`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { PERSONE, salaAperta } from './salaDiProva';
import PresenzeDellaSala from './PresenzeDellaSala.vue';
import type { SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return { default: dc({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => hh('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const segnati: string[] = [];
const fetchFinto = vi.fn();
const richieste = vi.fn();
let w: VueWrapper | null = null;
beforeEach(() => {
    segnati.length = 0;
    vi.stubGlobal('fetch', fetchFinto);
    vi.spyOn(XMLHttpRequest.prototype, 'open').mockImplementation(richieste);
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.unstubAllGlobals(); vi.restoreAllMocks(); fetchFinto.mockReset(); richieste.mockReset(); });

/** La sala aperta alle 18:45, senza Colombo Rita: è assente, e di serie la sua riga non si vede. */
function senzaColombo(): SalaDellAssemblea {
    const s = salaAperta();
    const presenze = s.presenze.filter((p) => p.anagrafica_id !== 10);

    return { ...s, presenze, versioni: Object.fromEntries(presenze.map((p) => [p.chiave, 1])) };
}

function monta(sala: SalaDellAssemblea) {
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => ({ ...sala, in_coda: [] })) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async (tipo: string) => { segnati.push(tipo); }, adesso: () => new Date('2026-12-15T17:47:00Z'), puoGestire: computed(() => true),
                dalFoglio: ref(false), oraDelFoglio: ref('18:47'), oraDelGesto: () => '18:47', nomeDi: (id: number | null) => (id === null ? '' : PERSONE[id] ?? `Condomino ${id}`),
                scriviLaDiscussione: () => {},
            } as never);
            return () => h(PresenzeDellaSala as never, { presenze: sala.presenze, presenti: sala.barra.presenti });
        },
    });
    w = mount(Contenitore, { attachTo: document.body });
    return w;
}

const campo = () => w!.get('[data-testid="cerca-in-sala"]');
const righe = (dove: 'presenze-tabella' | 'presenze-card') => w!.findAll(`[data-testid="${dove}"] [data-testa]`).map((r) => r.attributes('data-testa'));
async function cerca(testo: string) {
    await campo().setValue(testo);
    await flushPromises();
}

describe('la ricerca sopra le presenze', () => {
    test('c\'è sopra le presenze, a tutta riga al telefono; di serie Colombo Rita, assente, non si vede', async () => {
        monta(senzaColombo());
        await flushPromises();
        expect(campo().attributes('placeholder')).toBe('Cerca per nome o per unità');
        expect(campo().classes()).toContain('w-full');
        expect(righe('presenze-tabella')).not.toContain('p-10');
        expect(w!.find('[data-testid="mostra-tutti"]').exists()).toBe(true);
    });

    test('«Colombo», «colo», «12», «int 12» e il conduttore lasciano la sua riga sola, anche fra quelle nascoste, nella tabella e nelle card', async () => {
        monta(senzaColombo());
        for (const cercato of ['Colombo', 'colo', '12', 'int 12', 'Ferri']) {
            await cerca(cercato);
            expect(righe('presenze-tabella'), cercato).toEqual(['p-10']);
            expect(righe('presenze-card'), cercato).toEqual(['p-10']);
        }
        // Mentre si cerca, «Mostra tutti» non serve: la ricerca guarda già tutte le teste.
        expect(w!.find('[data-testid="mostra-tutti"]').exists()).toBe(false);
        // La sua riga ha «Entra», come nella lista intera.
        expect(w!.find('[data-testid="presenze-tabella"] [data-testa="p-10"] [data-testid="entra"]').exists()).toBe(true);
    });

    test('la × svuota il campo e torna la lista di prima; senza testo la × non c\'è', async () => {
        monta(senzaColombo());
        expect(w!.find('[data-testid="svuota-ricerca"]').exists()).toBe(false);
        await cerca('Colombo');
        await w!.get('[data-testid="svuota-ricerca"]').trigger('click');
        await flushPromises();
        expect((campo().element as HTMLInputElement).value).toBe('');
        expect(righe('presenze-tabella')).not.toContain('p-10');
        expect(righe('presenze-tabella').length).toBeGreaterThan(1);
        expect(w!.find('[data-testid="mostra-tutti"]').exists()).toBe(true);
    });

    test('una ricerca senza risultati lo dice', async () => {
        monta(senzaColombo());
        await cerca('Zanetti');
        expect(righe('presenze-tabella')).toEqual([]);
        expect(righe('presenze-card')).toEqual([]);
        expect(w!.get('[data-testid="ricerca-vuota"]').text()).toBe('Nessuno per «Zanetti»: prova con il cognome o con il numero dell\'unità.');
    });

    test('senza rete: la ricerca filtra ciò che la pagina ha già, e non chiede niente al server', async () => {
        monta(senzaColombo());
        for (const cercato of ['C', 'Co', 'Colombo', '12', 'Zanetti', '']) await cerca(cercato);
        expect(fetchFinto).not.toHaveBeenCalled();
        expect(richieste).not.toHaveBeenCalled();
        expect(segnati).toEqual([]);
    });
});
