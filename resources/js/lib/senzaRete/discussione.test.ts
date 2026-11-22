// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La discussione di un punto scritta nella sala, senza rete (decisione 595 (4): la discussione si scrive sotto il voto, nella sala e nel verbale):
 *
 * - la sala vista mostra la discussione scritta qui e non ancora arrivata, con la riga «Su questo computer» (`discussione:<punto>`);
 * - il lavoro ritrovato dice «la discussione del punto 4»;
 * - `scriviLaDiscussione()` scrive sul disco un gesto solo per punto (il primo con la seduta, prima che il verbale ci sia), parte dopo la pausa, e il
 *   salvataggio dopo ha il numero del verbale e la revisione arrivata.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { SalaDellAssemblea } from '@/types/assemblee';
import type { VoceInCoda } from './deposito';
import { DepositoInMemoria } from './deposito';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';
import { cheCosaErano, proiettaLaSala } from './sala';
import { salaDiProva } from './salaDiProva';
import { useSalaSenzaRete } from './useSalaSenzaRete';

const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
const conIlVerbale = (piu: Partial<NonNullable<SalaDellAssemblea['verbale']>> = {}): SalaDellAssemblea => ({ ...salaDiProva(),
    verbale: { seduta_id: 8, id: null, stato: null, discussioni: {}, ...piu } });
const testo = (parte: string, t: string, base = 0): VoceInCoda => ({ gesto: 'g-' + t, ambito: 'sala:7', ordine: 1, sessione: 's', tipo: 'testo',
    dati: { seduta_id: 8, parte, documento: doc(t) }, base, orologio: '2026-12-15T19:20:00Z', segnato_il: '2026-12-15T19:20:00Z', fondi: 'testo:' + parte });

describe('la discussione nella sala vista', () => {
    test('il testo scritto qui si vede nella discussione del punto, con la riga «Su questo computer»; il lavoro ritrovato dice quale', () => {
        const vista = proiettaLaSala(conIlVerbale({ discussioni: { '40': { parte: 'punto:40', documento: doc('dal server'), revisione: 2, impronta: 'x' } } }),
            [testo('punto:40', 'scritto qui', 2)], new Date('2026-12-15T19:21:00Z'));
        expect(vista.verbale!.discussioni['40']).toMatchObject({ documento: doc('scritto qui'), revisione: 2 });
        expect(vista.in_coda).toContain('discussione:40');
        expect(cheCosaErano([testo('punto:40', 'a')], salaDiProva())).toBe('la discussione del punto 4');
        // Senza il verbale della seduta (la sala non aperta), niente da mostrare.
        expect(proiettaLaSala(salaDiProva(), [testo('punto:40', 'x')]).in_coda).not.toContain('discussione:40');
    });
});

describe('scriviLaDiscussione', () => {
    let w: VueWrapper | null = null;
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-12-15T19:20:00Z'));
        globalThis.sessionStorage?.setItem('kondomanager:scheda', 'a1b2c3d4');
    });
    afterEach(() => {
        w?.unmount();
        w = null;
        vi.useRealTimers();
    });

    test('un gesto solo per punto sul disco, con la seduta prima che il verbale ci sia; poi il numero del verbale e la revisione arrivata', async () => {
        const deposito = new DepositoInMemoria();
        const lotti: LottoDaMandare[] = [];
        let rete = false;
        const invia = async (l: LottoDaMandare): Promise<RispostaDelServer> => {
            lotti.push(JSON.parse(JSON.stringify(l)));
            if (!rete) throw new ErroreDellInvio('rete');
            return { ora_del_server: '2026-12-15T19:31:00Z', risposte: l.gesti.map((g, i) => ({ gesto: g.gesto, tipo: g.tipo, esito: 'applicato', messaggio: null,
                verbale_id: 77, parte: (g.dati as { parte: string }).parte, revisione: lotti.length + i })) };
        };
        let api!: ReturnType<typeof useSalaSenzaRete>;
        const prop = ref(conIlVerbale());
        w = mount(defineComponent({ setup: () => {
            api = useSalaSenzaRete(prop, { operaio: false, ogniMs: 60_000, letturaOgniMs: 0, apriDeposito: async () => deposito, invia, inLinea: () => rete,
                leggiLoStato: async () => ({ ...conIlVerbale(), ora_del_server: '2026-12-15T19:31:00Z' }), spazio: { persist: async () => true } });
            return () => h('div');
        } }));
        const pronta = api.avviata();
        await vi.advanceTimersByTimeAsync(10);
        await pronta;

        for (const t of ['I', 'I prev', 'I preventivi']) {
            api.scriviLaDiscussione(40, doc(t));
            await vi.advanceTimersByTimeAsync(100);
        }
        await vi.advanceTimersByTimeAsync(400);
        const sulDisco = await deposito.inCoda('sala:7');
        expect(sulDisco.map((v) => [v.tipo, v.dati, v.base])).toEqual([['testo', { seduta_id: 8, parte: 'punto:40', documento: doc('I preventivi'), scheda: 'a1b2c3d4' }, 0]]);
        expect(api.salaVista.value.in_coda).toContain('discussione:40');

        rete = true;
        await vi.advanceTimersByTimeAsync(3000);
        expect(lotti.at(-1)!.gesti.map((g) => [g.tipo, (g.dati as { seduta_id?: number }).seduta_id])).toEqual([['testo', 8]]);
        api.scriviLaDiscussione(40, doc('I preventivi sono arrivati'));
        await vi.advanceTimersByTimeAsync(3500);
        const ultimo = lotti.at(-1)!.gesti[0];
        expect(ultimo.dati).toMatchObject({ verbale_id: 77, parte: 'punto:40' });
        expect(ultimo.base).toBeGreaterThan(0);
    });
});
