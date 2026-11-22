// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { Deposito, VoceInCoda } from './deposito';
import { ErroreDellInvio, type GestoDaMandare, type LottoDaMandare, type RispostaDelServer } from './invio';
import { nuovoNumero } from './numero';
import { Orologio } from './orologio';
import { seLaSchedaManda } from './schede';

/**
 * La coda dei gesti (decisioni 514 (5) e 519): ogni gesto **prima sul disco, poi a video**, poi verso il server, in ordine, un lotto per volta. **Generica**:
 * la usano la sala e il verbale.
 *
 * - **Prima sul disco**: {@link CodaDeiGesti.segna} scrive il gesto nel deposito e solo dopo risponde: la pagina cambia quando il gesto è salvato. Spegnendo di
 *   colpo si perde al massimo l'ultimo istante.
 * - **L'invio**: quando torna la rete, ogni pochi secondi con gesti in coda, alla riapertura della pagina; un lotto di al più 500 gesti, nell'ordine; il server
 *   risponde gesto per gesto, e **un gesto si toglie dalla coda solo dopo la sua risposta**. Senza risposta (la rete, il server, la sessione scaduta) non si
 *   toglie niente.
 * - **Niente si perde** (decisione 519): un gesto che il server non accetta, o che non parte perché dipende da un gesto non accettato, non si toglie dal
 *   computer: passa fra i gesti **da rivedere** (nel deposito, con il perché), che la pagina mostra. «Rimanda» li rimette in coda nell'ordine, con un numero
 *   nuovo (il server ricorda il numero di allora) e i riferimenti fra loro aggiornati; «Togli» ne toglie uno, solo per scelta.
 * - **L'ordine** è un contatore tenuto nel deposito, non l'ora: un orologio che torna indietro non cambia l'ordine dei gesti.
 * - **Il numero**: ogni gesto ha il suo ({@link nuovoNumero}, anche su un indirizzo senza https): se arriva due volte il server lo conta una volta («doppio»).
 * - **Dal foglio della sala** il gesto porta l'ora scritta sul foglio ({@link CodaDeiGesti.segna}, `segnatoIl`); l'ora del dispositivo resta quella vera.
 * - **I riferimenti**: un gesto può dipendere da un gesto ancora in coda (il voto di una votazione aperta senza rete: il numero della votazione lo dà il
 *   server). Nei dati si scrive {@link riferimento}`(gesto, 'votazione_id')`; il lotto si ferma prima del gesto che aspetta, e al lotto dopo il riferimento
 *   è il numero vero. Ciò che il server ha risposto si scrive nel deposito **prima** di togliere i gesti, così anche dopo uno spegnimento si ritrova.
 * - **Il lavoro ritrovato**: i gesti di una pagina di prima (il computer riacceso, il browser riaperto) sono «ritrovati», con le loro ore; partono da soli, e la
 *   coda dice quanti sono arrivati e quanti hanno due versioni.
 * - **Una scheda sola manda** (il lucchetto del browser); l'orologio corregge le ore con lo scarto del server.
 * - **Il gesto che sostituisce** (il testo del verbale): un gesto segnato con una chiave di fusione ({@link OpzioniDelGesto.fondi}) prende il posto di quello
 *   con la stessa chiave che aspetta ancora di partire, così per ogni parte del testo c'è **un solo gesto in attesa**, l'ultimo. Quello già partito e senza
 *   risposta resta: il nuovo parte dopo la sua risposta, nel lotto dopo. Il nuovo si scrive sul disco **prima** di togliere quello che sostituisce: spegnendo
 *   in mezzo, restano tutti e due e partono in ordine. Con `parti: false` il gesto aspetta che chi usa la coda lo mandi ({@link CodaDeiGesti.prova}): il
 *   testo si scrive sul disco a ogni lettera e parte dopo la pausa.
 */

export type StatoDellaCoda = 'in_linea' | 'senza_rete' | 'invio' | 'sessione_scaduta' | 'non_puoi';

/** Un riferimento a ciò che il server risponderà a un gesto ancora in coda. */
export interface Riferimento {
    $gesto: string;
    campo: string;
}

export const riferimento = (gesto: string, campo: string): Riferimento => ({ $gesto: gesto, campo });

export const eUnRiferimento = (x: unknown): x is Riferimento =>
    typeof x === 'object' && x !== null && typeof (x as Riferimento).$gesto === 'string' && typeof (x as Riferimento).campo === 'string';

/** Ciò che il server ha risposto a un gesto, per chi ne dipende: l'esito e i numeri che ha dato. */
export type Risolto = { esito: string } & Record<string, unknown>;

export interface EsitoDiUnGesto {
    gesto: string;
    tipo: string;
    esito: 'applicato' | 'doppio' | 'in_conflitto' | 'rifiutato';
    messaggio: string | null;
    voce: VoceInCoda;
    /** L'ora (corretta) in cui è arrivata la risposta. */
    arrivato_il: string;
    /** Il server non l'ha visto: rifiutato qui (un gesto senza la forma giusta, o che dipende da un gesto rifiutato). */
    locale: boolean;
    /** I numeri che il server ha dato al gesto (`votazione_id`, `delega_id`, `assemblea_nuova_id`…), per la pagina. */
    numeri?: Record<string, unknown>;
    /** La risposta del server com'è (per il testo del verbale: la parte, la revisione, l'impronta, il conflitto). */
    risposta?: Record<string, unknown>;
}

/** Come si segna un gesto: con una chiave di fusione, e se parte subito. */
export interface OpzioniDelGesto {
    /** La chiave di fusione: il gesto prende il posto di quello con la stessa chiave che non è ancora partito. */
    fondi?: string;
    /** `false`: il gesto resta in coda finché chi usa la coda non chiama {@link CodaDeiGesti.prova} (di serie parte subito). */
    parti?: boolean;
}

export interface Ritrovati {
    quanti: number;
    /** L'ora del primo e dell'ultimo gesto ritrovato. */
    dal: string;
    al: string;
    voci: VoceInCoda[];
    esiti: EsitoDiUnGesto[];
    /** Tutti hanno avuto la risposta. */
    finito: boolean;
    arrivati_alle: string | null;
}

/** Un gesto da rivedere: non accettato dal server, o non partito perché dipendeva da un gesto non accettato. Resta sul computer finché non si sceglie. */
export interface DaRivedere {
    voce: VoceInCoda;
    messaggio: string | null;
    /** L'ora (corretta) della risposta. */
    arrivato_il: string;
    /** Non partito: lo ha fermato questo computer (la forma, o il gesto da cui dipende), non il server. */
    locale: boolean;
}

export interface FotoDellaCoda {
    stato: StatoDellaCoda;
    inCoda: VoceInCoda[];
    /** I gesti da rivedere, i più vecchi in testa. */
    daRivedere: DaRivedere[];
    invio: { fatti: number; di: number } | null;
    /** Senza risposta perché il server non risponde (non la rete del dispositivo). */
    serverFermo: boolean;
    ritrovati: Ritrovati | null;
    /** Le ultime risposte, le più recenti in fondo. */
    esiti: EsitoDiUnGesto[];
}

export interface OpzioniDellaCoda {
    deposito: Deposito;
    /** `sala:12`. */
    ambito: string;
    dispositivo: string;
    invia: (lotto: LottoDaMandare) => Promise<RispostaDelServer>;
    orologio?: Orologio;
    sessione?: string;
    tetto?: number;
    numero?: () => string;
    adesso?: () => number;
    schede?: (nome: string, lavoro: () => Promise<void>) => Promise<boolean>;
    /**
     * Un riferimento che il server non ha dato (un gesto arrivato «doppio» non ridice i suoi numeri): chi usa la coda lo cerca da sé, nell'ultimo stato del
     * server. `undefined`: non lo trova.
     */
    cercaIlRiferimento?: (voce: VoceInCoda | undefined, campo: string, gesto: string) => unknown;
}

/** Quante risposte la coda tiene per la pagina. */
const ESITI_TENUTI = 200;

class Attende extends Error {}

class Manca extends Error {}

export class CodaDeiGesti {
    readonly sessione: string;
    private readonly orologio: Orologio;
    private readonly tetto: number;
    private readonly numero: () => string;
    private readonly adesso: () => number;
    private readonly schede: (nome: string, lavoro: () => Promise<void>) => Promise<boolean>;
    private foto: FotoDellaCoda = { stato: 'in_linea', inCoda: [], daRivedere: [], invio: null, serverFermo: false, ritrovati: null, esiti: [] };
    private ascoltatori = new Set<(foto: FotoDellaCoda) => void>();
    private inVolo: Promise<void> | null = null;
    private dopo: Promise<void> | null = null;
    private ancora = false;
    /** L'ultimo posto dato nella coda (il contatore tenuto nel deposito), caricato al primo gesto. */
    private ultimoOrdine: Promise<number> | null = null;
    /** Le voci già tolte, per ritrovare la voce di un riferimento. */
    private viste = new Map<string, VoceInCoda>();
    /** I gesti partiti e senza risposta: un gesto che sostituisce non li tocca. */
    private inVoloOra = new Set<string>();
    /** I gesti con una chiave di fusione si scrivono uno alla volta: due insieme non si toglierebbero l'un l'altro. */
    private fusioni: Promise<unknown> = Promise.resolve();

    constructor(private readonly o: OpzioniDellaCoda) {
        this.orologio = o.orologio ?? new Orologio(o.adesso);
        this.sessione = o.sessione ?? nuovoNumero();
        this.tetto = o.tetto ?? 500;
        this.numero = o.numero ?? nuovoNumero;
        this.adesso = o.adesso ?? (() => Date.now());
        this.schede = o.schede ?? ((nome, lavoro) => seLaSchedaManda(nome, lavoro));
    }

    private chiave(nome: string): string {
        return this.o.ambito + ':' + nome;
    }

    get stato(): FotoDellaCoda {
        return this.foto;
    }

    sottoscrivi(ascoltatore: (foto: FotoDellaCoda) => void): () => void {
        this.ascoltatori.add(ascoltatore);
        ascoltatore(this.foto);

        return () => this.ascoltatori.delete(ascoltatore);
    }

    private cambia(parte: Partial<FotoDellaCoda>): void {
        this.foto = { ...this.foto, ...parte };
        for (const a of this.ascoltatori) a(this.foto);
    }

    /** Alla riapertura della pagina: lo scarto dell'orologio, i gesti di prima (ritrovati), poi l'invio. */
    async avvia(): Promise<void> {
        const scarto = await this.o.deposito.leggi<number>(this.chiave('scarto'));
        if (typeof scarto === 'number') this.orologio.scarto = scarto;
        const voci = await this.o.deposito.inCoda(this.o.ambito);
        const diPrima = voci.filter((v) => v.sessione !== this.sessione);
        const ore = diPrima.map((v) => v.segnato_il).sort();
        this.cambia({
            inCoda: voci,
            daRivedere: (await this.o.deposito.leggi<DaRivedere[]>(this.chiave('da_rivedere'))) ?? [],
            ritrovati: diPrima.length === 0 ? null : { quanti: diPrima.length, dal: ore[0], al: ore[ore.length - 1], voci: diPrima, esiti: [], finito: false,
                arrivati_alle: null },
        });
        await this.prova();
    }

    /**
     * Segna un gesto: prima sul disco, poi la coda cambia (e la pagina con lei), poi parte. `base` è la versione da cui il gesto parte (null per ciò che è
     * nato senza rete). I dati possono avere dei {@link riferimento}. `segnatoIl` è l'ora già scritta (dal foglio della sala), ISO in UTC: altrimenti l'ora di
     * adesso, corretta con lo scarto.
     */
    async segna(tipo: string, dati: Record<string, unknown>, base: number | null = null, segnatoIl?: string, opzioni: OpzioniDelGesto = {}): Promise<VoceInCoda> {
        if (opzioni.fondi !== undefined) {
            const fatto = this.fusioni.then(() => this.segnaEFondi(tipo, dati, base, segnatoIl, opzioni.fondi!));
            this.fusioni = fatto.catch(() => undefined);
            const voce = await fatto;
            if (opzioni.parti !== false) void this.prova();

            return voce;
        }
        const ore = this.orologio.segna();
        const voce: VoceInCoda = { gesto: this.numero(), ambito: this.o.ambito, ordine: await this.prossimoOrdine(), sessione: this.sessione,
            tipo, dati, base, orologio: ore.orologio, segnato_il: segnatoIl ?? ore.segnato_il };
        await this.o.deposito.aggiungi(voce);
        this.cambia({ inCoda: [...this.foto.inCoda, voce] });
        if (opzioni.parti !== false) void this.prova();

        return voce;
    }

    /**
     * Il gesto che sostituisce: il nuovo sul disco, poi via quelli con la stessa chiave che non sono partiti (i partiti senza risposta restano); i ritrovati
     * sostituiti non si contano più fra quelli da mandare.
     */
    private async segnaEFondi(tipo: string, dati: Record<string, unknown>, base: number | null, segnatoIl: string | undefined, fondi: string): Promise<VoceInCoda> {
        const ore = this.orologio.segna();
        const voce: VoceInCoda = { gesto: this.numero(), ambito: this.o.ambito, ordine: await this.prossimoOrdine(), sessione: this.sessione,
            tipo, dati, base, orologio: ore.orologio, segnato_il: segnatoIl ?? ore.segnato_il, fondi };
        await this.o.deposito.aggiungi(voce);
        const sostituiti = (await this.o.deposito.inCoda(this.o.ambito)).filter((v) => v.fondi === fondi && v.gesto !== voce.gesto && !this.inVoloOra.has(v.gesto))
            .map((v) => v.gesto);
        await this.o.deposito.togli(sostituiti);
        const via = new Set(sostituiti);
        let ritrovati = this.foto.ritrovati;
        if (ritrovati && ritrovati.voci.some((v) => via.has(v.gesto))) {
            const voci = ritrovati.voci.filter((v) => !via.has(v.gesto));
            ritrovati = voci.length === 0 ? null : { ...ritrovati, voci, quanti: voci.length, finito: ritrovati.esiti.length >= voci.length };
        }
        this.cambia({ inCoda: [...this.foto.inCoda.filter((v) => !via.has(v.gesto)), voce], ritrovati });

        return voce;
    }

    /**
     * Il posto nella coda: l'ultimo dato più uno, tenuto nel deposito (anche dopo uno spegnimento), mai prima dei gesti già in coda. Non l'ora del
     * dispositivo: un orologio che torna indietro (sincronizzato al ritorno della rete) non manda un gesto prima di quello segnato prima di lui.
     */
    private prossimoOrdine(): Promise<number> {
        const prima = this.ultimoOrdine ?? (async () => {
            const tenuto = await this.o.deposito.leggi<number>(this.chiave('ordine'));
            const inCoda = (await this.o.deposito.inCoda(this.o.ambito)).map((v) => v.ordine);

            return Math.max(typeof tenuto === 'number' ? tenuto : 0, ...inCoda, 0);
        })();
        this.ultimoOrdine = prima.then((n) => n + 1);

        return this.ultimoOrdine.then(async (n) => {
            await this.o.deposito.scrivi(this.chiave('ordine'), n);
            return n;
        });
    }

    /**
     * «Rimanda»: i gesti da rivedere (tutti, o quelli dati) tornano in coda nell'ordine in cui erano, con l'ora di allora e un numero nuovo (il server ricorda
     * il numero di prima, con la sua risposta); un riferimento a un gesto rimandato insieme prende il numero nuovo. Poi partono.
     */
    async rimanda(gesti?: string[]): Promise<void> {
        const scelti = this.foto.daRivedere.filter((d) => gesti === undefined || gesti.includes(d.voce.gesto)).sort((a, b) => a.voce.ordine - b.voce.ordine);
        if (scelti.length === 0) return;
        const nuovi = new Map(scelti.map((d) => [d.voce.gesto, this.numero()]));
        const rinumera = (x: unknown): unknown => {
            if (eUnRiferimento(x)) return nuovi.has(x.$gesto) ? { ...x, $gesto: nuovi.get(x.$gesto)! } : x;
            if (Array.isArray(x)) return x.map(rinumera);
            if (typeof x === 'object' && x !== null) return Object.fromEntries(Object.entries(x).map(([k, y]) => [k, k === 'aperta_dal_gesto' && typeof y === 'string'
                && nuovi.has(y) ? nuovi.get(y) : rinumera(y)]));

            return x;
        };
        const voci: VoceInCoda[] = [];
        for (const d of scelti) {
            voci.push({ ...d.voce, gesto: nuovi.get(d.voce.gesto)!, ordine: await this.prossimoOrdine(), sessione: this.sessione,
                dati: rinumera(d.voce.dati) as Record<string, unknown> });
        }
        for (const v of voci) await this.o.deposito.aggiungi(v);
        const resto = this.foto.daRivedere.filter((d) => !nuovi.has(d.voce.gesto));
        await this.o.deposito.scrivi(this.chiave('da_rivedere'), resto);
        this.cambia({ inCoda: [...this.foto.inCoda, ...voci], daRivedere: resto });
        await this.prova();
    }

    /** «Togli»: un gesto da rivedere si toglie dal computer, solo per scelta (la pagina lo chiede prima). */
    async lascia(gesto: string): Promise<void> {
        const resto = this.foto.daRivedere.filter((d) => d.voce.gesto !== gesto);
        await this.o.deposito.scrivi(this.chiave('da_rivedere'), resto);
        this.cambia({ daRivedere: resto });
    }

    /** La lettura dello stato ha avuto una risposta che ferma il lavoro: la sessione scaduta, il permesso, il server fermo. I gesti restano. */
    fermo(motivo: 'sessione' | 'permesso' | 'server'): void {
        this.cambia({ stato: motivo === 'sessione' ? 'sessione_scaduta' : motivo === 'permesso' ? 'non_puoi' : 'senza_rete', serverFermo: motivo === 'server' });
    }

    /** La rete del dispositivo è caduta: la striscia lo dice subito, i gesti restano. */
    senzaRete(): void {
        this.cambia({ stato: 'senza_rete' });
    }

    /**
     * Il server ha risposto con lo stato della sala (la lettura): la sessione vale e il server c'è. Con la coda vuota si è in linea; con dei gesti in coda
     * fermi per la sessione o per il server, si riprova a mandarli. Chi non gestisce le assemblee resta com'è: leggere non basta per segnare.
     */
    contatto(oraDelServer?: string, partitaAlle?: number): void {
        if (oraDelServer) this.orologio.aggiorna(oraDelServer, partitaAlle);
        if (this.foto.stato === 'non_puoi' && this.foto.inCoda.length > 0) return;
        if (this.foto.inCoda.length === 0) {
            this.cambia({ stato: 'in_linea', serverFermo: false });
        } else if (this.foto.stato === 'sessione_scaduta' || this.foto.stato === 'senza_rete') {
            void this.prova();
        }
    }

    /** I gesti partiti e senza risposta, adesso. */
    get partitiSenzaRisposta(): ReadonlySet<string> {
        return this.inVoloOra;
    }

    /** Prova a mandare ciò che è in coda. Se un invio è già in volo, se ne fa un altro subito dopo (uno solo, per quanti siano i richiami). */
    prova(): Promise<void> {
        if (this.inVolo) {
            this.dopo ??= this.inVolo.then(() => {
                this.dopo = null;
                return this.prova();
            });
            return this.dopo;
        }
        this.inVolo = this.manda().finally(() => {
            this.inVolo = null;
        });

        return this.inVolo;
    }

    /** A sala chiusa e arrivata al server: il lavoro tenuto nel browser si cancella. */
    async svuota(): Promise<void> {
        await this.o.deposito.svuota(this.o.ambito);
        this.ultimoOrdine = null;
        this.cambia({ inCoda: [], daRivedere: [], ritrovati: null, esiti: [] });
    }

    private async manda(): Promise<void> {
        await this.schede('kondomanager-senza-rete:' + this.o.ambito, async () => {
            let di = 0;
            let fatti = 0;
            do {
                this.ancora = false;
                const voci = await this.o.deposito.inCoda(this.o.ambito);
                this.cambia({ inCoda: voci });
                if (voci.length === 0) {
                    if (this.foto.stato === 'invio') this.cambia({ stato: 'in_linea', invio: null, serverFermo: false });
                    return;
                }
                di = Math.max(di, fatti + voci.length);
                const risolti = (await this.o.deposito.leggi<Record<string, Risolto>>(this.chiave('risolti'))) ?? {};
                const { lotto, scartati } = this.lotto(voci, risolti);
                if (scartati.length > 0) {
                    await this.registra(scartati.map(([voce, messaggio]) => ({ voce, risposta: { gesto: voce.gesto, tipo: voce.tipo, esito: 'rifiutato', messaggio } })), risolti, true);
                    fatti += scartati.length;
                    this.ancora = true;
                    continue;
                }
                if (lotto.length === 0) return;
                this.cambia({ stato: 'invio', invio: { fatti, di } });
                const partita = this.adesso();
                let risposta: RispostaDelServer;
                this.inVoloOra = new Set(lotto.map(([v]) => v.gesto));
                try {
                    risposta = await this.o.invia({ dispositivo: this.o.dispositivo, gesti: lotto.map(([, g]) => g) });
                } catch (e) {
                    this.inVoloOra = new Set();
                    if (e instanceof ErroreDellInvio && e.motivo === 'forma') {
                        await this.scartaPerLaForma(lotto.map(([v]) => v), e.errori, risolti);
                        this.ancora = true;
                        continue;
                    }
                    const motivo = e instanceof ErroreDellInvio ? e.motivo : 'rete';
                    this.cambia({ stato: motivo === 'sessione' ? 'sessione_scaduta' : motivo === 'permesso' ? 'non_puoi' : 'senza_rete', invio: null,
                        serverFermo: motivo === 'server' });
                    return;
                }
                this.orologio.aggiorna(risposta.ora_del_server, partita);
                await this.o.deposito.scrivi(this.chiave('scarto'), this.orologio.scarto);
                const perGesto = new Map(risposta.risposte.map((r) => [String(r.gesto).toLowerCase(), r]));
                const risposte = lotto.map(([v]) => ({ voce: v, risposta: perGesto.get(v.gesto.toLowerCase()) })).filter((x) => x.risposta !== undefined);
                await this.registra(risposte as { voce: VoceInCoda; risposta: RispostaDelServer['risposte'][number] }[], risolti, false);
                this.inVoloOra = new Set();
                fatti += risposte.length;
                this.cambia({ stato: 'invio', invio: { fatti, di }, serverFermo: false });
                // Un lotto tagliato prima di un gesto che aspetta, o gesti segnati intanto: si continua.
                this.ancora = true;
            } while (this.ancora);
        });
    }

    /** Il lotto: i gesti nell'ordine, con i riferimenti sciolti, fino al tetto o al primo gesto che aspetta una risposta. */
    private lotto(voci: VoceInCoda[], risolti: Record<string, Risolto>): { lotto: [VoceInCoda, GestoDaMandare][]; scartati: [VoceInCoda, string][] } {
        const inCoda = new Map(voci.map((v, i) => [v.gesto, i]));
        const lotto: [VoceInCoda, GestoDaMandare][] = [];
        for (const [i, v] of voci.entries()) {
            if (lotto.length >= this.tetto) break;
            this.viste.set(v.gesto, v);
            try {
                const dati = this.sciogli(v.dati, risolti, inCoda, i) as Record<string, unknown>;
                lotto.push([v, { gesto: v.gesto, tipo: v.tipo, dati, base: v.base, orologio: v.orologio, segnato_il: v.segnato_il }]);
            } catch (e) {
                if (e instanceof Attende) break;
                if (lotto.length === 0) return { lotto: [], scartati: [[v, (e as Error).message]] };
                break;
            }
        }

        return { lotto, scartati: [] };
    }

    /** I riferimenti dei dati, sciolti con ciò che il server ha risposto. */
    private sciogli(valore: unknown, risolti: Record<string, Risolto>, inCoda: Map<string, number>, posto: number): unknown {
        if (eUnRiferimento(valore)) {
            const dove = inCoda.get(valore.$gesto);
            if (dove !== undefined) {
                if (dove < posto) throw new Attende();
                throw new Manca('Non partito: il gesto da cui dipende è segnato dopo di lui.');
            }
            const r = risolti[valore.$gesto];
            if (r && r[valore.campo] !== undefined && r[valore.campo] !== null) return r[valore.campo];
            const trovato = this.o.cercaIlRiferimento?.(this.viste.get(valore.$gesto), valore.campo, valore.$gesto);
            if (trovato !== undefined && trovato !== null) return trovato;
            throw new Manca(r?.esito === 'rifiutato' || r?.esito === 'in_conflitto'
                ? 'Non partito: il gesto da cui dipende non è stato applicato.' : 'Non partito: non trovo il gesto da cui dipende.');
        }
        if (Array.isArray(valore)) return valore.map((x) => this.sciogli(x, risolti, inCoda, posto));
        if (typeof valore === 'object' && valore !== null) {
            return Object.fromEntries(Object.entries(valore).map(([k, x]) => [k, this.sciogli(x, risolti, inCoda, posto)]));
        }

        return valore;
    }

    /** Un 422: i gesti indicati (`gesti.3.dati`) si rifiutano qui; se il server non li indica, il primo del lotto. */
    private async scartaPerLaForma(voci: VoceInCoda[], errori: Record<string, string[]>, risolti: Record<string, Risolto>): Promise<void> {
        const indicati = new Map<number, string>();
        for (const [chiave, messaggi] of Object.entries(errori)) {
            const m = /^gesti\.(\d+)\./.exec(chiave);
            if (m && !indicati.has(Number(m[1]))) indicati.set(Number(m[1]), messaggi[0] ?? 'Il gesto non ha la forma giusta.');
        }
        if (indicati.size === 0) indicati.set(0, Object.values(errori)[0]?.[0] ?? 'Il gesto non ha la forma giusta.');
        const scartati = [...indicati].filter(([i]) => voci[i] !== undefined).map(([i, messaggio]) => ({ voce: voci[i],
            risposta: { gesto: voci[i].gesto, tipo: voci[i].tipo, esito: 'rifiutato', messaggio } }));
        await this.registra(scartati, risolti, true);
    }

    /**
     * Le risposte: ciò che il server ha dato si scrive **prima** di togliere i gesti dal deposito; poi le risposte vanno nella foto, e nei ritrovati.
     */
    private async registra(risposte: { voce: VoceInCoda; risposta: RispostaDelServer['risposte'][number] }[], risolti: Record<string, Risolto>, locale: boolean): Promise<void> {
        if (risposte.length === 0) return;
        const nuovi = { ...risolti };
        for (const { voce, risposta } of risposte) {
            const prima = nuovi[voce.gesto] ?? {};
            const numeri = Object.fromEntries(Object.entries(risposta).filter(([k, v]) => !['gesto', 'tipo', 'messaggio', 'conflitto'].includes(k) && v !== null && v !== undefined));
            nuovi[voce.gesto] = { ...prima, ...numeri, esito: risposta.esito === 'doppio' ? String(prima.esito ?? risposta.esito_di_allora ?? 'doppio') : risposta.esito };
        }
        await this.o.deposito.scrivi(this.chiave('risolti'), nuovi);
        const alle = this.orologio.ora().toISOString();
        // Un gesto non accettato non si perde: prima fra quelli da rivedere, sul disco, poi fuori dalla coda.
        const rifiutati: DaRivedere[] = risposte.filter(({ risposta }) => risposta.esito === 'rifiutato')
            .map(({ voce, risposta }) => ({ voce, messaggio: risposta.messaggio ?? null, arrivato_il: alle, locale }));
        const daRivedere = rifiutati.length === 0 ? this.foto.daRivedere : [...this.foto.daRivedere, ...rifiutati];
        if (rifiutati.length > 0) await this.o.deposito.scrivi(this.chiave('da_rivedere'), daRivedere);
        await this.o.deposito.togli(risposte.map((r) => r.voce.gesto));
        const esiti: EsitoDiUnGesto[] = risposte.map(({ voce, risposta }) => ({ gesto: voce.gesto, tipo: voce.tipo, esito: risposta.esito as EsitoDiUnGesto['esito'],
            messaggio: risposta.messaggio ?? null, voce, arrivato_il: alle, locale,
            numeri: Object.fromEntries(Object.entries(risposta).filter(([k, v]) => k.endsWith('_id') && v !== null && v !== undefined)),
            risposta: { ...risposta } }));
        const tolti = new Set(risposte.map((r) => r.voce.gesto));
        let ritrovati = this.foto.ritrovati;
        if (ritrovati) {
            const loro = esiti.filter((e) => ritrovati!.voci.some((v) => v.gesto === e.gesto));
            if (loro.length > 0) {
                const tutti = [...ritrovati.esiti, ...loro];
                const finito = tutti.length >= ritrovati.quanti;
                ritrovati = { ...ritrovati, esiti: tutti, finito, arrivati_alle: finito ? alle : ritrovati.arrivati_alle };
            }
        }
        this.cambia({ inCoda: this.foto.inCoda.filter((v) => !tolti.has(v.gesto)), daRivedere, esiti: [...this.foto.esiti, ...esiti].slice(-ESITI_TENUTI), ritrovati });
    }
}
