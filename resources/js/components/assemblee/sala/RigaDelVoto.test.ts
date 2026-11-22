// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il voto del punto nella sala aperta (decisione 664 (4) e (7), rilievi C51 e C53):
 *
 * - **la riga del voto**: «Nota sul voto», «Nota su chi non ha votato», «Dichiarazione a verbale» e «Un altro esito» (un pulsante, non un collegamento) alti
 *   uguali, 28 px, chiari; il gesto principale («Conferma: approvata», «Si vota») scuro, alto uguale, a destra in fondo alla riga; al telefono la conferma a
 *   tutta riga;
 * - **le dichiarazioni a verbale**: ognuna in una riga sotto il punto, con chi l'ha fatta, «Modifica» (la finestra della dichiarazione con il testo) e «Togli»
 *   (una conferma breve), finché il voto del punto non è confermato: i gesti `dichiarazione_cambia` e `dichiarazione_togli`.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { PERSONE, salaAperta, votazioneDelPortone } from './salaDiProva';
import VotoDelPunto from './VotoDelPunto.vue';
import type { PuntoDellaSala, SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return { default: dc({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => hh('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const segnati: { tipo: string; dati: Record<string, unknown> }[] = [];
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; segnati.length = 0; document.body.innerHTML = ''; });

function monta(punto: PuntoDellaSala, piu: Partial<SalaDellAssemblea> = {}) {
    const base = salaAperta();
    const sala: SalaDellAssemblea = { ...base, punti: base.punti.map((p) => (p.id === punto.id ? punto : p)), ...piu, in_coda: [] } as SalaDellAssemblea;
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => sala) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async (tipo: string, dati: Record<string, unknown>) => { segnati.push({ tipo, dati }); }, adesso: () => new Date('2026-12-15T18:40:00Z'),
                puoGestire: computed(() => true), dalFoglio: ref(false), oraDelFoglio: ref('19:40'), oraDelGesto: () => '19:40',
                nomeDi: (id: number | null) => (id === null ? '' : PERSONE[id] ?? ''), scriviLaDiscussione: () => {},
            } as never);
            return () => h(VotoDelPunto as never, { punto, presenti: base.barra!.presenti, costituzione: null });
        },
    });
    w = mount(Contenitore, { attachTo: document.body });
}
const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const conLaVotazione = (piu = {}) => ({ ...salaAperta().punti[1], votazioni: [votazioneDelPortone(piu)] }) as PuntoDellaSala;
const dichiarazioni = [{ id: 31, seduta_id: 2, punto_id: 44, anagrafica_id: 13, nome: 'Romano Ida', testo: 'Chiedo il preventivo scritto.' },
    { id: 32, seduta_id: 2, punto_id: 44, anagrafica_id: 1, nome: 'Rossi Mario', testo: 'Favorevole solo con la garanzia.' }];

describe('la riga del voto (C51)', () => {
    test('tutti i pulsanti alti 28 px e chiari; «Un altro esito» è un pulsante; la conferma scura, alta uguale, a destra in fondo', async () => {
        monta(conLaVotazione());
        await flushPromises();
        const riga = el('[data-testid="riga-del-voto"]');
        for (const id of ['nota-voto', 'nota-non-votato', 'dichiarazione', 'altro-esito']) {
            const b = riga.querySelector(`[data-testid="${id}"]`) as HTMLElement;
            expect(b, id).not.toBeNull();
            expect(b.tagName, id).toBe('BUTTON');
            expect(b.classList.contains('h-7'), id).toBe(true);
            expect(b.classList.contains('underline'), id).toBe(false);
            expect(b.classList.contains('bg-slate-900'), id).toBe(false);
        }
        const conferma = riga.querySelector('[data-testid="conferma-voto"]') as HTMLElement;
        expect(conferma.classList.contains('h-7')).toBe(true);
        expect(conferma.classList.contains('bg-slate-900')).toBe(true);
        expect(conferma.classList.contains('sm:ml-auto')).toBe(true);
        // Al telefono a tutta riga, in fondo.
        expect(conferma.classList.contains('w-full')).toBe(true);
        expect(riga.lastElementChild).toBe(conferma);
        expect(riga.classList.contains('flex-wrap')).toBe(true);
    });

    test('«Si vota» scuro e alto uguale', async () => {
        monta({ ...salaAperta().punti[1], votazioni: [] } as PuntoDellaSala);
        await flushPromises();
        const siVota = el('[data-testid="si-vota"]');
        expect(siVota.classList.contains('h-7')).toBe(true);
        expect(siVota.classList.contains('bg-slate-900')).toBe(true);
    });
});

// La prova a video del 22/11/2026, notte (assemblea n. 14): la riga di Luca Verdi, rappresentante dell'Int. 3, andava su due righe con cinque pulsanti, e
// sotto il nome si leggeva «rappresentante, int. 3 ·», con la minuscola.
describe('la riga del voto di ogni persona', () => {
    /** Il portone con i Russo comproprietari dell'Int. 5: vota Russo Anna, che rappresenta l'unità; il suo voto c'è, quindi anche «In conflitto». */
    const conIRusso = () => {
        const v = votazioneDelPortone();
        const teste = v.intervenuti.teste.map((t) => (t.chiave === 'p-6-7' ? { ...t, chi: { anagrafica_id: 6, nome: 'Russo Anna', ruolo: 'rappresentante' as const } } : t));
        const voti = [...v.voti, { testa: 'p-6-7', anagrafica_id: 6, nome: 'Russo Anna', ruolo: 'rappresentante', millesimi: teste.find((t) => t.chiave === 'p-6-7')!.millesimi,
            voto: 'favorevole' as const, decisione: null, in_conflitto: false, nota_conflitto: null, versione: 1 }];

        return { ...salaAperta().punti[1], votazioni: [{ ...v, intervenuti: { ...v.intervenuti, teste }, voti }] } as PuntoDellaSala;
    };
    const riga = (testa: string) => el(`[data-voto-testa="${testa}"]`);

    test('sotto il nome «rappresenta l\'Int. 5 · 51,80», con la maiuscola; il nudo proprietario «nudo proprietario, Int. 9»', async () => {
        monta(conIRusso());
        await flushPromises();
        expect(riga('p-6-7').textContent?.replace(/\s+/g, ' ')).toContain('rappresenta l\'Int. 5 · 51,80');
        expect(riga('p-6-7').textContent).not.toContain('int. 5');
        expect(riga('p-9').textContent?.replace(/\s+/g, ' ')).toContain('nudo proprietario, Int. 9 · 40,50');
    });

    test('i cinque pulsanti alti 28 px, a 12, con meno spazio ai lati, su una riga; al telefono vanno sotto il nome, tutti insieme', async () => {
        monta(conIRusso());
        await flushPromises();
        const r = riga('p-6-7');
        const ids = ['favorevole', 'contrario', 'astenuto', 'discorde', 'in-conflitto'];
        const pulsanti = ids.map((id) => r.querySelector(`[data-testid="${id}"]`) as HTMLElement);
        for (const [i, b] of pulsanti.entries()) {
            expect(b, ids[i]).not.toBeNull();
            expect(b.className.split(' '), ids[i]).toEqual(expect.arrayContaining(['h-7', 'text-xs', 'px-2', 'whitespace-nowrap']));
            expect(b.className.split(' '), ids[i]).not.toContain('h-8');
            expect(b.className.split(' '), ids[i]).not.toContain('px-2.5');
        }
        // Tutti e cinque nello stesso gruppo, che è l'ultimo della riga: al telefono la riga va in colonna e il gruppo intero sta sotto il nome.
        const gruppo = pulsanti[0].parentElement!;
        expect(pulsanti.every((b) => b.parentElement === gruppo)).toBe(true);
        expect(r.lastElementChild).toBe(gruppo);
        expect(r.className.split(' ')).toEqual(expect.arrayContaining(['flex-col', '@xl:flex-row']));
        expect(gruppo.className.split(' ')).toEqual(expect.arrayContaining(['flex-wrap', '@xl:flex-nowrap', '@xl:shrink-0']));
        // La riga si dispone sulla larghezza dell'elenco dei voti, non dello schermo: nella sala a due colonne c'è metà pagina.
        expect(el('[data-testid="voti"]').parentElement!.className.split(' ')).toContain('@container');
    });

    test('da otto persone, sopra i voti, «Cerca per nome o per unità»: filtra le righe sul posto, senza gesti; senza risultati lo dice', async () => {
        monta(conIRusso());
        await flushPromises();
        const cerca = el('[data-testid="cerca-nei-voti"]') as HTMLInputElement;
        expect(cerca.getAttribute('placeholder')).toBe('Cerca per nome o per unità');
        const visibili = () => [...document.body.querySelectorAll('[data-testid="voti"] [data-voto-testa]')].map((x) => x.getAttribute('data-voto-testa'));
        expect(visibili()).toHaveLength(12);
        const scrivi = async (testo: string) => { cerca.value = testo; cerca.dispatchEvent(new Event('input')); await flushPromises(); };
        await scrivi('rossi');
        expect(visibili()).toEqual(['p-1']);
        // Per unità: «5» trova l'Int. 5 dei Russo, anche dal nome di chi non vota.
        await scrivi('5');
        expect(visibili()).toEqual(['p-6-7']);
        await scrivi('marco');
        expect(visibili()).toEqual(['p-6-7']);
        await scrivi('zzz');
        expect(el('[data-testid="ricerca-vuota-voti"]').textContent).toContain('Nessuno per «zzz»');
        expect(segnati).toEqual([]);
    });

    test('sotto le otto persone la ricerca non c\'è', async () => {
        const v = votazioneDelPortone();
        const teste = v.intervenuti.teste.slice(0, 7);
        monta({ ...salaAperta().punti[1], votazioni: [{ ...v, intervenuti: { ...v.intervenuti, teste, numero: 7 } }] } as PuntoDellaSala);
        await flushPromises();
        expect(document.body.querySelector('[data-testid="cerca-nei-voti"]')).toBeNull();
        expect(document.body.querySelectorAll('[data-testid="voti"] [data-voto-testa]')).toHaveLength(7);
    });
});

describe('le dichiarazioni a verbale si correggono (C53)', () => {
    test('una riga per dichiarazione, con chi e il testo, «Modifica» e «Togli»; i due gesti nuovi', async () => {
        monta(conLaVotazione(), { dichiarazioni });
        await flushPromises();
        const righe = Array.from(document.body.querySelectorAll('[data-testid="dichiarazioni-del-punto"] [data-dichiarazione]')) as HTMLElement[];
        expect(righe.map((r) => r.getAttribute('data-dichiarazione'))).toEqual(['31', '32']);
        expect(righe[0].textContent).toContain('Romano Ida');
        expect(righe[0].textContent).toContain('Chiedo il preventivo scritto.');
        expect((righe[0].querySelector('[data-testid="modifica-dichiarazione"]') as HTMLElement).classList.contains('h-7')).toBe(true);

        (righe[0].querySelector('[data-testid="modifica-dichiarazione"]') as HTMLElement).click();
        await flushPromises();
        const campo = el('[data-testid="finestra-modifica-dichiarazione"] textarea') as HTMLTextAreaElement;
        expect(campo.value).toBe('Chiedo il preventivo scritto.');
        campo.value = 'Chiedo il preventivo scritto entro venerdì.';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        el('[data-testid="salva-modifica-dichiarazione"]').click();
        await flushPromises();
        expect(segnati.at(-1)).toEqual({ tipo: 'dichiarazione_cambia', dati: { dichiarazione_id: 31, testo: 'Chiedo il preventivo scritto entro venerdì.' } });

        (righe[1].querySelector('[data-testid="togli-dichiarazione"]') as HTMLElement).click();
        await flushPromises();
        expect(el('[data-testid="finestra-togli-dichiarazione"]').textContent).toContain('Rossi Mario');
        el('[data-testid="conferma-togli-dichiarazione"]').click();
        await flushPromises();
        expect(segnati.at(-1)).toEqual({ tipo: 'dichiarazione_togli', dati: { dichiarazione_id: 32 } });
    });

    test('a voto confermato le dichiarazioni restano, senza «Modifica» né «Togli»', async () => {
        monta(conLaVotazione({ esito: 'approvata', confermata_il: '2026-12-15T18:50:00Z' }), { dichiarazioni });
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid="dichiarazioni-del-punto"] [data-dichiarazione]')).toHaveLength(2);
        expect(document.body.querySelector('[data-testid="modifica-dichiarazione"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="togli-dichiarazione"]')).toBeNull();
    });
});
