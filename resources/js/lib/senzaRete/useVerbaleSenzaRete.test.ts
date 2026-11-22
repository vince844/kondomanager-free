// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il verbale senza rete come lo usa la pagina, da un capo all'altro, con il disco del browser finto, l'orologio finto e un server finto che fa come
 * quello vero (la revisione, il «doppio», le due versioni solo con un altro dispositivo):
 *
 * 1. **la catena**: le lettere scritte senza rete → il disco (un gesto solo per parte, l'ultimo) → la scritta «Non salvato: senza connessione… 3 modifiche in
 *    attesa» → la rete torna → il lotto del file in comune (`tests/Fixtures/verbale/lotti_senza_rete.json`, che il server riceve in
 *    `CateneSenzaReteDelVerbaleTest.php`) → la revisione nel server → «Di nuovo in linea: … le 3 modifiche sono partite» → niente resta nel browser;
 * 2. **il computer spento e riacceso**: la pagina riaperta senza rete mostra il testo scritto qui, e «Ho ritrovato 2 modifiche…»; la rete torna, «e le ho
 *    mandate», «Arrivate alle …»; la stessa macchina non è mai in conflitto con sé stessa;
 * 3. **le due versioni**: il tablet ha scritto la stessa parte: niente si sovrascrive, la parte si ferma;
 * 4. **la sessione scaduta**: il 419 non toglie niente, e quando si rientra il testo parte;
 * 5. chiudendo la pagina con il testo in attesa il browser lo chiede; il browser che non tiene i dati; il verbale registrato cancella ciò che resta; la
 *    pagina del verbale registra lo stesso operaio della sala.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { PaginaDelVerbale } from '@/types/verbale';
import fileInComune from '../../../../tests/Fixtures/verbale/lotti_senza_rete.json';
import { apriDepositoIndexedDb } from './deposito';
import { IndexedDbFinto } from './indexedDbFinto';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';
import { registraLOperaio } from './useSalaSenzaRete';
import { useVerbaleSenzaRete, type OpzioniDelVerbaleSenzaRete } from './useVerbaleSenzaRete';

const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
/** Il nome del dispositivo di queste prove: il primo numero dato è il suo. */
const PORTATILE = 'computer di Andrea Costa#00000000';

/** La pagina «Il verbale» di Via Roma 12: il verbale 7 in bozza, il punto 4 (id 31) senza discussione, il verbale intero alla revisione 2. */
function paginaDiProva(piu: Partial<PaginaDelVerbale['verbale'] & object> = {}): PaginaDelVerbale {
    return {
        assemblea: { id: 12, nome: 'Ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria', stato: 'chiusa', stato_parola: 'Chiusa', modalita: 'presenza', tipo: 'ordinaria' },
        ora_del_server: '2026-12-15T19:10:00Z',
        riunioni: [{ seduta_id: 8, giorno: '2026-12-15', convocazione: 'seconda', numero: 1, aperta_il: '2026-12-15T17:42:00Z', chiusa_il: '2026-12-15T19:55:00Z',
            esito: 'tenuta', verbale: { id: 7, stato: 'bozza', numero: null } }],
        verbale: { id: 7, seduta_id: 8, stato: 'bozza', stato_parola: 'Bozza', parti: { intero: { documento: doc('Il giorno 15 dicembre 2026…'), revisione: 2,
            impronta: 'x', caratteri: 26, dispositivo: 'programma', scritta_il: '2026-12-15T19:02:00Z' } },
        punti: [{ punto_id: 31, numero: 4, testo: 'Sostituzione del portone', parte: 'punto:31', votazioni: [], dichiarazioni: [] }], versioni: [], in_attesa: [],
        chi_scrive: null, trasmissione: null, termini: null, ...piu } as PaginaDelVerbale['verbale'],
        modelli: { modelli: [], proposto: null, ultimo_usato: null },
        puo: { gestire: true, registrare: true },
        rotte: { pagina: '/admin/gestionale/5/assemblee/12/il-verbale', stato: '/admin/gestionale/5/assemblee/12/il-verbale/stato',
            gesti: '/admin/gestionale/5/assemblee/12/il-verbale/gesti', genera: '', sala: '/admin/gestionale/5/assemblee/12/sala', assemblea: '', registro: '' },
    } as unknown as PaginaDelVerbale;
}

/** Il server finto del testo: la revisione per parte, chi l'ha scritta; «doppio» con la revisione di allora; due versioni solo con un altro dispositivo. */
function server() {
    const s = { rete: false, stato: 200, lotti: [] as LottoDaMandare[], ora: '2026-12-15T19:31:00Z',
        parti: new Map<string, { documento: unknown; revisione: number; scrittori: [number, string][] }>(),
        arrivati: new Map<string, Record<string, unknown>>(), inAttesa: [] as { parte: string; dispositivo: string; documento: unknown }[] };
    const invia = async (lotto: LottoDaMandare): Promise<RispostaDelServer> => {
        s.lotti.push(JSON.parse(JSON.stringify(lotto)));
        if (!s.rete) throw new ErroreDellInvio('rete');
        if (s.stato !== 200) throw new ErroreDellInvio(s.stato === 419 || s.stato === 401 ? 'sessione' : 'server', s.stato);
        const risposte = lotto.gesti.map((g) => {
            const gia = s.arrivati.get(g.gesto);
            if (gia) return { ...gia, esito: 'doppio', esito_di_allora: gia.esito };
            const d = g.dati as { parte: string; documento: unknown };
            const p = s.parti.get(d.parte) ?? { documento: null, revisione: 0, scrittori: [] };
            const base = Number(g.base ?? 0);
            const altri = p.scrittori.filter(([r, chi]) => r > base && chi !== lotto.dispositivo);
            let r: Record<string, unknown>;
            if (base < p.revisione && altri.length > 0) {
                s.inAttesa.push({ parte: d.parte, dispositivo: lotto.dispositivo, documento: d.documento });
                r = { gesto: g.gesto, tipo: 'testo', esito: 'in_conflitto', messaggio: 'due versioni', verbale_id: 7, parte: d.parte, revisione: p.revisione,
                    conflitto: { tipo: 'due_versioni' } };
            } else {
                const revisione = p.revisione + 1;
                s.parti.set(d.parte, { documento: d.documento, revisione, scrittori: [...p.scrittori, [revisione, lotto.dispositivo]] });
                r = { gesto: g.gesto, tipo: 'testo', esito: 'applicato', messaggio: null, verbale_id: 7, parte: d.parte, revisione };
            }
            s.arrivati.set(g.gesto, r);
            return r;
        });

        return { ora_del_server: s.ora, risposte } as RispostaDelServer;
    };
    /** La lettura della pagina: senza rete non risponde; con la rete la pagina con l'ora del server. */
    const leggiLoStato = async (): Promise<PaginaDelVerbale> => {
        if (!s.rete) throw Object.assign(new Error('senza rete'), { request: {} });
        return { ...paginaDiProva(), ora_del_server: s.ora };
    };

    return { s, invia, leggiLoStato };
}

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    vi.useRealTimers();
});
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-12-15T19:16:00Z'));
    // La scheda del browser del file in comune: ogni testo la porta nei dati.
    globalThis.sessionStorage?.setItem('kondomanager:scheda', 'a1b2c3d4');
});

/** Il tempo che passa: i timer della pagina, del disco finto e delle risposte. */
const passa = (ms = 50) => vi.advanceTimersByTimeAsync(ms);

let n = 0;
const numero = () => `00000000-0000-4000-a000-${String(++n).padStart(12, '0')}`;

function apri(pagina: PaginaDelVerbale, opzioni: OpzioniDelVerbaleSenzaRete & { leggiLoStato: OpzioniDelVerbaleSenzaRete['leggiLoStato'] }) {
    let api!: ReturnType<typeof useVerbaleSenzaRete>;
    const prop = ref(pagina);
    w = mount(defineComponent({ setup: () => {
        api = useVerbaleSenzaRete(prop, { operaio: false, ogniMs: 60_000, letturaOgniMs: 0, nomeUtente: 'Andrea Costa', utente: 5,
            spazio: { persist: async () => true, estimate: async () => ({ quota: 5e10 }) }, numero, ...opzioni });
        return () => h('div');
    } }));

    return { api: () => api, prop };
}

async function aperto(pagina: PaginaDelVerbale, opzioni: OpzioniDelVerbaleSenzaRete & { leggiLoStato: OpzioniDelVerbaleSenzaRete['leggiLoStato'] }) {
    const a = apri(pagina, opzioni);
    const pronta = a.api().avviata();
    await passa(100);
    await pronta;

    return a;
}

/** Scrive e lascia la pausa: una modifica. */
async function scriviEPausa(api: ReturnType<typeof useVerbaleSenzaRete>, parte: string, testi: string[]) {
    for (const t of testi) {
        api.scrivi(parte, doc(t));
        await passa(200);
    }
    await passa(3100);
}

describe('il verbale senza rete, da un capo all\'altro', () => {
    test('la catena: le lettere senza rete → il disco → la scritta → la rete torna → il lotto del file in comune → la revisione nel server → niente nel browser',
        async () => {
            n = 0;
            const fabbrica = new IndexedDbFinto();
            const disco = () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
            const { s, invia, leggiLoStato } = server();
            let rete = false;
            const { api } = await aperto(paginaDiProva(), { apriDeposito: disco, invia, leggiLoStato, inLinea: () => rete });
            expect(api().dispositivo.value).toBe(PORTATILE);

            // Tre modifiche alla discussione del punto 4, fra le 20:16 e le 20:18, senza rete.
            await scriviEPausa(api(), 'punto:31', ['I', 'I prev', 'I preventivi']);
            vi.setSystemTime(new Date('2026-12-15T19:17:00Z'));
            await scriviEPausa(api(), 'punto:31', ['I preventivi sono arrivati']);
            vi.setSystemTime(new Date('2026-12-15T19:18:00Z'));
            await scriviEPausa(api(), 'punto:31', ['I preventivi sono arrivati il giorno prima.']);

            // Sul disco un gesto solo per la parte, l'ultimo testo, con la sua base.
            const sulDisco = await (async () => {
                const p = disco();
                await passa(10);
                const d = await p;
                const v = d.inCoda('verbale:12:5');
                await passa(10);
                return v;
            })();
            expect(sulDisco.map((v) => [v.tipo, (v.dati as { parte: string }).parte, v.base])).toEqual([['testo', 'punto:31', 0]]);
            expect((sulDisco[0].dati as { documento: unknown }).documento).toEqual(doc('I preventivi sono arrivati il giorno prima.'));
            expect(api().scritta.value).toEqual({ stato: 'senza_rete', titolo: 'Non salvato: senza connessione. Lo tengo qui, nel browser.', dettaglio: '3 modifiche in attesa' });
            expect(api().vista.value['punto:31']).toMatchObject({ documento: doc('I preventivi sono arrivati il giorno prima.'), qui: true });

            // La rete torna alle 20:31: parte il lotto del file in comune.
            vi.setSystemTime(new Date('2026-12-15T19:31:00Z'));
            s.rete = true;
            rete = true;
            s.lotti = [];
            globalThis.dispatchEvent(new Event('online'));
            await passa(200);
            const atteso = JSON.parse(JSON.stringify(fileInComune.lotti).replaceAll('"{verbale}"', '7').replaceAll('{punto4}', '31'));
            expect(s.lotti).toEqual(atteso);
            expect(s.parti.get('punto:31')).toMatchObject({ documento: doc('I preventivi sono arrivati il giorno prima.'), revisione: 1 });
            expect(api().scritta.value).toEqual({ stato: 'di_nuovo_in_linea', titolo: 'Di nuovo in linea: salvato alle 20:31', dettaglio: 'le 3 modifiche sono partite' });

            // Niente resta nel browser: né il gesto, né le modifiche in attesa.
            const dopo = disco();
            await passa(10);
            const d = await dopo;
            const coda = d.inCoda('verbale:12:5');
            const modifiche = d.leggi<{ modifiche: unknown[] }>('verbale:12:5:modifiche');
            await passa(10);
            expect(await coda).toEqual([]);
            expect((await modifiche)?.modifiche).toEqual([]);

            // Si continua a scrivere: la base è la revisione arrivata, e la scritta torna «Salvato».
            api().scrivi('punto:31', doc('I preventivi sono arrivati il giorno prima. Si rinvia.'));
            await passa(3500);
            expect(s.lotti.at(-1)!.gesti[0].base).toBe(1);
            expect(s.parti.get('punto:31')!.revisione).toBe(2);
            expect(api().scritta.value).toEqual({ stato: 'salvato', titolo: 'Salvato alle 20:31', dettaglio: 'tutto sul server' });
        });

    test('il computer spento e riacceso: la pagina riaperta senza rete ha il testo scritto qui e «Ho ritrovato…»; con la rete «e le ho mandate»', async () => {
        const fabbrica = new IndexedDbFinto();
        const disco = () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        const { s, invia, leggiLoStato } = server();
        const prima = await aperto(paginaDiProva(), { apriDeposito: disco, invia, leggiLoStato, inLinea: () => false });
        await scriviEPausa(prima.api(), 'intero', ['Il giorno 15 dicembre 2026, alle ore 18:42']);
        vi.setSystemTime(new Date('2026-12-15T19:18:00Z'));
        await scriviEPausa(prima.api(), 'punto:31', ['Rossi chiede i preventivi']);
        // Spento di colpo: la pagina se ne va senza salutare.
        w!.unmount();
        w = null;

        vi.setSystemTime(new Date('2026-12-15T19:30:00Z'));
        let rete = false;
        const dopo = await aperto(paginaDiProva(), { apriDeposito: disco, invia, leggiLoStato, inLinea: () => rete });
        expect(dopo.api().riapertaSenzaRete.value).toEqual({ datiFinoAlle: '2026-12-15T19:10:00Z' });
        expect(dopo.api().vista.value.intero).toMatchObject({ documento: doc('Il giorno 15 dicembre 2026, alle ore 18:42'), revisione: 2, qui: true });
        expect(dopo.api().ritrovato.value?.frasi).toEqual({ titolo: 'Ho ritrovato 2 modifiche che non erano partite: partono da sole quando torna la rete.',
            righe: ['Del 15/12/2026, dalle 20:16 alle 20:18: una correzione al verbale e una correzione alla discussione del punto 4.'] });

        vi.setSystemTime(new Date('2026-12-15T19:31:00Z'));
        s.rete = true;
        rete = true;
        globalThis.dispatchEvent(new Event('online'));
        await passa(300);
        expect(dopo.api().ritrovato.value?.frasi).toEqual({ titolo: 'Ho ritrovato 2 modifiche che non erano partite, e le ho mandate.',
            righe: ['Del 15/12/2026, dalle 20:16 alle 20:18: una correzione al verbale e una correzione alla discussione del punto 4.', 'Arrivate alle 20:31.'] });
        expect(s.parti.get('intero')?.documento).toEqual(doc('Il giorno 15 dicembre 2026, alle ore 18:42'));
        expect(s.parti.get('punto:31')?.documento).toEqual(doc('Rossi chiede i preventivi'));
        // La stessa macchina non è in conflitto con sé stessa: un altro salvataggio dalla base di prima si applica.
        expect(s.inAttesa).toEqual([]);
    });

    test('le due versioni: il tablet ha scritto la stessa parte; niente si sovrascrive, la parte si ferma e la scritta non dice «salvato»', async () => {
        const { s, invia, leggiLoStato } = server();
        s.parti.set('punto:31', { documento: doc('dal tablet'), revisione: 1, scrittori: [[1, 'tablet di Marco Galli#ab12cd34']] });
        s.rete = true;
        const pagina = paginaDiProva();
        const { api } = await aperto(pagina, { invia, leggiLoStato, inLinea: () => true });
        // La pagina era aperta prima che il tablet scrivesse: la discussione del punto 4 per lei è vuota (base 0).
        expect(api().scrivi('punto:31', doc('dal portatile'))).toBe(true);
        await passa(3500);
        expect(s.inAttesa).toEqual([{ parte: 'punto:31', dispositivo: expect.any(String), documento: doc('dal portatile') }]);
        expect(s.parti.get('punto:31')?.documento).toEqual(doc('dal tablet'));
        expect(api().bloccata('punto:31')).toBe(true);
        expect(api().scrivi('punto:31', doc('ancora'))).toBe(false);
        expect(api().bloccata('intero')).toBe(false);
    });

    test('la sessione scaduta: il 419 non toglie niente; quando si rientra il testo parte', async () => {
        const { s, invia, leggiLoStato } = server();
        s.rete = true;
        s.stato = 419;
        const { api } = await aperto(paginaDiProva(), { invia, leggiLoStato, inLinea: () => true });
        await scriviEPausa(api(), 'intero', ['Testo dopo due ore di assemblea']);
        expect(api().scritta.value.stato).toBe('sessione_scaduta');
        expect(api().voci().map((v) => v.tipo)).toEqual(['testo']);
        s.stato = 200;
        await api().salvaOra();
        await passa(100);
        expect(s.parti.get('intero')?.documento).toEqual(doc('Testo dopo due ore di assemblea'));
        expect(api().voci()).toEqual([]);
    });

    test('il testo scritto nei primi istanti, prima che il deposito sia aperto, non si perde: va sul disco appena si può', async () => {
        const { invia, leggiLoStato } = server();
        const a = apri(paginaDiProva(), { invia, leggiLoStato, inLinea: () => false });
        expect(a.api().scrivi('intero', doc('Subito'))).toBe(true);
        const pronta = a.api().avviata();
        await passa(100);
        await pronta;
        await passa(400);
        expect(a.api().voci().map((v) => (v.dati as { documento: unknown }).documento)).toEqual([doc('Subito')]);
    });

    test('chiudendo la pagina con il testo in attesa il browser lo chiede prima; senza niente in attesa no', async () => {
        const { invia, leggiLoStato } = server();
        const { api } = await aperto(paginaDiProva(), { invia, leggiLoStato, inLinea: () => false });
        const chiudi = () => {
            const e = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent;
            globalThis.dispatchEvent(e);
            return e.defaultPrevented;
        };
        expect(chiudi()).toBe(false);
        api().scrivi('intero', doc('Non chiudere'));
        expect(chiudi()).toBe(true);
    });

    test('il browser che non tiene i dati lo dice prima dell\'editor; il testo sta in memoria', async () => {
        const fabbrica = new IndexedDbFinto();
        fabbrica.rotta = true;
        const { invia, leggiLoStato } = server();
        const { api } = await aperto(paginaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => false });
        expect(api().tenuta.value).toBe('non_tiene');
        await scriviEPausa(api(), 'intero', ['In memoria']);
        expect(api().voci().map((v) => v.tipo)).toEqual(['testo']);
    });

    test('il verbale registrato e arrivato al server: ciò che resta nel browser si cancella; il testo non si scrive più', async () => {
        const fabbrica = new IndexedDbFinto();
        const disco = () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        const { invia, leggiLoStato } = server();
        const { api, prop } = await aperto(paginaDiProva(), { apriDeposito: disco, invia, leggiLoStato, inLinea: () => true });
        expect(api().cancellato.value).toBe(false);
        prop.value = { ...paginaDiProva({ stato: 'registrato', numero: 42 }), ora_del_server: '2026-12-15T19:40:00Z' };
        await passa(100);
        expect(api().cancellato.value).toBe(true);
        expect(api().bloccata('intero')).toBe(true);
        const p = disco();
        await passa(10);
        const stato = (await p).leggi('verbale:12:5:stato');
        await passa(10);
        expect(await stato).toBeUndefined();
    });
});

describe('la pagina senza rete (N7)', () => {
    test('la pagina del verbale registra lo stesso operaio della sala, nella cartella dell\'assemblea, e gli chiede di tenere sé stessa', async () => {
        vi.useRealTimers();
        const registrati: { script: string; ambito: string }[] = [];
        const messaggi: unknown[] = [];
        const attivo = { postMessage: (m: unknown) => messaggi.push(m) };
        const contenitore = { register: async (script: string, o: { scope: string }) => {
            registrati.push({ script, ambito: o.scope });
            return { active: attivo, update: async () => undefined };
        }, ready: Promise.resolve({ active: attivo }), addEventListener: () => undefined } as unknown as ServiceWorkerContainer;
        const sicuro = Object.getOwnPropertyDescriptor(globalThis, 'isSecureContext');
        Object.defineProperty(globalThis, 'isSecureContext', { value: true, configurable: true });
        try {
            await registraLOperaio('http://localhost/admin/gestionale/5/assemblee/12/il-verbale?verbale=7', contenitore, '/admin/gestionale/5/assemblee/12/sala');
        } finally {
            if (sicuro) Object.defineProperty(globalThis, 'isSecureContext', sicuro);
            else delete (globalThis as { isSecureContext?: boolean }).isSecureContext;
        }
        expect(registrati).toEqual([{ script: '/admin/gestionale/5/assemblee/12/sala/senza-rete', ambito: '/admin/gestionale/5/assemblee/12/' }]);
        expect(messaggi).toEqual([{ tipo: 'tieni', indirizzo: 'http://localhost/admin/gestionale/5/assemblee/12/il-verbale?verbale=7' }]);
    });
});
