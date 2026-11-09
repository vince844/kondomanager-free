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
 * Poi l'anteprima di chi riceve che cosa: per persona il totale, il credito accanto, il canale proposto (che si cambia), gli indirizzi; «togli»
 * una persona. Le azioni lavorano sulle persone viste nell'anteprima, ognuna con il suo canale: l'email (solo promemoria e sollecito), la
 * stampa, il PDF per persona; «Mandane una a me» manda la prima lettera per email a chi lavora. Dopo la stampa, la spedizione si registra in
 * blocco. Dopo un'azione riuscita i pulsanti si spengono: per un altro invio si rifà l'anteprima. La gestione dei filtri dell'elenco vale anche
 * per le persone spuntate; un'anteprima arrivata dopo un cambio delle scelte si scarta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AVVISO_PEC, TIPI_INDIRIZZO, apriPdfInUnaScheda, fraseDaErroreConFile, frasePerErrore, nomeDalContentDisposition, scaricaFile, testoDelPdf, testoRiepilogo,
    verificaIlFile, type Selezione,
} from '@/lib/gestionale/incassi/ricevute';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { testoInAccordoNellaLettera, testoSoloInAccordo } from '@/lib/gestionale/debiti/accordiRientro';
import {
    FRASE_MODELLI_SENZA_PERMESSO, FRASE_TESTO_SOSTITUITO, modelliDelTipo, testoAggiorna, testoAggiornato, testoModelloMancante, testoSalvato,
    type ModelloDellaFinestra, type ModelloRicordato,
} from '@/lib/gestionale/lettere/modelli';
import {
    CANALI_SPEDIZIONE, FRASE_CAMPI, FRASE_CONSERVATO, FRASE_FATTO, FRASE_SENZA_IBAN, FRASE_SENZA_TESTO, fraseDelloStudio, FRASE_TESTO_DEL_PROGRAMMA,
    domani, testoAccordiPassati, testoAccordoPassato, type FormaDelTermine,
    FRASE_TESTO_SINGOLO, NOMI_CANALI, SOLO_CARTA, coperteDalCredito, personeDelCanale, personeViste, testoAggiunte, testoConteggi, testoCredito, testoGestione,
    testoOltreTetto, testoRestanti, testoUscite,
    type CanaleLettera, type PersonaDellAnteprima, type TipoLettera,
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
    giorni?: number;
    oggi: string;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.${nome}`), { condominio: props.condominioId, ...altro });

interface Opzioni {
    tipi: Array<{ tipo: TipoLettera; nome: string; solo_carta: boolean; conservato: boolean }>;
    /** I testi del programma, uno per tipo: non si cambiano. */
    testi: Record<TipoLettera, string>;
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
    termine.value = ricordato?.giorni ? String(ricordato.giorni) : '';
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
/** Un'azione dell'anteprima (invio, stampa, .zip) è riuscita: i pulsanti si spengono finché non si rifà l'anteprima. */
const fatto = ref(false);
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
    tipo.value = props.vista === 'in_scadenza' ? 'promemoria' : 'sollecito';
    invalida();
    fatto.value = false;
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
        indirizzi.value = r.data.scelta_ricordata.indirizzi ?? ['email', 'email_secondaria'];
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
const delCanale = (c: CanaleLettera) => personeDelCanale(persone.value, canali.value, escluse.value, c);
const conteggi = computed(() => ({ email: delCanale('email').length, stampa: delCanale('stampa').length, zip: delCanale('zip').length }));
const lettere = (n: number) => (n === 1 ? '1 lettera' : `${n} lettere`);

const oltreTetto = computed(() => (anteprima.value ? testoOltreTetto(conteggi.value, anteprima.value.tetto) : null));

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
        fatto.value = false;
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
            fatto.value = true;
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
        fatto.value = true;
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
        fatto.value = true;
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
const tipiIndirizzo = ['email', 'email_secondaria', 'pec'];
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
                        <select :value="modelloId === null ? '' : String(modelloId)" class="h-8 w-full max-w-md rounded-md border px-2 bg-transparent text-xs" data-testid="modello"
                            @change="scegliModello(($event.target as HTMLSelectElement).value)">
                            <option value="">Testo del programma</option>
                            <optgroup v-if="modelliDelTipoScelto.delCondominio.length" label="Per questo condominio">
                                <option v-for="m in modelliDelTipoScelto.delCondominio" :key="m.id" :value="String(m.id)">{{ m.nome }}</option>
                            </optgroup>
                            <optgroup v-if="modelliDelTipoScelto.perTutti.length" label="Per tutti i condomini">
                                <option v-for="m in modelliDelTipoScelto.perTutti" :key="m.id" :value="String(m.id)">{{ m.nome }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <p v-if="mancante !== null" class="text-xs text-amber-800 dark:text-amber-300" data-testid="modello-mancante">{{ testoModelloMancante(mancante) }}</p>
                    <label class="block text-xs text-slate-500 dark:text-slate-400" for="testo-lettera">Testo</label>
                    <p v-if="testoPrima" class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        {{ FRASE_TESTO_SOSTITUITO }}
                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="torna-al-testo" @click="tornaAlTestoDiPrima">Torna al testo di prima</Button>
                    </p>
                    <Textarea id="testo-lettera" v-model="testo" rows="6" data-testid="testo" @update:model-value="testoToccato = true; testoPrima = null; invalida()" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_CAMPI }}</p>
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
                            <select v-model="cassaId" class="h-8 rounded-md border px-2 bg-transparent text-xs" data-testid="conto">
                                <option :value="null" disabled>Scegli il conto</option>
                                <option v-for="c in opzioni.conti" :key="c.id" :value="c.id">{{ c.nome }} — {{ c.iban }}</option>
                            </select>
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
                    <p v-if="indirizzi.includes('pec')" class="text-xs text-slate-500 dark:text-slate-400">{{ AVVISO_PEC }}</p>
                    <label v-if="singola" class="block space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Un altro indirizzo (solo della persona o di chi la rappresenta)</span>
                        <Input v-model="altroIndirizzo" type="email" class="h-8 text-xs" data-testid="altro-indirizzo" />
                    </label>
                </fieldset>

                <Button size="sm" :disabled="inCorso" data-testid="anteprima" @click="chiediAnteprima">Anteprima: chi riceve che cosa</Button>

                <!-- L'anteprima -->
                <div v-if="anteprima" class="space-y-2" data-testid="anteprima-persone">
                    <p class="text-xs">{{ testoConteggi(conteggi) }}</p>
                    <p v-if="testoGestione(anteprima.gestione)" class="text-xs" data-testid="gestione-lettere">{{ testoGestione(anteprima.gestione) }}</p>
                    <p v-for="c in anteprima.credito" :key="c.nome" class="text-xs text-sky-800 dark:text-sky-300">{{ testoCredito(c) }}</p>
                    <p v-if="testoAccordiPassati(persone.filter((p) => p.accordo_passato).map((p) => p.nome))" class="text-xs text-amber-800 dark:text-amber-300" data-testid="accordi-passati">
                        {{ testoAccordiPassati(persone.filter((p) => p.accordo_passato).map((p) => p.nome)) }}
                    </p>
                    <Button v-if="coperte.length" size="sm" variant="outline" class="h-7 text-xs" data-testid="togli-coperti" @click="togliCoperte">
                        Togli chi ha un credito che copre la lettera ({{ coperte.length }})
                    </Button>
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
                    <table class="w-full text-xs">
                        <thead class="text-slate-500 dark:text-slate-400">
                            <tr><th class="p-1 text-left">Nell'invio</th><th class="p-1 text-left">Persona</th><th class="p-1 text-right">Totale</th><th class="p-1 text-left">Canale</th><th class="p-1 text-left">Indirizzi</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in persone" :key="p.id" class="border-t align-top" data-testid="persona-anteprima">
                                <td class="p-1"><Checkbox :model-value="!escluse.includes(p.id)" :aria-label="`Togli ${p.nome}`" @update:model-value="alterna(p.id)" /></td>
                                <td class="p-1">
                                    {{ p.nome }}
                                    <span v-if="p.senza_indirizzo_postale" class="block text-[11px] text-slate-500">senza indirizzo di casa: in alto c'è il solo nome, senza indirizzo</span>
                                    <span v-if="testoAccordoPassato(p.accordo_passato)" class="block text-[11px] text-amber-800 dark:text-amber-300" data-testid="accordo-passato">{{ testoAccordoPassato(p.accordo_passato) }}</span>
                                </td>
                                <td class="p-1 text-right whitespace-nowrap">
                                    {{ euroTesto(p.totale) }} <span class="block text-[11px] text-slate-500">{{ p.righe === 1 ? '1 rata' : `${p.righe} rate` }}</span>
                                    <span v-if="testoInAccordoNellaLettera(p.in_accordo ?? 0)" class="block whitespace-normal text-[11px] text-sky-800 dark:text-sky-300" data-testid="in-accordo">{{ testoInAccordoNellaLettera(p.in_accordo ?? 0) }}</span>
                                </td>
                                <td class="p-1">
                                    <select :value="canali[p.id] ?? p.canale" class="h-7 rounded-md border px-1 bg-transparent" :data-testid="`canale-${p.id}`"
                                        @change="canali = { ...canali, [p.id]: ($event.target as HTMLSelectElement).value as CanaleLettera }">
                                        <option v-for="c in p.canali" :key="c" :value="c">{{ NOMI_CANALI[c] }}</option>
                                    </select>
                                </td>
                                <td class="p-1">
                                    <template v-if="(canali[p.id] ?? p.canale) === 'email'">
                                        <span v-if="p.indirizzi.length">{{ p.indirizzi.map((i) => i.indirizzo).join(', ') }}</span>
                                        <span v-else class="text-amber-800 dark:text-amber-300">{{ p.solo_pec ? 'Ha solo la PEC: spuntala, o stampala per la posta.' : 'Nessun indirizzo fra quelli scelti: stampala per la posta.' }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="oltreTetto" class="text-xs text-amber-800 dark:text-amber-300" data-testid="oltre-tetto">{{ oltreTetto }}</p>
                    <p v-if="anteprima.senza_server_posta && conteggi.email > 0" class="text-xs text-amber-800 dark:text-amber-300">
                        Nessun server di posta configurato: le lettere non partono per email. Intanto puoi stamparle.
                    </p>
                </div>

                <!-- Dopo la stampa: la spedizione, in blocco. -->
                <div v-if="stampate.length" class="space-y-2 rounded-lg border p-2" data-testid="registra-spedizione">
                    <p class="text-xs font-medium">Registra la spedizione {{ stampate.length === 1 ? 'della lettera' : `delle ${stampate.length} lettere` }}, quando l'hai fatta</p>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <select v-model="spedizione.canale" class="h-8 rounded-md border px-2 bg-transparent">
                            <option value="" disabled>Come</option>
                            <option v-for="(nome, c) in CANALI_SPEDIZIONE" :key="c" :value="c">{{ nome }}</option>
                        </select>
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-8 w-[150px] text-xs" />
                        <Input v-model="spedizione.nota" placeholder="Nota" class="h-8 w-[200px] text-xs" />
                    </div>
                    <div v-for="s in stampate" :key="s.invio" class="flex items-center gap-2 text-xs">
                        <span class="w-40 truncate">{{ nomeDi(s.persona) }}</span>
                        <Input v-model="spedizione.numeri[s.invio]" placeholder="Numero o estremi" class="h-7 w-[200px] text-xs" />
                    </div>
                    <Button size="sm" variant="outline" :disabled="inCorso || !spedizione.canale" @click="registraSpedizione">Registra la spedizione</Button>
                </div>

                <p v-for="(riga, i) in esito" :key="i" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ riga }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
            <p v-else-if="errore" class="text-xs text-red-700 dark:text-red-400">{{ errore }}</p>

            <DialogFooter v-if="anteprima" class="flex flex-wrap gap-2">
                <Button size="sm" variant="ghost" :disabled="inCorso" data-testid="pdf-anteprima" @click="stampa(true)">Vedi il PDF</Button>
                <Button v-if="conteggi.email > 0" size="sm" variant="outline" :disabled="inCorso || fatto" data-testid="prova" @click="provaAMe">Mandane una a me</Button>
                <Button v-if="conteggi.email > 0 && !soloCarta" size="sm" :disabled="inCorso || fatto" data-testid="invia" @click="invia()">Invia {{ conteggi.email === 1 ? '1 email' : `${conteggi.email} email` }}</Button>
                <Button v-if="restanti?.length" size="sm" :disabled="inCorso" @click="invia(restanti)">Invia le altre</Button>
                <Button v-if="conteggi.stampa > 0" size="sm" :disabled="inCorso || fatto" data-testid="stampa" @click="stampa()">Stampa {{ lettere(conteggi.stampa) }}</Button>
                <Button v-if="conteggi.zip > 0" size="sm" :disabled="inCorso || fatto" data-testid="zip" @click="scaricaZip">Scarica {{ lettere(conteggi.zip) }} in un PDF per persona</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
