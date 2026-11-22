// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'esito del voto per condizione, nella pagina del voto: al posto della frase unica, le teste e il valore, una riga ciascuna, con la soglia e quanto
 * manca («Teste: 9 su 11, più della metà: sì», «Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34»). Le righe arrivano con i conteggi del server;
 * con un voto segnato qui e non ancora arrivato si contano sul posto, con lo stesso conto (`lib/assemblee/sala/conti.ts`). Un'altra maggioranza non ha
 * condizioni: resta la frase, «decide il presidente».
 *
 * Che cosa non copre: le parole delle condizioni (il file dei casi comuni, `tests/Fixtures/sala/conti.json`, con `conti.test.ts` e `ContiDellaSalaTest`);
 * il verbale, che dice la regola e non i conti che mancano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { salaAperta, votazioneDelPortone } from './salaDiProva';
import VotoDelPunto from './VotoDelPunto.vue';
import type { GestoDellaSala, SalaDellAssemblea, VotazioneDellaSala } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return { default: dc({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => hh('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

function monta(v: VotazioneDellaSala, inCoda: GestoDellaSala[] = []) {
    const base = salaAperta();
    const punto = { ...base.punti[1], maggioranza: v.maggioranza, votazioni: [v] };
    const sala: SalaDellAssemblea = { ...base, punti: [base.punti[0], punto, ...base.punti.slice(2)] };
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => ({ ...sala, in_coda: [] })) as never, inCoda: computed(() => inCoda), tenuta: ref(null), dispositivo: 'computer',
                segna: async () => {}, adesso: () => new Date('2026-12-15T18:40:00Z'), puoGestire: computed(() => true), dalFoglio: ref(false),
                oraDelFoglio: ref('19:40'), oraDelGesto: () => '19:40', nomeDi: () => '', scriviLaDiscussione: () => {},
            } as never);
            return () => h(VotoDelPunto as never, { punto, presenti: base.barra.presenti, costituzione: null });
        },
    });
    w = mount(Contenitore, { attachTo: document.body });
    return w;
}

const righe = () => w!.findAll('[data-testid="condizioni"] li').map((r) => [r.attributes('data-passa'), r.text().replace(/\s+/g, ' ').trim()]);

describe('l\'esito del voto per condizione', () => {
    test('dal server: le teste bastano, il valore no, e quanto manca; la frase unica non c\'è più', async () => {
        const v = votazioneDelPortone();
        monta({ ...v, maggioranza: 'ordinaria', conteggi: { ...v.conteggi!, frase: 'Respinta: 9 favorevoli su 11 intervenuti, 292,00 millesimi; in seconda convocazione '
            + 'servono la maggioranza degli intervenuti e 333,34 millesimi.', condizioni: [
            { condizione: 'teste', passa: true, frase: 'Teste: 9 su 11, più della metà: sì' },
            { condizione: 'valore', passa: false, frase: 'Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34' },
        ] } });
        await flushPromises();
        expect(righe()).toEqual([['true', 'Teste: 9 su 11, più della metà: sì'], ['false', 'Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34']]);
        expect(w!.text()).not.toContain('Respinta: 9 favorevoli su 11 intervenuti');
    });

    test('con un voto segnato qui e non ancora arrivato le condizioni si contano sul posto, con lo stesso conto', async () => {
        const v = votazioneDelPortone();
        monta(v, [{ chiave: 'g-1', tipo: 'voto', dati: { votazione_id: 404, testa: 'p-1', voto: 'favorevole' } } as unknown as GestoDellaSala]);
        await flushPromises();
        // Uno su dodici intervenuti, 98,30 millesimi, sulla metà del valore.
        expect(righe()).toEqual([['false', 'Teste: 1 su 12, più della metà: no, mancano 6'],
            ['false', 'Valore: 98,30 millesimi, ne servono 500,00: mancano 401,70']]);
    });

    test('un\'altra maggioranza non ha condizioni: resta la frase, decide il presidente', async () => {
        const v = votazioneDelPortone();
        monta({ ...v, maggioranza: 'libera', conteggi: { ...v.conteggi!, frase: 'Un\'altra maggioranza: decide il presidente.', condizioni: null } });
        await flushPromises();
        expect(w!.find('[data-testid="condizioni"]').exists()).toBe(false);
        expect(w!.text()).toContain('Un\'altra maggioranza: decide il presidente.');
    });

    test('senza condizioni la riga dice comunque i conti dei favorevoli come gli altri punti (teste e valore), senza il sì o il no, e sotto la frase (B1)', async () => {
        const v = votazioneDelPortone();
        monta({ ...v, maggioranza: 'libera', conteggi: { ...v.conteggi!, frase: 'Un\'altra maggioranza: decide il presidente.', condizioni: null } },
            [{ chiave: 'g-1', tipo: 'voto', dati: { votazione_id: 404, testa: 'p-1', voto: 'favorevole' } } as unknown as GestoDellaSala]);
        await flushPromises();
        const conti = w!.findAll('[data-testid="conti-senza-soglia"] li').map((r) => r.text().replace(/\s+/g, ' ').trim());
        expect(conti).toEqual(['Teste: 1 su 12', 'Valore: 98,30 millesimi su 1.000,00']);
        expect(w!.find('[data-testid="condizioni"]').exists()).toBe(false);
        expect(w!.text()).toContain('Un\'altra maggioranza: decide il presidente.');
    });

    test('con una maggioranza che ha le condizioni le righe senza soglia non ci sono', async () => {
        const v = votazioneDelPortone();
        monta({ ...v, maggioranza: 'ordinaria', conteggi: { ...v.conteggi!, condizioni: [{ condizione: 'teste', passa: true, frase: 'Teste: 9 su 11, più della metà: sì' }] } });
        await flushPromises();
        expect(w!.find('[data-testid="conti-senza-soglia"]').exists()).toBe(false);
    });
});
