// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I pezzi della sala senza rete, con le parole della bozza delle scene della sala (scene 2 e 3):
 *
 * - la striscia della rete nei suoi stati (in linea grigia; senza rete, invio, sessione scaduta, due versioni, browser che non tiene, colorati con il numero
 *   dei gesti), con «Rientra», «Scegli», «Stampa il foglio»; «su questo telefono» al telefono;
 * - il badge «Su questo computer», `rounded-md`;
 * - le due versioni affiancate, con il dispositivo e l'ora e «Tieni questa»; il punto che non si conferma;
 * - il lavoro ritrovato: la sala riaperta senza rete, e i gesti ritrovati partiti con «Vedi che cosa è partito»; i nomi come testo;
 * - l'avviso del browser: la finestra della finestra privata con le tre scelte, la nota leggera.
 *
 * Che cosa non copre: come la pagina li monta (la pagina della sala); i colori del tema scuro e i 375 pixel (la prova a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Ritrovati } from '@/lib/senzaRete/coda';
import { gestoDiProva as g, salaDiProva } from '@/lib/senzaRete/salaDiProva';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Striscia = (await import('./StrisciaDellaRete.vue')).default;
const Badge = (await import('./SuQuestoComputer.vue')).default;
const DueVersioni = (await import('./DueVersioni.vue')).default;
const Ritrovato = (await import('./LavoroRitrovato.vue')).default;
const Avviso = (await import('./AvvisoDelBrowser.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});
/** Il testo come si legge: i pezzi di testo separati da uno spazio (il testo di due elementi vicini non si attacca). */
const parole = (radice: Node = document.body) => {
    const passo = document.createTreeWalker(radice, NodeFilter.SHOW_TEXT);
    const pezzi: string[] = [];
    while (passo.nextNode()) pezzi.push(passo.currentNode.textContent ?? '');
    return pezzi.join(' ').replace(/\s+/g, ' ').trim();
};
const testo = () => parole();
const campo = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;

describe('la striscia della rete', () => {
    const casi: [Record<string, unknown>, string][] = [
        [{ stato: 'in_linea', inCoda: 0 }, 'In linea tutto sul server'],
        [{ stato: 'senza_rete', inCoda: 4 }, 'Senza rete: 4 gesti salvati su questo computer. partono da soli quando torna la rete'],
        [{ stato: 'senza_rete', inCoda: 1, dispositivo: 'telefono' }, 'Senza rete: 1 gesto salvato su questo telefono. partono da soli quando torna la rete'],
        [{ stato: 'senza_rete', inCoda: 2, serverFermo: true }, 'Il server non risponde: 2 gesti salvati su questo computer. partono da soli quando il server torna'],
        // La lettura dello stato che trova il server fermo, senza gesti in coda: lo dice lo stesso.
        [{ stato: 'senza_rete', inCoda: 0, serverFermo: true }, 'Il server non risponde: i gesti si salvano su questo computer. partono da soli quando il server torna'],
        [{ stato: 'invio', inCoda: 2, invio: { fatti: 2, di: 4 } }, 'Sto mandando i gesti salvati… 2 di 4'],
        [{ stato: 'sessione_scaduta', inCoda: 12 }, 'La sessione è scaduta: rientra; i 12 gesti in coda partono da soli. Rientra'],
        [{ stato: 'due_versioni', inCoda: 0, conflitto: 'Il voto di Romano Ida al punto 4.' }, 'Due versioni: scegli. Il voto di Romano Ida al punto 4. Scegli'],
        [{ stato: 'non_tiene', inCoda: 0 }, 'Questo browser non tiene il lavoro senza rete. aperta lo stesso Stampa il foglio'],
    ];

    test.each(casi)('%o dice «%s»', (props, atteso) => {
        w = mount(Striscia, { attachTo: document.body, props: props as never });
        expect(testo()).toBe(atteso);
        expect(campo('striscia-della-rete')!.getAttribute('data-stato')).toBe(props.stato);
    });

    test('in linea è grigia; gli altri stati si colorano; ogni colore ha il suo tema scuro', () => {
        w = mount(Striscia, { attachTo: document.body, props: { stato: 'in_linea', inCoda: 0 } });
        expect(campo('striscia-della-rete')!.className).toContain('bg-slate-50');
        for (const stato of ['senza_rete', 'invio', 'sessione_scaduta', 'due_versioni', 'non_tiene']) {
            w.unmount();
            w = mount(Striscia, { attachTo: document.body, props: { stato: stato as never, inCoda: 1 } });
            const classi = campo('striscia-della-rete')!.className;
            expect(classi).not.toContain('bg-slate-50');
            expect(classi).toMatch(/dark:bg-/);
        }
    });

    test('i pulsanti chiamano la pagina: «Rientra», «Scegli», «Stampa il foglio»', async () => {
        for (const [stato, id, evento] of [['sessione_scaduta', 'striscia-rientra', 'rientra'], ['due_versioni', 'striscia-scegli', 'scegli'],
            ['non_tiene', 'striscia-foglio', 'stampa-foglio']] as const) {
            w?.unmount();
            w = mount(Striscia, { attachTo: document.body, props: { stato, inCoda: 1 } });
            campo(id)!.click();
            await nextTick();
            expect(Object.keys(w.emitted())).toContain(evento);
        }
    });
});

describe('il badge «Su questo computer»', () => {
    test('rounded-md, con il dispositivo', () => {
        w = mount(Badge, { attachTo: document.body, props: { dispositivo: 'telefono' } });
        expect(testo()).toBe('Su questo telefono');
        expect(campo('su-questo-computer')!.className).toContain('rounded-md');
        expect(campo('su-questo-computer')!.className).not.toContain('rounded-full');
    });
});

describe('le due versioni', () => {
    const conflitto = { gesto: 'g-1', tipo: 'voto' as const, dispositivo: 'computer di Andrea Costa#a1b2c3d4', questa: 'Romano Ida: Contrario', quella: 'Romano Ida: Astenuto',
        su: 'votazione:12', segnato_il: '2026-12-15T18:41:00Z' };

    test('affiancate con il dispositivo e l\'ora; «Tieni questa» su ognuna; il punto non si conferma finché non si sceglie', async () => {
        w = mount(DueVersioni, { attachTo: document.body, props: { open: true, conflitto: { ...conflitto, quella_dispositivo: 'tablet di Marco Galli#ffff0000',
            quella_segnato_il: '2026-12-15T18:44:00Z' }, mioDispositivo: 'computer di Andrea Costa#a1b2c3d4', titolo: 'Il voto di Romano Ida sul punto 4', punto: 4 } });
        await nextTick();
        expect(testo()).toContain('Il voto di Romano Ida sul punto 4: due versioni');
        expect(testo()).toContain('Non sovrascrivo niente: scegli tu quale tenere.');
        expect(parole(campo('versione-questa')!)).toBe('Da questo computer segnato alle 19:41 Contrario Tieni questa');
        expect(parole(campo('versione-quella')!)).toBe('Dal dispositivo «tablet di Marco Galli» segnato alle 19:44 Astenuto Tieni questa');
        expect(campo('due-versioni-punto')!.textContent).toBe('Finché non scegli, il punto 4 non si conferma.');
        expect(testo()).toContain('La scelta resta nel registro delle modifiche, con le due ore e chi ha scelto.');
        campo('tieni-quella')!.click();
        campo('tieni-questa')!.click();
        expect(w.emitted('scegli')).toEqual([['quella'], ['questa']]);
    });

    test('senza il dispositivo dell\'altra versione: «Com\'è adesso sul server»; segnata da un altro dispositivo: il suo nome', async () => {
        w = mount(DueVersioni, { attachTo: document.body, props: { open: true, conflitto, mioDispositivo: 'telefono di Marco Galli#00000000', titolo: 'Il voto di Romano Ida' } });
        await nextTick();
        expect(campo('versione-questa')!.textContent).toContain('Dal dispositivo «computer di Andrea Costa»');
        expect(campo('versione-quella')!.textContent).toContain('Com\'è adesso sul server');
        expect(campo('due-versioni-punto')).toBeNull();
    });
});

describe('il lavoro ritrovato', () => {
    test('la sala riaperta senza rete: fino a che ora sono i dati del server, e i gesti su questo computer', () => {
        w = mount(Ritrovato, { attachTo: document.body, props: { ritrovati: null, sala: salaDiProva(), riapertaSenzaRete: { datiFinoAlle: '2026-12-15T19:09:00Z' }, inCoda: 4 } });
        expect(testo()).toContain('Ho riaperto la sala senza rete.');
        expect(campo('riaperta-dati')!.textContent).toBe('I dati sono quelli arrivati al server fino alle 20:09, più i 4 gesti salvati su questo computer.');
        expect(testo()).toContain('Puoi continuare: la barra conta sul posto.');
        expect(testo()).toContain('Quando la rete torna, i gesti partono da soli e vedi quelli fatti dagli altri dispositivi.');
    });

    test('i gesti ritrovati partiti: quanti sono arrivati e quanti hanno due versioni, e «Vedi che cosa è partito»; un nome con un tag resta testo', async () => {
        const sala = salaDiProva();
        sala.fotografia!.persone['4'].nome = '<b>De Luca</b> Gianni';
        sala.punti[1].votazioni[0].intervenuti.teste[1].chi.nome = '<b>De Luca</b> Gianni';
        const voti = [g('voto', { votazione_id: 12, testa: 'p-3', voto: 'contrario' }, '2026-12-15T18:39:00Z', 0),
            g('voto', { votazione_id: 12, testa: 'p-4', voto: 'favorevole' }, '2026-12-15T18:40:00Z', 0)];
        const r: Ritrovati = { quanti: 2, dal: voti[0].segnato_il, al: voti[1].segnato_il, voci: voti, finito: true, arrivati_alle: '2026-12-15T19:31:00Z',
            esiti: [{ gesto: voti[0].gesto, tipo: 'voto', esito: 'in_conflitto', messaggio: null, voce: voti[0], arrivato_il: '', locale: false },
                { gesto: voti[1].gesto, tipo: 'voto', esito: 'applicato', messaggio: null, voce: voti[1], arrivato_il: '', locale: false }] };
        w = mount(Ritrovato, { attachTo: document.body, props: { ritrovati: r, sala } });
        expect(campo('ritrovati-titolo')!.textContent).toBe('Ho ritrovato 2 gesti che non erano partiti: 1 è arrivato e 1 ha due versioni.');
        expect(testo()).toContain('il voto di <b>De Luca</b> Gianni al punto 4 è arrivato alle 20:31.');
        expect(document.body.querySelector('b')).toBeNull();
        expect(testo()).toContain('Il voto di Romano Ida al punto 4 aspetta la tua scelta.');
        expect(campo('che-cosa-e-partito')).toBeNull();
        campo('vedi-che-cosa-e-partito')!.click();
        await nextTick();
        expect(parole(campo('che-cosa-e-partito')!)).toBe(
            '19:39 · Il voto di Romano Ida al punto 4: due versioni 19:40 · Il voto di <b>De Luca</b> Gianni al punto 4: arrivato');
    });
});

describe('l\'avviso del browser', () => {
    test('la finestra privata: la finestra con le righe della bozza e le tre scelte', async () => {
        const scrivi = vi.fn(async () => undefined);
        Object.defineProperty(globalThis.navigator, 'clipboard', { value: { writeText: scrivi }, configurable: true });
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'non_tiene', foglio: '/admin/gestionale/5/assemblee/7/sala/foglio', indirizzo: 'https://studio.example/sala' } });
        await nextTick();
        const t = testo();
        expect(t).toContain('Questo browser non tiene il lavoro della sala');
        expect(t).toContain('Sembra una finestra privata: quando si chiude, cancella tutto.');
        expect(t).toContain('Se la rete cade e la finestra si chiude, presenze e voti segnati senza rete si perdono.');
        expect(t).toContain('Copia l\'indirizzo, chiudi questa finestra, incollalo in una finestra normale.');
        expect(campo('stampa-il-foglio')!.getAttribute('href')).toBe('/admin/gestionale/5/assemblee/7/sala/foglio');
        campo('copia-indirizzo')!.click();
        await nextTick();
        await nextTick();
        expect(scrivi).toHaveBeenCalledWith('https://studio.example/sala');
        expect(campo('indirizzo-copiato')!.textContent).toContain('Indirizzo copiato');
        campo('apri-lo-stesso')!.click();
        expect(w.emitted('apri-lo-stesso')).toHaveLength(1);
    });

    test('il browser che tiene senza promettere: solo la nota leggera; quello che tiene: niente', () => {
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'non_promette' } });
        expect(parole(campo('nota-del-browser')!)).toBe('Questo browser tiene il lavoro, ma non promette di non cancellarlo se il disco si riempie. '
            + 'Per questa sala basta. Per esserne sicuro, tieni il foglio della sala.');
        expect(campo('avviso-finestra-privata')).toBeNull();
        w.unmount();
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'tiene' } });
        expect(testo()).toBe('');
    });
});

describe('le frasi della sala non dicono «stasera» (la sala si tiene anche di giorno)', () => {
    test('né l\'avviso del browser né la finestra della sospensione', async () => {
        const { readFileSync } = await import('node:fs');
        for (const file of ['./AvvisoDelBrowser.vue', '../SospendiLAssemblea.vue']) {
            expect(readFileSync(new URL(file, import.meta.url), 'utf8').toLowerCase()).not.toContain('stasera');
        }
    });
});
