// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Chi scrive il verbale, per il server: il dispositivo di chi usa il browser e la scheda.
 *
 * - **Marco sul browser di Andrea** è «computer di Marco Galli», non «computer di Andrea Costa»: il dispositivo si tiene per utente; Andrea, tornando, ritrova
 *   il suo.
 * - **Due schede dello stesso browser** non si sostituiscono il testo in coda (la chiave di fusione ha la scheda): partono tutte e due, ognuna con la sua
 *   scheda nei dati, e il server dice le due versioni (`TestoCheNonSiPerdeTest.php`).
 * - **Un salvataggio non accettato** non esce dal computer: resta fra quelli da rivedere, e «Rimettilo nell'editor» lo riscrive dalla revisione di adesso;
 *   quando il verbale cambia (la registrazione annullata) le basi ripartono da quelle del verbale nuovo.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { PaginaDelVerbale } from '@/types/verbale';
import { CodaDeiGesti } from './coda';
import { DepositoInMemoria } from './deposito';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';
import { CHIAVE_DELLA_SCHEDA, dispositivoDellUtente, schedaDelBrowser } from './scrittore';
import { ScrittoreDelTesto } from './testo';
import { useVerbaleSenzaRete } from './useVerbaleSenzaRete';

const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
let n = 0;
const numero = () => `00000000-0000-4000-a000-${String(++n).padStart(12, '0')}`;

let w: VueWrapper | null = null;
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-12-15T20:00:00Z'));
    globalThis.sessionStorage?.clear();
});
afterEach(() => {
    w?.unmount();
    w = null;
    vi.useRealTimers();
});

describe('il dispositivo e la scheda', () => {
    test('il dispositivo è di chi usa il browser: Marco sul browser di Andrea è «computer di Marco Galli»', async () => {
        const deposito = new DepositoInMemoria();
        const andrea = await dispositivoDellUtente(deposito, 5, 'computer', 'Andrea Costa', numero);
        const marco = await dispositivoDellUtente(deposito, 6, 'computer', 'Marco Galli', numero);
        expect(andrea).toMatch(/^computer di Andrea Costa#/);
        expect(marco).toMatch(/^computer di Marco Galli#/);
        expect(await dispositivoDellUtente(deposito, 5, 'computer', 'Andrea Costa', numero)).toBe(andrea);
    });

    test('la scheda vive in sessionStorage: la stessa scheda la ritrova, un\'altra no', () => {
        const una = schedaDelBrowser();
        expect(una).toMatch(/^[0-9a-f]{8}$/);
        expect(schedaDelBrowser()).toBe(una);
        expect(globalThis.sessionStorage.getItem(CHIAVE_DELLA_SCHEDA)).toBe(una);
        const altra = new Map<string, string>();
        expect(schedaDelBrowser({ getItem: (k) => altra.get(k) ?? null, setItem: (k, v) => void altra.set(k, v) })).not.toBe(una);
    });

    test('due schede dello stesso browser: tutte e due le parti restano in coda, ognuna con la sua scheda', async () => {
        const deposito = new DepositoInMemoria();
        const coda = new CodaDeiGesti({ deposito, ambito: 'verbale:12:5', dispositivo: 'computer di Andrea Costa#a1b2c3d4', numero,
            invia: async () => { throw new ErroreDellInvio('rete'); } });
        const scrittore = (scheda: string) => new ScrittoreDelTesto({ segna: (d, b, o) => coda.segna('testo', d, b, undefined, o), manda: () => undefined,
            dati: (parte, documento) => ({ verbale_id: 7, parte, documento, scheda }), base: () => 5, fondi: (parte) => `testo:${scheda}:${parte}` });
        scrittore('scheda-uno').scrivi('intero', doc('La frase delle 21:02.'));
        await vi.advanceTimersByTimeAsync(10);
        scrittore('scheda-due').scrivi('intero', doc('Il refuso delle 21:03.'));
        await vi.advanceTimersByTimeAsync(10);
        const voci = await deposito.inCoda('verbale:12:5');
        expect(voci.map((v) => (v.dati as { scheda: string }).scheda)).toEqual(['scheda-uno', 'scheda-due']);
    });
});

describe('la risposta persa', () => {
    test('il server scrive «Uno.», la risposta si perde, intanto si scrive «Uno. Due.»: riparte il gesto di prima com\'era, poi il nuovo con un numero nuovo', async () => {
        const server = { testo: '', arrivati: new Map<string, string>(), lotti: [] as string[][] };
        let perdi = true;
        const deposito = new DepositoInMemoria();
        const coda = new CodaDeiGesti({ deposito, ambito: 'verbale:12:5', dispositivo: 'computer', numero, invia: async (l) => {
            server.lotti.push(l.gesti.map((g) => g.gesto));
            const risposte = l.gesti.map((g) => {
                if (server.arrivati.has(g.gesto)) return { gesto: g.gesto, tipo: 'testo', esito: 'doppio', messaggio: null };
                const t = JSON.stringify((g.dati as { documento: unknown }).documento);
                server.arrivati.set(g.gesto, t);
                server.testo = t;
                return { gesto: g.gesto, tipo: 'testo', esito: 'applicato', messaggio: null, revisione: server.arrivati.size };
            });
            if (perdi) { perdi = false; throw new ErroreDellInvio('rete'); }
            return { ora_del_server: '2026-12-15T20:00:00Z', risposte } as RispostaDelServer;
        } });
        const scrittore = new ScrittoreDelTesto({ segna: (d, b, o) => coda.segna('testo', d, b, undefined, o), manda: () => coda.prova(),
            dati: (parte, documento) => ({ verbale_id: 7, parte, documento }), base: () => 0 });
        let rispondi!: () => void;
        const attesa = new Promise<void>((r) => { rispondi = r; });
        const invia = (coda as unknown as { o: { invia: (l: unknown) => Promise<unknown> } }).o.invia;
        (coda as unknown as { o: { invia: (l: unknown) => Promise<unknown> } }).o.invia = async (l) => { await attesa; return invia(l); };
        scrittore.scrivi('intero', doc('Uno.'));
        const prima = scrittore.mandaOra();
        await vi.advanceTimersByTimeAsync(10);
        // La prima richiesta è in viaggio (e la sua risposta si perderà): intanto si scrive ancora.
        scrittore.scrivi('intero', doc('Uno. Due.'));
        await vi.advanceTimersByTimeAsync(10);
        rispondi();
        await prima;
        await vi.advanceTimersByTimeAsync(10);
        await scrittore.mandaOra();
        await vi.advanceTimersByTimeAsync(10);
        expect(server.testo).toContain('Uno. Due.');
        expect(await deposito.inCoda('verbale:12:5')).toEqual([]);
        // Il gesto di prima è ripartito con il suo numero (il server lo conta una volta), il testo nuovo con un numero suo.
        expect(new Set(server.lotti.flat()).size).toBe(2);
    });
});

describe('la rete lenta', () => {
    test('90 secondi di scrittura continua con la rete a 300 millisecondi: il testo parte pochi secondi dopo la pausa e almeno ogni 30 secondi, non a ogni risposta', async () => {
        let partenze = 0;
        const scrittore = new ScrittoreDelTesto({ segna: async (d) => ({ gesto: 'g', ambito: 'a', ordine: 1, sessione: 's', tipo: 'testo', dati: d, base: 0,
            orologio: '', segnato_il: '' }), manda: async () => { partenze++; await new Promise((r) => setTimeout(r, 300)); },
        dati: (parte, documento) => ({ parte, documento }), base: () => 0 });
        for (let ms = 0; ms < 90_000; ms += 200) {
            scrittore.scrivi('intero', doc(`lettera ${ms}`));
            await vi.advanceTimersByTimeAsync(200);
        }
        expect(partenze).toBeLessThanOrEqual(4);
        expect(partenze).toBeGreaterThanOrEqual(3);
        await scrittore.ferma();
    });
});

describe('il salvataggio non accettato, e il verbale che cambia', () => {
    function pagina(verbale = 7, revisione = 12): PaginaDelVerbale {
        return {
            assemblea: { id: 12, nome: 'Ordinaria', titolo: 'Ordinaria', stato: 'chiusa', stato_parola: 'Chiusa', modalita: 'presenza', tipo: 'ordinaria' },
            ora_del_server: `2026-12-16T09:0${verbale - 7}:00Z`,
            riunioni: [{ seduta_id: 8, giorno: '2026-12-15', convocazione: 'seconda', numero: 1, aperta_il: '2026-12-15T17:42:00Z', chiusa_il: '2026-12-15T19:55:00Z',
                esito: 'tenuta', verbale: { id: verbale, stato: 'bozza', numero: null } }],
            verbale: { id: verbale, seduta_id: 8, stato: 'bozza', stato_parola: 'Bozza', parti: { intero: { documento: doc('Il testo.'), revisione, impronta: 'x',
                caratteri: 9, dispositivo: 'programma', scritta_il: '2026-12-16T09:00:00Z' } }, punti: [], versioni: [], in_attesa: [], chi_scrive: null,
            trasmissione: null, termini: null } as unknown as PaginaDelVerbale['verbale'],
            modelli: { modelli: [], proposto: null, ultimo_usato: null }, puo: { gestire: true, registrare: true },
            rotte: { pagina: '/v', stato: '/v/stato', gesti: '/v/gesti', genera: '', sala: '/s', assemblea: '', registro: '' },
        } as unknown as PaginaDelVerbale;
    }

    test('la registrazione annullata: il verbale nuovo riparte dalla revisione 1, non dalla 12; un rifiutato resta e si rimette nell\'editor', async () => {
        const lotti: LottoDaMandare[] = [];
        let rifiuta = true;
        const invia = async (l: LottoDaMandare): Promise<RispostaDelServer> => {
            lotti.push(JSON.parse(JSON.stringify(l)));
            return { ora_del_server: '2026-12-16T09:10:00Z', risposte: l.gesti.map((g) => (rifiuta
                ? { gesto: g.gesto, tipo: 'testo', esito: 'rifiutato', messaggio: 'Il salvataggio parte da una revisione che il verbale non ha: ricarica la pagina.' }
                : { gesto: g.gesto, tipo: 'testo', esito: 'applicato', messaggio: null, parte: 'intero', revisione: 2, verbale_id: 8 })) };
        };
        const prop = ref(pagina());
        let api!: ReturnType<typeof useVerbaleSenzaRete>;
        w = mount(defineComponent({ setup: () => {
            api = useVerbaleSenzaRete(prop, { operaio: false, ogniMs: 60_000, letturaOgniMs: 0, utente: 5, nomeUtente: 'Andrea Costa', numero, invia,
                apriDeposito: async () => new DepositoInMemoria(), leggiLoStato: async () => prop.value, spazio: { persist: async () => true } });
            return () => h('div');
        } }));
        await vi.advanceTimersByTimeAsync(50);
        await api.avviata();

        // La pagina tiene il verbale 7 alla revisione 12; la registrazione è annullata e il testo torna in bozza nel verbale 8, alla revisione 1.
        api.scrivi('intero', doc('Il testo. Una correzione.'));
        prop.value = pagina(8, 1);
        await vi.advanceTimersByTimeAsync(10);
        api.scrivi('intero', doc('Il testo. Una correzione, sul verbale nuovo.'));
        await vi.advanceTimersByTimeAsync(3500);
        expect(lotti.at(-1)?.gesti.at(-1)).toMatchObject({ base: 1 });

        // Il server rifiuta: il testo non esce dal computer, e si rimette nell'editor.
        await vi.advanceTimersByTimeAsync(10);
        expect(api.daRivedere.value.length).toBeGreaterThan(0);
        expect(api.daRivedere.value.at(-1)?.messaggio).toContain('revisione che il verbale non ha');
        rifiuta = false;
        const gesto = api.daRivedere.value.at(-1)!.gesto;
        expect(await api.riprendi(gesto)).toBe(true);
        await vi.advanceTimersByTimeAsync(3500);
        expect(api.daRivedere.value.map((d) => d.gesto)).not.toContain(gesto);
        expect(JSON.stringify(lotti.at(-1)?.gesti.at(-1)?.dati)).toContain('sul verbale nuovo');
    });
});
