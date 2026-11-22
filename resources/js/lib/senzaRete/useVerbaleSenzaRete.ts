// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch, type Ref } from 'vue';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { PaginaDelVerbale } from '@/types/verbale';
import { CodaDeiGesti, type EsitoDiUnGesto, type FotoDellaCoda } from './coda';
import { apriDepositoIndexedDb, type Deposito, DepositoInMemoria, type VoceInCoda } from './deposito';
import { inviaA, type LottoDaMandare, type RispostaDelServer } from './invio';
import { Orologio } from './orologio';
import { type TenutaDelBrowser, valutaIlBrowser } from './persistenza';
import { tipoDiDispositivo } from './sala';
import { dispositivoDellUtente, schedaDelBrowser } from './scrittore';
import { ScrittoreDelTesto } from './testo';
import { registraLOperaio } from './useSalaSenzaRete';
import {
    dueVersioniAperte,
    fraseDelLavoroRitrovato,
    type LavoroRitrovatoDelVerbale,
    type ModificheInAttesa,
    modificheRitrovate,
    scrittaDelSalvataggio,
    vistaDelleParti,
} from './verbale';

/**
 * Il verbale senza rete, per la pagina «Il verbale»: il punto d'innesto fra l'editor e la coda dei gesti (decisioni 514 (5), 519, 590 (T5)).
 *
 * - **N1 · Ogni lettera sul disco** e **N3 · quando parte**: {@link ScrittoreDelTesto} (`testo.ts`). L'editor chiama `scrivi(parte, documento)` a ogni
 *   cambio; la parte va sul disco al più ogni 300 millisecondi e parte pochi secondi dopo la pausa, almeno ogni 30 secondi, al ritorno della rete e alla
 *   riapertura della pagina.
 * - **N2 · Il gesto che sostituisce**: la coda tiene un solo gesto in attesa per parte (la chiave `testo:<parte>`); quello partito senza risposta resta.
 * - **La base**: la revisione da cui parte il testo dell'editor. Si fissa quando la parte comincia a cambiare qui (quella che l'editor mostrava), e avanza
 *   solo con le risposte ai gesti di questo computer: un testo scritto da un altro dispositivo intanto non c'è dentro, e il server lo dice con due versioni.
 * - **N4 · La scritta** (`scritta`), e **chiudendo la pagina con modifiche in attesa il browser lo chiede prima**; la scheda nascosta o chiusa scrive sul disco.
 * - **N5 · Il lavoro ritrovato** (`ritrovato`): le modifiche di una pagina di prima, quando partono e quando arrivano.
 * - **N6 · Le due versioni** (`dueVersioni`): dalla prop della pagina; finché c'è la scelta, la parte è ferma (`bloccata`), e niente si sovrascrive.
 * - **N7 · La pagina senza rete**: lo stesso operaio della sala tiene anche questa pagina; lo stato della pagina più recente si tiene nel deposito e vale se
 *   è più nuovo della pagina riaperta. Il browser che non tiene i dati lo dice `tenuta` (la finestra privata), prima dell'editor.
 * - **N8 · La sessione scaduta** (401, 419) e il permesso tolto (403) non tolgono niente dalla coda: la scritta lo dice, e «Rientra».
 * - **N9 · Si cancella** ciò che resta nel browser appena è arrivato al server; registrato il verbale, con niente in coda, anche la copia della pagina.
 * - **La coda è di chi scrive**: l'ambito è `verbale:<assemblea>:<utente>`. All'uscita dal programma il lavoro tenuto lo cancella `uscita.ts`.
 * - **Chi scrive** ({@link dispositivoDellUtente}, {@link schedaDelBrowser}): il dispositivo è di chi usa il browser, lo stesso della sala; la scheda va nei
 *   dati di ogni testo e nella chiave di fusione, così due schede sono due scrittori e non si sostituiscono il testo in coda.
 * - **Un salvataggio non accettato** (la revisione che il verbale non ha più, la forma) non si perde: resta su questo computer fra `daRivedere`, e
 *   `riprendi()` lo rimette nell'editor, dalla revisione di adesso. Quando il verbale cambia (la registrazione annullata, un verbale nuovo) le basi ripartono.
 */
export interface OpzioniDelVerbaleSenzaRete {
    nomeUtente?: string;
    fuso?: string;
    /** Ogni quanto riprova con gesti in coda fermi. */
    ogniMs?: number;
    apriDeposito?: () => Promise<Deposito>;
    invia?: (lotto: LottoDaMandare) => Promise<RispostaDelServer>;
    leggiLoStato?: (indirizzo: string) => Promise<PaginaDelVerbale>;
    spazio?: Partial<Pick<StorageManager, 'persist' | 'persisted' | 'estimate'>>;
    operaio?: boolean;
    inLinea?: () => boolean;
    numero?: () => string;
    adesso?: () => number;
    indirizzoDiAccesso?: string;
    utente?: number | string | null;
    /** Ogni quanto si rilegge la pagina dal server (gli altri dispositivi, chi scrive); 0 per non rileggerla. */
    letturaOgniMs?: number;
    /** I parametri in più della lettura periodica (`parte`, `scrivo`): il dispositivo lo mette il verbale. */
    parametriDellaLettura?: () => Record<string, string>;
    /** I tempi dello scrittore, per le prove. */
    sulDiscoOgniMs?: number;
    pausaMs?: number;
    almenoOgniMs?: number;
}

/** Gli stati del verbale in cui il testo non si scrive più: arrivato tutto, il lavoro tenuto nel browser si cancella. */
const VERBALE_FERMO = ['registrato', 'annullato'];

export function useVerbaleSenzaRete(pagina: Ref<PaginaDelVerbale>, o: OpzioniDelVerbaleSenzaRete = {}) {
    const ambito = o.utente != null && o.utente !== '' ? `verbale:${pagina.value.assemblea.id}:${o.utente}` : `verbale:${pagina.value.assemblea.id}`;
    const fuso = o.fuso ?? 'Europe/Rome';
    const inLinea = o.inLinea ?? (() => globalThis.navigator?.onLine !== false);
    const adessoMs = o.adesso ?? (() => Date.now());
    const tipo = tipoDiDispositivo();
    const stato = shallowRef<PaginaDelVerbale>(pagina.value);
    const foto = shallowRef<FotoDellaCoda>({ stato: 'in_linea', inCoda: [], daRivedere: [], invio: null, serverFermo: false, ritrovati: null, esiti: [] });
    const orologio = new Orologio(o.adesso);
    const tenuta = ref<TenutaDelBrowser | null>(null);
    const pronta = ref(false);
    const dispositivo = ref('');
    const riapertaSenzaRete = ref<{ datiFinoAlle: string } | null>(null);
    const cancellato = ref(false);
    /** Il testo in memoria non ancora sul disco, per la vista. */
    const inMemoria = shallowRef<Record<string, NodoDelVerbale>>({});
    /** L'ultimo testo arrivato dalle risposte, per parte. */
    const arrivati = shallowRef<Record<string, { documento: NodoDelVerbale; revisione: number }>>({});
    const modifiche = shallowRef<ModificheInAttesa>({ modifiche: [], ferma: false });
    const partite = ref<{ quante: number; alle: string } | null>(null);
    const salvatoAlle = ref<string | null>(null);
    /** Le parti con una risposta «due versioni» che la pagina non ha ancora riletto. */
    const conflittiNuovi = ref<string[]>([]);
    /** Il lavoro ritrovato: le modifiche di prima, tenute all'apertura. */
    const ritrovatoAllApertura = shallowRef<{ modifiche: ModificheInAttesa['modifiche'] } | null>(null);
    const ritrovatoChiuso = ref(false);
    let verbaleId: number | null = pagina.value.verbale?.id ?? null;
    /** La revisione da cui parte il testo di ogni parte che cambia qui. */
    const basi = new Map<string, number>();
    const esitiVisti = new Set<string>();
    const scheda = schedaDelBrowser();
    let deposito: Deposito | null = null;
    let coda: CodaDeiGesti | null = null;
    let scrittore: ScrittoreDelTesto | null = null;
    let registrazione: ServiceWorkerRegistration | null = null;
    let quandoPronta: Promise<void> | null = null;
    let intervallo: ReturnType<typeof setInterval> | null = null;
    let lettura: ReturnType<typeof setInterval> | null = null;
    let cancellando = false;

    const punti = computed(() => stato.value.verbale?.punti ?? []);
    const testiInCoda = computed(() => foto.value.inCoda.filter((v) => v.tipo === 'testo'));
    const vista = computed(() => vistaDelleParti(Object.fromEntries(Object.entries(stato.value.verbale?.parti ?? {}).map(([k, p]) => [k, { documento: p.documento,
        revisione: p.revisione }])), testiInCoda.value, arrivati.value, inMemoria.value));
    const dueVersioni = computed(() => dueVersioniAperte(stato.value.verbale, dispositivo.value, fuso));
    const ritrovato = computed((): (LavoroRitrovatoDelVerbale & { frasi: { titolo: string; righe: string[] } }) | null => {
        const r = foto.value.ritrovati;
        if (ritrovatoChiuso.value || !r || ritrovatoAllApertura.value === null) return null;
        const lavoro: LavoroRitrovatoDelVerbale = { modifiche: modificheRitrovate(ritrovatoAllApertura.value.modifiche, r.voci), finito: r.finito,
            arrivate_alle: r.arrivati_alle, esiti: r.esiti };

        return { ...lavoro, frasi: fraseDelLavoroRitrovato(lavoro, punti.value, fuso) };
    });
    const scritta = computed(() => scrittaDelSalvataggio({ stato: foto.value.stato, serverFermo: foto.value.serverFermo,
        daMandare: Object.keys(inMemoria.value).length > 0 || testiInCoda.value.length > 0, inAttesa: Math.max(modifiche.value.modifiche.length,
            foto.value.stato === 'in_linea' || foto.value.stato === 'invio' ? 0 : testiInCoda.value.length), salvatoAlle: salvatoAlle.value, partite: partite.value, fuso }));

    /** La parte è ferma: due versioni da scegliere, o il verbale non si scrive più. */
    function bloccata(parte: string): boolean {
        const v = stato.value.verbale;
        return (v !== null && VERBALE_FERMO.includes(v.stato)) || dueVersioni.value.some((d) => d.parte === parte) || conflittiNuovi.value.includes(parte);
    }

    /** La revisione da cui parte il prossimo gesto della parte. */
    function base(parte: string): number {
        return basi.get(parte) ?? vista.value[parte]?.revisione ?? 0;
    }

    /** I dati del gesto: il verbale, o la riunione prima che il verbale ci sia (la discussione scritta senza rete in sala). */
    function dati(parte: string, documento: NodoDelVerbale): Record<string, unknown> {
        if (verbaleId !== null) return { verbale_id: verbaleId, parte, documento, scheda };
        const riunione = [...stato.value.riunioni].filter((r) => r.aperta_il !== null).sort((a, b) => String(a.aperta_il).localeCompare(String(b.aperta_il))).pop();

        return { seduta_id: riunione?.seduta_id ?? stato.value.riunioni[0]?.seduta_id, parte, documento, scheda };
    }

    async function tieniLeModifiche(m: ModificheInAttesa): Promise<void> {
        modifiche.value = m;
        await deposito?.scrivi(`${ambito}:modifiche`, m);
    }

    /** Le risposte nuove ai gesti del testo: il testo arrivato e la sua revisione, le due versioni. */
    function leggiLeRisposte(esiti: EsitoDiUnGesto[]): void {
        const nuovi = esiti.filter((e) => e.tipo === 'testo' && !esitiVisti.has(e.gesto));
        if (nuovi.length === 0) return;
        const prima = { ...arrivati.value };
        for (const e of nuovi) {
            esitiVisti.add(e.gesto);
            const r = (e.risposta ?? {}) as { parte?: string; revisione?: number | null; verbale_id?: number | null; esito_di_allora?: string };
            const d = e.voce.dati as { parte?: string; documento?: NodoDelVerbale };
            const parte = r.parte ?? d.parte;
            if (!parte) continue;
            if (typeof r.verbale_id === 'number') verbaleId = r.verbale_id;
            const arrivato = e.esito === 'applicato' || (e.esito === 'doppio' && (r.esito_di_allora ?? 'applicato') === 'applicato');
            if (arrivato && typeof r.revisione === 'number' && d.documento) {
                prima[parte] = { documento: d.documento, revisione: r.revisione };
                if (basi.has(parte)) basi.set(parte, Math.max(basi.get(parte)!, r.revisione));
                salvatoAlle.value = e.arrivato_il;
            } else if (e.esito === 'in_conflitto') {
                // Niente si sovrascrive: la parte si ferma finché non si sceglie, e la base resta quella di prima.
                if (!conflittiNuovi.value.includes(parte)) conflittiNuovi.value = [...conflittiNuovi.value, parte];
            }
        }
        arrivati.value = prima;
    }

    /** Una parte senza niente qui (né in memoria né in coda) torna a seguire il server. */
    function dimenticaLeBasiFinite(): void {
        for (const parte of [...basi.keys()]) {
            if (!(parte in inMemoria.value) && !testiInCoda.value.some((v) => (v.dati as { parte?: string }).parte === parte)) basi.delete(parte);
        }
    }

    /** Arrivato tutto: le modifiche in attesa si cancellano; dopo una caduta, «Di nuovo in linea». */
    async function forseArrivatoTutto(f: FotoDellaCoda): Promise<void> {
        if (f.stato !== 'in_linea' || f.inCoda.some((v) => v.tipo === 'testo') || scrittore?.haDaMandare || modifiche.value.modifiche.length === 0) return;
        if (modifiche.value.ferma) partite.value = { quante: modifiche.value.modifiche.length, alle: orologio.ora().toISOString() };
        await tieniLeModifiche({ modifiche: [], ferma: false });
    }

    const piuRecente = (a: PaginaDelVerbale, b: PaginaDelVerbale) => Date.parse(a.ora_del_server) > Date.parse(b.ora_del_server);

    async function nuovoStato(s: PaginaDelVerbale, partitaAlle?: number): Promise<void> {
        if (piuRecente(stato.value, s)) return;
        // Un verbale nuovo (la registrazione annullata torna in bozza in un verbale nuovo): le revisioni ripartono da quelle sue, mai da quelle di prima.
        if (s.verbale?.id && verbaleId !== null && s.verbale.id !== verbaleId) {
            basi.clear();
            arrivati.value = {};
            // Ciò che è ancora in coda è del verbale di prima (il server lo dice «non accettato», e resta da rivedere): il testo nuovo parte dalle revisioni
            // del verbale nuovo.
            for (const [parte, p] of Object.entries(s.verbale.parti ?? {})) basi.set(parte, p.revisione);
        }
        stato.value = s;
        if (s.verbale?.id) verbaleId = s.verbale.id;
        // La pagina riletta dice le due versioni (o la scelta fatta): le risposte di prima non contano più.
        conflittiNuovi.value = [];
        await deposito?.scrivi(`${ambito}:stato`, s);
        coda?.contatto(s.ora_del_server, partitaAlle);
        if (riapertaSenzaRete.value && foto.value.stato === 'in_linea') riapertaSenzaRete.value = null;
        await forseCancella();
    }

    /** Rilegge la pagina dal server: una richiesta JSON con il dispositivo (chi ha la pagina aperta), mai una visita. */
    async function aggiorna(): Promise<void> {
        const partita = adessoMs();
        const parametri = new URLSearchParams({ ...(o.parametriDellaLettura?.() ?? {}), dispositivo: dispositivo.value });
        if (stato.value.verbale?.id) parametri.set('verbale', String(stato.value.verbale.id));
        const indirizzo = stato.value.rotte.stato + (stato.value.rotte.stato.includes('?') ? '&' : '?') + parametri.toString();
        let s: unknown;
        try {
            s = o.leggiLoStato ? await o.leggiLoStato(indirizzo) : (await axios.get(indirizzo, { headers: { Accept: 'application/json' } })).data;
        } catch (e: any) {
            const codice: number | null = e?.response?.status ?? null;
            if (codice === 401 || codice === 419) coda?.fermo('sessione');
            else if (codice === 403) coda?.fermo('permesso');
            else if (codice !== null) coda?.fermo('server');
            else if (e?.isAxiosError || e?.request) coda?.senzaRete();
            return;
        }
        if (s && typeof s === 'object' && 'ora_del_server' in s) await nuovoStato(s as PaginaDelVerbale, partita);
        else if (s !== undefined) coda?.fermo('server');
    }

    /** Registrato (o annullato) il verbale, con niente in coda: il lavoro tenuto nel browser si cancella, e con la sala chiusa anche le copie della pagina. */
    async function forseCancella(): Promise<void> {
        const v = stato.value.verbale;
        if (cancellato.value || cancellando || !coda || v === null || !VERBALE_FERMO.includes(v.stato) || foto.value.inCoda.length > 0
            || foto.value.daRivedere.length > 0 || scrittore?.haDaMandare) return;
        cancellando = true;
        try {
            await coda.svuota();
            if (stato.value.assemblea.stato !== 'in_corso') {
                registrazione?.active?.postMessage({ tipo: 'dimentica' });
            }
            cancellato.value = true;
        } finally {
            cancellando = false;
        }
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
        dispositivo.value = await dispositivoDellUtente(deposito, o.utente, tipo, o.nomeUtente ?? '', o.numero);
        const tenuto = await deposito.leggi<PaginaDelVerbale>(`${ambito}:stato`);
        if (tenuto && piuRecente(tenuto, stato.value)) {
            stato.value = tenuto;
            if (tenuto.verbale?.id) verbaleId = tenuto.verbale.id;
        } else {
            await deposito.scrivi(`${ambito}:stato`, stato.value);
        }
        modifiche.value = (await deposito.leggi<ModificheInAttesa>(`${ambito}:modifiche`)) ?? { modifiche: [], ferma: false };
        ritrovatoAllApertura.value = { modifiche: [...modifiche.value.modifiche] };

        coda = new CodaDeiGesti({ deposito, ambito, dispositivo: dispositivo.value, invia: o.invia ?? inviaA(stato.value.rotte.gesti), numero: o.numero,
            adesso: o.adesso, orologio });
        scrittore = new ScrittoreDelTesto({
            segna: (d, b, opzioni) => coda!.segna('testo', d, b, undefined, opzioni),
            manda: () => coda!.prova(),
            dati,
            base,
            fondi: (parte) => `testo:${scheda}:${parte}`,
            adesso: o.adesso,
            sulDiscoOgniMs: o.sulDiscoOgniMs,
            pausaMs: o.pausaMs,
            almenoOgniMs: o.almenoOgniMs,
            sulDiscoFatto: (parte) => {
                if (parte in inMemoria.value) {
                    const resto = { ...inMemoria.value };
                    if (scrittore?.inScrittura(parte) === null) delete resto[parte];
                    inMemoria.value = resto;
                }
            },
            parte: async (parti) => {
                const ferma = foto.value.stato !== 'in_linea' && foto.value.stato !== 'invio';
                partite.value = null;
                await tieniLeModifiche({ modifiche: [...modifiche.value.modifiche, ...parti].slice(-500), ferma: modifiche.value.ferma || ferma });
            },
        });
        for (const [parte, documento] of Object.entries(inMemoria.value)) scrittore.scrivi(parte, documento);
        coda.sottoscrivi((f) => {
            foto.value = f;
            leggiLeRisposte(f.esiti);
            dimenticaLeBasiFinite();
            if (f.stato !== 'in_linea' && f.stato !== 'invio' && modifiche.value.modifiche.length > 0 && !modifiche.value.ferma) {
                void tieniLeModifiche({ ...modifiche.value, ferma: true });
            }
            if (riapertaSenzaRete.value && (f.ritrovati?.finito || (f.stato === 'in_linea' && f.inCoda.length === 0))) riapertaSenzaRete.value = null;
            void forseArrivatoTutto(f).then(() => forseCancella());
        });
        if (!inLinea()) {
            riapertaSenzaRete.value = { datiFinoAlle: stato.value.ora_del_server };
            coda.senzaRete();
        }
        pronta.value = true;
        if (o.operaio !== false && stato.value.rotte.sala) {
            void registraLOperaio(globalThis.location?.href ?? stato.value.rotte.pagina, globalThis.navigator?.serviceWorker, stato.value.rotte.sala)
                .then((r) => (registrazione = r));
        }
        await coda.avvia();
        await forseCancella();
    }

    /**
     * L'editor è cambiato: il testo va sul disco e poi parte (N1, N3). Una parte ferma (due versioni, il verbale registrato) non si scrive: rende `false`.
     */
    function scrivi(parte: string, documento: NodoDelVerbale): boolean {
        if (bloccata(parte)) return false;
        // La parte comincia a cambiare qui: la base è la revisione del testo che l'editor mostrava.
        if (!basi.has(parte)) basi.set(parte, vista.value[parte]?.revisione ?? 0);
        partite.value = null;
        inMemoria.value = { ...inMemoria.value, [parte]: documento };
        // Prima che il deposito sia aperto (i primi istanti della pagina) il testo resta in memoria, e va sul disco appena si può.
        if (scrittore) scrittore.scrivi(parte, documento);

        return true;
    }

    /**
     * Salva adesso e aspetta la risposta (prima di «Stampa la bozza», «Genera», «Registra»): vero se il testo è arrivato tutto, falso se resta qui (senza
     * rete, la sessione scaduta, due versioni).
     */
    async function salvaOra(): Promise<boolean> {
        if (!coda) await quandoPronta;
        await scrittore!.mandaOra();
        await coda!.prova();

        return !foto.value.inCoda.some((v) => v.tipo === 'testo') && !scrittore!.haDaMandare && conflittiNuovi.value.length === 0;
    }

    /** I testi non accettati dal server, ancora su questo computer: la parte, il testo, il perché. */
    const daRivedere = computed(() => foto.value.daRivedere.filter((d) => d.voce.tipo === 'testo').map((d) => ({ gesto: d.voce.gesto,
        parte: String((d.voce.dati as { parte?: string }).parte ?? ''), documento: (d.voce.dati as { documento?: NodoDelVerbale }).documento ?? null,
        messaggio: d.messaggio, il: d.voce.segnato_il })));

    /**
     * «Rimettilo nell'editor»: il testo non accettato torna a essere il testo di questa parte, dalla revisione di adesso del server (la base di prima non
     * c'è più); parte come ogni testo, e se intanto un altro ha scritto, il server dice le due versioni. Poi si toglie da quelli da rivedere.
     */
    async function riprendi(gesto: string): Promise<boolean> {
        const d = daRivedere.value.find((x) => x.gesto === gesto);
        if (!d || !d.documento || !coda) return false;
        basi.delete(d.parte);
        delete (arrivati.value as Record<string, unknown>)[d.parte];
        const scritto = scrivi(d.parte, d.documento);
        if (scritto) await coda.lascia(gesto);

        return scritto;
    }

    /** «Rientra»: l'accesso in un'altra scheda, così questa resta com'è; il testo parte da solo al tentativo dopo. */
    function rientra(): void {
        globalThis.open?.(o.indirizzoDiAccesso ?? '/login', '_blank', 'noopener');
    }

    const allaRete = () => {
        void scrittore?.mandaOra();
        void aggiorna();
    };
    const senzaRete = () => coda?.senzaRete();
    const nascosta = () => {
        if (globalThis.document?.visibilityState === 'hidden') void scrittore?.sulDisco();
    };
    /** Chiudendo la pagina con modifiche in attesa il browser lo chiede prima; intanto il testo va sul disco. */
    const primaDiChiudere = (e: BeforeUnloadEvent) => {
        if (!scrittore) return;
        const daMandare = scrittore.haDaMandare || foto.value.inCoda.some((v) => v.tipo === 'testo');
        void scrittore.sulDisco();
        if (daMandare) {
            e.preventDefault();
            e.returnValue = '';
        }
    };

    watch(pagina, (s) => void nuovoStato(s));

    onMounted(() => {
        quandoPronta = avvia();
        globalThis.addEventListener?.('online', allaRete);
        globalThis.addEventListener?.('offline', senzaRete);
        globalThis.addEventListener?.('beforeunload', primaDiChiudere);
        globalThis.addEventListener?.('pagehide', nascosta);
        globalThis.document?.addEventListener?.('visibilitychange', nascosta);
        intervallo = setInterval(() => {
            if (foto.value.stato !== 'in_linea' && foto.value.stato !== 'invio' && foto.value.inCoda.length > 0) void coda?.prova();
        }, o.ogniMs ?? 5000);
        if ((o.letturaOgniMs ?? 10000) > 0) {
            lettura = setInterval(() => {
                if (inLinea() && foto.value.stato !== 'invio') void aggiorna();
            }, o.letturaOgniMs ?? 10000);
        }
    });
    onBeforeUnmount(() => {
        globalThis.removeEventListener?.('online', allaRete);
        globalThis.removeEventListener?.('offline', senzaRete);
        globalThis.removeEventListener?.('beforeunload', primaDiChiudere);
        globalThis.removeEventListener?.('pagehide', nascosta);
        globalThis.document?.removeEventListener?.('visibilitychange', nascosta);
        if (intervallo) clearInterval(intervallo);
        if (lettura) clearInterval(lettura);
        // Si esce dalla pagina (una visita di Inertia) con il testo appena scritto: va sul disco e parte; la coda finisce il suo lavoro anche dopo.
        if (scrittore?.haDaMandare) void scrittore.ferma().then(() => coda?.prova());
    });

    return { pronta, tenuta, dispositivo, scheda, pagina: stato, foto, vista, scritta, ritrovato, dueVersioni, riapertaSenzaRete, cancellato, scrivi, salvaOra,
        bloccata, base, rientra, aggiorna, daRivedere, riprendi, chiudiIlRitrovato: () => (ritrovatoChiuso.value = true), prova: () => coda?.prova(),
        avviata: () => quandoPronta ?? Promise.resolve(), voci: (): VoceInCoda[] => foto.value.inCoda };
}
