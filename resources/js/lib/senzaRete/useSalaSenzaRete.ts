// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch, type Ref } from 'vue';
import type { SalaDellAssemblea } from '@/types/assemblee';
import { CodaDeiGesti, type FotoDellaCoda } from './coda';
import { apriDepositoIndexedDb, type Deposito, DepositoInMemoria, type VoceInCoda } from './deposito';
import type { EsitoDiUnGesto } from './coda';
import { inviaA, type LottoDaMandare, type RispostaDelServer } from './invio';
import { Orologio } from './orologio';
import { type TenutaDelBrowser, valutaIlBrowser } from './persistenza';
import { cercaNelloStato, proiettaLaSala, tipoDiDispositivo } from './sala';
import { dispositivoDellUtente, schedaDelBrowser } from './scrittore';
import { fraseDelConflitto, statoDellaStriscia } from './striscia';
import { ScrittoreDelTesto } from './testo';

/**
 * La sala senza rete, per la pagina della sala: il punto d'innesto fra la pagina e la coda.
 *
 * - **All'apertura**: apre il deposito sul disco del browser (se non si apre, la sala lo dice: `tenuta` è `non_tiene`, e il lavoro sta in memoria); chiede al
 *   browser di tenere i dati; dà un nome al dispositivo («computer di Andrea Costa»); riprende l'ultimo stato del server tenuto nel deposito se è più recente
 *   di quello della pagina (la pagina riaperta senza rete è la copia dell'operaio del browser); registra l'operaio (`senza-rete`) che tiene la pagina; avvia la
 *   coda, che manda da sola i gesti ritrovati.
 * - **`salaVista`**: lo stato del server più i gesti in coda, contati sul posto (`proiettaLaSala`): la pagina mostra questa, non la prop. **Un gesto arrivato
 *   resta a video** finché arriva uno stato che lo contiene (rilievo C46): fra la risposta dell'invio, che lo toglie dalla coda, e la rilettura dello stato
 *   la sala non torna a com'era prima. I gesti arrivati e applicati si contano sullo stato tenuto come quelli in coda; la rilettura partita dopo la loro
 *   risposta li porta (il server li ha già applicati quando risponde) e li sostituisce; una rilettura partita prima di una risposta non vale, perché lo
 *   stato che porta può non avere quel gesto (la lettura periodica servita dal server prima del gesto): la sala aspetta la rilettura dopo.
 * - **`segna(tipo, dati, base)`**: il gesto prima sul disco, poi a video, poi verso il server.
 * - **La rete**: l'evento `online` del browser e un tentativo ogni pochi secondi con gesti in coda; dopo ogni risposta del server a un invio, e quando la
 *   coda si svuota, rilegge lo stato del server (`sala.rotte.stato`), che vale più dei conti fatti sul posto.
 * - **La striscia**: lo stato, i gesti in coda, l'invio, le due versioni con la loro frase.
 * - **La coda è di chi la segna** (`sala:<assemblea>:<utente>`): sullo stesso browser i gesti di un utente non partono con la sessione di un altro, né li vede.
 * - **L'ora della pagina** è quella dell'orologio della coda (lo scarto dal server tenuto nel deposito), non quella della copia della pagina: `adesso()`. La
 *   sala vista si ricalcola anche con il passare del tempo («si apre» il giorno della seduta).
 * - **La lettura periodica** è una richiesta JSON (`aggiorna()`), mai una visita: la sessione scaduta, il permesso tolto o il server fermo la fermano e lo
 *   dice la striscia, senza sostituire la pagina.
 * - **A sala chiusa, rinviata (annullata) o deserta e arrivata al server** il lavoro tenuto nel browser si cancella (`cancellato`), e l'operaio dimentica le sue
 *   copie. All'uscita dal programma lo cancella `uscita.ts`.
 */
export interface OpzioniDellaSalaSenzaRete {
    /** Il nome di chi usa la pagina, per il nome del dispositivo. */
    nomeUtente?: string;
    fuso?: string;
    /** Ogni quanto riprova con gesti in coda. */
    ogniMs?: number;
    apriDeposito?: () => Promise<Deposito>;
    invia?: (lotto: LottoDaMandare) => Promise<RispostaDelServer>;
    leggiLoStato?: (indirizzo: string) => Promise<SalaDellAssemblea>;
    spazio?: Partial<Pick<StorageManager, 'persist' | 'persisted' | 'estimate'>>;
    /** Registra l'operaio del browser (di serie sì, dove il browser lo permette). */
    operaio?: boolean;
    inLinea?: () => boolean;
    numero?: () => string;
    adesso?: () => number;
    indirizzoDiAccesso?: string;
    /** Chi usa la pagina: la coda è sua (`sala:<assemblea>:<utente>`). */
    utente?: number | string | null;
    /** Ogni quanto si rilegge lo stato della sala con la rete (i gesti degli altri dispositivi); 0 per non rileggerlo. */
    letturaOgniMs?: number;
}

/** Registra l'operaio dell'assemblea (quello della `sala`, anche dalla pagina del verbale) e gli fa tenere questa pagina; senza operai (http), niente. */
export async function registraLOperaio(pagina: string, contenitore: ServiceWorkerContainer | undefined = globalThis.navigator?.serviceWorker, sala: string = pagina): Promise<ServiceWorkerRegistration | null> {
    if (!contenitore || !globalThis.isSecureContext) return null;
    const [url, dellaSala] = [new URL(pagina, globalThis.location.href), new URL(sala, globalThis.location.href)];
    const ambito = dellaSala.pathname.slice(0, dellaSala.pathname.lastIndexOf('/') + 1);
    try {
        const registrazione = await contenitore.register(dellaSala.pathname + '/senza-rete', { scope: ambito, updateViaCache: 'none' });
        void registrazione.update().catch(() => undefined);
        const attivo = registrazione.active ?? (await contenitore.ready).active;
        attivo?.postMessage({ tipo: 'tieni', indirizzo: url.href });
        // Un operaio nuovo (il programma aggiornato) cancella le copie di prima: appena prende la pagina, la tiene di nuovo.
        contenitore.addEventListener?.('controllerchange', () => contenitore.controller?.postMessage({ tipo: 'tieni', indirizzo: url.href }));

        return registrazione;
    } catch {
        // Senza l'operaio la sala funziona lo stesso: solo, senza rete non si riapre.
        return null;
    }
}

/** Un gesto che il server ha applicato, adesso o la prima volta («doppio»): il suo effetto è nello stato del server. */
const applicato = (e: EsitoDiUnGesto): boolean => !e.locale
    && (e.esito === 'applicato' || (e.esito === 'doppio' && String((e.risposta as { esito_di_allora?: string } | undefined)?.esito_di_allora ?? 'applicato') === 'applicato'));

/** Gli stati dell'assemblea in cui la sala non si riapre più: arrivato tutto al server, il lavoro tenuto nel browser si cancella. */
const STATI_FINITI = ['chiusa', 'annullata', 'deserta'];

export function useSalaSenzaRete(sala: Ref<SalaDellAssemblea>, o: OpzioniDellaSalaSenzaRete = {}) {
    const ambito = o.utente != null && o.utente !== '' ? `sala:${sala.value.assemblea.id}:${o.utente}` : `sala:${sala.value.assemblea.id}`;
    const inLinea = o.inLinea ?? (() => globalThis.navigator?.onLine !== false);
    const tipo = tipoDiDispositivo();
    const base = shallowRef<SalaDellAssemblea>(sala.value);
    const foto = shallowRef<FotoDellaCoda>({ stato: 'in_linea', inCoda: [], daRivedere: [], invio: null, serverFermo: false, ritrovati: null, esiti: [] });
    const orologio = new Orologio(o.adesso);
    /** Il passare del tempo, per la sala vista e le ore scritte: si rinnova ogni pochi secondi. */
    const battito = ref(0);
    const tenuta = ref<TenutaDelBrowser | null>(null);
    const pronta = ref(false);
    const riapertaSenzaRete = ref<{ datiFinoAlle: string } | null>(null);
    const cancellato = ref(false);
    const dispositivo = ref('');
    let deposito: Deposito | null = null;
    let coda: CodaDeiGesti | null = null;
    let registrazione: ServiceWorkerRegistration | null = null;
    let intervallo: ReturnType<typeof setInterval> | null = null;
    let quandoPronta: Promise<void> | null = null;
    let scrittore: ScrittoreDelTesto | null = null;
    /** La revisione da cui parte la discussione di ogni punto che cambia qui. */
    const basiDelTesto = new Map<string, number>();
    /** L'ultima revisione arrivata dai gesti di questo computer, per punto: la sala riletta può non averla ancora. */
    const arrivatiDelTesto = new Map<string, number>();
    const esitiDelTesto = new Set<string>();
    let verbaleDellaSala: number | null = sala.value.verbale?.id ?? null;
    const scheda = schedaDelBrowser();
    /**
     * I gesti arrivati e applicati che lo stato tenuto non ha ancora: si contano come quelli in coda finché una rilettura partita dopo la loro risposta li
     * porta. `al` è il posto della risposta nella sequenza delle risposte e delle riletture (un contatore, non l'ora: l'orologio può tornare indietro).
     */
    const arrivati = shallowRef<{ voce: VoceInCoda; risposta?: Record<string, unknown>; al: number }[]>([]);
    const esitiVisti = new Set<string>();
    let sequenza = 0;
    /** Il posto dell'ultima risposta del server a un invio: una rilettura partita prima non vale. */
    let ultimaRisposta = 0;

    /** Adesso, con l'ora del server: l'orologio della coda, con lo scarto tenuto. */
    const adesso = (): Date => {
        void battito.value;
        return orologio.ora();
    };
    /** Ciò che il server ha risposto ai gesti (`{votazione_id: 99}`), per il riferimento di un gesto ancora in coda a un gesto già arrivato. */
    const numeri = computed(() => Object.fromEntries(foto.value.esiti.map((e) => [e.gesto, { ...(e.numeri ?? {}) }])));
    const salaVista = computed(() => proiettaLaSala(base.value, foto.value.inCoda, adesso(), numeri.value, arrivati.value));
    const striscia = computed(() => ({
        stato: statoDellaStriscia(foto.value, tenuta.value, base.value.conflitti),
        inCoda: foto.value.inCoda.length,
        invio: foto.value.invio,
        serverFermo: foto.value.serverFermo,
        dispositivo: tipo,
        conflitto: base.value.conflitti[0] ? fraseDelConflitto(base.value.conflitti[0], base.value) : null,
    }));

    const piuRecente = (a: SalaDellAssemblea, b: SalaDellAssemblea) => Date.parse(a.ora_del_server) > Date.parse(b.ora_del_server);

    async function tieni(s: SalaDellAssemblea): Promise<void> {
        await deposito?.scrivi(`${ambito}:stato`, s);
    }

    /**
     * Lo stato nuovo dal server: vale lui, si tiene nel deposito, e la coda sa che il server risponde. `lettura`: il posto della rilettura che lo ha portato
     * (i gesti arrivati prima che partisse ci sono, e non si contano più due volte); senza (la prop della pagina, a una visita), lo stato è di dopo ogni
     * risposta.
     */
    async function nuovoStato(s: SalaDellAssemblea, partitaAlle?: number, lettura?: number): Promise<void> {
        if (piuRecente(base.value, s)) return;
        base.value = s;
        if (arrivati.value.length > 0) arrivati.value = lettura === undefined ? [] : arrivati.value.filter((a) => a.al > lettura);
        await tieni(s);
        coda?.contatto(s.ora_del_server, partitaAlle);
        if (riapertaSenzaRete.value && foto.value.stato === 'in_linea') riapertaSenzaRete.value = null;
        await forseCancella();
    }

    /**
     * Rilegge lo stato della sala dal server (dopo un invio, quando torna la rete, ogni pochi secondi con la rete): una richiesta JSON, mai una visita. La
     * sessione scaduta (401, 419), il permesso tolto (403) o il server fermo (un 5xx, o una risposta che non è lo stato: la pagina di accesso, un errore del
     * proxy) fermano il lavoro con il loro perché nella striscia; senza risposta, la rete.
     */
    async function aggiorna(): Promise<void> {
        const partita = (o.adesso ?? Date.now)();
        const lettura = ++sequenza;
        let s: unknown;
        try {
            s = o.leggiLoStato ? await o.leggiLoStato(sala.value.rotte.stato) : (await axios.get(sala.value.rotte.stato, { headers: { Accept: 'application/json' } })).data;
        } catch (e: any) {
            const stato: number | null = e?.response?.status ?? null;
            if (stato === 401 || stato === 419) coda?.fermo('sessione');
            else if (stato === 403) coda?.fermo('permesso');
            else if (stato !== null) coda?.fermo('server');
            else if (e?.isAxiosError || e?.request) coda?.senzaRete();
            // Altrimenti lo stato resta quello tenuto: la coda lo dice nella striscia.
            return;
        }
        if (s && typeof s === 'object' && 'ora_del_server' in s) {
            // Partita prima di una risposta: il server può averla servita prima di quel gesto, e la sala tornerebbe a prima. Il server c'è; lo stato aspetta
            // la rilettura dopo (quella che segue ogni risposta).
            if (lettura < ultimaRisposta) coda?.contatto((s as SalaDellAssemblea).ora_del_server, partita);
            else await nuovoStato(s as SalaDellAssemblea, partita, lettura);
        } else if (s !== undefined) coda?.fermo('server');
    }

    /**
     * A sala chiusa, rinviata o deserta, con niente in coda: il lavoro della sala tenuto in questo browser si cancella, e l'operaio dimentica la copia della
     * sala. **Non** quella del verbale, né l'operaio: a sala chiusa si scrive il verbale intero, e la sua pagina deve riaprirsi senza rete (la cancella la
     * pagina del verbale registrato, o l'uscita dal programma).
     */
    async function forseCancella(): Promise<void> {
        if (cancellato.value || !coda || !STATI_FINITI.includes(base.value.assemblea.stato) || foto.value.inCoda.length > 0
            || foto.value.daRivedere.length > 0) return;
        await coda.svuota();
        arrivati.value = [];
        registrazione?.active?.postMessage({ tipo: 'dimentica', indirizzo: new URL(sala.value.rotte.pagina, globalThis.location?.href ?? 'http://localhost/').href });
        cancellato.value = true;
    }

    async function avvia(): Promise<void> {
        let aperto = true;
        try {
            deposito = await (o.apriDeposito ?? (() => apriDepositoIndexedDb()))();
        } catch {
            deposito = new DepositoInMemoria();
            aperto = false;
        }
        tenuta.value = (await valutaIlBrowser(o.spazio ?? globalThis.navigator?.storage, aperto)).tenuta;
        // Lo stesso dispositivo della pagina del verbale: sullo stesso computer chi scrive in sala e nel verbale è uno solo.
        dispositivo.value = await dispositivoDellUtente(deposito, o.utente, tipo, o.nomeUtente ?? '', o.numero);
        const tenuto = await deposito.leggi<SalaDellAssemblea>(`${ambito}:stato`);
        if (tenuto && piuRecente(tenuto, base.value)) base.value = tenuto;
        else await tieni(base.value);

        coda = new CodaDeiGesti({ deposito, ambito, dispositivo: dispositivo.value, invia: o.invia ?? inviaA(sala.value.rotte.gesti), numero: o.numero, adesso: o.adesso,
            orologio, cercaIlRiferimento: (voce, campo) => cercaNelloStato(base.value, voce, campo) });
        scrittore = new ScrittoreDelTesto({
            segna: (d, b, opzioni) => coda!.segna('testo', d, b, undefined, opzioni),
            manda: () => coda!.prova(),
            dati: (parte, documento) => {
                const id = verbaleDellaSala ?? base.value.verbale?.id ?? null;
                return id !== null ? { verbale_id: id, parte, documento, scheda } : { seduta_id: base.value.verbale?.seduta_id ?? base.value.seduta_id, parte,
                    documento, scheda };
            },
            base: (parte) => basiDelTesto.get(parte) ?? 0,
            fondi: (parte) => `testo:${scheda}:${parte}`,
            adesso: o.adesso,
        });
        coda.sottoscrivi((f) => {
            const svuotata = foto.value.inCoda.length > 0 && f.inCoda.length === 0 && f.stato === 'in_linea';
            // Le risposte ai testi: il verbale nato dalla prima discussione, e la revisione da cui parte il testo dopo.
            for (const e of f.esiti.filter((x) => x.tipo === 'testo' && !esitiDelTesto.has(x.gesto))) {
                esitiDelTesto.add(e.gesto);
                const r = (e.risposta ?? {}) as { parte?: string; revisione?: number | null; verbale_id?: number | null; esito_di_allora?: string };
                if (typeof r.verbale_id === 'number') verbaleDellaSala = r.verbale_id;
                const arrivato = e.esito === 'applicato' || (e.esito === 'doppio' && (r.esito_di_allora ?? 'applicato') === 'applicato');
                if (arrivato && r.parte && typeof r.revisione === 'number') {
                    arrivatiDelTesto.set(r.parte, Math.max(arrivatiDelTesto.get(r.parte) ?? 0, r.revisione));
                    if (basiDelTesto.has(r.parte)) basiDelTesto.set(r.parte, Math.max(basiDelTesto.get(r.parte)!, r.revisione));
                }
            }
            // Le risposte nuove: i gesti applicati restano a video, contati sullo stato tenuto, finché una rilettura partita dopo li porta.
            const nuovi = f.esiti.filter((e) => !esitiVisti.has(e.gesto));
            for (const e of nuovi) esitiVisti.add(e.gesto);
            const dalServer = nuovi.some((e) => !e.locale);
            if (dalServer) {
                ultimaRisposta = ++sequenza;
                const applicati = nuovi.filter(applicato).map((e) => ({ voce: e.voce, risposta: e.risposta, al: ultimaRisposta }));
                if (applicati.length > 0) arrivati.value = [...arrivati.value, ...applicati];
            }
            foto.value = f;
            // Il server ha risposto: lo stato si rilegge (una richiesta JSON, mai una visita), e la rilettura, partita dopo la risposta, porta i gesti arrivati.
            if (dalServer) void aggiorna();
            // La rete è tornata e i gesti ritrovati sono partiti: al posto di «Ho riaperto la sala senza rete» c'è «Ho ritrovato…».
            if (riapertaSenzaRete.value && (f.ritrovati?.finito || (f.stato === 'in_linea' && f.inCoda.length === 0))) riapertaSenzaRete.value = null;
            if (svuotata) void aggiorna();
        });
        if (!inLinea()) {
            riapertaSenzaRete.value = { datiFinoAlle: base.value.ora_del_server };
            coda.senzaRete();
        }
        pronta.value = true;
        if (o.operaio !== false) {
            void registraLOperaio(sala.value.rotte.pagina).then((r) => (registrazione = r));
        }
        await coda.avvia();
        await forseCancella();
    }

    /** Segna un gesto: prima sul disco, poi a video, poi verso il server. `segnatoIl`: l'ora scritta sul foglio della sala. */
    async function segna(tipoDiGesto: string, dati: Record<string, unknown>, versione: number | null = null, segnatoIl?: string): Promise<VoceInCoda> {
        if (!coda) await quandoPronta;

        return coda!.segna(tipoDiGesto, dati, versione, segnatoIl);
    }

    /**
     * La discussione di un punto (il testo del verbale scritto sotto il voto) è cambiata nell'editor: va sul disco al più ogni 300 millisecondi e parte dopo
     * la pausa, con un solo gesto in attesa per punto (`testo.ts`, come la pagina del verbale). La base si fissa al primo cambio (la revisione del testo che
     * l'editor mostrava) e avanza solo con le risposte ai gesti di questo computer; un punto che non ha più niente qui torna a seguire il server.
     */
    function scriviLaDiscussione(puntoId: number, documento: Parameters<ScrittoreDelTesto['scrivi']>[1]): void {
        const parte = `punto:${puntoId}`;
        const qui = foto.value.inCoda.some((v) => v.tipo === 'testo' && (v.dati as { parte?: string }).parte === parte) || scrittore?.inScrittura(parte) != null;
        if (!qui || !basiDelTesto.has(parte)) {
            basiDelTesto.set(parte, Math.max(salaVista.value.verbale?.discussioni[String(puntoId)]?.revisione ?? 0, arrivatiDelTesto.get(parte) ?? 0));
        }
        scrittore?.scrivi(parte, documento);
    }

    /** «Tieni questa» fra due versioni: è un gesto anch'esso. */
    function scegli(gesto: string, scelta: 'questa' | 'quella'): Promise<VoceInCoda> {
        return segna('scegli', { gesto, scelta });
    }

    /** «Rimanda» i gesti da rivedere (tutti, o quelli dati), dopo averli corretti. */
    async function rimanda(gesti?: string[]): Promise<void> {
        if (!coda) await quandoPronta;
        await coda!.rimanda(gesti);
    }

    /** «Togli» un gesto da rivedere: si toglie da questo computer. */
    async function lascia(gesto: string): Promise<void> {
        if (!coda) await quandoPronta;
        await coda!.lascia(gesto);
    }

    /** «Rientra»: l'accesso in un'altra scheda, così questa resta com'è; i gesti partono da soli al tentativo dopo. */
    function rientra(): void {
        globalThis.open?.(o.indirizzoDiAccesso ?? '/login', '_blank', 'noopener');
    }

    const allaRete = () => {
        void coda?.prova();
        void aggiorna();
    };
    /** La scheda nascosta o chiusa: il testo in memoria va sul disco. */
    const nascosta = () => {
        if (globalThis.document?.visibilityState !== 'visible') void scrittore?.sulDisco();
    };
    const senzaRete = () => coda?.senzaRete();

    watch(sala, (s) => void nuovoStato(s));

    let lettura: ReturnType<typeof setInterval> | null = null;
    onMounted(() => {
        quandoPronta = avvia();
        globalThis.addEventListener?.('online', allaRete);
        globalThis.addEventListener?.('offline', senzaRete);
        globalThis.addEventListener?.('pagehide', nascosta);
        globalThis.document?.addEventListener?.('visibilitychange', nascosta);
        intervallo = setInterval(() => {
            battito.value += 1;
            if (foto.value.inCoda.length > 0 || foto.value.stato !== 'in_linea') void coda?.prova();
        }, o.ogniMs ?? 5000);
        // La lettura periodica: i gesti degli altri dispositivi, e la sessione tenuta viva. Senza la rete del dispositivo si ferma (la riprende l'evento
        // «online»); con la sessione scaduta o il server fermo continua, così quando si rientra (in un'altra scheda) o il server torna la sala lo sa da sé.
        if ((o.letturaOgniMs ?? 10000) > 0) {
            lettura = setInterval(() => {
                if (inLinea() && foto.value.stato !== 'invio') void aggiorna();
            }, o.letturaOgniMs ?? 10000);
        }
    });
    onBeforeUnmount(() => {
        globalThis.removeEventListener?.('online', allaRete);
        globalThis.removeEventListener?.('offline', senzaRete);
        globalThis.removeEventListener?.('pagehide', nascosta);
        globalThis.document?.removeEventListener?.('visibilitychange', nascosta);
        if (intervallo) clearInterval(intervallo);
        if (lettura) clearInterval(lettura);
        // Si esce dalla pagina con la discussione appena scritta: va sul disco e parte adesso (gli altri gesti restano dove sono, e partono alla volta dopo).
        if (scrittore?.haDaMandare) void scrittore.ferma().then(() => coda?.prova());
    });

    return { pronta, tenuta, dispositivo, scheda, salaVista, foto, striscia, riapertaSenzaRete, cancellato, segna, scegli, rimanda, lascia, rientra, aggiorna, adesso,
        scriviLaDiscussione,
        prova: () => coda?.prova(), avviata: () => quandoPronta ?? Promise.resolve() };
}
