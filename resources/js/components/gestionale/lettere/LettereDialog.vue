<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra delle lettere ai condòmini, dall'elenco delle rate scadute (le persone spuntate o «tutte le N dei filtri») o dall'estratto conto
 * di una persona. Il tipo lo sceglie l'amministratore: di serie «sollecito» dalle rate scadute, «promemoria» dalla vista «In scadenza», e non
 * si ricorda. Il testo parte da un modello: il menu «Modello» porta il testo del programma e i modelli dello studio di quel tipo validi
 * nel condominio (prima quelli del condominio, poi quelli per tutti), e la finestra parte dall'ultimo modello di quel tipo nel condominio (da
 * un modello che non c'è più torna al testo del programma, e lo dice). Scegliere riempie il testo, che si cambia per questa volta; la
 * richiesta porta il modello. «Salva come modello…» (il nome e «vale per», nessuno già scelto) e «Aggiorna «nome»» ci sono solo per il testo
 * comune, mai nella lettera di una persona, e solo a chi gestisce le impostazioni di stampa; per diffida e messa in mora il testo del programma
 * va controllato, e la finestra lo dice. Il termine per il versamento ha tre forme (giorni dal ricevimento, una data, nessun termine) e si ricorda per tipo di
 * lettera: per il sollecito si propongono i giorni dell'ultimo sollecito, per diffida e messa in mora nessuna forma, mai; il promemoria non ne
 * ha. Il conto dell'IBAN è quello dell'ultima volta, se c'è ancora.
 *
 * Poi l'anteprima di chi riceve che cosa: per persona il totale, il credito accanto, il canale proposto (che si cambia, con un `v-select`), gli
 * indirizzi; «togli» una persona. Le azioni lavorano sulle persone viste nell'anteprima, ognuna con il suo canale: l'email (solo promemoria e sollecito),
 * la PEC dalla casella dello studio (sollecito, diffida, messa in mora: decisioni 569 e 588), «PEC e carta» (la stessa lettera per PEC e stampata), la
 * stampa, il PDF per persona; «Mandane una a me» manda la prima lettera per email a chi lavora. Accanto alla PEC scelta, se la persona non l'ha comunicata,
 * il badge «PEC non comunicata dalla persona» con la «i» (un pulsante: al tocco apre la spiegazione) e la sua scheda; sopra la tabella la riga che le conta
 * e «Usa la PEC per chi ce l'ha»; senza la casella la PEC è spenta con il perché e «Imposta la casella PEC». A chi ha già oggi la PEC dello stesso tipo
 * partita la riga lo dice, e «Manda N PEC» non la conta. «Manda N PEC» chiede conferma dicendo la casella e quante partono adesso (il tetto per volta); se
 * la pagina non ha la risposta, dice di guardare la corrispondenza prima di rimandare. Dopo la stampa, la spedizione si registra in blocco. Dopo un'azione riuscita
 * il suo pulsante si spegne (la PEC e la stampa delle copie di «PEC e carta» sono due azioni): per un altro invio si rifà l'anteprima. Al telefono una card
 * per persona. La gestione dei filtri dell'elenco vale anche per le persone spuntate; un'anteprima arrivata dopo un cambio delle scelte si scarta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    TIPI_INDIRIZZO, apriPdfInUnaScheda, fraseDaErroreConFile, frasePerErrore, nomeDalContentDisposition, scaricaFile, testoDelPdf, testoRiepilogo,
    verificaIlFile, type Selezione,
} from '@/lib/gestionale/incassi/ricevute';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { testoInAccordoNellaLettera, testoSoloInAccordo } from '@/lib/gestionale/debiti/accordiRientro';
import {
    FRASE_MODELLI_SENZA_PERMESSO, FRASE_TESTO_SOSTITUITO, modelliDelTipo, testoAggiorna, testoAggiornato, testoModelloMancante, testoSalvato,
    type ModelloDellaFinestra, type ModelloRicordato,
} from '@/lib/gestionale/lettere/modelli';
import {
    CANALI_SPEDIZIONE, FRASE_CONSERVATO, FRASE_FATTO, FRASE_SENZA_IBAN, FRASE_SENZA_TESTO, fraseDelloStudio, FRASE_TESTO_DEL_PROGRAMMA,
    domani, fraseDeiCampi, testoAccordiPassati, testoAccordoPassato, type FormaDelTermine,
    FRASE_TESTO_SINGOLO, SOLO_CARTA, coperteDalCredito, personeDelCanale, personeViste, testoAggiunte, testoConteggi, testoCredito, testoGestione,
    testoOltreTetto, testoRestanti, testoUscite, testiSegnapostiAZero, CANALI_CON_LA_PEC, CANALI_CON_LA_STAMPA, FRASE_DOPO_LE_PEC, opzioniDelCanale,
    testoConfermaPec, testoPecNonComunicate, testoRestantiPec, FRASE_PEC_SENZA_RISPOSTA, FRASE_PEC_NON_COMUNICATA, fraseGiaPartita,
    type CanaleLettera, type PersonaDellAnteprima, type TipoLettera, type VoceDelCanale,
} from '@/lib/gestionale/lettere/lettere';

const props = defineProps<{
    open: boolean;
    condominioId: number;
    /** La lettera di una persona sola (dall'estratto conto o dalla sua riga). */
    persona?: { id: number; nome: string } | null;
    /** Le persone dell'elenco: la lista spuntata o «tutte le N dei filtri». */
    selezione?: Selezione | null;
    filtri?: Record<string, unknown>;
    /** La vista da cui si parte: dalle rate «in scadenza» il tipo di serie è il promemoria. */
    vista?: string;
    /**
     * Il tipo da cui parte la finestra, quando chi la apre propone un passo (il riquadro dei sei mesi: «Manda la diffida»): una proposta visibile, che si
     * cambia qui. Senza, il tipo di serie.
     */
    tipoIniziale?: TipoLettera | null;
    giorni?: number;
    oggi: string;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.${nome}`), { condominio: props.condominioId, ...altro });

/** La casella PEC dello studio, come la manda il server: senza, il perché e dove si imposta (solo a chi apre le impostazioni). */
interface CasellaPec { impostata: boolean; indirizzo: string | null; perche: string | null; dove: string | null; avviso: string | null }

interface Opzioni {
    tipi: Array<{ tipo: TipoLettera; nome: string; senza_email?: boolean; conservato: boolean; canali?: CanaleLettera[] }>;
    /** La casella PEC dello studio. */
    casella_pec?: CasellaPec;
    /** I testi del programma, uno per tipo: non si cambiano. */
    testi: Record<TipoLettera, string>;
    /** I campi che il testo può usare, con la loro descrizione (`TestiLettere::CAMPI`): la riga dei campi li nomina tutti. */
    campi?: Record<string, string>;
    /** I modelli dello studio validi nel condominio, per tutti i tipi, nell'ordine del server. */
    modelli?: ModelloDellaFinestra[];
    /** L'ultimo modello di ogni tipo nel condominio, dalla riga dell'ultima lettera. */
    modelli_ricordati?: Record<string, ModelloRicordato | null>;
    conti: Array<{ id: number; nome: string; iban: string; intestatario: string | null }>;
    /** Il termine ricordato per tipo di lettera (la forma e i giorni); mai per il promemoria. */
    scelta_ricordata: { termini: Record<string, { forma: FormaDelTermine; giorni: number | null } | null>; cassa_id: number | null; indirizzi: string[] | null };
    studio_compilato: boolean;
    // Le lettere escono su una carta intestata che si legge: lo studio lo porta la carta.
    carta_intestata?: boolean;
    senza_server_posta: boolean;
    puo_salvare_il_testo: boolean;
    /** Il termine di serie delle regole del recupero del condominio, se lo dicono. */
    termine_di_serie?: number | null;
}

const opzioni = ref<Opzioni | null>(null);
const tipo = ref<TipoLettera>('sollecito');
const testo = ref('');
const testoToccato = ref(false);
/** Il modello da cui parte il testo: null è il testo del programma. */
const modelloId = ref<number | null>(null);
/** Il nome del modello dell'ultima lettera di questo tipo che non c'è più (o non vale più qui): la finestra lo dice. */
const mancante = ref<string | null>(null);
/** «Salva come modello…»: il nome e «vale per», nessuno già scelto. */
const nuovoModello = ref<{ aperto: boolean; nome: string; valePer: '' | 'condominio' | 'tutti' }>({ aperto: false, nome: '', valePer: '' });
const termine = ref('');
/**
 * La forma del termine: per il sollecito si propone quella dell'ultimo sollecito (o i giorni); per diffida e messa in mora nessuna, mai: la sceglie
 * l'amministratore ogni volta. Il promemoria non ha un termine.
 */
const formaTermine = ref<FormaDelTermine | null>(null);
const dataTermine = ref('');
function terminePer(t: TipoLettera) {
    const ricordato = t === 'sollecito' ? (opzioni.value?.scelta_ricordata.termini?.sollecito ?? null) : null;
    formaTermine.value = t === 'sollecito' ? (ricordato?.forma ?? 'giorni') : null;
    // Senza un termine ricordato, quello di serie delle regole del recupero del condominio, se lo dicono.
    const diSerie = t === 'sollecito' && !ricordato && opzioni.value?.termine_di_serie ? String(opzioni.value.termine_di_serie) : '';
    termine.value = ricordato?.giorni ? String(ricordato.giorni) : diSerie;
    dataTermine.value = '';
}
const cassaId = ref<number | null>(null);
const indirizzi = ref<string[]>(['email', 'email_secondaria']);
const altroIndirizzo = ref('');
/** Le rate in accordo di rientro rimesse nella lettera: una scelta di questa emissione, mai ricordata. */
const ancheInAccordo = ref(false);
const anteprima = ref<any>(null);
const escluse = ref<number[]>([]);
const canali = ref<Record<number, CanaleLettera>>({});
const errore = ref<string | null>(null);
const esito = ref<string[]>([]);
const inCorso = ref(false);
/**
 * Le azioni dell'anteprima già riuscite (l'email, la PEC, la stampa, il .zip): il loro pulsante si spegne finché non si rifà l'anteprima, e un secondo clic
 * non manda di nuovo le stesse lettere. Le altre restano: con «PEC e carta» si manda la PEC e si stampa la copia.
 */
const fatte = ref<string[]>([]);
const fatta = (azione: string) => fatte.value.includes(azione);
const segnaFatta = (azione: string) => { fatte.value = [...fatte.value, azione]; };
/** «Manda N PEC» aspetta la conferma, che dice la casella da cui partono. */
const confermaPec = ref(false);
/** Il numero dell'ultima anteprima chiesta: una risposta arrivata dopo un cambio delle scelte si scarta. */
let numeroAnteprima = 0;
const stampate = ref<Array<{ invio: number; persona: number }>>([]);
const spedizione = ref({ canale: '', data: '', nota: '', numeri: {} as Record<number, string> });
const singola = computed(() => !!props.persona);
const soloCarta = computed(() => tipo.value === 'diffida' || tipo.value === 'messa_in_mora');

watch(() => props.open, async (aperta) => {
    if (!aperta) {
        return;
    }
    tipo.value = props.tipoIniziale ?? (props.vista === 'in_scadenza' ? 'promemoria' : 'sollecito');
    invalida();
    fatte.value = [];
    confermaPec.value = false;
    escluse.value = [];
    canali.value = {};
    errore.value = null;
    esito.value = [];
    stampate.value = [];
    altroIndirizzo.value = '';
    ancheInAccordo.value = false;
    try {
        const r = await axios.get(rotta('opzioni'));
        opzioni.value = r.data;
        nuovoModello.value = { aperto: false, nome: '', valePer: '' };
        partiDalRicordato(tipo.value);
        terminePer(tipo.value);
        // Il conto ricordato solo se è ancora fra i conti di oggi; altrimenti il conto solo, o la scelta.
        const ricordato = r.data.scelta_ricordata.cassa_id;
        cassaId.value = r.data.conti.some((c: { id: number }) => c.id === ricordato) ? ricordato : (r.data.conti.length === 1 ? r.data.conti[0].id : null);
        // La PEC non è un indirizzo dell'email: parte dalla casella PEC dello studio.
        indirizzi.value = (r.data.scelta_ricordata.indirizzi ?? ['email', 'email_secondaria']).filter((t: string) => t !== 'pec');
    } catch (e) {
        errore.value = frasePerErrore(e, 'Le opzioni delle lettere non si sono caricate. Riprova.');
    }
}, { immediate: true });

/** Le scelte sono cambiate: l'anteprima di prima non vale più, e quella in arrivo nemmeno. */
function invalida() {
    anteprima.value = null;
    numeroAnteprima++;
}

/** Il testo di un modello, o quello del programma (null). */
function testoDelModello(id: number | null, t: TipoLettera): string {
    return id === null ? (opzioni.value?.testi[t] ?? '') : (opzioni.value?.modelli?.find((m) => m.id === id)?.testo ?? '');
}
/** Il tipo parte dall'ultimo modello di quel tipo nel condominio, se c'è ancora; altrimenti dal testo del programma. */
function partiDalRicordato(t: TipoLettera) {
    const r = opzioni.value?.modelli_ricordati?.[t] ?? null;
    const valido = !!r && !r.mancante && r.id !== null && (opzioni.value?.modelli ?? []).some((m) => m.id === r.id && m.tipo === t);
    modelloId.value = valido ? r!.id : null;
    mancante.value = r?.mancante ? r.nome : null;
    testo.value = testoDelModello(modelloId.value, t);
    testoToccato.value = false;
}
const modelliDelTipoScelto = computed(() => modelliDelTipo(opzioni.value?.modelli ?? [], tipo.value));
const modelloScelto = computed(() => (opzioni.value?.modelli ?? []).find((m) => m.id === modelloId.value) ?? null);
/**
 * Il testo cambiato a mano che un modello scelto ha sostituito, con il modello da cui era partito: «Torna al testo di prima» lo rimette. Il menu si
 * cambia anche con la rotella o con la tastiera, e un testo scritto a mano non si perde senza rimedio; scritto ancora dopo la scelta, non c'è più.
 */
const testoPrima = ref<{ testo: string; modelloId: number | null } | null>(null);
/** La pagina dei modelli, per chi prepara le lettere: si apre in un'altra scheda, la finestra resta com'è. */
const indirizzoDeiModelli = route('impostazioni.stampe.modelli-lettere');
/** Scegliere un modello riempie il testo, che resta modificabile per questo invio. */
function scegliModello(valore: string) {
    testoPrima.value = testoToccato.value ? { testo: testo.value, modelloId: modelloId.value } : null;
    modelloId.value = valore === '' ? null : Number(valore);
    mancante.value = null;
    testo.value = testoDelModello(modelloId.value, tipo.value);
    testoToccato.value = false;
    invalida();
}
function tornaAlTestoDiPrima() {
    const prima = testoPrima.value;
    if (!prima) {
        return;
    }
    testo.value = prima.testo;
    modelloId.value = prima.modelloId;
    mancante.value = null;
    testoToccato.value = true;
    testoPrima.value = null;
    invalida();
}

// Un altro tipo: il testo riparte dall'ultimo modello del tipo nuovo, se l'amministratore non l'ha cambiato; se l'ha cambiato il testo resta, ma
// non è più del modello del tipo di prima. L'anteprima si rifà.
watch(tipo, (nuovo) => {
    if (!testoToccato.value && opzioni.value) {
        partiDalRicordato(nuovo);
    } else {
        modelloId.value = null;
        mancante.value = null;
    }
    nuovoModello.value = { aperto: false, nome: '', valePer: '' };
    testoPrima.value = null;
    // Il termine del tipo nuovo: si ricorda per tipo di lettera.
    terminePer(nuovo);
    invalida();
});
// La riga «testo di partenza del programma» finché l'amministratore non lo cambia.
const testoDelProgramma = computed(() => soloCarta.value && !testoToccato.value && modelloId.value === null);
const ripartiDalModello = () => {
    testo.value = testoDelModello(modelloId.value, tipo.value);
    testoToccato.value = false;
    invalida();
};

// La gestione dei filtri dell'elenco vale anche per le persone spuntate e per la lettera di una riga: le stesse persone non hanno lettere diverse
// secondo come le si è scelte.
const gestione = () => (props.filtri?.gestione_id ? { gestione_id: props.filtri.gestione_id } : {});
// La lettera di una persona lo dice: il suo modello non diventa quello ricordato del condominio.
const selezione = () => (props.persona
    ? { persone: [props.persona.id], singola: 1, ...gestione() }
    : props.selezione?.tutte ? { tutte: 1, filtri: props.filtri ?? {} } : { persone: props.selezione?.ids ?? [], ...gestione() });
const scelte = () => ({
    tipo: tipo.value, testo: testo.value, modello_id: modelloId.value, cassa_id: cassaId.value, indirizzi: indirizzi.value, giorni: props.giorni,
    // Il promemoria non ha un termine.
    ...(tipo.value === 'promemoria' ? {} : { termine_forma: formaTermine.value, termine: formaTermine.value === 'giorni' ? (termine.value || null) : null,
        termine_data: formaTermine.value === 'data' ? (dataTermine.value || null) : null }),
    ...(ancheInAccordo.value ? { anche_in_accordo: 1 } : {}),
    ...selezione(),
    ...(singola.value && altroIndirizzo.value.trim() !== '' ? { altro_indirizzo: altroIndirizzo.value.trim() } : {}),
});
// Le azioni portano le persone viste nell'anteprima e rimaste, ognuna con il suo canale: chi entra nel filtro dopo non riceve niente. E, a parte,
// tutte le persone dell'anteprima: chi l'amministratore ha tolto non risulta «entrato dopo».
const conConfine = () => ({
    ...scelte(), viste: personeViste(persone.value, escluse.value), in_anteprima: persone.value.map((p) => p.id),
    canali: { ...Object.fromEntries(persone.value.map((p) => [p.id, p.canale])), ...canali.value },
});

const persone = computed<PersonaDellAnteprima[]>(() => anteprima.value?.persone ?? []);
const delCanale = (c: CanaleLettera | CanaleLettera[]) => personeDelCanale(persone.value, canali.value, escluse.value, c);
/** Le lettere di ogni canale, per la riga dei conteggi. */
const perCanale = computed(() => ({ email: delCanale('email').length, pec: delCanale('pec').length, pec_e_carta: delCanale('pec_e_carta').length,
    stampa: delCanale('stampa').length, zip: delCanale('zip').length }));
/**
 * Le persone di ogni azione: la PEC anche per «PEC e carta» (non chi ha già oggi la PEC dello stesso tipo partita: non parte di nuovo), e la stampa anche per
 * le copie su carta.
 */
const conteggi = computed(() => ({ email: perCanale.value.email, pec: delCanale(CANALI_CON_LA_PEC).filter((p) => !p.pec_partita_oggi).length,
    stampa: delCanale(CANALI_CON_LA_STAMPA).length, zip: perCanale.value.zip }));
const lettere = (n: number) => (n === 1 ? '1 lettera' : `${n} lettere`);

const oltreTetto = computed(() => (anteprima.value ? testoOltreTetto(perCanale.value, anteprima.value.tetto) : null));

// La PEC dello studio: la casella, il canale di ogni persona, «Usa la PEC per chi ce l'ha», le PEC non comunicate.
const casella = computed<CasellaPec | null>(() => anteprima.value?.casella_pec ?? opzioni.value?.casella_pec ?? null);
const tipoConLaPec = computed(() => (opzioni.value?.tipi.find((t) => t.tipo === tipo.value)?.canali ?? []).includes('pec'));
const canaleDi = (p: PersonaDellAnteprima): CanaleLettera => canali.value[p.id] ?? p.canale;
const scegliCanale = (id: number, valore: CanaleLettera | null) => {
    if (valore) {
        canali.value = { ...canali.value, [id]: valore };
    }
};
const selezionabile = (o: VoceDelCanale) => !o.spento;
const conLaPec = (p: PersonaDellAnteprima) => CANALI_CON_LA_PEC.includes(canaleDi(p));
/** Chi ha la PEC che si può scegliere e oggi non va per PEC: «Usa la PEC per chi ce l'ha» la mette a tutti. */
const senzaLaPecScelta = computed(() => persone.value.filter((p) => !escluse.value.includes(p.id) && !conLaPec(p)
    && opzioniDelCanale(p).some((o) => o.valore === 'pec' && !o.spento)));
const usaLaPec = () => {
    canali.value = { ...canali.value, ...Object.fromEntries(senzaLaPecScelta.value.map((p) => [p.id, 'pec' as CanaleLettera])) };
};
const pecNonComunicate = computed(() => persone.value.filter((p) => !escluse.value.includes(p.id) && conLaPec(p) && p.pec_non_comunicata).length);
/** Le PEC per volta: oltre, «Manda le altre». */
const tettoPec = computed<number>(() => anteprima.value?.tetto_pec ?? 20);
const righeDellaConferma = computed(() => testoConfermaPec(conteggi.value.pec, tipo.value, casella.value?.indirizzo ?? null, tettoPec.value));
/** Le persone di cui si vede la spiegazione della «i» del badge «PEC non comunicata dalla persona»: si apre al tocco, e con la tastiera. */
const spiegazioni = ref<string[]>([]);
const alternaSpiegazione = (chiave: string) => {
    spiegazioni.value = spiegazioni.value.includes(chiave) ? spiegazioni.value.filter((x) => x !== chiave) : [...spiegazioni.value, chiave];
};
/**
 * Il menu del canale si apre sopra la finestra (`append-to-body`): dentro la finestra che scorre le ultime righe lo taglierebbero. La finestra è `fixed`: il
 * menu prende le coordinate del suo campo, senza lo scorrimento della pagina.
 */
const posizionaIlMenu = (lista: HTMLElement, componente: any, { width }: { width: string }) => {
    lista.style.width = width;
    lista.style.position = 'fixed';
    lista.style.zIndex = '60';
    const campo = componente.$refs.toggle.getBoundingClientRect();
    lista.style.left = `${campo.left}px`;
    lista.style.top = `${campo.bottom}px`;
};
const restantiPec = ref<number[] | null>(null);

async function chiediAnteprima() {
    errore.value = null;
    esito.value = [];
    inCorso.value = true;
    const numero = ++numeroAnteprima;
    const tipoChiesto = tipo.value;
    try {
        const r = await axios.post(rotta('anteprima'), scelte());
        // Arrivata dopo un cambio delle scelte (il tipo, il testo, gli indirizzi): non è l'anteprima di quello che c'è a video.
        if (numero !== numeroAnteprima || tipoChiesto !== tipo.value || r.data?.tipo !== tipo.value) {
            return;
        }
        anteprima.value = r.data;
        fatte.value = [];
        confermaPec.value = false;
        restantiPec.value = null;
        escluse.value = [];
        canali.value = {};
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'anteprima non è riuscita. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const alterna = (id: number) => {
    escluse.value = escluse.value.includes(id) ? escluse.value.filter((x) => x !== id) : [...escluse.value, id];
};
// Chi ha un credito che copre tutta la lettera, ancora nell'invio: si toglie in un clic. Non sceglie il programma: toglie chi l'amministratore
// toglierebbe uno per uno, e lo si rimette con la sua casella.
const coperte = computed(() => coperteDalCredito(persone.value).filter((id) => !escluse.value.includes(id)));
const togliCoperte = () => { escluse.value = [...escluse.value, ...coperte.value]; };

function dopoLeRighe(intestazione: unknown) {
    const testo = typeof intestazione === 'string' ? intestazione : '';
    stampate.value = testo.split(',').filter(Boolean).map((coppia) => {
        const [invio, persona] = coppia.split(':').map(Number);

        return { invio, persona };
    });
    spedizione.value = { canale: '', data: props.oggi, nota: '', numeri: {} };
}

function stampa(anteprimaPdf = false) {
    errore.value = null;
    let risposta: any = null;
    // La scheda si apre nel clic, prima della richiesta: niente `await` prima di questa chiamata.
    apriPdfInUnaScheda(async () => {
        risposta = await axios.post(rotta(anteprimaPdf ? 'pdf-anteprima' : 'stampa'), conConfine(), { responseType: 'blob' });

        return risposta;
    }, 'lettere.pdf', undefined, undefined, { scaricaSeChiusa: !anteprimaPdf }).then((arrivato) => {
        const frase = testoDelPdf(arrivato, { stampa: !anteprimaPdf, una: conteggi.value.stampa === 1, documento: 'lettera' });
        esito.value = [frase, testoAggiunte(Number(risposta?.headers?.['x-persone-aggiunte'] ?? 0)), anteprimaPdf ? null : FRASE_FATTO].filter((x): x is string => !!x);
        if (!anteprimaPdf) {
            segnaFatta('stampa');
            dopoLeRighe(risposta?.headers?.['x-lettere-invii']);
            emit('fatto');
        }
    }).catch(async (e) => {
        errore.value = await fraseDaErroreConFile(e, 'Il PDF non è stato fatto. Riprova.');
    });
}

async function scaricaZip() {
    errore.value = null;
    inCorso.value = true;
    try {
        const r = await axios.post(rotta('pdf-per-persona'), conConfine(), { responseType: 'blob' });
        await verificaIlFile(r, 'zip');
        scaricaFile(r.data, nomeDalContentDisposition(r.headers?.['content-disposition'], 'lettere-per-persona.zip'));
        esito.value = [`${lettere(conteggi.value.zip)} in un PDF per persona: l'archivio si scarica.`, testoAggiunte(Number(r.headers?.['x-persone-aggiunte'] ?? 0)), FRASE_FATTO]
            .filter((x): x is string => !!x);
        segnaFatta('zip');
        dopoLeRighe(r.headers?.['x-lettere-invii']);
        emit('fatto');
    } catch (e) {
        errore.value = await fraseDaErroreConFile(e, 'L\'archivio non è stato fatto. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

async function invia(soloLe?: number[]) {
    errore.value = null;
    inCorso.value = true;
    try {
        const corpo = soloLe ? { ...conConfine(), viste: soloLe } : conConfine();
        const r = (await axios.post(rotta('invia'), corpo)).data;
        const senza = r.senza_indirizzo?.length ? `Senza un indirizzo fra quelli scelti: ${r.senza_indirizzo.join(', ')}. Stampale per la posta.` : null;
        esito.value = [testoRiepilogo({ inviate: r.inviate, in_coda: r.in_coda, non_partite: r.non_partite, senza_indirizzo: r.senza_indirizzo?.length ?? 0 }),
            ...(r.errori ?? []), senza, testoAggiunte(r.aggiunte ?? 0), testoUscite(r.uscite ?? 0), FRASE_FATTO].filter((x): x is string => !!x);
        segnaFatta('email');
        restanti.value = r.restanti?.persone ?? null;
        const resto = testoRestanti(restanti.value?.length ?? 0);
        if (resto) {
            esito.value = [...esito.value.filter((x) => x !== FRASE_FATTO), resto, FRASE_FATTO];
        }
        emit('fatto');
    } catch (e) {
        errore.value = frasePerErrore(e, 'Le lettere non sono partite. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
const restanti = ref<number[] | null>(null);

/**
 * «Manda N PEC», dopo la conferma: le persone con la PEC dello studio o «PEC e carta» (le altre dell'anteprima vanno con la loro azione), oltre il tetto «Manda
 * le altre». La frase è quella del server: quante sono partite, e perché le altre no.
 */
async function mandaPec(soloLe?: number[]) {
    errore.value = null;
    confermaPec.value = false;
    inCorso.value = true;
    try {
        const corpo = soloLe ? { ...conConfine(), viste: soloLe } : conConfine();
        const r = (await axios.post(rotta('pec'), corpo)).data;
        restantiPec.value = r.restanti?.persone?.length ? r.restanti.persone : null;
        esito.value = [r.frase, r.partite > 0 ? FRASE_DOPO_LE_PEC : null, testoRestantiPec(restantiPec.value?.length ?? 0), testoAggiunte(r.aggiunte ?? 0),
            testoUscite(r.uscite ?? 0), FRASE_FATTO].filter((x): x is string => !!x);
        segnaFatta('pec');
        emit('fatto');
    } catch (e) {
        // Senza la risposta del programma alcune PEC possono essere partite: prima di rimandarle si guarda (il programma non manda la stessa PEC due volte).
        errore.value = frasePerErrore(e, FRASE_PEC_SENZA_RISPOSTA);
    } finally {
        inCorso.value = false;
    }
}

async function provaAMe() {
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta('prova'), conConfine())).data;
        esito.value = [`Mandata a ${r.indirizzo}: è la lettera di ${r.persona}, con «PROVA» in testa.`];
    } catch (e) {
        errore.value = frasePerErrore(e, 'La prova non è partita. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

/**
 * Dopo il salvataggio il testo a video è quello del modello, come lo ha scritto il server; se intanto l'amministratore ha scritto ancora, il suo
 * testo resta, ed è un testo cambiato rispetto al modello.
 */
function dopoIlModello(modelli: ModelloDellaFinestra[], modello: ModelloDellaFinestra, inviato: string) {
    if (opzioni.value) {
        opzioni.value.modelli = modelli;
    }
    modelloId.value = modello.id;
    mancante.value = null;
    if (testo.value !== inviato) {
        testoToccato.value = true;

        return;
    }
    if (testo.value !== modello.testo) {
        testo.value = modello.testo;
        invalida();
    }
    testoToccato.value = false;
}

async function salvaModello() {
    errore.value = null;
    const scelto = nuovoModello.value;
    if (!scelto.nome.trim() || scelto.valePer === '') {
        return;
    }
    const tipoChiesto = tipo.value;
    const inviato = testo.value;
    inCorso.value = true;
    try {
        const r = await axios.post(rotta('modelli.store'), { tipo: tipoChiesto, nome: scelto.nome.trim(), testo: inviato, per_tutti: scelto.valePer === 'tutti' });
        // Arrivata dopo un cambio del tipo: il modello è salvato, ma non è del tipo a video.
        if (tipoChiesto !== tipo.value) {
            if (opzioni.value) {
                opzioni.value.modelli = r.data.modelli;
            }

            return;
        }
        dopoIlModello(r.data.modelli, r.data.modello, inviato);
        nuovoModello.value = { aperto: false, nome: '', valePer: '' };
        esito.value = [testoSalvato(r.data.modello.nome, r.data.modello.per_tutti)];
    } catch (e) {
        errore.value = frasePerErrore(e, 'Il modello non è stato salvato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

async function aggiornaModello() {
    const modello = modelloScelto.value;
    if (!modello) {
        return;
    }
    errore.value = null;
    const inviato = testo.value;
    inCorso.value = true;
    try {
        const r = await axios.put(rotta('modelli.update', { modello: modello.id }), { testo: inviato });
        // Il modello di partenza è cambiato nel frattempo (un altro modello, un altro tipo): il modello è aggiornato, il menu resta com'è.
        if (modelloId.value !== modello.id) {
            if (opzioni.value) {
                opzioni.value.modelli = r.data.modelli;
            }

            return;
        }
        dopoIlModello(r.data.modelli, r.data.modello, inviato);
        esito.value = [testoAggiornato(r.data.modello.nome, r.data.modello.per_tutti)];
    } catch (e) {
        errore.value = frasePerErrore(e, 'Il modello non è stato aggiornato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const nomeDi = (persona: number) => persone.value.find((p) => p.id === persona)?.nome ?? '';
async function registraSpedizione() {
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta('spedizione'), {
            invii: stampate.value.map((s) => s.invio), canale: spedizione.value.canale, data: spedizione.value.data, nota: spedizione.value.nota || null,
            numeri: spedizione.value.numeri,
        })).data;
        esito.value = [r.spedite === 1 ? 'Spedizione registrata per 1 lettera.' : `Spedizione registrata per ${r.spedite} lettere.`];
        stampate.value = [];
        emit('fatto');
    } catch (e) {
        errore.value = frasePerErrore(e, 'La spedizione non è stata registrata. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const contoScelto = computed(() => opzioni.value?.conti.find((c) => c.id === cassaId.value) ?? null);
/** Gli indirizzi dell'email: la primaria e la secondaria. La PEC parte dalla casella PEC dello studio, con il suo canale. */
const tipiIndirizzo = ['email', 'email_secondaria'];
/** Le voci del menu «Modello»: il testo del programma, poi i modelli del condominio e quelli per tutti, con il gruppo sotto il nome. */
const vociDelModello = computed(() => [
    { valore: null as number | null, nome: 'Testo del programma', gruppo: null as string | null },
    ...modelliDelTipoScelto.value.delCondominio.map((m) => ({ valore: m.id as number | null, nome: m.nome, gruppo: 'Per questo condominio' as string | null })),
    ...modelliDelTipoScelto.value.perTutti.map((m) => ({ valore: m.id as number | null, nome: m.nome, gruppo: 'Per tutti i condomini' as string | null })),
]);
const vociDelConto = computed(() => (opzioni.value?.conti ?? []).map((c) => ({ id: c.id, nome: `${c.nome} — ${c.iban}` })));
const vociDellaSpedizione = Object.entries(CANALI_SPEDIZIONE).map(([valore, nome]) => ({ valore, nome }));
const alternaIndirizzo = (t: string) => {
    indirizzi.value = indirizzi.value.includes(t) ? indirizzi.value.filter((x) => x !== t) : [...indirizzi.value, t];
    invalida();
};
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ persona ? `Lettera a ${persona.nome}` : 'Prepara le lettere' }}</DialogTitle>
                <DialogDescription>Le rate e il totale sono quelli di oggi; la lettera dice che i versamenti registrati dopo non sono compresi.</DialogDescription>
            </DialogHeader>

            <div v-if="opzioni" class="space-y-4 text-sm">
                <!-- Il tipo: lo sceglie l'amministratore, ogni volta. -->
                <fieldset class="space-y-1">
                    <legend class="text-xs text-slate-500 dark:text-slate-400">Tipo della lettera</legend>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="t in opzioni.tipi" :key="t.tipo" class="flex items-center gap-1 rounded-md border px-2 py-1 text-xs" :data-testid="`tipo-${t.tipo}`">
                            <input v-model="tipo" type="radio" name="tipo-lettera" :value="t.tipo" /> {{ t.nome }}
                        </label>
                    </div>
                    <p v-if="soloCarta" class="text-xs text-amber-800 dark:text-amber-300" data-testid="solo-carta">{{ SOLO_CARTA }}</p>
                    <p v-if="tipo !== 'promemoria'" class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_CONSERVATO }}</p>
                </fieldset>

                <!-- Il modello e il testo -->
                <div class="space-y-1">
                    <label class="block space-y-1">
                        <span class="flex flex-wrap items-baseline gap-2 text-xs text-slate-500 dark:text-slate-400">
                            Modello
                            <a :href="indirizzoDeiModelli" target="_blank" rel="noopener" class="underline hover:text-slate-700 dark:hover:text-slate-200"
                                data-testid="vai-ai-modelli">I modelli delle lettere</a>
                        </span>
                        <v-select :model-value="modelloId" :options="vociDelModello" label="nome" :reduce="(v: { valore: number | null }) => v.valore" :clearable="false"
                            :searchable="false" class="w-full max-w-md text-xs" data-testid="modello"
                            @update:model-value="(v: number | null) => scegliModello(v === null ? '' : String(v))">
                            <template #option="v">
                                <span class="block text-sm">{{ v.nome }}</span>
                                <span v-if="v.gruppo" class="block text-xs text-slate-500 dark:text-slate-400">{{ v.gruppo }}</span>
                            </template>
                        </v-select>
                    </label>
                    <p v-if="mancante !== null" class="text-xs text-amber-800 dark:text-amber-300" data-testid="modello-mancante">{{ testoModelloMancante(mancante) }}</p>
                    <label class="block text-xs text-slate-500 dark:text-slate-400" for="testo-lettera">Testo</label>
                    <p v-if="testoPrima" class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        {{ FRASE_TESTO_SOSTITUITO }}
                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="torna-al-testo" @click="tornaAlTestoDiPrima">Torna al testo di prima</Button>
                    </p>
                    <Textarea id="testo-lettera" v-model="testo" rows="6" data-testid="testo" @update:model-value="testoToccato = true; testoPrima = null; invalida()" />
                    <p v-if="fraseDeiCampi(opzioni.campi)" class="text-xs text-slate-500 dark:text-slate-400" data-testid="frase-campi">{{ fraseDeiCampi(opzioni.campi) }}</p>
                    <p v-if="!testo.trim() && soloCarta" class="text-xs text-amber-800 dark:text-amber-300">{{ FRASE_SENZA_TESTO }}</p>
                    <p v-else-if="testoDelProgramma" class="text-xs text-amber-800 dark:text-amber-300" data-testid="testo-del-programma">{{ FRASE_TESTO_DEL_PROGRAMMA }}</p>
                    <p v-if="singola" class="text-xs text-slate-500 dark:text-slate-400" data-testid="testo-singolo">{{ FRASE_TESTO_SINGOLO }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="testoToccato" size="sm" variant="ghost" class="h-7 text-xs" @click="ripartiDalModello">
                            {{ modelloScelto ? `Riparti da «${modelloScelto.nome}»` : 'Riparti dal testo del programma' }}
                        </Button>
                        <Button v-if="opzioni.puo_salvare_il_testo" size="sm" variant="outline" class="h-7 text-xs" data-testid="salva-modello"
                            :disabled="inCorso" @click="nuovoModello = { aperto: true, nome: '', valePer: '' }">
                            Salva come modello…
                        </Button>
                        <!-- «Aggiorna» solo dal blocco: il testo scritto per una persona non diventa il modello di tutte le lettere. -->
                        <Button v-if="!singola && opzioni.puo_salvare_il_testo && modelloScelto && testoToccato" size="sm" variant="outline" class="h-7 text-xs"
                            data-testid="aggiorna-modello" :disabled="inCorso" @click="aggiornaModello">
                            {{ testoAggiorna(modelloScelto.nome, modelloScelto.per_tutti) }}
                        </Button>
                    </div>
                    <div v-if="nuovoModello.aperto" class="space-y-2 rounded-lg border p-2 text-xs" data-testid="nuovo-modello">
                        <label class="block space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Nome del modello</span>
                            <Input v-model="nuovoModello.nome" maxlength="120" class="h-8 text-xs" data-testid="nome-modello" />
                        </label>
                        <fieldset class="space-y-1">
                            <legend class="text-slate-500 dark:text-slate-400">Vale per</legend>
                            <label class="flex items-center gap-1"><input v-model="nuovoModello.valePer" type="radio" name="vale-per" value="condominio" /> solo questo condominio</label>
                            <label class="flex items-center gap-1"><input v-model="nuovoModello.valePer" type="radio" name="vale-per" value="tutti" /> tutti i condomini</label>
                        </fieldset>
                        <div class="flex gap-2">
                            <Button size="sm" class="h-7 text-xs" data-testid="conferma-modello" :disabled="inCorso || !nuovoModello.nome.trim() || nuovoModello.valePer === ''"
                                @click="salvaModello">Salva il modello</Button>
                            <Button size="sm" variant="ghost" class="h-7 text-xs" @click="nuovoModello = { aperto: false, nome: '', valePer: '' }">Annulla</Button>
                        </div>
                    </div>
                    <p v-if="!opzioni.puo_salvare_il_testo" class="text-xs text-slate-500 dark:text-slate-400" data-testid="modelli-senza-permesso">{{ FRASE_MODELLI_SENZA_PERMESSO }}</p>
                </div>

                <!-- Il termine, poi il conto in una riga sua: accanto alle forme del termine sembrava una di loro. -->
                <div class="space-y-2">
                    <fieldset v-if="tipo !== 'promemoria'" class="space-y-1 text-xs" data-testid="termine-forma">
                        <legend class="text-slate-500 dark:text-slate-400">Termine per il versamento</legend>
                        <label class="flex items-center gap-1">
                            <input v-model="formaTermine" type="radio" name="termine-forma" value="giorni" @change="invalida()" />
                            <Input v-model="termine" type="number" min="1" max="365" class="h-7 w-20 text-xs" data-testid="termine" :disabled="formaTermine !== 'giorni'" @update:model-value="invalida()" />
                            giorni dal ricevimento della lettera
                        </label>
                        <label class="flex items-center gap-1">
                            <input v-model="formaTermine" type="radio" name="termine-forma" value="data" @change="invalida()" /> entro il
                            <Input v-model="dataTermine" type="date" :min="domani(oggi)" class="h-7 w-[150px] text-xs" data-testid="termine-data" :disabled="formaTermine !== 'data'" @update:model-value="invalida()" />
                        </label>
                        <label class="flex items-center gap-1">
                            <input v-model="formaTermine" type="radio" name="termine-forma" value="nessuno" @change="invalida()" /> nessun termine nella lettera
                        </label>
                    </fieldset>
                    <div>
                        <label v-if="opzioni.conti.length > 1" class="space-y-1">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Conto per il bonifico</span>
                            <v-select v-model="cassaId" :options="vociDelConto" label="nome" :reduce="(c: { id: number }) => c.id" :clearable="false" :searchable="false"
                                placeholder="Scegli il conto" class="w-full max-w-md text-xs" data-testid="conto" />
                        </label>
                        <p v-else-if="contoScelto" class="text-xs" data-testid="iban">IBAN {{ contoScelto.iban }}</p>
                    </div>
                </div>
                <p v-if="opzioni.conti.length === 0" class="text-xs text-amber-800 dark:text-amber-300">{{ FRASE_SENZA_IBAN }}</p>
                <p v-if="fraseDelloStudio(opzioni)" class="text-xs text-slate-600 dark:text-slate-300" data-testid="studio-non-compilato">{{ fraseDelloStudio(opzioni) }}</p>

                <fieldset v-if="!soloCarta" class="space-y-1">
                    <legend class="text-xs text-slate-500 dark:text-slate-400">Per email, agli indirizzi</legend>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <label v-for="t in tipiIndirizzo" :key="t" class="flex items-center gap-1">
                            <Checkbox :model-value="indirizzi.includes(t)" @update:model-value="alternaIndirizzo(t)" /> {{ TIPI_INDIRIZZO[t] }}
                        </label>
                    </div>
                    <label v-if="singola" class="block space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Un altro indirizzo (solo della persona o di chi la rappresenta)</span>
                        <Input v-model="altroIndirizzo" type="email" class="h-8 text-xs" data-testid="altro-indirizzo" />
                    </label>
                </fieldset>

                <Button size="sm" :disabled="inCorso" data-testid="anteprima" @click="chiediAnteprima">Anteprima: chi riceve che cosa</Button>

                <!-- L'anteprima -->
                <div v-if="anteprima" class="space-y-2" data-testid="anteprima-persone">
                    <p class="text-xs">{{ testoConteggi(perCanale) }}</p>
                    <!-- La PEC dello studio: senza la casella, il perché e «Imposta»; con la casella, «Usa la PEC per chi ce l'ha» e la riga delle PEC non comunicate. -->
                    <p v-if="tipoConLaPec && casella && !casella.impostata" class="flex flex-wrap items-center gap-2 text-xs text-amber-800 dark:text-amber-300" data-testid="casella-da-impostare">
                        <span>PEC dello studio: {{ casella.perche }}</span>
                        <a v-if="casella.dove" :href="casella.dove" target="_blank" rel="noopener" class="underline" data-testid="imposta-casella-pec">Imposta la casella PEC</a>
                    </p>
                    <Button v-if="tipoConLaPec && casella?.impostata && senzaLaPecScelta.length" size="sm" variant="outline" class="h-7 text-xs" data-testid="usa-la-pec" @click="usaLaPec">
                        Usa la PEC per chi ce l'ha ({{ senzaLaPecScelta.length }})
                    </Button>
                    <p v-if="testoPecNonComunicate(pecNonComunicate)" class="text-xs text-amber-800 dark:text-amber-300" data-testid="pec-non-comunicate">{{ testoPecNonComunicate(pecNonComunicate) }}</p>
                    <p v-if="testoGestione(anteprima.gestione)" class="text-xs" data-testid="gestione-lettere">{{ testoGestione(anteprima.gestione) }}</p>
                    <p v-for="c in anteprima.credito" :key="c.nome" class="text-xs text-sky-800 dark:text-sky-300">{{ testoCredito(c) }}</p>
                    <p v-if="testoAccordiPassati(persone.filter((p) => p.accordo_passato).map((p) => p.nome))" class="text-xs text-amber-800 dark:text-amber-300" data-testid="accordi-passati">
                        {{ testoAccordiPassati(persone.filter((p) => p.accordo_passato).map((p) => p.nome)) }}
                    </p>
                    <Button v-if="coperte.length" size="sm" variant="outline" class="h-7 text-xs" data-testid="togli-coperti" @click="togliCoperte">
                        Togli chi ha un credito che copre la lettera ({{ coperte.length }})
                    </Button>
                    <p v-for="f in testiSegnapostiAZero(testo, anteprima)" :key="f" class="text-xs text-slate-600 dark:text-slate-300" data-testid="segnaposti-a-zero">{{ f }}</p>
                    <p v-if="anteprima.senza_niente.length" class="text-xs text-slate-600 dark:text-slate-300">
                        Niente da scrivere in questa lettera per: {{ anteprima.senza_niente.join(', ') }}.
                    </p>
                    <p v-if="testoSoloInAccordo(anteprima.solo_in_accordo ?? [])" class="text-xs text-sky-800 dark:text-sky-300" data-testid="solo-in-accordo">
                        {{ testoSoloInAccordo(anteprima.solo_in_accordo ?? []) }}
                    </p>
                    <label v-if="ancheInAccordo || (anteprima.solo_in_accordo ?? []).length || persone.some((p) => (p.in_accordo ?? 0) > 0)" class="flex items-center gap-2 text-xs" data-testid="anche-in-accordo">
                        <Checkbox :model-value="ancheInAccordo" @update:model-value="(v: any) => { ancheInAccordo = !!v; invalida(); }" />
                        Metti nella lettera anche le rate in accordo di rientro
                    </label>
                    <table class="hidden w-full text-xs sm:table">
                        <thead class="text-slate-500 dark:text-slate-400">
                            <tr><th class="p-1 text-left">Nell'invio</th><th class="p-1 text-left">Persona</th><th class="p-1 text-right">Totale</th><th class="p-1 text-left">Canale</th><th class="p-1 text-left">Dove</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in persone" :key="p.id" class="border-t align-top" data-testid="persona-anteprima">
                                <td class="p-1"><Checkbox :model-value="!escluse.includes(p.id)" :aria-label="`Togli ${p.nome}`" @update:model-value="alterna(p.id)" /></td>
                                <td class="p-1">
                                    {{ p.nome }}
                                    <span v-if="p.senza_indirizzo_postale" class="block text-xs text-slate-500 dark:text-slate-400">senza indirizzo di casa: in alto c'è il solo nome, senza indirizzo</span>
                                    <span v-if="testoAccordoPassato(p.accordo_passato)" class="block text-xs text-amber-800 dark:text-amber-300" data-testid="accordo-passato">{{ testoAccordoPassato(p.accordo_passato) }}</span>
                                </td>
                                <td class="p-1 text-right whitespace-nowrap">
                                    {{ euroTesto(p.totale) }} <span class="block text-xs text-slate-500 dark:text-slate-400">{{ p.righe === 1 ? '1 rata' : `${p.righe} rate` }}</span>
                                    <span v-if="testoInAccordoNellaLettera(p.in_accordo ?? 0)" class="block whitespace-normal text-xs text-sky-800 dark:text-sky-300" data-testid="in-accordo">{{ testoInAccordoNellaLettera(p.in_accordo ?? 0) }}</span>
                                </td>
                                <td class="min-w-[12rem] p-1">
                                    <v-select :model-value="canaleDi(p)" :options="opzioniDelCanale(p)" label="nome" :reduce="(o: VoceDelCanale) => o.valore" :selectable="selezionabile"
                                        :clearable="false" :searchable="false" append-to-body :calculate-position="posizionaIlMenu" class="text-xs" :data-testid="`canale-${p.id}`"
                                        @update:model-value="(v: CanaleLettera | null) => scegliCanale(p.id, v)">
                                        <template #option="o">
                                            <span class="block text-sm">{{ o.nome }}</span>
                                            <span class="block text-xs" :class="o.spento ? 'text-amber-800 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400'">{{ o.sotto }}</span>
                                        </template>
                                    </v-select>
                                </td>
                                <td class="p-1">
                                    <template v-if="canaleDi(p) === 'email'">
                                        <span v-if="p.indirizzi.length">{{ p.indirizzi.map((i) => i.indirizzo).join(', ') }}</span>
                                        <span v-else class="text-amber-800 dark:text-amber-300">{{ p.frase_solo_pec ?? 'Nessun indirizzo fra quelli scelti: stampala per la posta.' }}</span>
                                    </template>
                                    <template v-else-if="conLaPec(p)">
                                        <span class="block break-all">{{ p.pec }}</span>
                                        <span v-if="p.pec_partita_oggi" class="mt-1 block text-xs text-slate-600 dark:text-slate-300" :data-testid="`pec-partita-oggi-${p.id}`">
                                            {{ fraseGiaPartita(p.pec_partita_oggi) }}
                                        </span>
                                        <span v-if="p.pec_non_comunicata" class="mt-1 flex flex-wrap items-center gap-1">
                                            <span class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                                                :data-testid="`pec-non-comunicata-${p.id}`">
                                                PEC non comunicata dalla persona
                                                <button type="button" class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-current text-xs leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                                    :aria-label="'Perché «PEC non comunicata dalla persona»'" :aria-expanded="spiegazioni.includes(`t${p.id}`)" :data-testid="`perche-pec-${p.id}`"
                                                    @click="alternaSpiegazione(`t${p.id}`)">i</button>
                                            </span>
                                            <a v-if="p.scheda" :href="p.scheda" target="_blank" rel="noopener" class="text-xs underline" :data-testid="`scheda-${p.id}`">Apri la scheda</a>
                                        </span>
                                        <span v-if="p.pec_non_comunicata && spiegazioni.includes(`t${p.id}`)" class="mt-1 block text-xs text-slate-600 dark:text-slate-300"
                                            :data-testid="`spiegazione-pec-${p.id}`">{{ FRASE_PEC_NON_COMUNICATA }}</span>
                                    </template>
                                    <span v-else-if="p.frase_solo_pec" class="text-amber-800 dark:text-amber-300">{{ p.frase_solo_pec }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Al telefono: una card per persona, con il canale sotto il nome. -->
                    <ul class="space-y-2 sm:hidden">
                        <li v-for="p in persone" :key="p.id" class="space-y-1 rounded-lg border p-2 text-xs" data-testid="card-persona">
                            <div class="flex items-start justify-between gap-2">
                                <label class="flex items-center gap-2 font-medium">
                                    <Checkbox :model-value="!escluse.includes(p.id)" :aria-label="`Togli ${p.nome} (telefono)`" @update:model-value="alterna(p.id)" /> {{ p.nome }}
                                </label>
                                <span class="whitespace-nowrap">{{ euroTesto(p.totale) }}</span>
                            </div>
                            <v-select :model-value="canaleDi(p)" :options="opzioniDelCanale(p)" label="nome" :reduce="(o: VoceDelCanale) => o.valore" :selectable="selezionabile"
                                :clearable="false" :searchable="false" append-to-body :calculate-position="posizionaIlMenu" class="text-xs" :data-testid="`canale-card-${p.id}`"
                                @update:model-value="(v: CanaleLettera | null) => scegliCanale(p.id, v)" />
                            <p v-if="conLaPec(p)" class="break-all text-slate-600 dark:text-slate-300">{{ p.pec }}</p>
                            <p v-if="conLaPec(p) && p.pec_partita_oggi" class="text-slate-600 dark:text-slate-300">{{ fraseGiaPartita(p.pec_partita_oggi) }}</p>
                            <div v-if="conLaPec(p) && p.pec_non_comunicata" class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                    PEC non comunicata dalla persona
                                    <button type="button" class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-current text-xs leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                        :aria-label="'Perché «PEC non comunicata dalla persona»'" :aria-expanded="spiegazioni.includes(`c${p.id}`)" :data-testid="`perche-pec-card-${p.id}`"
                                        @click="alternaSpiegazione(`c${p.id}`)">i</button>
                                </span>
                                <a v-if="p.scheda" :href="p.scheda" target="_blank" rel="noopener" class="underline" :data-testid="`scheda-card-${p.id}`">Apri la scheda</a>
                            </div>
                            <p v-if="conLaPec(p) && p.pec_non_comunicata && spiegazioni.includes(`c${p.id}`)" class="text-slate-600 dark:text-slate-300">{{ FRASE_PEC_NON_COMUNICATA }}</p>
                            <p v-else-if="!conLaPec(p) && p.frase_solo_pec" class="text-amber-800 dark:text-amber-300">{{ p.frase_solo_pec }}</p>
                        </li>
                    </ul>
                    <p v-if="oltreTetto" class="text-xs text-amber-800 dark:text-amber-300" data-testid="oltre-tetto">{{ oltreTetto }}</p>
                    <p v-if="anteprima.senza_server_posta && conteggi.email > 0" class="text-xs text-amber-800 dark:text-amber-300">
                        Nessun server di posta configurato: le lettere non partono per email. Intanto puoi stamparle.
                    </p>
                </div>

                <!-- Dopo la stampa: la spedizione, in blocco. -->
                <div v-if="stampate.length" class="space-y-2 rounded-lg border p-2" data-testid="registra-spedizione">
                    <p class="text-xs font-medium">Registra la spedizione {{ stampate.length === 1 ? 'della lettera' : `delle ${stampate.length} lettere` }}, quando l'hai fatta</p>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <v-select v-model="spedizione.canale" :options="vociDellaSpedizione" label="nome" :reduce="(c: { valore: string }) => c.valore" :clearable="false"
                            :searchable="false" append-to-body :calculate-position="posizionaIlMenu" placeholder="Come" class="min-w-[12rem] text-xs" data-testid="canale-spedizione" />
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-8 w-[150px] text-xs" />
                        <Input v-model="spedizione.nota" placeholder="Nota" class="h-8 w-[200px] text-xs" />
                    </div>
                    <div v-for="s in stampate" :key="s.invio" class="flex items-center gap-2 text-xs">
                        <span class="w-40 truncate">{{ nomeDi(s.persona) }}</span>
                        <Input v-model="spedizione.numeri[s.invio]" placeholder="Numero o estremi" class="h-7 w-[200px] text-xs" />
                    </div>
                    <Button size="sm" variant="outline" :disabled="inCorso || !spedizione.canale" @click="registraSpedizione">Registra la spedizione</Button>
                </div>

                <!-- La conferma di «Manda N PEC»: da quale casella partono, che cosa vuol dire «partita», dove arriva la ricevuta. -->
                <div v-if="confermaPec" class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950/40" role="alertdialog"
                    data-testid="conferma-pec">
                    <p class="text-sm font-semibold">{{ righeDellaConferma[0] }}</p>
                    <ul class="list-disc space-y-0.5 pl-4 text-xs">
                        <li v-for="r in righeDellaConferma.slice(1)" :key="r">{{ r }}</li>
                    </ul>
                    <p v-if="casella?.avviso" class="text-xs text-amber-800 dark:text-amber-300">{{ casella.avviso }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" variant="ghost" @click="confermaPec = false">Annulla</Button>
                        <Button size="sm" :disabled="inCorso" data-testid="conferma-manda-pec" @click="mandaPec()">Manda {{ Math.min(conteggi.pec, tettoPec) }} PEC</Button>
                    </div>
                </div>

                <p v-for="(riga, i) in esito" :key="i" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ riga }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
            <p v-else-if="errore" class="text-xs text-red-700 dark:text-red-400">{{ errore }}</p>

            <DialogFooter v-if="anteprima" class="flex flex-wrap gap-2">
                <Button size="sm" variant="ghost" :disabled="inCorso" data-testid="pdf-anteprima" @click="stampa(true)">Vedi il PDF</Button>
                <Button v-if="conteggi.email > 0" size="sm" variant="outline" :disabled="inCorso || fatta('email')" data-testid="prova" @click="provaAMe">Mandane una a me</Button>
                <Button v-if="conteggi.email > 0 && !soloCarta" size="sm" :disabled="inCorso || fatta('email')" data-testid="invia" @click="invia()">Invia {{ conteggi.email === 1 ? '1 email' : `${conteggi.email} email` }}</Button>
                <Button v-if="restanti?.length" size="sm" :disabled="inCorso" @click="invia(restanti)">Invia le altre</Button>
                <Button v-if="conteggi.stampa > 0" size="sm" :disabled="inCorso || fatta('stampa')" data-testid="stampa" @click="stampa()">Stampa {{ lettere(conteggi.stampa) }}</Button>
                <Button v-if="conteggi.zip > 0" size="sm" :disabled="inCorso || fatta('zip')" data-testid="zip" @click="scaricaZip">Scarica {{ lettere(conteggi.zip) }} in un PDF per persona</Button>
                <Button v-if="conteggi.pec > 0" size="sm" :disabled="inCorso || fatta('pec') || !casella?.impostata" data-testid="manda-pec" @click="confermaPec = true">
                    Manda {{ conteggi.pec }} PEC
                </Button>
                <Button v-if="restantiPec?.length" size="sm" :disabled="inCorso" data-testid="manda-le-altre" @click="mandaPec(restantiPec)">Manda le altre</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
