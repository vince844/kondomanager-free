// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La sala senza rete come la usa la pagina, da un capo all'altro con il deposito sul disco finto e un server finto:
 *
 * 1. il gesto segnato senza rete → il deposito → la sala vista con la riga «su questo computer» e la barra contata sul posto → la rete torna → il gesto parte
 *    con le sue due ore → la coda si svuota e la sala rilegge lo stato del server;
 * 2. il computer spento senza rete e riacceso → «Ho riaperto la sala senza rete» con l'ora dei dati del server tenuti → la rete torna → «Ho ritrovato…» → il
 *    voto arriva una volta sola, anche se la risposta della prima volta si era persa;
 * 3. il deposito che non si apre (la finestra privata) → la striscia «non tiene», e il lavoro sta in memoria;
 * 4. il nome del dispositivo, che resta; la sala chiusa e arrivata al server → il lavoro tenuto nel browser si cancella;
 * 5. dopo un gesto arrivato la sala vista non torna mai a com'era prima: il gesto resta a video finché arriva uno stato che lo contiene, e una rilettura
 *    partita prima della risposta non la riporta indietro.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref, watch } from 'vue';
import type { SalaDellAssemblea } from '@/types/assemblee';
import { apriDepositoIndexedDb, DepositoInMemoria } from './deposito';
import { IndexedDbFinto } from './indexedDbFinto';
import type { LottoDaMandare, RispostaDelServer } from './invio';
import { ErroreDellInvio } from './invio';
import { PERSONE, salaDiProva } from './salaDiProva';
import { useSalaSenzaRete, type OpzioniDellaSalaSenzaRete } from './useSalaSenzaRete';

/** Il server finto della sala: conta una volta un numero già arrivato, dà lo stato con l'ora. */
function server() {
    const s = { rete: true, arrivati: new Map<string, LottoDaMandare['gesti'][number]>(), lotti: [] as LottoDaMandare[], letture: 0, ora: '2026-12-15T19:31:00Z',
        perdiLaRisposta: false };
    const invia = async (l: LottoDaMandare): Promise<RispostaDelServer> => {
        s.lotti.push(JSON.parse(JSON.stringify(l)));
        if (!s.rete) throw new ErroreDellInvio('rete');
        const risposte = l.gesti.map((g) => {
            const gia = s.arrivati.has(g.gesto);
            if (!gia) s.arrivati.set(g.gesto, g);
            return { gesto: g.gesto, tipo: g.tipo, esito: gia ? 'doppio' : 'applicato', messaggio: null };
        });
        if (s.perdiLaRisposta) {
            s.perdiLaRisposta = false;
            throw new ErroreDellInvio('rete');
        }
        return { ora_del_server: s.ora, risposte };
    };
    const leggiLoStato = async (): Promise<SalaDellAssemblea> => {
        if (!s.rete) throw new Error('senza rete');
        s.letture += 1;
        return { ...salaDiProva(), ora_del_server: s.ora };
    };
    return { s, invia, leggiLoStato };
}

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
});

function apri(sala: SalaDellAssemblea, opzioni: OpzioniDellaSalaSenzaRete) {
    let api!: ReturnType<typeof useSalaSenzaRete>;
    const prop = ref(sala);
    w = mount(defineComponent({ setup: () => {
        api = useSalaSenzaRete(prop, { operaio: false, ogniMs: 60_000, nomeUtente: 'Andrea Costa', spazio: { persist: async () => true, estimate: async () => ({ quota: 5e10 }) },
            ...opzioni });
        return () => h('div');
    } }));

    return { api: () => api, prop };
}

let n = 0;
const numero = () => `00000000-0000-4000-9000-${String(++n).padStart(12, '0')}`;

describe('la sala senza rete, da un capo all\'altro', () => {
    test('catena 1: il gesto senza rete → il disco → la sala vista «su questo computer» → la rete torna → parte con le sue due ore → lo stato del server', async () => {
        const fabbrica = new IndexedDbFinto();
        const { s, invia, leggiLoStato } = server();
        s.rete = false;
        let rete = false;
        const { api } = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => rete, numero });
        await api().avviata();
        await api().segna('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, 0);
        await flushPromises();

        // Sul disco, e a video con la riga «su questo computer» e la barra contata qui.
        const suDisco = await (await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory)).inCoda('sala:7');
        expect(suDisco.map((v) => v.tipo)).toEqual(['entra']);
        expect(api().salaVista.value.in_coda).toEqual(['presenza:anagrafica:1']);
        expect(api().salaVista.value.barra!.barra.startsWith('4 su 26')).toBe(true);
        expect(api().striscia.value).toMatchObject({ stato: 'senza_rete', inCoda: 1 });

        // La rete torna.
        s.rete = true;
        rete = true;
        globalThis.dispatchEvent(new Event('online'));
        await flushPromises();
        await api().prova();
        await flushPromises();
        const arrivato = [...s.arrivati.values()][0];
        expect(arrivato).toMatchObject({ tipo: 'entra', base: 0 });
        expect(typeof arrivato.segnato_il).toBe('string');
        expect(typeof arrivato.orologio).toBe('string');
        expect(api().striscia.value).toMatchObject({ stato: 'in_linea', inCoda: 0 });
        expect(s.letture).toBeGreaterThan(0);
        expect(api().salaVista.value.ora_del_server).toBe('2026-12-15T19:31:00Z');
        expect(api().salaVista.value.in_coda).toEqual([]);
    });

    test('catena 2: spento senza rete e riacceso → «Ho riaperto la sala senza rete» → la rete torna → «Ho ritrovato…» → il voto arriva una volta', async () => {
        const fabbrica = new IndexedDbFinto();
        const { s, invia, leggiLoStato } = server();
        s.rete = false;
        // Prima dello spegnimento: lo stato del server delle 20:09 sul disco, e due gesti segnati senza rete.
        const prima = apri({ ...salaDiProva(), ora_del_server: '2026-12-15T19:09:00Z' }, { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory),
            invia, leggiLoStato, inLinea: () => false, numero });
        await prima.api().avviata();
        const voto = await prima.api().segna('voto', { votazione_id: 12, testa: 'p-4', voto: 'favorevole' }, 0);
        await prima.api().segna('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, testo: 'A verbale.' });
        await flushPromises();
        w!.unmount();
        w = null;

        // Riacceso: la pagina (la copia dell'operaio) ha lo stato di prima delle 20:09; la rete è ancora giù.
        const dopo = apri({ ...salaDiProva(), ora_del_server: '2026-12-15T18:50:00Z' }, { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory),
            invia, leggiLoStato, inLinea: () => s.rete, numero });
        await dopo.api().avviata();
        await flushPromises();
        expect(dopo.api().riapertaSenzaRete.value).toEqual({ datiFinoAlle: '2026-12-15T19:09:00Z' });
        expect(dopo.api().salaVista.value.ora_del_server).toBe('2026-12-15T19:09:00Z');
        expect(dopo.api().foto.value.ritrovati).toMatchObject({ quanti: 2, finito: false });
        expect(dopo.api().salaVista.value.in_coda).toContain('voto:12:p-4');

        // La rete torna, ma la risposta del primo invio si perde: il voto riparte, e il server lo conta una volta.
        s.rete = true;
        s.lotti = [];
        s.perdiLaRisposta = true;
        await dopo.api().prova();
        await dopo.api().prova();
        await flushPromises();
        expect(s.lotti.filter((l) => l.gesti.some((g) => g.gesto === voto.gesto)).length).toBe(2);
        expect([...s.arrivati.keys()].filter((k) => k === voto.gesto).length).toBe(1);
        expect(dopo.api().foto.value.ritrovati).toMatchObject({ quanti: 2, finito: true });
        expect(dopo.api().foto.value.ritrovati!.esiti.map((e) => e.esito)).toEqual(['doppio', 'doppio']);
        await vi.waitFor(() => expect(dopo.api().riapertaSenzaRete.value).toBeNull());
    });

    test('catena 3: il deposito che non si apre (la finestra privata) → la striscia dice che il browser non tiene, e il lavoro sta in memoria', async () => {
        const fabbrica = new IndexedDbFinto();
        fabbrica.rotta = true;
        const { invia, leggiLoStato } = server();
        const { api } = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => true, numero });
        await api().avviata();
        expect(api().tenuta.value).toBe('non_tiene');
        expect(api().striscia.value.stato).toBe('non_tiene');
        await api().segna('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, 0);
        await flushPromises();
        await api().prova();
        expect(api().striscia.value.stato).toBe('non_tiene');
    });

    test('catena 4: il nome del dispositivo resta; la sala chiusa e arrivata al server cancella il lavoro tenuto nel browser', async () => {
        const fabbrica = new IndexedDbFinto();
        const { invia, leggiLoStato } = server();
        const uno = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => true, numero });
        await uno.api().avviata();
        const nome = uno.api().dispositivo.value;
        expect(nome).toMatch(/^computer di Andrea Costa#[0-9a-f]{8}$/);
        w!.unmount();
        const due = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => true, numero });
        await due.api().avviata();
        expect(due.api().dispositivo.value).toBe(nome);

        const deposito = await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        expect(await deposito.leggi('sala:7:stato')).toBeDefined();
        due.prop.value = { ...salaDiProva(), ora_del_server: '2026-12-15T19:45:00Z', assemblea: { ...salaDiProva().assemblea, stato: 'chiusa' } };
        await nextTick();
        await vi.waitFor(() => expect(due.api().cancellato.value).toBe(true));
        expect(await deposito.leggi('sala:7:stato')).toBeUndefined();
        expect(await deposito.leggi('dispositivo')).toBe(nome);
    });
});

describe('niente resta nel browser che non deve, e la pagina sa che ore sono', () => {
    test('anche a sala annullata (rinviata) o deserta il lavoro tenuto nel browser si cancella', async () => {
        for (const stato of ['annullata', 'deserta'] as const) {
            const fabbrica = new IndexedDbFinto();
            const { invia, leggiLoStato } = server();
            const sala = { ...salaDiProva(), assemblea: { ...salaDiProva().assemblea, stato } };
            const { api } = apri(sala, { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => true, numero });
            await api().avviata();
            await vi.waitFor(() => expect(api().cancellato.value).toBe(true));
            expect(await (await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory)).leggi('sala:7:stato')).toBeUndefined();
            w!.unmount();
            w = null;
        }
    });

    test('la coda è di chi l\'ha segnata: i gesti di un utente non partono con la sessione di un altro sullo stesso browser', async () => {
        const fabbrica = new IndexedDbFinto();
        const { s, invia, leggiLoStato } = server();
        s.rete = false;
        const deposito = () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        const andrea = apri(salaDiProva(), { apriDeposito: deposito, invia, leggiLoStato, inLinea: () => false, numero, utente: 5 });
        await andrea.api().avviata();
        await andrea.api().segna('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, 0);
        // Il tentativo di Andrea senza rete finisce prima che la rete torni.
        await andrea.api().prova();
        w!.unmount();

        s.rete = true;
        s.lotti = [];
        const marco = apri(salaDiProva(), { apriDeposito: deposito, invia, leggiLoStato, inLinea: () => true, numero, utente: 6 });
        await marco.api().avviata();
        await marco.api().prova();
        expect(s.lotti).toEqual([]);
        expect(marco.api().foto.value.inCoda).toEqual([]);
        expect((await (await deposito()).inCoda('sala:7:5')).map((v) => v.tipo)).toEqual(['entra']);
    });

    test('la lettura periodica con la sessione scaduta o il server fermo non apre niente: la striscia lo dice', async () => {
        const fabbrica = new IndexedDbFinto();
        const { invia } = server();
        let errore: unknown = Object.assign(new Error('401'), { isAxiosError: true, response: { status: 401 } });
        const leggiLoStato = async (): Promise<SalaDellAssemblea> => {
            throw errore;
        };
        const { api } = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => true, numero });
        await api().avviata();
        await api().aggiorna();
        expect(api().striscia.value.stato).toBe('sessione_scaduta');

        w!.unmount();
        errore = Object.assign(new Error('502'), { isAxiosError: true, response: { status: 502 } });
        const due = apri(salaDiProva(), { apriDeposito: () => apriDepositoIndexedDb(new IndexedDbFinto() as unknown as IDBFactory), invia, leggiLoStato,
            inLinea: () => true, numero });
        await due.api().avviata();
        await due.api().aggiorna();
        expect(due.api().striscia.value).toMatchObject({ stato: 'senza_rete', serverFermo: true });
    });

    test('l\'ora della pagina è quella dell\'orologio della coda (lo scarto tenuto), non quella della copia della pagina', async () => {
        const fabbrica = new IndexedDbFinto();
        const deposito = await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        await deposito.scrivi('sala:7:scarto', 600_000);
        const { invia, leggiLoStato } = server();
        const vecchia = { ...salaDiProva(), ora_del_server: '2026-12-15T14:00:00Z' };
        const { api } = apri(vecchia, { apriDeposito: () => apriDepositoIndexedDb(fabbrica as unknown as IDBFactory), invia, leggiLoStato, inLinea: () => false, numero,
            adesso: () => Date.parse('2026-12-15T17:25:00Z') });
        await api().avviata();
        expect(api().adesso().toISOString()).toBe('2026-12-15T17:35:00.000Z');
    });
});

/** Una promessa che si risolve quando lo dice la prova: la rilettura dello stato o l'invio che non ha ancora risposto. */
function inAttesa<T>() {
    let risolvi!: (x: T) => void;
    const promessa = new Promise<T>((r) => (risolvi = r));
    return { promessa, risolvi };
}

describe('dopo un gesto arrivato la sala vista non torna mai a com\'era prima (lo sfarfallio)', () => {
    const conRossi = (ora: string): SalaDellAssemblea => {
        const s = salaDiProva();
        s.presenze.push({ id: 90, chiave: 'anagrafica:1', anagrafica_id: PERSONE.rossi, nome: 'Rossi Mario', come: 'in_sala', entrata_il: '2026-12-15T19:30:00Z',
            uscita_il: null, caduto: false, rappresenta: null, motivo_collegamento: null } as never);
        s.versioni['anagrafica:1'] = 1;
        s.dichiarazioni.push({ id: 70, seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, nome: 'Romano Ida', testo: 'A verbale.' });
        return { ...s, ora_del_server: ora };
    };
    const rossiDentro = (s: SalaDellAssemblea) => s.presenze.filter((p) => p.chiave === 'anagrafica:1' && p.uscita_il === null).length;

    test('fra la risposta dell\'invio e la rilettura il gesto resta a video; arrivato lo stato che lo contiene, c\'è una volta sola', async () => {
        const { invia } = server();
        const letture: ReturnType<typeof inAttesa<SalaDellAssemblea>>[] = [];
        const leggiLoStato = () => {
            const l = inAttesa<SalaDellAssemblea>();
            letture.push(l);
            return l.promessa;
        };
        const { api } = apri(salaDiProva(), { apriDeposito: async () => new DepositoInMemoria(), invia, leggiLoStato, inLinea: () => true, numero, letturaOgniMs: 0 });
        await api().avviata();
        const visto: number[] = [];
        watch(() => api().salaVista.value, (s) => visto.push(rossiDentro(s)), { flush: 'sync' });

        await api().segna('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, 0);
        await api().segna('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, testo: 'A verbale.' });
        await api().prova();
        await flushPromises();
        // L'invio ha risposto «applicato»: la coda è vuota, e la rilettura (partita da sola dopo la risposta) non è ancora tornata.
        expect(api().foto.value.inCoda).toEqual([]);
        expect(letture.length).toBeGreaterThan(0);
        expect(rossiDentro(api().salaVista.value)).toBe(1);
        expect(api().salaVista.value.dichiarazioni.map((d) => d.testo)).toEqual(['A verbale.']);
        // Arrivati al server, non sono più «su questo computer».
        expect(api().salaVista.value.in_coda).toEqual([]);

        for (const l of letture) l.risolvi(conRossi('2026-12-15T19:31:00Z'));
        await flushPromises();
        expect(api().salaVista.value.ora_del_server).toBe('2026-12-15T19:31:00Z');
        expect(rossiDentro(api().salaVista.value)).toBe(1);
        expect(api().salaVista.value.dichiarazioni).toHaveLength(1);
        expect(api().salaVista.value.in_coda).toEqual([]);
        // Mai un momento in cui la sala è tornata a prima del gesto.
        expect(visto.length).toBeGreaterThan(0);
        expect(visto.slice(visto.indexOf(1))).not.toContain(0);
    });

    test('una rilettura partita prima della risposta (lo stato di prima) non riporta indietro la sala: vale quella partita dopo', async () => {
        const { s, invia } = server();
        const risposta = inAttesa<void>();
        const invioLento = async (l: LottoDaMandare) => {
            await risposta.promessa;
            return invia(l);
        };
        const letture: ReturnType<typeof inAttesa<SalaDellAssemblea>>[] = [];
        const leggiLoStato = () => {
            const l = inAttesa<SalaDellAssemblea>();
            letture.push(l);
            return l.promessa;
        };
        const { api } = apri(salaDiProva(), { apriDeposito: async () => new DepositoInMemoria(), invia: invioLento, leggiLoStato, inLinea: () => true, numero,
            letturaOgniMs: 0 });
        await api().avviata();
        const visto: number[] = [];
        watch(() => api().salaVista.value, (x) => visto.push(rossiDentro(x)), { flush: 'sync' });

        await api().segna('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, 0);
        await flushPromises();
        // La lettura periodica parte mentre il gesto è in viaggio: il server la serve prima del gesto.
        const vecchia = api().aggiorna();
        risposta.risolvi();
        await api().prova();
        await flushPromises();
        expect(s.arrivati.size).toBe(1);
        // La vecchia torna dopo la risposta, con lo stato di prima del gesto (e un'ora più recente di quella tenuta).
        letture[0].risolvi({ ...salaDiProva(), ora_del_server: '2026-12-15T19:31:00Z' });
        await vecchia;
        await flushPromises();
        expect(rossiDentro(api().salaVista.value)).toBe(1);
        expect(api().striscia.value.stato).toBe('in_linea');

        // La rilettura partita dopo la risposta porta il gesto.
        expect(letture).toHaveLength(2);
        letture[1].risolvi(conRossi('2026-12-15T19:31:00Z'));
        await flushPromises();
        expect(rossiDentro(api().salaVista.value)).toBe(1);
        expect(api().salaVista.value.ora_del_server).toBe('2026-12-15T19:31:00Z');
        expect(visto.slice(visto.indexOf(1))).not.toContain(0);
    });

    test('il voto ancora in coda su una votazione aperta senza rete resta a video quando lo stato porta la votazione con il suo numero vero', async () => {
        const lotti: LottoDaMandare[] = [];
        const secondo = inAttesa<void>();
        let rete = false;
        const invia = async (l: LottoDaMandare): Promise<RispostaDelServer> => {
            if (!rete) throw new ErroreDellInvio('rete');
            lotti.push(l);
            if (lotti.length > 1) await secondo.promessa;
            return { ora_del_server: '2026-12-15T19:31:00Z', risposte: l.gesti.map((g) => ({ gesto: g.gesto, tipo: g.tipo, esito: 'applicato', messaggio: null,
                ...(g.tipo === 'si_vota' ? { votazione_id: 99 } : {}) })) };
        };
        const letture: ReturnType<typeof inAttesa<SalaDellAssemblea>>[] = [];
        const leggiLoStato = () => {
            const l = inAttesa<SalaDellAssemblea>();
            letture.push(l);
            return l.promessa;
        };
        const { api } = apri(salaDiProva(), { apriDeposito: async () => new DepositoInMemoria(), invia, leggiLoStato, inLinea: () => rete, numero, letturaOgniMs: 0 });
        await api().avviata();
        const siVota = await api().segna('si_vota', { seduta_id: 8, punto_id: 10, numero: 1 });
        const provvisoria = api().salaVista.value.punti[0].votazioni[0] as { riferimento?: unknown };
        await api().segna('voto', { votazione_id: provvisoria.riferimento, testa: 'p-3', voto: 'favorevole' }, 0);
        const votiDelPunto1 = () => api().salaVista.value.punti[0].votazioni.flatMap((v) => v.voti).map((v) => v.testa);
        expect(votiDelPunto1()).toEqual(['p-3']);
        const visto: number[] = [];
        watch(() => api().salaVista.value, () => visto.push(votiDelPunto1().length), { flush: 'sync' });

        rete = true;
        void api().prova();
        await flushPromises();
        // «Si vota» è arrivato (la votazione 99), il voto è in viaggio; lo stato riletto ha la votazione 99 senza voti.
        expect(lotti.map((l) => l.gesti.map((g) => g.tipo))).toEqual([['si_vota'], ['voto']]);
        expect(api().foto.value.esiti.map((e) => e.gesto)).toEqual([siVota.gesto]);
        const stato = salaDiProva();
        stato.punti[0].votazioni = [{ ...stato.punti[1].votazioni[0], id: 99, punto_id: 10, maggioranza: 'ordinaria' }];
        letture[letture.length - 1].risolvi({ ...stato, ora_del_server: '2026-12-15T19:31:00Z' });
        await flushPromises();
        expect(api().salaVista.value.punti[0].votazioni.map((v) => v.id)).toEqual([99]);
        expect(votiDelPunto1()).toEqual(['p-3']);
        expect(visto).not.toContain(0);
        secondo.risolvi();
        await flushPromises();
    });
});
