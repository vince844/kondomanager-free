// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La sala e il verbale: sotto il voto di ogni punto «La discussione» (decisione 595 (4)), che a ogni cambio passa da `scriviLaDiscussione` della sala (il
 * disco del browser al più ogni 300 millisecondi, poi la coda: `useSalaSenzaRete`, provato in `lib/senzaRete/discussione.test.ts`), mai da un ritardo in
 * memoria;
 * alla conferma di un punto di nomina il nome del nuovo amministratore (decisione 615 (2)); nella finestra «Sospendi» il promemoria ai condòmini, acceso di
 * serie, con la scelta fra «Pubblica subito» e «Lascia in bozza» per l'avviso in bacheca (decisione 615 (5)).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { punto, salaAperta, votazioneDelPortone } from './salaDiProva';
import VotoDelPunto from './VotoDelPunto.vue';
import SospendiLAssemblea from './SospendiLAssemblea.vue';
import type { SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return {
        default: dc({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => hh('div', { 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => hh('button', {
                    type: 'button', 'data-opzione': i, onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o),
                }, String((o as Record<string, unknown>)[p.label ?? 'label']))));
            },
        }),
    };
});
// La pagina monta l'editor (Tiptap): su una macchina carica supera i cinque secondi di serie.
vi.setConfig({ testTimeout: 20000 });
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
(globalThis as unknown as { ClipboardEvent: unknown }).ClipboardEvent ??= class extends Event { clipboardData = null; };

const segnati: { tipo: string; dati: Record<string, unknown>; opzioni?: Record<string, unknown> }[] = [];
const discussioni: { punto: number; documento: unknown }[] = [];
let w: VueWrapper | null = null;
beforeEach(() => { segnati.length = 0; discussioni.length = 0; });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

function monta(componente: unknown, props: Record<string, unknown>, sala: SalaDellAssemblea) {
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => ({ ...sala, in_coda: [] })) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async (tipo: string, dati: unknown, opzioni?: unknown) => { segnati.push({ tipo, dati: dati as Record<string, unknown>, opzioni: opzioni as Record<string, unknown> }); },
                adesso: () => new Date('2026-12-15T19:46:00Z'), puoGestire: computed(() => true), dalFoglio: ref(false), oraDelFoglio: ref('20:46'),
                oraDelGesto: () => '20:46', nomeDi: () => '',
                scriviLaDiscussione: (punto: number, documento: unknown) => { discussioni.push({ punto, documento }); },
            } as never);
            return () => h(componente as never, props);
        },
    });
    w = mount(Contenitore, { attachTo: document.body });
    return w;
}

describe('la discussione sotto il voto', () => {
    test('c\'è sotto il voto del punto, e ogni cambio passa subito dalla tenuta della sala (il disco), mai da un ritardo in memoria', async () => {
        const sala = salaAperta({ verbale: { seduta_id: 2, id: 7, stato: 'in_sala', discussioni: { 44: { parte: 'punto:44', documento: { type: 'doc' }, revisione: 3, impronta: 'x' } } } });
        const p = { ...sala.punti[1], votazioni: [votazioneDelPortone({ id: 404, punto_id: 44 })] };
        const v = monta(VotoDelPunto, { punto: p, presenti: [1, 2], costituzione: null }, sala);
        await flushPromises();
        expect(v.find('[data-testid="la-discussione"]').exists()).toBe(true);
        vi.useFakeTimers();
        const editor = (v.findComponent({ name: 'EditorDelVerbale' }).vm as unknown as { editor: { commands: { insertContent: (t: string) => void } } }).editor;
        editor.commands.insertContent('L\'amministratore illustra i tre preventivi.');
        // Nessun tempo che passa: il testo è già nelle mani della tenuta della sala, che lo scrive sul disco.
        expect(discussioni).toHaveLength(1);
        expect(discussioni[0].punto).toBe(44);
        expect(JSON.stringify(discussioni[0].documento)).toContain('i tre preventivi');
        // E nessun gesto «testo» segnato a parte, fuori dalla tenuta.
        await vi.advanceTimersByTimeAsync(31000);
        expect(segnati.filter((s) => s.tipo === 'testo')).toEqual([]);
        expect(v.find('[data-testid="la-discussione"]').text()).toContain('Su questo computer');
    });
});

describe('il punto di nomina', () => {
    test('la conferma chiede il nome del nuovo amministratore, e il gesto lo porta', async () => {
        const sala = salaAperta();
        const nomina = punto(46, 6, 'Conferma dell\'amministratore', { materia: 'nomina',
            votazioni: [votazioneDelPortone({ id: 406, punto_id: 46, esito_proposto: 'approvata', maggioranza: 'ordinaria' })] });
        const v = monta(VotoDelPunto, { punto: nomina, presenti: [1, 2], costituzione: null }, { ...sala, punti: [...sala.punti, nomina] });
        await flushPromises();
        await v.find('[data-testid="conferma-voto"]').trigger('click');
        await flushPromises();
        const campo = document.body.querySelector('[data-testid="nuovo-amministratore"]') as HTMLInputElement;
        expect(campo).not.toBeNull();
        campo.value = 'Studio Marchetti';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        (document.body.querySelector('[data-testid="conferma-esito"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(segnati.find((s) => s.tipo === 'conferma')?.dati).toMatchObject({ votazione_id: 406, esito: 'approvata', amministratore: 'Studio Marchetti' });
    });
});

describe('la finestra «Sospendi»', () => {
    test('il promemoria è acceso di serie; l\'avviso in bacheca si pubblica subito o resta in bozza', async () => {
        const sala = salaAperta({ sedute: [...salaAperta().sedute, { id: 3, convocazione: 'seconda', numero: 2, giorno: '2026-12-17', ora: '18:30', aperta_il: null,
            chiusa_il: null, esito: null, presidente: null, segretario: null, dal_foglio: false }] });
        monta(SospendiLAssemblea, { open: true }, sala);
        await flushPromises();
        const t = document.body.textContent ?? '';
        expect(t).toContain('Manda il promemoria ai condòmini convocati');
        expect(t).toContain('Pubblica subito');
        expect(t).toContain('Lascia in bozza');
        (document.body.querySelector('[data-testid="bacheca-bozza"]') as HTMLButtonElement).click();
        await flushPromises();
        (document.body.querySelector('[data-testid="conferma-sospendi"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(segnati.find((s) => s.tipo === 'sospendi')?.dati).toMatchObject({ seduta_id: 2, prosegue_seduta_id: 3, promemoria: true, bacheca: 'bozza' });
    });

    test('spento il promemoria, la scelta della bacheca non c\'è e il gesto lo dice', async () => {
        const sala = salaAperta({ sedute: [...salaAperta().sedute, { id: 3, convocazione: 'seconda', numero: 2, giorno: '2026-12-17', ora: '18:30', aperta_il: null,
            chiusa_il: null, esito: null, presidente: null, segretario: null, dal_foglio: false }] });
        monta(SospendiLAssemblea, { open: true }, sala);
        await flushPromises();
        (document.body.querySelector('[data-testid="promemoria"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="bacheca-bozza"]')).toBeNull();
        (document.body.querySelector('[data-testid="conferma-sospendi"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(segnati.find((s) => s.tipo === 'sospendi')?.dati).toMatchObject({ promemoria: false });
    });
});
