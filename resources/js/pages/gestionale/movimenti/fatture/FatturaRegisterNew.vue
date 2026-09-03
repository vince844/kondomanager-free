<script setup lang="ts">

import { ref, computed, watch, nextTick } from 'vue';
import { useForm, Head, router, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import FatturaRegistrazioneGuide from '@/components/guides/FatturaRegistrazioneGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { usePuliziaErrori } from '@/composables/usePuliziaErrori';
import { FileText, Plus, Trash2, AlertTriangle, User, ShieldAlert, Save, AlertOctagon, TriangleAlert, TrendingDown, Zap, ArrowRightLeft, Briefcase, History, ChevronDown, ChevronRight, CheckCircle, LoaderCircle, HelpCircle, UploadCloud, ShieldCheck } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import { useFattureSimili } from '@/composables/useFattureSimili';
import { useImportaFatturaXml, type EsitoImportazioneXml } from '@/composables/useImportaFatturaXml';
import { watchDebounced } from '@vueuse/core';
import WidgetDoubleLock from '@/components/gestionale/movimenti/fatture/WidgetDoubleLock.vue';
import ModalSpesaImprevista from '@/components/gestionale/movimenti/fatture/ModalSpesaImprevista.vue';
import ModalOverrideBudget from '@/components/gestionale/movimenti/fatture/ModalOverrideBudget.vue';
import ModalCreaFornitoreDaXml from '@/components/gestionale/movimenti/fatture/ModalCreaFornitoreDaXml.vue';
import ModalImportaXml from '@/components/gestionale/movimenti/fatture/ModalImportaXml.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { lordoRigaCents } from '@/lib/gestionale/fatture/budget';
import { calcolaTotali, risolviRegimeRitenuta } from '@/lib/gestionale/fatture/totali';
import { confrontaRitenuta } from '@/lib/gestionale/fatture/confrontoRitenuta';
import { euroToCents } from '@/lib/gestionale/fatture/money';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

// ---------------------------------------------------------------------------
// Interfaces & Props
// ---------------------------------------------------------------------------
const { euro } = useCurrencyFormatter();
const { generateRoute } = usePermission();

const moneyOptions = ref({
    prefix: '€ ',
    suffix: '',
    thousands: '.',
    decimal: ',',
    precision: 2,
    allowBlank: false,
    masked: false
});

interface Fornitore {
    id: number;
    ragione_sociale: string;
    // ⚠️ La colonna a database è `partita_iva`: `piva` non esiste e il ramo che la
    // leggeva non poteva mai essere vero, quindi ogni fornitore compariva come «Nessuna
    // P.IVA / C.F.» anche avendola. Corretto nella beta.75.
    partita_iva?: string;
    codice_fiscale?: string;
    soggetto_ritenuta: boolean;
    perc_ritenuta?: number;
    perc_imponibile_ritenuta?: number;
    giorni_scadenza?: number;
    iban_principale?: string;
    modalita_pagamento_default?: string;
    codice_tributo?: string;
    regime_forfetario?: boolean;
    ultima_aliquota_iva?: number | null;
    // Stessa forma di ultima_aliquota_iva, aggiunto il 02/09/2026 aprendo la
    // riprogettazione della UI di importazione XML: il capitolo dell'ultima fattura
    // di questo fornitore, proposto invece di lasciare la scelta da zero — 28 righe
    // da assegnare su 11 fatture vere è il costo di tempo più alto misurato
    // (docs/lettura_xml_fatture_passive.md).
    ultimo_conto_id?: number | null;
    tipo_ritenuta?: string | null;
    natura_percipiente?: string | null;
}

const MOTIVI_ESCLUSIONE_RITENUTA = [
    { value: 'bonifico_parlante', label: "Bonifico parlante (ritenuta 11% già operata dalla banca)" },
    { value: 'forfetario', label: 'Fornitore in regime forfetario' },
    { value: 'fuori_campo', label: 'Fuori dal campo di applicazione della ritenuta' },
    { value: 'posa_accessoria', label: 'Posa in opera accessoria alla fornitura' },
    { value: 'override_manuale', label: 'Altro motivo (specificare)' },
];

interface Condominio {
    id: number;
    nome: string;
}

interface Esercizio {
    id: number;
    nome: string;
    stato: string;
    data_inizio?: string;
}

interface Gestione {
    id: number;
    nome: string;
    tipo: string;
    esercizio_ids?: number[];
}

interface Conto {
    id: number
    nome: string
    parent_nome?: string | null
    residuo_budget?: number
    is_capiente?: boolean
    gia_versato_cents?: number
    ultimi_movimenti?: {
        data: string
        fornitore: string
        documento: string
        importo: number
        is_pregresso?: boolean
    }[]
}

interface Banca {
    id: number;
    nome: string;
    saldo_attuale?: number;
}

interface Immobile {
    id: number;
    label: string;
}

interface DebitoPatrimoniale {
    id: number;
    fornitore_id: number;
    descrizione: string;
    importo_iniziale: number;
    importo_disponibile: number;
    fatture_collegate?: any[];
}

interface FondoRiserva {
    id: number;
    nome: string;
    saldo_attuale: number;
}

const props = defineProps<{
    condominio: Condominio;
    condomini: Condominio[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    gestioni: Gestione[];
    fornitori: Fornitore[];
    conti: Conto[];
    banche: Banca[];
    immobili: Immobile[];
    debiti_patrimoniali: DebitoPatrimoniale[];
    fatture_pregresse_registrate: any[];
    fondi_riserva: FondoRiserva[];
    capienza_rata_zero: number;
    incassato_rata_zero: number;
    // ⚠️ Deciso il 02/09/2026, riprogettando la UI dopo il confronto con Vincenzo su
    // dove l'amministratore risparmia tempo davvero: «Importa XML» e «Nuova fattura»
    // sono la stessa rotta (decisione 1 dell'apertura, «due porte, una stanza») ma non
    // possono più aprirsi sulla stessa identica pagina — prima non c'era modo di
    // distinguerle a colpo d'occhio. Assente o diverso da 'xml' = manuale.
    modalita_ingresso?: 'xml' | 'manuale';
    /** Senza, il controllo sull'intestatario dell'XML non può girare: vedi ModalImportaXml. */
    condominio_senza_codice_fiscale?: boolean;
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const fileInput = ref<HTMLInputElement | null>(null);

// ---------------------------------------------------------------------------
// Ingresso XML — una modale sopra il modulo, non una fase della pagina
// (03/09/2026, seconda riprogettazione con Vincenzo)
// ---------------------------------------------------------------------------
/**
 * ⚠️ **Qui c'era `faseAttiva`, ed è stato tolto.** Per un giorno la lettura XML è stata
 * una fase di questa pagina: `?modo=xml` mostrava la dropzone al posto del modulo, e
 * scegliendo un file si passava a `'revisione'`. Risolveva la domanda giusta — Vincenzo:
 * «dov'è che l'amministratore risparmia tempo?» — ma introduceva **una porta a senso
 * unico**: `faseAttiva` veniva messo a `'revisione'` in due punti e non tornava mai
 * indietro. Chi cliccava «Nuova fattura», cominciava a compilare e poi si ricordava di
 * avere l'XML doveva uscire dalla pagina e ricominciare.
 *
 * Ora il lettore è una modale (`ModalImportaXml`), raggiungibile in qualunque momento
 * dalla fascia in testa al modulo. `?modo=xml` non cambia più la pagina: la apre
 * subito, così il pulsante in elenco e qualunque collegamento salvato continuano a
 * funzionare.
 */
const showImportaXmlModal = ref(props.modalita_ingresso === 'xml');

/**
 * Ogni apertura — e ogni chiusura — della modale è una **sessione a sé**, contata.
 *
 * ⚠️ Serve perché leggere un XML è una chiamata di rete con upload: fra il gesto che
 * deposita il file e la risposta passano secondi, e in quei secondi la modale che ha
 * ricevuto il file può non essere più quella a schermo. Chiedere alla fine
 * `showImportaXmlModal` risponderebbe a un'altra domanda — «ce n'è una aperta *adesso*» —
 * e sbaglierebbe proprio il caso di chi chiude e riapre durante l'attesa: la modale nuova
 * gli si richiuderebbe sotto le dita, col modulo riempito da un file che aveva
 * abbandonato. Il numero catturato al gesto risponde invece a «è ancora **quella**
 * apertura», che è la domanda giusta.
 *
 * Il watch è il solo punto di incremento perché il flag si scrive da tre parti (il
 * pulsante della fascia, il `v-model:show` della modale, `applicaFilePendente`): contare
 * in ognuna significherebbe dimenticarsene in una.
 */
const sessioneModaleXml = ref(0);
watch(showImportaXmlModal, () => { sessioneModaleXml.value++; });

interface FilePendente {
    file: File;
    stato: 'in_corso' | 'pronto' | 'errore';
    esito: EsitoImportazioneXml | null;
    erroreMessaggio: string | null;
}
const filesPendenti = ref<FilePendente[]>([]);
const trascinamentoAttivo = ref(false);
const inputMultiplo = ref<HTMLInputElement | null>(null);

/**
 * Due domande diverse, due stati — fino al 03/09/2026 erano un ref solo, e tenerle
 * insieme costava tre difetti (Fase 1-bis, reperti 8, 9 e 10).
 *
 * ⚠️ `provenienzaXml` è un **valore** (il nome del file), non un puntatore: risponde a
 * «chi ha scritto quello che vedi nel modulo» e deve restare leggibile anche quando la
 * voce che l'ha scritta non esiste più — a registrazione riuscita quella voce esce dalla
 * coda mentre la fascia in testa, sotto la modale di successo, deve continuare a dirne il
 * nome. Un puntatore, lì, andrebbe in bianco.
 *
 * ⚠️ `fileInLavorazione` è un **puntatore vivo dentro `filesPendenti`**, e serve a due
 * cose sole: sottrarre dal conteggio del lotto il documento che l'amministratore ha
 * davanti (`altriFileInCoda`) e sapere che cosa togliere dalla coda a registrazione
 * riuscita. Vale la regola che prima non c'era: **muore con la voce che punta**. E non è
 * mai valorizzato con un oggetto che nella coda non c'è — la porta «Allega documento»
 * costruisce la sua voce al volo, e da lì resta `null` mentre la provenienza si scrive
 * lo stesso. Era proprio quel puntatore fantasma a far dire al pulsante «Gestisci i
 * file» aprendo poi un elenco vuoto, nel percorso più battuto di tutti.
 */
const provenienzaXml = ref<string | null>(null);
const fileInLavorazione = ref<FilePendente | null>(null);

/**
 * Ogni file entra nel proprio riquadro, indipendente dagli altri: nessuno stato
 * condiviso da sovrascrivere. È la stessa correzione, per costruzione, del difetto
 * della richiesta-fuori-ordine trovato dalla revisione avversariale su
 * `useImportaFatturaXml` — lì il difetto era un `form` unico riscritto da risposte in
 * arrivo in ordine imprevedibile; qui non esiste un `form` unico finché non si sceglie
 * quale file rivedere.
 */
async function gestisciFileMultipli(fileList: FileList | File[]) {
    const file = Array.from(fileList);
    if (file.length === 0) return;

    // Il contesto del gesto, catturato adesso: vedi `sessioneModaleXml`.
    const sessione = sessioneModaleXml.value;

    const nuovi: FilePendente[] = file.map((f) => ({ file: f, stato: 'in_corso', esito: null, erroreMessaggio: null }));
    filesPendenti.value.push(...nuovi);

    await Promise.all(nuovi.map(async (voceGrezza) => {
        const { importa, errore } = useImportaFatturaXml();
        const esito = await importa(props.condominio.id, voceGrezza.file);

        // ⚠️ Si scrive sull'oggetto letto DA `filesPendenti.value`, non sul
        // riferimento grezzo di `nuovi`: Vue avvolge un oggetto in reattività nel
        // momento in cui lo si legge attraverso l'array reattivo (`ref([])`), non
        // quando lo si costruisce. Mutare `voceGrezza` direttamente cambia il
        // valore in memoria ma bypassa il trap che notifica il re-render — il
        // documento resterebbe bloccato su «Leggo il file...» per sempre, anche a
        // lettura completata.
        const voce = filesPendenti.value.find((v) => v.file === voceGrezza.file);
        if (!voce) return; // rimosso dall'elenco nel frattempo

        if (esito) {
            voce.stato = 'pronto';
            voce.esito = esito;
        } else {
            voce.stato = 'errore';
            voce.erroreMessaggio = errore.value;
        }
    }));

    // ⚠️ **La scorciatoia vale solo dentro l'apertura di modale che l'ha chiesta.** Chi
    // ci ripensa e chiude mentre il file si legge si vedeva il modulo riempirsi da solo a
    // modale sparita — e quel file diventava anche l'allegato della fattura, senza che
    // nessuno l'avesse scelto (Fase 1-bis, reperto 9). Il file **resta in coda**:
    // chiudere la modale scarta la scorciatoia, non la lettura, e da «Gestisci i file»
    // lo si ritrova pronto.
    //
    // Il confronto è sul **numero di sessione**, non su `showImportaXmlModal`: alla fine
    // della lettura una modale aperta può esserci lo stesso, solo che è un'altra — chi
    // chiude e riapre se la vedrebbe richiudere sotto le dita, riempita da un file che
    // aveva abbandonato.
    if (sessione !== sessioneModaleXml.value) return;

    // Un solo file, letto senza errori: si passa subito alla revisione, come già
    // avveniva prima di questa beta — l'elenco di triage serve al caso multiplo, non
    // deve aggiungere un clic in più al caso più comune. Stessa ragione della nota
    // qui sopra: si legge lo stato aggiornato da `filesPendenti.value`, non da
    // `nuovi[0]`, che è rimasto congelato allo stato con cui è stato creato.
    const primoAggiornato = nuovi.length === 1 ? filesPendenti.value.find((v) => v.file === nuovi[0].file) : null;
    if (primoAggiornato && primoAggiornato.stato === 'pronto' && primoAggiornato.esito) {
        selezionaFilePendente(primoAggiornato);
    }
}

/**
 * C'è lavoro **scritto a mano** che l'importazione cancellerebbe?
 *
 * ⚠️ La distinzione che conta non è «il modulo è pieno» ma «da dove viene ciò che
 * contiene»: se i dati sono arrivati da un altro file (`fileInLavorazione` valorizzato),
 * sostituirli è il gesto normale di chi sta lavorando un lotto e chiedere conferma
 * sarebbe solo un clic in più a ogni documento. Se invece l'amministratore stava
 * compilando da sé, quel lavoro non si butta senza domandare (Fase 1-bis, reperto 6).
 */
function moduloHaLavoroAMano(): boolean {
    // ⚠️ La domanda è **da dove vengono i campi**, non se una voce è ancora in coda: dopo
    // il salvataggio, o dopo che quel file è stato tolto dall'elenco, il modulo continua a
    // contenere roba arrivata da un file e sostituirla resta il gesto normale di chi
    // lavora un lotto. È esattamente ciò che questa funzione dichiara di distinguere, e
    // con un puntatore alla coda al posto della provenienza lo diceva per sbaglio.
    if (provenienzaXml.value) return false;

    const righeCompilate = form.righe.some(
        (r) => (r.descrizione ?? '').trim() !== '' || Number(r.importo_imponibile) !== 0 || r.conto_id !== null,
    );

    // ⚠️ **Il fornitore scelto NON conta come lavoro da proteggere**, ed è una
    // calibratura, non una dimenticanza: è un clic solo, e il file lo riscrive comunque
    // con quello che dichiara. Contandolo, chiedeva conferma anche a chi sceglie il
    // fornitore e poi importa il suo XML — che è il gesto normale, non un incidente.
    return righeCompilate || form.numero_documento.trim() !== '';
}

/**
 * Il documento scelto che aspetta il via libera a sovrascrivere il lavoro a mano.
 *
 * ⚠️ **Il dato e l'interruttore sono due cose separate, e devono restare separate.**
 * `AlertDialogAction` chiude il dialogo da sé, e la chiusura arriva *prima* di `confirm`:
 * se fosse l'azzeramento di `fileDaConfermare` a spegnere la finestra, alla conferma qui
 * ci sarebbe già `null` e il pulsante non farebbe assolutamente niente — nessun errore,
 * nessun messaggio. È il difetto della 1.11.0-beta.9, spiegato per esteso in
 * `useConfermaEliminazione.ts`, ed era tornato qui: i test lo mancavano perché chiamavano
 * `confermaSovrascrittura()` invece di premere il pulsante.
 */
const fileDaConfermare = ref<FilePendente | null>(null);
const confermaSovrascritturaAperta = ref(false);

function chiediConfermaSovrascrittura(voce: FilePendente) {
    fileDaConfermare.value = voce;
    confermaSovrascritturaAperta.value = true;
}

function selezionaFilePendente(voce: FilePendente) {
    if (!voce.esito) return;

    if (moduloHaLavoroAMano()) {
        chiediConfermaSovrascrittura(voce);
        return;
    }

    applicaFilePendente(voce);
}

function confermaSovrascrittura() {
    // Legge il dato per primo: a questo punto la finestra si è già chiusa.
    const voce = fileDaConfermare.value;
    fileDaConfermare.value = null;
    confermaSovrascritturaAperta.value = false;
    if (voce) applicaFilePendente(voce);
}

function annullaSovrascrittura() {
    fileDaConfermare.value = null;
    confermaSovrascritturaAperta.value = false;
}

function applicaFilePendente(voce: FilePendente) {
    if (!voce.esito) return;

    provenienzaXml.value = voce.file.name;

    // ⚠️ Il puntatore si aggancia **solo se la voce sta davvero nella coda**, e la domanda
    // si fa qui una volta invece che in ognuno dei tre chiamanti. Dalla porta «Allega
    // documento» la voce è costruita al volo e in `filesPendenti` non è mai entrata:
    // agganciarla comunque faceva promettere alla fascia un elenco che si apriva vuoto, e
    // a `onSuccess` una rimozione che filtrava un oggetto assente, cioè non toglieva
    // niente. La stessa riga copre la conferma di sovrascrittura arrivata su una voce che
    // nel frattempo è stata cestinata.
    //
    // ⚠️ **Il documento si applica comunque**: la condizione riguarda il puntatore, non il
    // ritorno. Una guardia in testa alla funzione (`if (!includes(voce)) return`) avrebbe
    // fatto ripartire il difetto della beta.9 dall'altro capo — «Sostituisci con il file»
    // che non fa assolutamente niente, senza errore e senza messaggio.
    fileInLavorazione.value = filesPendenti.value.includes(voce) ? voce : null;

    form.file = voce.file;
    precompilaDaXml(voce.esito);
    // Scelto il documento, la modale ha finito il suo lavoro: si torna al modulo, che è
    // dove il documento va controllato e registrato.
    showImportaXmlModal.value = false;
}

function rimuoviFilePendente(voce: FilePendente) {
    filesPendenti.value = filesPendenti.value.filter((v) => v !== voce);

    // ⚠️ Il puntatore muore con la voce che punta, e vale per **tutti e due** i chiamanti,
    // per ragioni opposte: col cestino quella voce non esiste più e la fascia continuava a
    // promettere «te lo ripropongo dopo il salvataggio» per un file appena buttato
    // (reperto 10); a registrazione riuscita la voce è uscita dalla coda e
    // `altriFileInCoda` deve smettere di sottrarla.
    //
    // ⚠️ La **provenienza** invece non si tocca, ed è una decisione, non una dimenticanza:
    // quei campi li ha scritti quel file, e toglierlo dall'elenco non lo cambia. Il
    // cestino è un comando dell'**elenco** — la sua etichetta dice «Togli X dall'elenco» —
    // e un comando che sbianca in silenzio un modulo compilato, allegato compreso, sarebbe
    // una sorpresa più grossa di quella che evita.
    if (fileInLavorazione.value === voce) fileInLavorazione.value = null;
}

/** I documenti del lotto ancora da registrare — letti dalla modale di successo. */
const filesInCodaPronti = computed(() => filesPendenti.value.filter((v) => v.stato === 'pronto'));

/**
 * Quelli che restano **oltre a quello aperto nel modulo**, per la fascia in testa.
 *
 * ⚠️ Non è `filesInCodaPronti`, e la differenza si vedeva a schermo: caricati due file
 * e scelto il primo, la fascia diceva «Restano 2 documenti» mentre ne restava uno —
 * contava anche quello che l'amministratore aveva davanti. La modale di successo usa
 * invece `filesInCodaPronti` senza sottrazioni, ed è corretto così: là il documento
 * registrato è già stato tolto dalla coda (`onSuccess`), quindi non c'è niente da
 * escludere.
 */
const altriFileInCoda = computed(() =>
    filesInCodaPronti.value.filter((v) => v !== fileInLavorazione.value),
);

/** Chiude la modale di successo e passa al prossimo documento del lotto scelto. */
function continuaConProssimo(voce: FilePendente) {
    showSuccessModal.value = false;
    resettaFormPerNuovoDocumento();
    selezionaFilePendente(voce);
}

/**
 * Stima grezza dell'importo per la riga dell'elenco — non è il totale che l'XML
 * dichiara (che questo endpoint non espone, decisione 5 dell'apertura: solo
 * l'imponibile di riga passa il confine centesimi/euro), è imponibile + IVA sommati
 * riga per riga. Basta a orientarsi nell'elenco, non è un valore da controllare.
 *
 * ⚠️ `euro()` (useCurrencyFormatter) si aspetta CENTESIMI per default
 * (`fromCents: true`): le righe arrivano già in euro da questo controller
 * (MoneyHelper::fromCents() applicato al confine, stessa decisione 5) — è la
 * stessa conversione inversa che serve ovunque nel form si sommano importo_imponibile
 * di più righe per un totale da passare a `euro()`.
 */
function totaleLordoStimatoCents(esito: EsitoImportazioneXml): number {
    const euroTotali = esito.righe.reduce((s, r) => s + r.importo_imponibile * (1 + r.aliquota_iva / 100), 0);
    return Math.round(euroTotali * 100);
}

function suDrop(e: DragEvent) {
    trascinamentoAttivo.value = false;
    if (e.dataTransfer?.files?.length) gestisciFileMultipli(e.dataTransfer.files);
}

function suSelezioneMultipla(e: Event) {
    const files = (e.target as HTMLInputElement).files;
    if (files?.length) gestisciFileMultipli(files);
    (e.target as HTMLInputElement).value = ''; // permette di ricaricare lo stesso file due volte
}

// ---------------------------------------------------------------------------
// Importazione XML — beta.14, decisione 1 di apertura («due porte, una stanza»)
// ---------------------------------------------------------------------------
const ESTENSIONI_XML = ['xml', 'p7m'];
const { isLoading: importazioneInCorso, errore: erroreImportazione, importa: importaXml, reset: resetErroreImportazione } = useImportaFatturaXml();
const esitoFornitoreXml = ref<EsitoImportazioneXml['fornitore'] | null>(null);
const avvisiImportazioneXml = ref<EsitoImportazioneXml['avvisi'] | null>(null);

/**
 * La ritenuta d'acconto che il **file** dichiara, se ne dichiara una.
 *
 * ⚠️ Non entra in nessun calcolo: la trattenuta continua a dipendere solo
 * dall'anagrafica del fornitore. Serve al confronto — vedi `confrontoRitenuta` — perché
 * fino alla beta.14 questo dato attraversava il confine e non lo leggeva nessuno, e una
 * parcella con ritenuta dichiarata si registrava a netto pieno senza che nessuna
 * schermata lo dicesse (Fase 1-bis, reperti 2 e 12).
 */
const ritenutaLettaDaXml = ref<EsitoImportazioneXml['ritenuta']>(null);

/**
 * ⚠️ **Trovato dalla revisione avversariale della beta.14, corretto qui.** Il watch su
 * `form.fornitore_id` (poco più sotto) scrive scadenza/IBAN/modalità di pagamento dai
 * DEFAULT dell'anagrafica ogni volta che il fornitore cambia — è la sua funzione, utile
 * quando l'utente sceglie un fornitore a mano. Ma `precompilaDaXml()` scrive PRIMA quegli
 * stessi campi dal file (quando il file li dichiara) e POI `form.fornitore_id`, per
 * agganciare o proporre il fornitore letto: quel secondo passaggio faceva scattare il
 * watch, che sovrascriveva in silenzio ciò che il primo aveva appena scritto — l'IBAN su
 * cui la fattura chiede di essere pagata spariva, sostituito da quello registrato.
 *
 * La guardia è **per campo**, non un blocco totale: se il file dichiara la scadenza ma
 * non l'IBAN, solo la scadenza va protetta — l'IBAN deve continuare a prendere il default
 * dell'anagrafica, com'è sempre stato. One-shot: letta e azzerata al primo cambio di
 * `fornitore_id` successivo, che sia l'aggancio automatico, la scelta fra candidati
 * ambigui (`scegliFornitoreXml`) o la creazione in linea di un fornitore mancante — un
 * cambio di fornitore fatto a mano più avanti nella sessione torna al comportamento
 * normale.
 */
const campiXmlDaPreservare = ref<{ scadenza: boolean; iban: boolean; modalitaPagamento: boolean } | null>(null);

function estensioneDi(nomeFile: string): string {
    return nomeFile.slice(nomeFile.lastIndexOf('.') + 1).toLowerCase();
}

async function gestisciFileSelezionato(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;

    // Resta allegato come sempre, XML compreso: chi importa vuole anche la
    // prova originale agganciata alla fattura, non solo i dati letti da essa.
    form.file = file;
    esitoFornitoreXml.value = null;
    avvisiImportazioneXml.value = null;
    // Anche la ritenuta letta va via qui, insieme agli altri residui: allegare un PDF
    // dopo aver importato un XML non deve lasciare a schermo il confronto del file
    // precedente. Se il file nuovo è un XML, la lettura la riscrive fra un istante.
    ritenutaLettaDaXml.value = null;
    resetErroreImportazione();

    if (!ESTENSIONI_XML.includes(estensioneDi(file.name))) {
        return;
    }

    const esito = await importaXml(props.condominio.id, file);
    if (!esito) return; // messaggio già in erroreImportazione

    // ⚠️ **Il bersaglio è quello catturato al gesto (`file`), e qui si controlla che sia
    // ancora lui.** Anche questa lettura dura secondi, e in quei secondi l'allegato può
    // essere cambiato: un secondo file scelto da questo stesso riquadro, o un documento
    // aperto dalla coda, che scrive `form.file` per conto suo. Applicare comunque
    // significa riempire il modulo da un file che non è più quello allegato — vince
    // l'ultima risposta arrivata invece dell'ultima chiesta. È la stessa domanda della
    // sessione della modale, posta sull'altra porta.
    if (form.file !== file) return;

    // ⚠️ **Stessa guardia dell'altro ingresso, e non è una ripetizione oziosa.** L'XML
    // entra da DUE porte — la modale del lettore e questo riquadro «Allega documento» —
    // e proteggerne una sola significa che il difetto resta intero da questa parte. È il
    // difetto trovato scrivendo la correzione stessa: la protezione era su
    // `selezionaFilePendente()` e il test, che passa di qui, continuava a perdere il
    // lavoro a mano.
    const voce: FilePendente = { file, stato: 'pronto', esito, erroreMessaggio: null };

    if (moduloHaLavoroAMano()) {
        chiediConfermaSovrascrittura(voce);
        return;
    }

    // ⚠️ Da questa porta si scrive la **provenienza** e basta: la voce è costruita qui e
    // in coda non c'è mai entrata. Serve a due cose — che un secondo XML importato da qui
    // non sembri lavoro a mano e non chieda una conferma senza senso, e che la fascia
    // possa dire da quale file arrivano i dati anche per chi passa dal riquadro Allegato.
    // Il puntatore alla coda, che qui non ha un referente, resta `null`: era proprio lui a
    // far dire al pulsante «Gestisci i file» aprendo poi un elenco vuoto — e questa è la
    // porta più battuta, non un caso di confine.
    provenienzaXml.value = file.name;
    precompilaDaXml(esito);
}

/**
 * ⚠️ **Sostituisce, non precompila — ed è il contrario di come era scritta.**
 *
 * Fino al 03/09/2026 questa funzione scriveva un campo *solo se l'XML lo dichiarava*, e
 * lasciava intatti gli altri. Era corretto quando il lettore XML era una **fase a senso
 * unico**: si importava sempre su un modulo vuoto, quindi «non tocco» e «azzero» erano
 * la stessa cosa. Da quando il lettore è una modale apribile in qualunque momento, non
 * lo sono più: **il modulo può già contenere un altro documento**, e ciò che l'XML tace
 * resta appiccicato al precedente (Fase 1-bis, reperti 4, 5, 7 e 15).
 *
 * Non è una sbavatura estetica. Dalla mappa delle catene
 * (`docs/catene_fra_moduli.md`): dal fornitore dipendono `soggetto_ritenuta`,
 * `regime_forfetario`, il codice tributo 1019/1020 e le percentuali — quindi netto da
 * pagare, pagamento e **F24**. Un fornitore rimasto agganciato registra la fattura col
 * **regime fiscale di un altro**; un IBAN rimasto manda il bonifico a un altro
 * destinatario.
 *
 * La regola ora è: **ogni campo del documento viene riscritto**, con il valore del file
 * o col valore vuoto. Quello che il file non dichiara non è «da conservare»: è
 * **assente**, e va mostrato assente perché l'amministratore lo veda e lo compili.
 *
 * Restano fuori di proposito le cose che NON appartengono al documento letto:
 * `esercizio_id` e `gestione_id` (il contesto in cui si sta lavorando) e le scelte già
 * fatte sulle righe che il file non conosce — vedi sotto.
 */
function precompilaDaXml(esito: EsitoImportazioneXml) {
    form.tipo_documento = esito.documento.tipo_documento;
    form.numero_documento = esito.documento.numero_documento;
    form.data_documento = esito.documento.data_documento;
    form.data_scadenza = esito.documento.data_scadenza ?? '';
    form.modalita_pagamento = esito.documento.modalita_pagamento ?? 'bonifico';
    form.iban_fornitore = esito.documento.iban_fornitore ?? '';

    // Il fornitore si riscrive SEMPRE: se il file nuovo non lo riconosce, il campo
    // torna da scegliere invece di restare quello del documento prima.
    form.fornitore_id = esito.fornitore.esito === 'trovato'
        ? esito.fornitore.candidati[0].id
        : null;

    // ⚠️ La giustificazione di uno sforo è **del documento che l'ha richiesta**, non del
    // modulo: sopravvivere a un cambio di documento significherebbe registrare la
    // fattura nuova con la motivazione legale della precedente, e la ratifica
    // assembleare verterebbe su una cifra che l'assemblea non ha mai visto.
    form.dati_extra.override_budget = null;
    form.dati_extra.log_legale_sopravvenienza = null;

    if (esito.righe.length > 0) {
        // ⚠️ Il fornitore per il prefill si cerca DIRETTAMENTE nell'esito (non da
        // `selectedFornitore`, che legge `form.fornitore_id` — non ancora scritto a
        // questo punto della funzione: le righe si costruiscono PRIMA del blocco
        // fornitore qualche riga più sotto). Solo quando l'aggancio è certo
        // (`trovato`, un candidato solo): su un esito ambiguo o non_trovato non c'è
        // ancora un fornitore su cui basare la proposta.
        const fornitoreAgganciato = esito.fornitore.esito === 'trovato'
            ? fornitoriDisponibili.value.find(f => f.id === esito.fornitore.candidati[0].id)
            : undefined;

        form.righe = esito.righe.map((r) => ({
            descrizione: r.descrizione,
            conto_id: fornitoreAgganciato?.ultimo_conto_id ?? null,
            immobile_id: null,
            importo_imponibile: r.importo_imponibile,
            aliquota_iva: r.aliquota_iva,
            is_sopravvenienza: false,
            // ⚠️ **Il flag lo dichiara il file, e riscriverlo a `true` scollegava la
            // protezione dell'F24 costruita nella beta.14.** Il server calcola
            // `concorre_base_ritenuta` per la riga del contributo cassa previdenziale
            // leggendo il campo `<Ritenuta>` che lo schema FatturaPA ha apposta; qui
            // veniva sovrascritto, quindi il contributo entrava nella base della ritenuta
            // anche quando il file dice di no — si trattiene al fornitore più del dovuto e
            // si versa all'Erario più del dovuto (trappola 1 di `docs/catene_fra_moduli.md`).
            // `!== false` e non `=== true`: le righe ordinarie il campo non ce l'hanno, e
            // per loro «concorre» resta il default corretto di tutta la catena.
            concorre_base_ritenuta: r.concorre_base_ritenuta !== false,
        }));
    }

    // Impostato PRIMA di scrivere form.fornitore_id, qualunque sia lo stato
    // dell'aggancio: la guardia serve anche quando il fornitore verrà attaccato più
    // tardi — dalla scelta fra ambigui o dalla creazione in linea di uno mancante.
    campiXmlDaPreservare.value = {
        scadenza: !!esito.documento.data_scadenza,
        iban: !!esito.documento.iban_fornitore,
        modalitaPagamento: !!esito.documento.modalita_pagamento,
    };

    // `form.fornitore_id` è già stato scritto sopra, insieme agli altri campi del
    // documento: qui resta solo l'esito, che serve ai riquadri «agganciato per P.IVA» /
    // «più fornitori possibili» / «da creare».
    esitoFornitoreXml.value = esito.fornitore;

    avvisiImportazioneXml.value = esito.avvisi;
    // `?? null`: un esito senza la chiave (fixture vecchie, risposte parziali) vale
    // «il file non dichiara ritenute», mai `undefined` che il confronto dovrebbe indovinare.
    ritenutaLettaDaXml.value = esito.ritenuta ?? null;
}

function scegliFornitoreXml(id: number) {
    form.fornitore_id = id;
    esitoFornitoreXml.value = null;
}

const showCreaFornitoreModal = ref(false);

/**
 * Il fornitore appena creato dal modale — deciso il 02/09/2026 aprendo la
 * riprogettazione della UI, la risposta di questa riprogettazione alla domanda posta
 * da Vincenzo: «se un fornitore non è già registrato l'amministratore deve lasciare
 * l'importo a metà [...] non credo che gli stiamo migliorando la vita».
 *
 * `campiXmlDaPreservare` è già pronto: `precompilaDaXml()` lo scrive PRIMA di sapere
 * l'esito dell'aggancio, quindi resta impostato per il caso `non_trovato` finché
 * `form.fornitore_id` non cambia per la prima volta — esattamente adesso.
 */
function gestisciFornitoreCreato(nuovo: {
    id: number;
    ragione_sociale: string;
    soggetto_ritenuta?: boolean;
    tipo_ritenuta?: string | null;
    regime_forfetario?: boolean;
}) {
    fornitoriDisponibili.value.push({
        id: nuovo.id,
        ragione_sociale: nuovo.ragione_sociale,
        // ⚠️ **Quello che il fornitore ha davvero, non `false` scritto a mano.** Questa
        // riga era il secondo tempo del reperto 12: anche spuntando la casella nel modale,
        // la copia locale nasceva non soggetta, quindi l'anteprima non mostrava nessuna
        // trattenuta e il documento si registrava a netto pieno. Il server è già a posto —
        // rilegge il fornitore dal database — ma a schermo l'amministratore vedeva un netto
        // che al salvataggio cambiava.
        soggetto_ritenuta: nuovo.soggetto_ritenuta ?? false,
        tipo_ritenuta: nuovo.tipo_ritenuta ?? null,
        regime_forfetario: nuovo.regime_forfetario ?? false,
        ultima_aliquota_iva: null,
        ultimo_conto_id: null,
    });
    form.fornitore_id = nuovo.id;
    esitoFornitoreXml.value = null;
}

const showOverrideModal = ref(false);
const showGuideCompleta = ref(false);
const showSuccessModal = ref(false);

const form = useForm({
    fornitore_id:       null as number | null,
    esercizio_id:       props.esercizio?.id || null,
    gestione_id:        null as number | null,
    tipo_documento:     'fattura',
    is_pregresso:       false,
    data_competenza_originaria: '',
    saldo_patrimoniale_id: null as number | null,
    // NUOVI CAMPI PER FATTURA PREGRESSA
    imponibile_pregresso:       0,
    aliquota_iva_pregressa:     22,
    numero_documento:   '',
    data_documento:     new Date().toISOString().substring(0, 10),
    data_scadenza:      '',
    conto_corrente_id:  null as number | null,
    modalita_pagamento: 'bonifico',
    iban_fornitore:     '',
    // null = eredita il default (applica sempre tranne che sulle note di credito);
    // true/false = scelta esplicita dell'amministratore per questo documento.
    applica_ritenuta: null as boolean | null,
    dati_extra: {
        fiscal:     { cig: '', cup: '', motivo_esclusione_ritenuta: '', motivo_esclusione_ritenuta_note: '', conferma_codice_tributo_mancante: false },
        competenza: { dal: '', al: '' },
        override_budget:          null as any,
        log_legale_sopravvenienza: null as any
    },
    stato_approvazione: 'approvata',
    righe: [{
        descrizione: '',
        conto_id: null as number | null,
        immobile_id: null as number | null,
        importo_imponibile: 0,
        aliquota_iva: 22,
        is_sopravvenienza: false,
        concorre_base_ritenuta: true,
    }],
    coperture: [] as any[],
    file: null as File | null,
});

// ⚠️ Copia locale e mutabile di `props.fornitori`, aperta con questa riprogettazione
// (02/09/2026): il fornitore creato in linea dal modale di importazione XML non può
// finire nella prop — è statica, arriva dal server al caricamento della pagina, e
// aggiungerci qualcosa senza una ricarica significherebbe un fornitore agganciato che
// il resto del form non trova (`selectedFornitore` sotto tornerebbe `undefined`).
const fornitoriDisponibili = ref<Fornitore[]>([...props.fornitori]);

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------
const selectedFornitore = computed(() => fornitoriDisponibili.value.find(f => f.id === form.fornitore_id));

/**
 * Nomi leggibili per il riquadro di riepilogo dei rifiuti (`FormErrorSummary`).
 *
 * ⚠️ **Le etichette delle righe si ricavano dalle chiavi che il server ha davvero
 * mandato, non dalle righe che il form ha adesso.** Sono due insiemi che possono non
 * coincidere — l'amministratore può togliere una riga dopo il rifiuto, e resterebbe un
 * messaggio senza il suo «Riga N». Derivandole dagli errori l'etichetta c'è sempre e
 * per qualunque campo di riga, anche uno aggiunto in futuro senza toccare questo
 * elenco. Presidiato dai test: la prima stesura iterava su `form.righe` e il test con
 * due righe in errore su un form da una riga sola l'ha smascherata subito.
 *
 * `righe.2.conto_id` → «Riga 3»: l'indice tecnico parte da zero, il registro che
 * l'amministratore ha davanti è numerato da uno.
 */
const etichetteErrori = computed<Record<string, string>>(() => {
    const etichette: Record<string, string> = {
        numero_documento: 'Numero documento',
        data_documento: 'Data documento',
        data_scadenza: 'Scadenza',
        fornitore_id: 'Fornitore',
        gestione_id: 'Gestione',
        conto_corrente_id: 'Conto addebito',
        modalita_pagamento: 'Modalità di pagamento',
        iban_fornitore: 'IBAN fornitore',
        file: 'Allegato',
    };

    Object.keys(form.errors).forEach((chiave) => {
        const riga = chiave.match(/^righe\.(\d+)\./);
        if (riga) etichette[chiave] = `Riga ${Number(riga[1]) + 1}`;
    });

    return etichette;
});

/**
 * Il riquadro va portato sotto gli occhi: su questa pagina il pulsante «Registra
 * documento» sta in fondo alla colonna sinistra, il riepilogo in testa alla pagina, e
 * senza questo salto un rifiuto resta fuori schermo — che è di nuovo «ho premuto e non
 * è successo niente». Stessa soluzione di FornitoriNew.vue (righe 204-222).
 */
const riepilogoErrori = ref<HTMLElement | null>(null);

function portaInVistaIlRiepilogo() {
    nextTick(() => {
        riepilogoErrori.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        riepilogoErrori.value?.focus?.();
    });
}

// La riga rossa sparisce appena il campo viene corretto, invece di restare finché non
// si salva di nuovo: senza, il programma continua a segnalare un errore già sistemato.
usePuliziaErrori(form);

/** Il forfetario esclude la ritenuta per legge, a prescindere da soggetto_ritenuta. */
const fornitoreRitenutaAttiva = computed(() =>
    !!selectedFornitore.value?.soggetto_ritenuta && !selectedFornitore.value?.regime_forfetario
);

/**
 * Stessa regola di default del backend (FatturaPassivaService): applicata
 * sempre tranne che sulle note di credito, salvo scelta esplicita dell'utente.
 */
const applicaRitenutaEffective = computed<boolean>({
    get: () => form.applica_ritenuta ?? (form.tipo_documento !== 'nota_credito'),
    set: (val: boolean) => { form.applica_ritenuta = val; },
});

/**
 * Design §2.4 M2: senza natura del percipiente (né un codice tributo legacy
 * come override) il codice tributo 1019/1020 è indeterminabile. v1.10: warning
 * bloccante con conferma esplicita — v1.11: blocco duro (design doc).
 */
const codiceTributoIndeterminabile = computed(() =>
    fornitoreRitenutaAttiva.value
    && applicaRitenutaEffective.value
    && !!selectedFornitore.value?.tipo_ritenuta
    && !selectedFornitore.value?.natura_percipiente
    && !selectedFornitore.value?.codice_tributo
);

/**
 * Il confronto fra la ritenuta dichiarata dal file e quella che il modulo tratterrebbe.
 *
 * ⚠️ **Non cambia nessun numero**: la logica è una funzione pura in
 * `lib/gestionale/fatture/confrontoRitenuta.ts`, provata caso per caso su tutta la
 * matrice senza montare questa pagina. Qui si legge solo il risultato.
 *
 * ⚠️ `provenienzaXml` come `daFile`: senza un file letto non c'è confronto possibile, e
 * un modulo compilato a mano non deve vedersi contestare niente da nessuno.
 */
/**
 * La somma delle righe che concorrono alla base ritenuta, **senza** la riduzione del
 * regime — serve a verificare l'aliquota dichiarata dal file contro i numeri del file.
 *
 * ⚠️ Non si può usare `totali.base_ritenuta_cents`: quello è `baseCalcolo`, già ridotto
 * da `percentualeBase()`, e soprattutto vale **zero** finché non c'è un fornitore con un
 * regime — cioè esattamente nel caso in cui serve, quando il fornitore lo si sta creando.
 * E non si può usare `totali.imponibile_cents`: comprende anche le righe escluse dalla
 * base, e sbaglierebbe proprio sulle parcelle con contributo cassa previdenziale, dove la
 * differenza fra i due numeri è tutta lì.
 */
const baseRitenutaGrezzaCents = computed(() => form.righe.reduce(
    (somma, r) => r.concorre_base_ritenuta !== false ? somma + euroToCents(r.importo_imponibile) : somma,
    0,
));

const confronto = computed(() => confrontaRitenuta({
    ritenutaDaXml: ritenutaLettaDaXml.value,
    ritenutaModuloCents: totali.value.ritenuta_cents,
    fornitore: selectedFornitore.value,
    tipoDocumento: form.tipo_documento,
    applicaRitenuta: applicaRitenutaEffective.value,
    daFile: provenienzaXml.value !== null,
}));

const hasSpesePrivate = computed(() => {
    if (!form.righe || !Array.isArray(form.righe)) return false;
    return form.righe.some(riga => riga.immobile_id !== null);
});

/**
 * Anteprima dei totali, in centesimi interi. Il calcolo vive in
 * `lib/gestionale/fatture/totali.ts` perché ricalca operazione per operazione quello di
 * `FatturaPassivaService` + `RitenutaService`: è l'unico modo perché il netto letto qui
 * coincida con quello che l'elenco fatture mostrerà dopo il salvataggio.
 */
const totali = computed(() => calcolaTotali({
    is_pregresso:           form.is_pregresso,
    imponibile_pregresso:   form.imponibile_pregresso,
    aliquota_iva_pregressa: form.aliquota_iva_pregressa,
    righe:                  form.righe,
    ritenuta:               risolviRegimeRitenuta(selectedFornitore.value, applicaRitenutaEffective.value),
}));

// Storico capitoli espanso
const expandedHistory = ref<Record<number, boolean>>({});
const toggleHistory = (contoId: number) => {
    expandedHistory.value[contoId] = !expandedHistory.value[contoId];
};

// Calcoli Budget in centesimi
const budgetImpacts = computed(() => {
    // Le fatture pregresse non impattano il budget corrente
    if (form.is_pregresso) return [];

    const grouped = new Map<number, {
        id: number; nome: string;
        speso_cents: number; residuo_cents: number;
        gia_versato_cents: number;
        ultimi_movimenti: any[]
    }>();

    form.righe.forEach(r => {
        if (!r.conto_id) return;
        const c = props.conti.find(c => c.id === r.conto_id);
        if (!c) return;

        const residuoCents = c.residuo_budget || 0;
        const spesaCents   = lordoRigaCents(r.importo_imponibile, r.aliquota_iva);
        const cur = grouped.get(r.conto_id) || {
            id:                c.id,
            nome:              c.nome,
            speso_cents:       0,
            residuo_cents:     residuoCents,
            gia_versato_cents: c.gia_versato_cents || 0,
            ultimi_movimenti:  c.ultimi_movimenti || []
        };
        cur.speso_cents += spesaCents;
        grouped.set(r.conto_id, cur);
    });

    return Array.from(grouped.values()).map(i => ({
        ...i,
        isOk:        i.speso_cents <= i.residuo_cents,
        delta_cents: i.residuo_cents - i.speso_cents,
        // Stima: presume che questa fattura rappresenti il costo TOTALE reale
        // della voce (nessun'altra spesa storica quest'anno) — il numero
        // autorevole resta quello calcolato da CalcoloQuoteService quando il
        // piano rate viene davvero generato. Vedi ModalOverrideBudget.
        residuoNettoStimatoCents: Math.max(0, i.speso_cents - i.gia_versato_cents),
    }));
});

/**
 * Sforo della SINGOLA riga, per il badge sotto il campo importo.
 *
 * Attenzione: `budgetImpacts` aggrega invece per capitolo, quindi due righe sullo stesso
 * conto possono sforare insieme senza che nessuna delle due sfori da sola. Il badge di riga
 * e il pannello laterale rispondono deliberatamente a domande diverse.
 */
const rigaInSforo = (riga: { conto_id: number | null; importo_imponibile: unknown; aliquota_iva: unknown }): boolean => {
    if (!riga.conto_id) return false;
    const c = props.conti.find(c => c.id === riga.conto_id);
    if (!c || c.residuo_budget === undefined) return false;

    return lordoRigaCents(riga.importo_imponibile, riga.aliquota_iva) > c.residuo_budget;
};

const bancheNormalizzate = computed(() =>
    props.banche.map(b => ({ ...b, saldo_attuale_cents: b.saldo_attuale || 0 }))
);

const bankForecast = computed(() => {
    if (!form.conto_corrente_id) return null;
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return null;

    const attualeCents = b.saldo_attuale_cents;
    const spesaCents   = totali.value.netto_cents;
    const postCents    = attualeCents - spesaCents;

    return { attuale_cents: attualeCents, post_cents: postCents, isRed: postCents < 0 };
});

const transactionStatus = computed(() => {
    if (!form.is_pregresso && budgetImpacts.value.some(i => !i.isOk)) return 'CRITICAL_BUDGET';
    if (!form.is_pregresso && bankForecast.value?.isRed) return 'WARNING_CASH';
    return 'SAFE';
});

const isDataDocumentoVecchia = computed(() => {
    if (!form.data_documento) return false;
    const diffTime = Math.abs(new Date().getTime() - new Date(form.data_documento).getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays > 30;
});

// ── Duplicati (decisione D4, 1.11.0-beta.13) ────────────────────────────────
// ⚠️ Segnale INDIPENDENTE da `transactionStatus`: quel computed restituisce una stringa sola
// fra CRITICAL_BUDGET/WARNING_CASH/SAFE, a precedenza rigida, e pilota banner, colore ed
// etichetta del pulsante. Un quarto valore lì dentro nasconderebbe uno sforo o sarebbe
// nascosto da esso — il duplicato non ha niente a che vedere con lo sforo di budget, e
// nemmeno con `is_pregresso`: le pregresse SI segnalano, sono il caso a più alto rischio.
const { simili: fattureSimili, cercaSimili: cercaFattureSimili, reset: resetFattureSimili } = useFattureSimili();

/** La data che arriva dal server è `YYYY-MM-DD`; il banner la scrive nel formato del resto della pagina. */
const formatDataBreve = (iso: string) => iso ? new Date(iso).toLocaleDateString('it-IT') : '';

watchDebounced(
    [
        () => form.fornitore_id,
        () => form.numero_documento,
        () => form.data_documento,
        () => totali.value.totale_documento_cents,
        () => form.tipo_documento,
    ],
    () => {
        if (!form.fornitore_id) {
            resetFattureSimili();
            return;
        }

        cercaFattureSimili({
            condominioId: props.condominio.id,
            esercizioId: form.esercizio_id,
            fornitoreId: form.fornitore_id,
            numeroDocumento: form.numero_documento,
            totaleDocumentoCents: totali.value.totale_documento_cents,
            dataDocumento: form.data_documento,
            tipoDocumento: form.tipo_documento,
        });
    },
    { debounce: 400 }
);

const sforoBudgetTotaleCents = computed(() =>
    budgetImpacts.value.filter(i => !i.isOk).reduce((acc, i) => acc + (i.speso_cents - i.residuo_cents), 0)
);

// Dettaglio per voce delle sole voci in sforo: una fattura può toccare più
// capitoli, solo alcuni dei quali con già-versato attivo — il modale deve
// mostrare il quadro per voce, non un unico numero aggregato che nasconde
// quali capitoli hanno già-versato e quali no.
const vociInSforo = computed(() =>
    budgetImpacts.value
        .filter(i => !i.isOk)
        .map(i => ({
            id: i.id,
            nome: i.nome,
            sforoLordoCents: i.speso_cents - i.residuo_cents,
            giaVersatoCents: i.gia_versato_cents,
            residuoNettoStimatoCents: i.residuoNettoStimatoCents,
        }))
);

const gestioniFiltrate = computed(() => {
    if (!form.esercizio_id) return [];
    return props.gestioni.filter(g => {
        if (g.esercizio_ids && g.esercizio_ids.length > 0) {
            return g.esercizio_ids.includes(form.esercizio_id as number);
        }
        return true;
    });
});

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------

/**
 * Watch unificato su [fornitore_id, data_documento].
 *
 * Ordine di operazioni:
 * 1. Aggiorna is_pregresso (dipende solo dalla data)
 * 2. Aggiorna campi derivati dal fornitore (dipende da entrambi)
 *
 * Avere un unico watch elimina il rischio di side-effect incrociati
 * che si verificavano con i due watch separati che reagivano entrambi
 * a data_documento in sequenza non garantita.
 */
watch(
    [() => form.fornitore_id, () => form.data_documento],
    ([newFornitoreId, newDataDoc], [oldFornitoreId, oldDataDoc]) => {
        const fornitoreCambiato = newFornitoreId !== oldFornitoreId;

        // ⚠️ **La guardia si legge SEMPRE, non solo quando cambia il fornitore.**
        // Prima era `fornitoreCambiato ? campiXmlDaPreservare.value : null`, e su un
        // caso reale non copriva niente: se l'amministratore sceglie il fornitore a mano
        // e *poi* importa l'XML di quello stesso fornitore, il fornitore NON cambia — ma
        // la data del documento sì, e la scadenza si ricalcola comunque (vedi il blocco
        // più sotto, che reagisce anche alla sola data). Risultato: i giorni
        // dell'anagrafica sovrascrivevano la scadenza dichiarata dal file, cioè il dato
        // che il fornitore ha davvero scritto sulla fattura (Fase 1-bis, reperto 15).
        //
        // Il caso è raggiungibile solo da quando il lettore è una modale apribile in
        // qualunque momento: prima si importava sempre su un modulo vuoto, dove il
        // fornitore cambiava per forza.
        //
        // Resta **one-shot**: consumata al primo scatto dopo l'importazione, così una
        // scelta successiva fatta a mano si comporta come sempre.
        const daPreservare = campiXmlDaPreservare.value;
        campiXmlDaPreservare.value = null;

        // 1. Aggiorna is_pregresso — SOLO quando cambia la data. Il watch scatta
        //    anche al cambio di fornitore: senza questo guard, ricalcolava il flag
        //    dalla data e cancellava la spunta messa a mano dall'utente (la colonna
        //    pregresso spariva appena si sceglieva il fornitore).
        if (newDataDoc !== oldDataDoc && newDataDoc && props.esercizio?.data_inizio) {
            form.is_pregresso = newDataDoc < props.esercizio.data_inizio.substring(0, 10);
        }

        // Il debito patrimoniale è per-fornitore: cambiando fornitore la selezione
        // precedente non è più tra le opzioni del menu, che mostrerebbe l'id grezzo.
        if (fornitoreCambiato && form.saldo_patrimoniale_id) {
            const debito = props.debiti_patrimoniali.find(d => d.id === form.saldo_patrimoniale_id);
            if (!debito || debito.fornitore_id !== newFornitoreId) {
                form.saldo_patrimoniale_id = null;
            }
        }

        // 2. Aggiorna campi derivati dal fornitore
        if (!newFornitoreId || !newDataDoc) return;
        const f = fornitoriDisponibili.value.find(x => x.id === newFornitoreId);
        if (!f) return;

        if ((fornitoreCambiato || newDataDoc !== oldDataDoc) && !daPreservare?.scadenza) {
            const d = new Date(newDataDoc);
            d.setDate(d.getDate() + (f.giorni_scadenza || 30));
            form.data_scadenza = d.toISOString().substring(0, 10);
        }

        if (fornitoreCambiato) {
            if (!daPreservare?.iban) {
                form.iban_fornitore = f.iban_principale || form.iban_fornitore;
            }
            if (!daPreservare?.modalitaPagamento) {
                form.modalita_pagamento = f.modalita_pagamento_default || form.modalita_pagamento;
            }
        }
    },
    { immediate: true }
);

watch(() => form.esercizio_id, (v) => {
    form.gestione_id = null;
    if (!v || !props.gestioni.length) return;
    form.gestione_id = props.gestioni.find(g => g.tipo === 'ordinaria')?.id ?? props.gestioni[0].id;
}, { immediate: true });

// Tornando alla vista corrente, il saldo pregresso selezionato non deve
// restare nel form come passeggero fantasma del prossimo submit.
watch(() => form.is_pregresso, (attivo) => {
    if (!attivo) {
        form.saldo_patrimoniale_id = null;
    }
});

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
const addRiga = () => form.righe.push({
    descrizione:        '',
    // Stessa forma di aliquota_iva qui sotto: prefill col capitolo dell'ultima
    // fattura di questo fornitore (calcolato dal backend), non una scelta da zero.
    // `null` per un fornitore senza storico — non si inventa un capitolo qualsiasi.
    conto_id:           selectedFornitore.value?.ultimo_conto_id ?? null,
    immobile_id:        null,
    importo_imponibile: 0,
    // Prefill con l'ultima aliquota usata per questo fornitore (calcolata dal
    // backend), invece di un 22% fisso spesso sbagliato in ambito condominiale
    // (manodopera edile 10%, bancarie/assicurazioni 0%). 22% resta il fallback
    // per un fornitore senza storico.
    aliquota_iva:       selectedFornitore.value?.ultima_aliquota_iva ?? 22,
    is_sopravvenienza:  false,
    concorre_base_ritenuta: true,
});

/**
 * Sposta le chiavi d'errore delle righe quando la voce `idx` viene tolta.
 *
 * ⚠️ **Gli indici delle voci sono anche le chiavi degli errori.** `righe.1.conto_id` non
 * nomina *quella* voce: nomina la voce che in quel momento sta in seconda posizione.
 * Togliendone una più in alto le voci scalano e le chiavi restavano ferme, quindi il
 * percorso smetteva di puntare allo stesso dato e `usePuliziaErrori` concludeva che il
 * campo era stato **corretto**: bastava cancellare una voce qualsiasi perché l'errore di
 * un'altra sparisse da solo — dal riepilogo in testa e da sotto il campo — mentre la voce
 * sbagliata restava a schermo, rinumerata e senza più niente di rosso addosso
 * (Fase 1-bis, reperto 16).
 *
 * La regola è che l'errore appartiene alla **voce**, non alla sua posizione: la voce
 * cancellata si porta via i propri errori, quelle sotto scalano di uno, quelle sopra non si
 * toccano. Non è indovinare: è letteralmente ciò che il server ridirà da sé al salvataggio
 * successivo, perché `StoreFatturaRequest` numera per posizione corrente.
 *
 * ⚠️ Il percorso vuole un campo in coda (`^righe\.\d+\.`) apposta: la chiave nuda `righe`
 * — la regola `required|array|min:1` — non appartiene a nessuna voce e non deve muoversi.
 */
function rinumeraErroriRighe(idx: number) {
    const errori = form.errors as unknown as Record<string, string>;
    const posizione = (chiave: string) => Number(chiave.slice(6, chiave.indexOf('.', 6)));

    const coinvolte = Object.keys(errori).filter(
        (chiave) => /^righe\.\d+\./.test(chiave) && posizione(chiave) >= idx,
    );
    if (!coinvolte.length) return;

    const scalate = coinvolte
        .filter((chiave) => posizione(chiave) > idx)
        .map((chiave) => [
            chiave.replace(/^righe\.\d+\./, `righe.${posizione(chiave) - 1}.`),
            errori[chiave],
        ] as const);

    form.clearErrors(...coinvolte);
    if (scalate.length) form.setError(Object.fromEntries(scalate));
}

const removeRiga = (idx: number) => {
    if (form.righe.length <= 1) return;

    form.righe.splice(idx, 1);
    rinumeraErroriRighe(idx);
};

const showSpesaImprevistaModal = ref(false);

const spesaImprevistaMode = ref<'corrente' | 'pregressa'>('corrente');

const totaleCopertoPregressoCents = computed(() => {
    if (!form.is_pregresso) return 0;
    let sum = 0;

    // 1. Aggiungiamo la base del debito patrimoniale selezionato (già in centesimi)
    if (form.saldo_patrimoniale_id) {
        const debito = props.debiti_patrimoniali.find(d => d.id === form.saldo_patrimoniale_id);
        if (debito) sum += debito.importo_disponibile;
    }

    // 2. Aggiungiamo i fondi extra selezionati (ignorando i click manuali su sopravvenienza).
    //    L'importo è in euro digitati: si converte qui una volta sola, come fa
    //    FatturaPassivaService con (int) round($copertura['importo'] * 100).
    if (form.coperture?.length) {
        sum += form.coperture
            .filter((c: any) => c.tipo_copertura !== 'sopravvenienza')
            .reduce((acc: number, c: any) => acc + euroToCents(c.importo), 0);
    }

    return sum;
});

const eccedenzaPregressaCents = computed(() => {
    if (!form.is_pregresso) return 0;
    const eccedenza = totali.value.totale_documento_cents - totaleCopertoPregressoCents.value;
    return eccedenza > 1 ? eccedenza : 0;
});

/**
 * Entry point unico per l'invio del form.
 *
 * Ordine dei controlli:
 * 1. Spesa imprevista corrente senza dati legali → apre ModalSpesaImprevista
 * 2. Sforo budget senza override → apre ModalOverrideBudget
 * 3. Tutto OK → invia
 *
 * Nota: ModalSopravvenienza (vecchia logica pregressa) è stata rimossa perché
 * il suo trigger (form.coperture) non veniva mai popolato, rendendo il check
 * irraggiungibile. La gestione dei pregressi avviene interamente via WidgetDoubleLock.
 */
const handleSubmit = () => {
    // 1. Spesa imprevista CORRENTE
    const hasSpesaImprevistaCorrente = form.righe.some(r => r.is_sopravvenienza && !r.immobile_id);
    if (!form.is_pregresso && hasSpesaImprevistaCorrente && !form.dati_extra.log_legale_sopravvenienza) {
        spesaImprevistaMode.value = 'corrente';
        showSpesaImprevistaModal.value = true;
        return;
    }

    // 2. Eccedenza PREGRESSA (Scenario C — fattura > coperture dichiarate)
    if (form.is_pregresso && eccedenzaPregressaCents.value > 0 && !form.dati_extra.log_legale_sopravvenienza) {
        spesaImprevistaMode.value = 'pregressa';
        showSpesaImprevistaModal.value = true;
        return;
    }

    // 3. Sforo budget CORRENTE
    if (!form.is_pregresso && transactionStatus.value === 'CRITICAL_BUDGET' && !form.dati_extra.override_budget) {
        showOverrideModal.value = true;
        return;
    }

    doSubmit();
};

/**
 * Chiamato da ModalSpesaImprevista al confirm.
 *
 * Salva i dati legali, poi rilancia handleSubmit() invece di chiamare
 * doSubmit() direttamente. In questo modo:
 * - Se is_ordinario === true  → override_budget viene settato e handleSubmit passa al punto 3
 * - Se is_ordinario === false → override_budget rimane null; se lo sforo esiste ancora,
 *   handleSubmit lo intercetta al punto 2 e apre ModalOverrideBudget
 */
const handleSpesaImprevistaConfirm = (payload: any) => {
    // La modale ora emette già i campi corretti, non serve più il remap manuale!
    form.dati_extra.log_legale_sopravvenienza = payload;

    if (payload.is_ordinario) {
        form.dati_extra.override_budget = {
            motivazione:           payload.motivazione_sforo,
            importo_sforo:         totali.value.imponibile_sopravvenienza_cents + totali.value.iva_sopravvenienza_cents,
            strategia_rientro:     payload.strategia_rientro,
            fondo_patrimoniale_id: payload.fondo_patrimoniale_id,
        };
    }

    showSpesaImprevistaModal.value = false;
    handleSubmit();
};

/**
 * Chiamato da ModalOverrideBudget al confirm.
 */
const handleOverrideConfirm = (payload: { strategia: string; fondoId: number | null; motivazione: string }) => {
    form.dati_extra.override_budget = {
        motivazione:                payload.motivazione,
        importo_sforo:              sforoBudgetTotaleCents.value,
        budget_residuo_al_momento:  -sforoBudgetTotaleCents.value,
        timestamp:                  new Date().toISOString(),
        strategia_rientro:          payload.strategia,
        fondo_patrimoniale_id:      payload.fondoId,
    };

    showOverrideModal.value = false;
    doSubmit();
};

/**
 * Riporta il form allo stato con cui si inizia un documento nuovo — usata sia da
 * «Registra un'altra fattura» sia, in coda, quando si passa al prossimo file del
 * lotto.
 *
 * ⚠️ **Non usa `form.reset()`.** Verificato dal vivo il 03/09/2026 registrando due
 * documenti in sequenza dallo stesso lotto: dopo `form.reset()` l'IBAN del primo
 * fornitore (Termotecnica Omega) restava nel campo mentre si stava rivedendo il
 * secondo (Multiutility Nord, che l'XML non dichiara e la cui scheda in anagrafica
 * non ce l'ha) — `reset()` non stava riportando i valori ai default passati a
 * `useForm()` come atteso. Non isolata la causa esatta nel tempo a disposizione
 * (sospetto legato al post/redirect Inertia appena concluso quando si chiama
 * reset()); piuttosto che continuare a fidarsene, i campi si azzerano qui uno per
 * uno, con gli stessi valori dell'inizializzazione di `useForm()` sopra — se quei
 * default cambiano, vanno aggiornati anche qui.
 */
function resettaFormPerNuovoDocumento() {
    form.clearErrors();
    form.fornitore_id = null;
    form.esercizio_id = props.esercizio?.id || null;
    // ⚠️ **La gestione si ricalcola qui, non si azzera e basta.** `gestione_id` non
    // nasce dall'inizializzazione di `useForm()` ma da un watcher su `form.esercizio_id`
    // (più sotto): e quel watcher NON riparte, perché qui l'esercizio viene riassegnato
    // allo **stesso** valore che aveva già e per Vue non è un cambiamento. Azzerandola e
    // basta, dal secondo documento del lotto in poi la Gestione restava vuota e il
    // server rifiutava il salvataggio («Il campo gestione id è richiesto») — cioè la
    // funzione principale di questa beta, registrare più fatture di seguito, si rompeva
    // al secondo giro. Trovato dalla Fase 1-bis (reperto 14), presidiato da un test.
    form.gestione_id = form.esercizio_id && props.gestioni.length
        ? props.gestioni.find(g => g.tipo === 'ordinaria')?.id ?? props.gestioni[0].id
        : null;
    form.tipo_documento = 'fattura';
    form.is_pregresso = false;
    form.data_competenza_originaria = '';
    form.saldo_patrimoniale_id = null;
    form.imponibile_pregresso = 0;
    form.aliquota_iva_pregressa = 22;
    form.numero_documento = '';
    form.data_documento = new Date().toISOString().substring(0, 10);
    form.data_scadenza = '';
    form.conto_corrente_id = null;
    form.modalita_pagamento = 'bonifico';
    form.iban_fornitore = '';
    form.applica_ritenuta = null;
    form.dati_extra = {
        fiscal: { cig: '', cup: '', motivo_esclusione_ritenuta: '', motivo_esclusione_ritenuta_note: '', conferma_codice_tributo_mancante: false },
        competenza: { dal: '', al: '' },
        override_budget: null,
        log_legale_sopravvenienza: null,
    };
    form.stato_approvazione = 'approvata';
    form.righe = [{
        descrizione: '',
        conto_id: null,
        immobile_id: null,
        importo_imponibile: 0,
        aliquota_iva: 22,
        is_sopravvenienza: false,
        concorre_base_ritenuta: true,
    }];
    form.coperture = [];
    form.file = null;

    esitoFornitoreXml.value = null;
    avvisiImportazioneXml.value = null;
    campiXmlDaPreservare.value = null;
    ritenutaLettaDaXml.value = null;
    provenienzaXml.value = null;
    // ⚠️ Anche l'errore di **lettura** del file, che vive nell'istanza di
    // `useImportaFatturaXml()` e non fra questi ref: senza, il modulo per il documento
    // nuovo si apriva con un messaggio rosso che parla di un file non più allegato, e
    // l'amministratore cercava un problema che non esisteva (Fase 1-bis, reperto 17).
    // Restava lì finché non si sceglieva un altro allegato — l'unico altro punto che lo
    // azzera.
    resetErroreImportazione();
    fileInLavorazione.value = null;
}

const doSubmit = () => {
    // ⚠️ **Il documento che sto spedendo si cattura QUI, non si rilegge quando la risposta
    // arriva.** L'invio è un multipart con l'allegato: dura secondi, e in quei secondi la
    // pagina resta viva — dalla fascia si riapre il lettore e si può aprire un altro
    // documento del lotto, che è una cosa legittima e non va impedita disabilitando il
    // pulsante. `fileInLavorazione` risponde a «che cosa c'è nel modulo adesso»;
    // `onSuccess` ha bisogno di «che cosa ho spedito»: due domande diverse, dal momento in
    // cui fra le due passa del tempo. Rileggendo il ref, dalla coda usciva il documento
    // sbagliato — spariva quello mai inviato, e quello appena registrato veniva riproposto
    // per una seconda registrazione (Fase 1-bis, reperto 8).
    //
    // La cattura sta in `doSubmit` e non in `handleSubmit` perché fra i due può passare la
    // modale di sforo: il bersaglio è quello che parte, non quello che era a schermo
    // quando si è premuto la prima volta.
    const voceInviata = fileInLavorazione.value;

    form.transform((data) => {
        const payload = {
            ...data,
            dati_extra: JSON.parse(JSON.stringify(data.dati_extra)),
            coperture: data.coperture ? JSON.parse(JSON.stringify(data.coperture)) : []
        };

        payload.righe = payload.righe.map((r: any) => ({
            ...r,
            conto_id: r.conto_id ? Number(r.conto_id) : null,
            immobile_id: r.immobile_id ? Number(r.immobile_id) : null,
            importo_imponibile: Number(r.importo_imponibile) || 0,
            // NB: `|| 22` trattava lo ZERO come valore assente e lo sostituiva con
            // l'aliquota ordinaria. Le spese senza IVA sono normalissime in condominio
            // — commissioni bancarie, professionisti in regime forfetario — e venivano
            // salvate con il 22% pur essendo state digitate a 0: l'anteprima mostrava
            // l'importo giusto (usa `|| 0`), il documento salvato no.
            aliquota_iva: Number.isFinite(Number(r.aliquota_iva)) ? Number(r.aliquota_iva) : 22,
            is_sopravvenienza: Boolean(r.is_sopravvenienza),
            concorre_base_ritenuta: r.concorre_base_ritenuta !== false,
        }));

        // Il motivo di esclusione va inviato solo quando la ritenuta è
        // effettivamente esclusa: una stringa vuota fallirebbe Rule::in lato
        // backend, quindi normalizziamo a null quando non applicabile.
        if (payload.dati_extra?.fiscal) {
            const fiscal = payload.dati_extra.fiscal;
            if (applicaRitenutaEffective.value) {
                fiscal.motivo_esclusione_ritenuta = null;
                fiscal.motivo_esclusione_ritenuta_note = null;
            } else {
                fiscal.motivo_esclusione_ritenuta = fiscal.motivo_esclusione_ritenuta || null;
                fiscal.motivo_esclusione_ritenuta_note = fiscal.motivo_esclusione_ritenuta_note || null;
            }
        }

        // --- INIZIO FIX PREGRESSO ---
        if (payload.is_pregresso) {
            // Puliamo eventuali "sopravvenienze" aggiunte per errore dall'utente nel Widget
            payload.coperture = payload.coperture.filter((c: any) => c.tipo_copertura !== 'sopravvenienza');

            // AUTO-INIEZIONE: Diciamo al backend che stiamo usando il Saldo Patrimoniale di base
            if (payload.saldo_patrimoniale_id) {
                const debito = props.debiti_patrimoniali.find(d => d.id === payload.saldo_patrimoniale_id);
                if (debito) {
                    // Il lordo è quello del riquadro totali, in centesimi. Calcolarlo qui in
                    // euro — imponibile × (1 + aliquota/100) — dava un centesimo in meno del
                    // backend ogni volta che il prodotto cadeva su mezzo centesimo: la
                    // copertura partiva sottostimata e l'eccedenza residua di 0,01 € generava
                    // una riga DARE spuria su passate_gestioni, su una fattura che a schermo
                    // quadrava perfettamente.
                    const copertureExtraCents = payload.coperture.reduce((a: number, c: any) => a + euroToCents(c.importo), 0);
                    const importoBaseCents = Math.min(
                        debito.importo_disponibile,
                        totali.value.totale_documento_cents - copertureExtraCents,
                    );

                    if (importoBaseCents > 0) {
                        payload.coperture.unshift({
                            tipo_copertura: 'rata_0',
                            // Il backend riconverte con `(int) round($importo * 100)`: la
                            // divisione per 100 di un intero torna esatta.
                            importo: importoBaseCents / 100,
                            fonte_id: debito.id
                        });
                    }
                }
            }
        }
        // --- FINE FIX ---

        return payload;
    }).post(route(generateRoute('gestionale.fatture.store'), { condominio: props.condominio.id }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            // Tolto dalla coda SUBITO, prima che la modale legga filesInCodaPronti:
            // è la risposta a «carico due documenti, ne registro uno, dove trovo
            // l'altro?» — la modale di chiusura lo mostra da sé, invece di lasciare
            // che si cerchi da qualche altra parte.
            if (voceInviata) rimuoviFilePendente(voceInviata);
            showSuccessModal.value = true;
        },
        // Il riquadro di riepilogo sta in testa alla pagina, il pulsante in fondo alla
        // colonna sinistra: senza questo salto un rifiuto resta fuori schermo, e per
        // l'amministratore «ho premuto e non è successo niente» — lo stesso difetto,
        // solo un po' più in là. Stessa soluzione di FornitoriNew.vue.
        onError: portaInVistaIlRiepilogo,
    });
};

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route(generateRoute('gestionale.index'),         { condominio: props.condominio.id }) },
    { title: 'Fatture',   href: route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }) },
    { title: 'Registrazione' },
]);

/**
 * ⚠️ **Qui c'erano due terne di schede, una per fase, ed è tornata a essercene una
 * sola.** Con la fase `scelta` è sparito anche il momento in cui la pagina parlava
 * dell'importazione invece che della registrazione: adesso la pagina è sempre la
 * registrazione, e l'XML è una scorciatoia per riempirla.
 *
 * Le tre spiegazioni dell'importazione (lettura automatica, fornitore riconosciuto,
 * capitolo proposto) **non sono state buttate**: sono nella guida dell'header
 * (`FatturaRegistrazioneGuide`), dove le ha volute Vincenzo il 03/09/2026 — lì le
 * legge chi vuole capire come funziona, senza rubare la testa della pagina a chi sta
 * solo registrando una fattura.
 */
const pageGuides = [
    { title: 'Panel + Ledger',   description: 'I dati principali a sinistra, le voci a destra come un registro contabile. Tutto visibile in un\'unica schermata.', icon: ArrowRightLeft, colorVariant: 'blue' as const },
    { title: 'Controllo Budget', description: 'Il sistema verifica il residuo per ogni capitolo di spesa in tempo reale, riga per riga.',                          icon: Zap,            colorVariant: 'amber' as const },
    { title: 'Audit Trail',      description: 'Ogni sforamento deve essere giustificato con motivazione legale prima della registrazione.',                         icon: ShieldAlert,    colorVariant: 'emerald' as const },
];

const pageTitle = 'Registrazione fattura passiva';
const pageSubtitle = 'Inserisci i dati nel pannello di sinistra e le voci di dettaglio nel registro a destra.';
</script>

<template>
    <Head title="Registrazione fattura" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                :page-title="pageTitle"
                :page-subtitle="pageSubtitle"
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :back-url="route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id })"
                back-text="Indietro"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <Button variant="outline" size="sm" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200" @click="showGuideCompleta = true">
                        <FileText class="w-4 h-4" />
                        Guida completa
                    </Button>
                </template>
            </PageHeaderGuide>

            <!-- ═══════════════════════════════════════════════════════════════════
                 La fascia d'ingresso del lettore XML.
                 Sostituisce la vecchia FASE «scelta» (una pagina intera con la
                 dropzone al posto del modulo), eliminata il 03/09/2026 con Vincenzo:
                 quella fase era una porta a senso unico — dal modulo non si tornava
                 alla dropzone, e chi si ricordava dell'XML a metà compilazione doveva
                 uscire dalla pagina e perdere quello che aveva scritto.
                 Qui il lettore è raggiungibile sempre, e la pagina di registrazione
                 resta una sola.
                 ⚠️ La fascia NON sparisce dopo aver letto un file: cambia stato. È il
                 punto — se sparisse tornerebbe la porta a senso unico, solo un po' più
                 in là. E resta separata dal riquadro «Allega documento» in fondo al
                 pannello: allegare il PDF e leggere l'XML sono due gesti diversi, e
                 confonderli era l'obiezione che ha scartato l'altra soluzione.
                 ═══════════════════════════════════════════════════════════════════ -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm px-4 py-3">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                        <UploadCloud class="w-3.5 h-3.5 text-primary" />
                    </span>
                    <div class="min-w-0">
                        <!-- ⚠️ Il nome arriva da `provenienzaXml`, che è un **valore**: a
                             registrazione riuscita la voce esce dalla coda mentre questa
                             fascia è ancora montata sotto la modale di successo, e un
                             puntatore andrebbe in bianco proprio lì. -->
                        <template v-if="provenienzaXml">
                            <p class="text-[13px] font-bold text-slate-900 dark:text-slate-100 truncate">
                                Compilato dal file {{ provenienzaXml }}
                            </p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                <template v-if="altriFileInCoda.length">
                                    Resta{{ altriFileInCoda.length === 1 ? '' : 'no' }} {{ altriFileInCoda.length }}
                                    {{ altriFileInCoda.length === 1 ? 'altro documento' : 'altri documenti' }} in questo lotto: te
                                    {{ altriFileInCoda.length === 1 ? 'lo ripropongo' : 'li ripropongo' }} dopo il salvataggio.
                                </template>
                                <template v-else>
                                    Controlla i campi qui sotto, poi registra.
                                </template>
                            </p>
                        </template>
                        <template v-else>
                            <p class="text-[13px] font-bold text-slate-900 dark:text-slate-100">Hai il file XML della fattura?</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                Lo leggo e compilo io i campi — numero, data, importi e fornitore.
                            </p>
                        </template>
                    </div>
                </div>

                <!-- ⚠️ L'etichetta guarda **l'elenco**, non da dove vengono i dati: questo
                     pulsante apre la modale che elenca `filesPendenti`, e prometteva
                     «Gestisci i file» anche a elenco vuoto — chi importa dal riquadro
                     «Allega documento», che è la porta più battuta, lo apriva sulla sola
                     dropzone. Un pulsante che promette un elenco deve aprire un elenco.
                     Nessun `:disabled` durante l'invio, ed è voluto: guardare la coda
                     mentre si aspetta è legittimo, e il documento spedito è già stato
                     catturato da `doSubmit()`. -->
                <Button type="button" class="h-8 rounded-lg font-medium text-xs px-3 shrink-0 gap-2" @click="showImportaXmlModal = true">
                    <UploadCloud class="w-3.5 h-3.5 text-sky-400" />
                    {{ filesPendenti.length ? 'Gestisci i file' : 'Importa XML' }}
                </Button>
            </div>


            <!-- Riepilogo dei rifiuti del server — **lo stesso componente della scheda
                 fornitore** (FornitoriNew/FornitoriEdit), non una seconda soluzione
                 allo stesso problema: `FormErrorSummary` nasce nella beta.7 proprio da
                 un rifiuto muto, e il suo docblock lo dice — «copre anche le chiavi che
                 nessuno ha ancora collegato a un campo».
                 ⚠️ Il difetto chiuso qui è esattamente quello: il template rende a
                 fianco del campo solo `numero_documento`, `righe.N.descrizione` e
                 `righe.N.conto_id`, quindi ogni altra chiave tornava dal server,
                 popolava `form.errors` e non compariva da nessuna parte — il pulsante
                 si riabilitava e basta. Provato dal vivo il 03/09/2026 leggendo la
                 risposta XHR: `righe.2.conto_id`, invisibile. Stessa classe della
                 Decisione D2 della beta.13 (il duplicato), risolta lì per un caso solo
                 invece che per la regola. -->
            <div ref="riepilogoErrori" tabindex="-1" class="outline-none">
                <FormErrorSummary :errors="form.errors" :labels="etichetteErrori" />
            </div>

            <!-- Banner warning -->
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100">
                <div v-if="!form.is_pregresso && transactionStatus !== 'SAFE'"
                    class="rounded-xl p-4 flex items-center gap-4 border"
                    :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-50 border-rose-200 text-rose-900' : 'bg-amber-50 border-amber-200 text-amber-900'">
                    <AlertOctagon v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5 shrink-0" />
                    <TriangleAlert v-else class="w-5 h-5 shrink-0" />
                    <p class="text-sm font-medium">
                        {{ transactionStatus === 'CRITICAL_BUDGET'
                            ? 'Sforamento budget rilevato — sarà necessaria una motivazione al momento della registrazione.'
                            : 'Liquidità insufficiente sul conto selezionato.' }}
                    </p>
                </div>
            </Transition>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start relative z-10">

                <!-- ── Pannello sinistro ── -->
                <div class="lg:col-span-4 h-full flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 shrink-0">
                        <div class="space-y-3 mb-4">
                            <!-- Toggle Fattura / Nota Credito -->
                            <div class="flex bg-slate-100 dark:bg-slate-800 p-1 rounded-lg">
                                <button type="button" @click="form.tipo_documento = 'fattura'"
                                    class="flex-1 py-2 text-[11px] font-black uppercase tracking-wider rounded-md transition-all flex items-center justify-center gap-1.5"
                                    :class="form.tipo_documento === 'fattura' ? 'bg-white dark:bg-slate-700 text-primary shadow-sm' : 'text-slate-400'">
                                    <FileText class="w-3.5 h-3.5" /> Fattura
                                </button>
                                <button type="button" @click="form.tipo_documento = 'nota_credito'"
                                    class="flex-1 py-2 text-[11px] font-black uppercase tracking-wider rounded-md transition-all"
                                    :class="form.tipo_documento === 'nota_credito' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-400'">
                                    Nota Credito
                                </button>
                            </div>
                            <Transition mode="out-in">
                                <div v-if="form.tipo_documento === 'fattura'" key="ft" class="flex items-start gap-2 px-2.5 py-2 bg-blue-50 dark:bg-blue-950/30 rounded-lg border border-blue-100 dark:border-blue-900/30">
                                    <FileText class="w-3.5 h-3.5 text-blue-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-blue-700 dark:text-blue-400 leading-relaxed">
                                        <strong>Fattura passiva</strong> — documento ricevuto dal fornitore per beni o servizi acquistati.
                                    </p>
                                </div>
                                <div v-else key="nc" class="flex items-start gap-2 px-2.5 py-2 bg-rose-50 dark:bg-rose-950/30 rounded-lg border border-rose-100 dark:border-rose-900/30">
                                    <AlertTriangle class="w-3.5 h-3.5 text-rose-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                        <strong>Nota di credito</strong> — documento emesso dal fornitore per rettificare una fattura.
                                    </p>
                                </div>
                            </Transition>
                        </div>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto space-y-5">

                        <!-- Fornitore -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore</Label>
                            <v-select
                                v-model="form.fornitore_id"
                                :options="fornitoriDisponibili"
                                label="ragione_sociale"
                                :reduce="(f: Fornitore) => f.id"
                                placeholder="Cerca fornitore..."
                                class="w-full">
                                <template #option="{ ragione_sociale, partita_iva, codice_fiscale, soggetto_ritenuta }">
                                    <div class="flex items-center gap-3 py-1">
                                        <div class="w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center shrink-0">
                                            <Briefcase class="w-4 h-4 text-slate-400" />
                                        </div>
                                        <div class="flex flex-col overflow-hidden">
                                            <span class="font-bold text-sm text-slate-800 dark:text-slate-200 truncate">{{ ragione_sociale }}</span>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <span v-if="partita_iva" class="text-[10px] text-slate-500 font-medium">P.IVA: {{ partita_iva }}</span>
                                                <span v-else-if="codice_fiscale" class="text-[10px] text-slate-500 font-medium">C.F.: {{ codice_fiscale }}</span>
                                                <span v-else class="text-[10px] text-slate-400 italic">Nessuna P.IVA / C.F.</span>
                                                <span v-if="soggetto_ritenuta" class="text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900/50 dark:text-amber-500 rounded px-1.5 py-0.5 leading-none">
                                                    Ritenuta
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <template #selected-option="{ ragione_sociale, soggetto_ritenuta }">
                                    <div class="flex items-center gap-2 w-full overflow-hidden pr-2">
                                        <Briefcase class="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                        <span class="font-semibold text-sm truncate text-slate-800 dark:text-slate-200">{{ ragione_sociale }}</span>
                                        <span v-if="soggetto_ritenuta" class="ml-auto text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900/50 dark:text-amber-500 rounded px-1.5 py-0.5 leading-none shrink-0">
                                            Ritenuta
                                        </span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <!-- Ritenuta d'acconto: toggle per documento -->
                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="-translate-y-1 opacity-0" enter-to-class="translate-y-0 opacity-100">
                            <div v-if="fornitoreRitenutaAttiva" class="p-3 bg-amber-50/50 dark:bg-amber-900/10 rounded-lg border border-amber-100 dark:border-amber-900/30 space-y-2.5">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox"
                                        :checked="applicaRitenutaEffective"
                                        @change="applicaRitenutaEffective = ($event.target as HTMLInputElement).checked"
                                        class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-400">
                                        Applica ritenuta d'acconto su questo documento
                                    </span>
                                </label>

                                <div v-if="!applicaRitenutaEffective" class="space-y-2 pt-1">
                                    <v-select
                                        v-model="form.dati_extra.fiscal.motivo_esclusione_ritenuta"
                                        :options="MOTIVI_ESCLUSIONE_RITENUTA"
                                        :reduce="(o: any) => o.value"
                                        label="label"
                                        placeholder="Motivo dell'esclusione..."
                                        class="text-xs"
                                    />
                                    <p v-if="form.errors['dati_extra.fiscal.motivo_esclusione_ritenuta']" class="text-[11px] text-red-600 font-medium">
                                        {{ form.errors['dati_extra.fiscal.motivo_esclusione_ritenuta'] }}
                                    </p>
                                    <Input
                                        v-if="form.dati_extra.fiscal.motivo_esclusione_ritenuta === 'override_manuale'"
                                        v-model="form.dati_extra.fiscal.motivo_esclusione_ritenuta_note"
                                        placeholder="Specifica il motivo..."
                                        class="h-9 text-xs bg-white" />
                                </div>
                            </div>
                        </Transition>

                        <!-- Codice tributo indeterminabile: warning bloccante con override (design §2.4 M2) -->
                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="-translate-y-1 opacity-0" enter-to-class="translate-y-0 opacity-100">
                            <div v-if="codiceTributoIndeterminabile" class="p-3 bg-rose-50 dark:bg-rose-900/10 rounded-lg border border-rose-200 dark:border-rose-900/30 space-y-2">
                                <p class="text-[11px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                    <strong>Codice tributo indeterminabile.</strong> Manca la natura del percipiente sull'anagrafica di {{ selectedFornitore?.ragione_sociale }}: il sistema non può decidere se il codice è 1019 o 1020.
                                    <Link :href="route(generateRoute('fornitori.edit'), { fornitore: form.fornitore_id })" target="_blank" class="underline font-semibold">Completa l'anagrafica</Link>
                                    oppure conferma per procedere comunque.
                                </p>
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox" v-model="form.dati_extra.fiscal.conferma_codice_tributo_mancante"
                                        class="w-4 h-4 text-rose-600 rounded border-slate-300 focus:ring-rose-500 cursor-pointer" />
                                    <span class="text-[11px] font-semibold text-rose-800 dark:text-rose-300">
                                        Confermo di voler procedere: correggerò il codice tributo manualmente prima dell'F24
                                    </span>
                                </label>
                            </div>
                        </Transition>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Gestione -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Gestione</Label>
                            <v-select
                                v-model="form.gestione_id"
                                :options="gestioniFiltrate"
                                :reduce="(g: Gestione) => g.id"
                                label="nome"
                                placeholder="Seleziona..."
                                class="style-chooser">
                                <template #option="{ nome, tipo }">
                                    <div class="flex justify-between items-center gap-2 py-0.5">
                                        <span class="font-bold text-sm truncate min-w-0">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400 capitalize shrink-0">{{ tipo }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, tipo }">
                                    <div class="flex items-center gap-2 w-full overflow-hidden pr-2">
                                        <span class="font-bold text-sm truncate min-w-0">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400 capitalize shrink-0">{{ tipo }}</span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <!-- N. Documento -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">N. Documento</Label>
                            <Input v-model="form.numero_documento"
                                class="h-9 uppercase text-base tracking-widest"
                                :class="{ 'border-red-500 focus-visible:ring-red-500': form.errors.numero_documento }"
                                @input="form.clearErrors('numero_documento')"
                                placeholder="Es. 123/A" />
                            <p v-if="form.errors.numero_documento" class="text-[11px] text-red-600 font-medium">
                                {{ form.errors.numero_documento }}
                            </p>
                        </div>

                        <!-- Date -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5 col-span-2 md:col-span-1">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data *</Label>
                                <Input type="date" v-model="form.data_documento" class="h-9 text-sm" />
                            </div>
                            <div class="space-y-1.5 col-span-2 md:col-span-1">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-primary">Scadenza *</Label>
                                <Input
                                    type="date"
                                    v-model="form.data_scadenza"
                                    class="h-9 text-sm border-primary/40 bg-primary/5 text-primary font-bold" />
                            </div>
                        </div>
                        <div v-if="isDataDocumentoVecchia" class="flex items-start gap-2 text-[10.5px] font-medium text-amber-700 bg-amber-50 p-2 rounded-md border border-amber-200">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-500" />
                            <span><strong>Attenzione (Art. 1130 c.c.)</strong> Stai registrando un'operazione avvenuta oltre 30 giorni fa. Ricorda che la normativa prevede l'annotazione a registro entro i 30 giorni.</span>
                        </div>

                        <!-- Duplicati (decisione D4) — stessa forma del banner sopra: ambra, sotto
                             i campi che l'hanno prodotto, testo visibile, e handleSubmit() non lo
                             guarda mai: due livelli, sempre avviso, mai blocco. -->
                        <div v-if="fattureSimili.length > 0" class="flex items-start gap-2 text-[10.5px] font-medium text-amber-700 bg-amber-50 p-2 rounded-md border border-amber-200">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-500" />
                            <div class="flex flex-col gap-1">
                                <div v-for="f in fattureSimili" :key="f.id">
                                    <strong>{{ f.motivo === 'forte' ? 'Possibile duplicato:' : 'Fattura simile già registrata:' }}</strong>
                                    n. {{ f.numero_documento }} del {{ formatDataBreve(f.data_documento) }}, {{ euro(f.totale_documento) }}<span v-if="f.is_pregresso"> (pregressa)</span>.
                                    <Link
                                        :href="route(generateRoute('gestionale.fatture.show'), { condominio: props.condominio.id, fattura: f.id })"
                                        target="_blank"
                                        class="underline font-semibold"
                                    >Vedi la fattura</Link>
                                </div>
                            </div>
                        </div>

                        <!-- Checkbox pregresso -->
                        <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700 flex items-start gap-3 transition-colors"
                            :class="{ 'bg-amber-50/50 border-amber-200 dark:bg-amber-900/10 dark:border-amber-700/50': form.is_pregresso }">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="is_pregresso" v-model="form.is_pregresso"
                                    class="w-4 h-4 text-amber-500 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                            </div>
                            <div class="flex flex-col">
                                <label for="is_pregresso" class="text-[11px] font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 cursor-pointer">
                                    Debito esercizio precedente
                                </label>
                                <p v-if="form.is_pregresso" class="text-[10px] text-amber-600 dark:text-amber-400 mt-1 font-medium leading-tight">
                                    Questa spesa non intaccherà il budget corrente. Verrà registrata come debito pregresso nello stato patrimoniale.
                                </p>
                            </div>
                        </div>

                        <!-- Campi imponibile/iva per pregressi -->
                        <div v-if="form.is_pregresso" class="grid grid-cols-3 gap-3 p-4 bg-amber-50/30 dark:bg-amber-900/5 border border-amber-100 dark:border-amber-800/30 rounded-lg mt-3">
                            <div class="col-span-2 space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Imponibile Lordo</Label>
                                <MoneyInput
                                    id="importo_pregresso"
                                    v-model="form.imponibile_pregresso"
                                    :money-options="moneyOptions"
                                    :lazy="false"
                                    class="h-10 font-black text-base bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm rounded-md border w-full px-3 focus:border-amber-400 focus:ring-amber-400/20"
                                    placeholder="0,00" />
                            </div>
                            <div class="col-span-1 space-y-1.5 relative">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IVA</Label>
                                <div class="relative">
                                    <Input min="0" max="100" v-model="form.aliquota_iva_pregressa"
                                        class="h-10 text-center font-black text-base pr-5 pl-1 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm focus:border-amber-400 focus:ring-amber-400/20" />
                                    <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Conto addebito -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto Addebito</Label>
                            <v-select v-model="form.conto_corrente_id" :options="bancheNormalizzate" label="nome" :reduce="(c: any) => c.id" placeholder="Seleziona banca...">
                                <template #option="{ nome, saldo_attuale_cents }">
                                    <div class="flex justify-between items-center py-0.5">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                        <span class="text-[10px]" :class="saldo_attuale_cents >= 0 ? 'text-emerald-600' : 'text-rose-500'">{{ euro(saldo_attuale_cents) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_attuale_cents }">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400">{{ euro(saldo_attuale_cents) }}</span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <!-- IBAN -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IBAN Fornitore</Label>
                            <Input v-model="form.iban_fornitore" class="h-9 text-sm" placeholder="IT00 0000..." />
                        </div>

                        <!-- Allegato — un XML/P7M qui viene anche letto, non solo allegato (beta.14) -->
                        <div class="border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors" @click="fileInput?.click()">
                            <LoaderCircle v-if="importazioneInCorso" class="w-5 h-5 text-primary mx-auto mb-1 animate-spin" />
                            <FileText v-else class="w-5 h-5 text-slate-300 mx-auto mb-1" />
                            <p class="text-[11px] text-slate-400 font-medium">
                                {{ importazioneInCorso ? 'Leggo il file...' : (form.file ? form.file.name : 'Allega documento (PDF, XML, P7M)') }}
                            </p>
                            <input type="file" ref="fileInput" class="hidden" accept=".pdf,.xml,.p7m,.jpg,.jpeg,.png" @change="gestisciFileSelezionato" />
                        </div>

                        <!-- Esito della lettura XML -->
                        <div v-if="erroreImportazione" class="flex items-start gap-2 px-2.5 py-2 bg-rose-50 dark:bg-rose-950/30 rounded-lg border border-rose-200 dark:border-rose-900/40">
                            <AlertOctagon class="w-3.5 h-3.5 text-rose-500 shrink-0 mt-0.5" />
                            <span class="text-[11px] text-rose-700 dark:text-rose-400">{{ erroreImportazione }}</span>
                        </div>

                        <template v-if="esitoFornitoreXml">
                            <div v-if="esitoFornitoreXml.esito === 'trovato'" class="flex items-start gap-2 px-2.5 py-2 bg-emerald-50 dark:bg-emerald-950/20 rounded-lg border border-emerald-200 dark:border-emerald-900/40">
                                <CheckCircle class="w-3.5 h-3.5 text-emerald-600 shrink-0 mt-0.5" />
                                <span class="text-[11px] text-emerald-800 dark:text-emerald-400">
                                    Fornitore agganciato per {{ esitoFornitoreXml.letto_da_xml.partita_iva ? 'P.IVA' : 'codice fiscale' }}: <strong>{{ selectedFornitore?.ragione_sociale }}</strong>.
                                </span>
                            </div>

                            <div v-else-if="esitoFornitoreXml.esito === 'ambiguo'" class="px-2.5 py-2 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-900/40">
                                <div class="flex items-start gap-2 mb-1.5">
                                    <HelpCircle class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" />
                                    <span class="text-[11px] text-amber-800 dark:text-amber-400">
                                        Più di un fornitore ha questa P.IVA — quale intendevi?
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-1.5 pl-5">
                                    <button v-for="c in esitoFornitoreXml.candidati" :key="c.id" type="button"
                                        @click="scegliFornitoreXml(c.id)"
                                        class="text-[10px] font-semibold px-2 py-1 rounded-md bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-800 text-amber-800 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors">
                                        {{ c.ragione_sociale }}
                                    </button>
                                </div>
                            </div>

                            <div v-else class="flex flex-col gap-2 px-2.5 py-2 bg-slate-100 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700">
                                <div class="flex items-start gap-2">
                                    <User class="w-3.5 h-3.5 text-slate-400 shrink-0 mt-0.5" />
                                    <span class="text-[11px] text-slate-600 dark:text-slate-400">
                                        Nessun fornitore trovato per <strong>{{ esitoFornitoreXml.letto_da_xml.denominazione }}</strong>
                                        <template v-if="esitoFornitoreXml.letto_da_xml.partita_iva"> (P.IVA {{ esitoFornitoreXml.letto_da_xml.partita_iva }})</template>.
                                        Puoi selezionarlo qui sopra se esiste già con un altro nome, o crearlo ora senza lasciare questa pagina.
                                    </span>
                                </div>
                                <button type="button" @click="showCreaFornitoreModal = true"
                                    class="self-start ml-5 text-[11px] font-bold px-2.5 py-1 rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors">
                                    Crea fornitore da questo file
                                </button>
                            </div>
                        </template>

                        <!-- ═══════════════════════════════════════════════════════════════════
                             CONFRONTO SULLA RITENUTA — «il file dice questo, il modulo fa quello»
                             ═══════════════════════════════════════════════════════════════════
                             ⚠️ Fino alla beta.14 il dato del file arrivava qui e non lo
                             leggeva nessuno: una parcella con ritenuta dichiarata si
                             registrava a netto pieno, il condominio pagava tutto al
                             fornitore e non versava niente all'Erario, restando comunque
                             responsabile come sostituto d'imposta (Fase 1-bis, reperti 2 e 12).

                             ⚠️ **Si segnala, non si blocca** — decisione di Vincenzo del
                             03/09/2026. L'anagrafica descrive il fornitore *oggi*, il file
                             descrive quel documento *allora*: nessuno dei due comanda
                             sull'altro, quindi questa è una **discrepanza**, non un errore,
                             e l'ultima parola è dell'amministratore.

                             ⚠️ Quando non c'è niente da dire questi riquadri NON compaiono:
                             `nessun_confronto` copre i due casi più frequenti di tutti. Un
                             avviso che c'è sempre smette di essere letto in una settimana.
                             ═══════════════════════════════════════════════════════════════════ -->
                        <div v-if="confronto.stato === 'coincidono'" class="flex items-start gap-2 px-2.5 py-2 bg-emerald-50 dark:bg-emerald-950/20 rounded-lg border border-emerald-200 dark:border-emerald-900/40">
                            <ShieldCheck class="w-3.5 h-3.5 text-emerald-600 shrink-0 mt-0.5" />
                            <span class="text-[11px] text-emerald-800 dark:text-emerald-400">
                                Ritenuta d'acconto: il file ne dichiara {{ euro(confronto.fileCents) }} e il modulo trattiene lo stesso importo.
                            </span>
                        </div>

                        <div v-else-if="confronto.stato === 'importi_diversi'" class="px-2.5 py-2 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-900/40">
                            <div class="flex items-start gap-2">
                                <TriangleAlert class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" />
                                <div class="text-[11px] text-amber-800 dark:text-amber-400 space-y-1">
                                    <p class="font-bold">Il file e l'anagrafica non coincidono.</p>
                                    <p>
                                        Il file dichiara {{ euro(confronto.fileCents) }}. Il modulo trattiene {{ euro(confronto.moduloCents) }},
                                        applicando il regime registrato in anagrafica<template v-if="selectedFornitore"> per {{ selectedFornitore.ragione_sociale }}</template>.
                                    </p>
                                    <p>
                                        Nessuno dei due valori viene cambiato in automatico. Se il regime in anagrafica è sbagliato correggilo
                                        prima di registrare; se è giusto, registra pure: il documento resta quello che è.
                                    </p>
                                    <Link v-if="selectedFornitore" :href="route(generateRoute('fornitori.edit'), { fornitore: selectedFornitore.id })"
                                          target="_blank" class="inline-block font-bold underline underline-offset-2">Apri l'anagrafica del fornitore</Link>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="confronto.stato === 'file_dichiara_modulo_no'" class="px-2.5 py-2 bg-rose-50 dark:bg-rose-950/20 rounded-lg border border-rose-200 dark:border-rose-900/40">
                            <div class="flex items-start gap-2">
                                <TriangleAlert class="w-3.5 h-3.5 text-rose-600 shrink-0 mt-0.5" />
                                <div class="text-[11px] text-rose-800 dark:text-rose-400 space-y-1">
                                    <p class="font-bold">Il file dichiara una ritenuta, il modulo non ne trattiene nessuna.</p>
                                    <p>
                                        Il documento dichiara una ritenuta d'acconto di {{ euro(confronto.fileCents) }}<template
                                            v-if="ritenutaLettaDaXml"> (aliquota {{ ritenutaLettaDaXml.aliquota }}%<template
                                            v-if="ritenutaLettaDaXml.tipo">, tipo {{ ritenutaLettaDaXml.tipo }}</template><template
                                            v-if="ritenutaLettaDaXml.causale_pagamento">, causale {{ ritenutaLettaDaXml.causale_pagamento }}</template>)</template>.
                                    </p>

                                    <p v-if="confronto.motivo === 'fornitore_mancante'">
                                        Il fornitore non è ancora agganciato: sceglilo qui sopra, o crealo dal file, e poi controlla che la ritenuta risulti.
                                    </p>
                                    <p v-else-if="confronto.motivo === 'non_soggetto'">
                                        In anagrafica {{ selectedFornitore?.ragione_sociale }} non è segnato come soggetto a ritenuta, quindi il netto
                                        da pagare qui sotto è l'intero importo del documento. Se la ritenuta è dovuta, il condominio deve trattenerla
                                        e versarla con l'F24: segna il fornitore in anagrafica prima di registrare.
                                    </p>
                                    <p v-else-if="confronto.motivo === 'forfetario'">
                                        {{ selectedFornitore?.ragione_sociale }} è registrato in regime forfetario, e sul forfetario la ritenuta non si
                                        applica per legge: le due cose si contraddicono. Controlla il documento, o il regime in anagrafica, prima di registrare.
                                    </p>
                                    <p v-else-if="confronto.motivo === 'nota_credito'">
                                        Su una nota di credito il modulo non applica la ritenuta, salvo spunta esplicita: è il comportamento previsto.
                                        Controlla che sia quello che vuoi.
                                    </p>
                                    <p v-else-if="confronto.motivo === 'esclusa_a_mano'">
                                        L'hai esclusa su questo documento, e resta esclusa: la scelta sul singolo documento vale più di quanto dichiara
                                        il file. Controlla solo che il motivo indicato sia quello giusto.
                                    </p>
                                    <p v-else>
                                        Il modulo calcola una trattenuta di {{ euro(0) }}: controlla che le righe concorrano alla base e che il regime
                                        sul fornitore sia completo.
                                    </p>

                                    <Link v-if="selectedFornitore" :href="route(generateRoute('fornitori.edit'), { fornitore: selectedFornitore.id })"
                                          target="_blank" class="inline-block font-bold underline underline-offset-2">Apri l'anagrafica del fornitore</Link>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="confronto.stato === 'modulo_trattiene_file_tace'" class="px-2.5 py-2 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-900/40">
                            <div class="flex items-start gap-2">
                                <TriangleAlert class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" />
                                <div class="text-[11px] text-amber-800 dark:text-amber-400 space-y-1">
                                    <p class="font-bold">Il file non dichiara nessuna ritenuta, il modulo ne trattiene {{ euro(confronto.moduloCents) }}.</p>
                                    <p>
                                        L'assenza del blocco nel file non vuol dire che la ritenuta non sia dovuta: l'obbligo è del condominio come
                                        sostituto d'imposta, non del fornitore che la dichiara. Qui si applica il regime registrato in anagrafica<template
                                        v-if="selectedFornitore"> per {{ selectedFornitore.ragione_sociale }}</template>.
                                    </p>
                                    <p>
                                        Se su questo documento non va applicata, togli la spunta «applica ritenuta d'acconto su questo documento»
                                        e indica il motivo.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- I contributi previdenziali (RT03…RT06) non sono ritenute d'acconto:
                             li versa il fornitore al proprio ente, non il condominio con l'F24.
                             Non li trattiamo, ma tacerli lascerebbe inspiegata la differenza fra
                             il totale del file e quello a schermo (Fase 1-bis, reperto 19). -->
                        <div v-if="avvisiImportazioneXml?.contributi_previdenziali_dichiarati?.length"
                             class="flex items-start gap-2 px-2.5 py-2 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-200 dark:border-slate-800">
                            <TriangleAlert class="w-3.5 h-3.5 text-slate-500 shrink-0 mt-0.5" />
                            <span class="text-[11px] text-slate-700 dark:text-slate-400">
                                Il file dichiara anche un contributo previdenziale ({{ avvisiImportazioneXml.contributi_previdenziali_dichiarati.join(', ') }}):
                                non è una ritenuta d'acconto, il modulo non lo tratta e non entra nel confronto qui sopra.
                            </span>
                        </div>

                        <div v-if="avvisiImportazioneXml?.lotto_con_altri_documenti" class="flex items-start gap-2 px-2.5 py-2 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-900/40">
                            <TriangleAlert class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" />
                            <span class="text-[11px] text-amber-800 dark:text-amber-400">
                                Il file contiene altri {{ avvisiImportazioneXml.lotto_con_altri_documenti }} documenti oltre a questo: precompilato solo il primo, gli altri vanno importati a parte.
                            </span>
                        </div>

                        <div v-if="avvisiImportazioneXml?.righe_non_quadrano_col_riepilogo" class="flex items-start gap-2 px-2.5 py-2 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-900/40">
                            <TriangleAlert class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" />
                            <span class="text-[11px] text-amber-800 dark:text-amber-400">
                                Il totale dichiarato dal documento non coincide con la somma delle righe lette ({{ euro(avvisiImportazioneXml.scarto_righe_riepilogo_cents) }} di scarto). Controlla gli importi prima di registrare.
                            </span>
                        </div>
                    </div>

                    <!-- Footer con totali e pulsante -->
                    <div class="p-5 bg-slate-900 dark:bg-slate-950 text-white border-t border-slate-700 shrink-0 space-y-4">
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Imponibile lordo</span>
                                <span>{{ euro(totali.imponibile_cents) }}</span>
                            </div>

                            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="totali.ha_sopravvenienze" class="flex justify-between text-[10px] pl-2 border-l-2 border-amber-500/50 ml-1 mt-1 mb-1">
                                    <span class="text-amber-400/80">Di cui imprevisto</span>
                                    <span class="text-amber-400/80">{{ euro(totali.imponibile_sopravvenienza_cents) }}</span>
                                </div>
                            </Transition>

                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">IVA</span>
                                <span>{{ euro(totali.iva_cents) }}</span>
                            </div>

                            <div v-if="totali.ritenuta_cents > 0" class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-amber-400">Ritenuta d'Acconto</span>
                                <span class="text-amber-400">- {{ euro(totali.ritenuta_cents) }}</span>
                            </div>
                            <div v-else class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-slate-500 italic">Nessuna Ritenuta</span>
                                <span class="text-slate-500">€ 0,00</span>
                            </div>

                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Netto da pagare</span>
                                <span class="font-black text-2xl" :class="totali.netto_cents > 0 ? 'text-emerald-400' : 'text-white'">
                                    {{ euro(totali.netto_cents) }}
                                </span>
                            </div>
                        </div>

                        <Button type="button" :disabled="form.processing" @click="handleSubmit"
                            class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'">
                            <ShieldAlert v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5" />
                            <Save v-else class="w-5 h-5" />
                            {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Autorizza e Registra' : 'Registra Documento' }}
                        </Button>
                    </div>
                </div>

                <!-- ── Pannello destro ── -->
                <div class="lg:col-span-8 flex flex-col gap-5 relative z-0">

                    <!-- Vista pregressa -->
                    <div v-if="form.is_pregresso">
                        <WidgetDoubleLock
                            :form="form"
                            :fornitore-id="form.fornitore_id"
                            :debiti-patrimoniali="debiti_patrimoniali as any"
                            :fatture-pregresse-registrate="fatture_pregresse_registrate as any"
                            :conti-spesa="conti as any"
                            :fondi-riserva="fondi_riserva as any"
                            :capienza-rata-zero="capienza_rata_zero"
                            :incassato-rata-zero="incassato_rata_zero"
                            :totale-fattura-lordo-cents="totali.totale_documento_cents"
                            :bank-forecast="bankForecast" />
                    </div>

                    <!-- Vista corrente -->
                    <div v-else class="flex flex-col gap-5">

                        <!-- Registro voci -->
                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                            <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-between rounded-t-xl">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Registro voci di spesa</h3>
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1">Aggiungi una o più righe per ripartire il documento sui capitoli corretti.</p>
                                </div>
                                <div class="flex items-center gap-4">
                                    <Badge variant="secondary" class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-transparent">
                                        {{ form.righe.length }} {{ form.righe.length === 1 ? 'Voce' : 'Voci' }}
                                    </Badge>
                                    <Button variant="outline" size="sm" type="button" @click="addRiga"
                                        class="h-9 text-[11px] font-bold uppercase border-primary/20 text-primary hover:bg-primary/5 hover:text-primary transition-colors gap-1.5 shadow-sm">
                                        <Plus class="w-3.5 h-3.5" /> Aggiungi riga
                                    </Button>
                                </div>
                            </div>

                            <div class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                <div v-for="(riga, idx) in form.righe" :key="idx" class="p-6 hover:bg-slate-50/30 group transition-colors flex flex-col gap-4">
                                    <div class="grid grid-cols-12 gap-4">

                                        <!-- Capitolo di spesa -->
                                        <div class="col-span-12 md:col-span-8 relative">
                                            <div class="flex items-center justify-between mb-1.5 min-h-[28px]">
                                                <Label class="text-[10px] font-bold uppercase text-slate-400">Capitolo di spesa</Label>

                                                <button
                                                    type="button"
                                                    @click="riga.is_sopravvenienza = !riga.is_sopravvenienza; riga.is_sopravvenienza && (riga.conto_id = null)"
                                                    class="flex items-center gap-1.5 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider transition-all border outline-none focus-visible:ring-2 focus-visible:ring-amber-500/50"
                                                    :class="riga.is_sopravvenienza
                                                        ? 'bg-amber-50 border-amber-200 text-amber-600 shadow-sm dark:bg-amber-900/30 dark:border-amber-700/50 dark:text-amber-400'
                                                        : 'bg-transparent border-slate-200 text-slate-400 hover:bg-slate-50 hover:text-slate-500 dark:border-slate-700 dark:hover:bg-slate-800'">
                                                    <Zap class="w-3 h-3" :class="riga.is_sopravvenienza ? 'text-amber-500' : 'text-slate-400'" />
                                                    <span>{{ riga.is_sopravvenienza ? 'Imprevista (Attiva)' : 'Fuori Preventivo' }}</span>
                                                </button>
                                            </div>

                                            <v-select
                                                v-model="riga.conto_id"
                                                :options="conti"
                                                :disabled="riga.is_sopravvenienza"
                                                label="nome"
                                                :reduce="(c: Conto) => c.id"
                                                placeholder="Cerca capitolo..."
                                                class="style-chooser w-full"
                                                append-to-body>
                                                <template #option="{ nome, parent_nome, residuo_budget, is_capiente }">
                                                    <div class="flex items-center justify-between py-1.5 border-b border-transparent hover:border-slate-100 dark:hover:border-slate-800">
                                                        <div class="flex flex-col">
                                                            <span v-if="parent_nome" class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-semibold mb-0.5">
                                                                {{ parent_nome }}
                                                            </span>
                                                            <span class="font-medium text-sm text-slate-800 dark:text-slate-200" :class="{ 'text-rose-600 dark:text-rose-400': !is_capiente }">
                                                                {{ nome }}
                                                            </span>
                                                        </div>
                                                        <span class="text-[10px] px-2 py-1 rounded-md font-semibold whitespace-nowrap ml-4 border"
                                                            :class="is_capiente ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-900/50' : 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/30 dark:border-rose-900/50'">
                                                            {{ euro(residuo_budget) }}
                                                        </span>
                                                    </div>
                                                </template>
                                                <template #selected-option="{ nome, parent_nome, is_capiente }">
                                                    <div class="flex items-center gap-2 text-sm w-full overflow-hidden">
                                                        <span class="font-medium truncate" :class="{ 'text-rose-600 dark:text-rose-400': !is_capiente }">{{ nome }}</span>
                                                        <span class="text-xs text-slate-400 truncate" v-if="parent_nome">– {{ parent_nome }}</span>
                                                    </div>
                                                </template>
                                            </v-select>
                                            <!-- L'errore sotto il campo che lo causa, stesso stile del
                                                 resto del prodotto (InputError sulla scheda fornitore):
                                                 il banner in testa dice QUANTE righe mancano, questo dice
                                                 QUALE. -->
                                            <p v-if="form.errors[`righe.${idx}.conto_id`]" class="text-[11px] text-red-600 dark:text-red-500 font-medium mt-1">
                                                {{ form.errors[`righe.${idx}.conto_id`] }}
                                            </p>
                                        </div>

                                        <!-- Unità -->
                                        <div class="col-span-12 md:col-span-4 relative">
                                            <div class="flex items-center mb-1.5 min-h-[28px]">
                                                <Label class="text-[10px] font-bold uppercase text-slate-400">Unità (Opzionale)</Label>
                                            </div>
                                            <v-select
                                                v-model="riga.immobile_id"
                                                :options="immobili"
                                                label="label"
                                                :reduce="(i: Immobile) => i.id"
                                                placeholder="Tutti (Spesa Comune)"
                                                class="style-chooser text-xs"
                                                append-to-body>
                                                <template #option="{ label }">
                                                    <div class="flex items-center gap-1.5 py-0.5">
                                                        <User class="w-3 h-3 text-blue-400 shrink-0" />
                                                        <span class="text-xs">{{ label }}</span>
                                                    </div>
                                                </template>
                                            </v-select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-12 gap-4 items-start">
                                        <!-- Causale -->
                                        <div class="col-span-12 md:col-span-4 lg:col-span-4">
                                            <Input v-model="riga.descrizione"
                                                placeholder="Causale riga..."
                                                class="h-10 text-sm"
                                                :class="{ 'border-red-500 focus-visible:ring-red-500': form.errors[`righe.${idx}.descrizione`] }"
                                                @input="form.clearErrors(`righe.${idx}.descrizione`)" />
                                            <p v-if="form.errors[`righe.${idx}.descrizione`]" class="text-[11px] text-red-600 font-medium mt-1">
                                                {{ form.errors[`righe.${idx}.descrizione`] }}
                                            </p>
                                        </div>

                                        <!-- Importo -->
                                        <div class="col-span-4 md:col-span-3 relative">
                                            <MoneyInput
                                                :id="'importo_' + idx"
                                                v-model="riga.importo_imponibile"
                                                :money-options="moneyOptions"
                                                :lazy="false"
                                                placeholder="0,00" />
                                            <div v-if="rigaInSforo(riga)" class="flex items-center gap-1 mt-1 text-rose-500 absolute -bottom-5 right-0">
                                                <TrendingDown class="w-3 h-3" />
                                                <span class="text-[9px] font-black uppercase">Sforo budget</span>
                                            </div>
                                        </div>

                                        <!-- Aliquota IVA -->
                                        <div class="col-span-3 md:col-span-2 lg:col-span-2 relative">
                                            <div class="relative">
                                                <Input min="0" max="100" v-model="riga.aliquota_iva"
                                                    class="h-10 text-center pr-5 pl-1" />
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                            </div>
                                        </div>

                                        <!-- Totale riga + elimina -->
                                        <div class="col-span-5 md:col-span-3 lg:col-span-3 flex items-center justify-end gap-2 h-10">
                                            <div class="text-right min-w-0 flex-1">
                                                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block leading-none mb-1 whitespace-nowrap">Totale Riga</span>
                                                <span class="font-black text-base text-slate-800 dark:text-slate-200 block leading-none whitespace-nowrap tabular-nums">
                                                    {{ euro(lordoRigaCents(riga.importo_imponibile, riga.aliquota_iva)) }}
                                                </span>
                                            </div>
                                            <Button variant="ghost" size="icon" type="button" @click="removeRiga(idx)"
                                                :aria-label="`Togli la riga ${idx + 1}`"
                                                class="h-10 w-10 shrink-0 text-slate-300 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/30 opacity-0 group-hover:opacity-100 transition-all rounded-lg border border-transparent hover:border-rose-100 ml-1">
                                                <Trash2 class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <label v-if="fornitoreRitenutaAttiva && applicaRitenutaEffective" class="flex items-center gap-1.5 cursor-pointer select-none w-fit">
                                        <input type="checkbox" v-model="riga.concorre_base_ritenuta"
                                            class="w-3.5 h-3.5 text-amber-600 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                                        <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400">
                                            Concorre alla base ritenuta
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <!-- Footer registro -->
                            <div class="py-5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-xl flex flex-col sm:flex-row items-end sm:items-center justify-between px-6">
                                <div>
                                    <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-x-4" enter-to-class="opacity-100 translate-x-0">
                                        <div v-if="totali.ha_sopravvenienze" class="flex items-center gap-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 px-3 py-2 rounded-lg shadow-sm">
                                            <div class="bg-amber-100 dark:bg-amber-800/50 p-1 rounded-md">
                                                <Zap class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                                            </div>
                                            <div class="text-[11px] text-amber-800 dark:text-amber-300 leading-tight">
                                                Di cui <strong class="font-black text-amber-900 dark:text-amber-100">{{ euro(totali.imponibile_sopravvenienza_cents + totali.iva_sopravvenienza_cents) }}</strong><span class="opacity-80"> fuori preventivo</span>
                                            </div>
                                        </div>
                                    </Transition>
                                </div>

                                <div class="flex items-center gap-8 pr-2 mt-4 sm:mt-0">
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">Imponibile</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.imponibile_cents) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">IVA</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.iva_cents) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                    <div class="text-right">
                                        <span class="text-[10px] text-primary font-bold uppercase tracking-widest block mb-0.5">Totale Doc.</span>
                                        <span class="font-black text-primary text-xl">{{ euro(totali.totale_documento_cents) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Simulazione impatto finanziario -->
                        <div class="bg-slate-900 dark:bg-slate-950 text-white rounded-xl border shadow-lg overflow-hidden transition-all duration-300"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'border-rose-500 shadow-rose-500/10' : transactionStatus === 'WARNING_CASH' ? 'border-amber-500/30' : 'border-slate-700'">

                            <div class="px-6 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-800/40">
                                <div class="flex items-center gap-2">
                                    <Zap class="w-4 h-4 text-blue-400" :class="transactionStatus === 'CRITICAL_BUDGET' ? 'text-rose-400 animate-pulse' : ''" />
                                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Simulazione Impatto Finanziario</span>
                                </div>
                                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase"
                                    :class="{
                                        'bg-rose-500/20 text-rose-400':      transactionStatus === 'CRITICAL_BUDGET',
                                        'bg-amber-500/20 text-amber-400':    transactionStatus === 'WARNING_CASH',
                                        'bg-emerald-500/20 text-emerald-400': transactionStatus === 'SAFE',
                                    }">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1"
                                        :class="{
                                            'bg-rose-500 animate-pulse':  transactionStatus === 'CRITICAL_BUDGET',
                                            'bg-amber-500 animate-pulse': transactionStatus === 'WARNING_CASH',
                                            'bg-emerald-500':             transactionStatus === 'SAFE',
                                        }"></span>
                                    {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Sforo Budget' : transactionStatus === 'WARNING_CASH' ? 'Attenzione Cassa' : 'Tutto OK' }}
                                </div>
                            </div>

                            <div class="grid grid-cols-2 divide-x divide-slate-700/50">
                                <!-- Analisi budget -->
                                <div class="p-5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Analisi Budget — Capitoli</p>
                                    <div v-if="budgetImpacts.length === 0" class="py-6 text-center text-slate-600 text-xs">Nessuna voce ancora</div>
                                    <div v-else class="space-y-3">
                                        <div v-for="impact in budgetImpacts" :key="impact.id" class="space-y-1.5 bg-slate-800/20 rounded-lg p-2.5 border border-slate-700/50">
                                            <div class="flex justify-between items-start">
                                                <span class="text-xs font-bold truncate max-w-[60%]">{{ impact.nome }}</span>
                                                <span class="text-xs font-black shrink-0" :class="impact.isOk ? 'text-emerald-400' : 'text-rose-400'">
                                                    {{ impact.isOk ? '+' : '' }}{{ euro(impact.delta_cents) }}
                                                </span>
                                            </div>

                                            <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full transition-all duration-500"
                                                    :class="impact.isOk ? 'bg-emerald-500' : 'bg-rose-500'"
                                                    :style="{ width: Math.min((impact.speso_cents / Math.max(impact.residuo_cents, 1)) * 100, 100) + '%' }">
                                                </div>
                                            </div>

                                            <div class="flex justify-between text-[9px] text-slate-500 font-medium">
                                                <span>Usato: {{ euro(impact.speso_cents) }}</span>
                                                <span>Budget: {{ euro(impact.residuo_cents) }}</span>
                                            </div>

                                            <div v-if="impact.ultimi_movimenti && impact.ultimi_movimenti.length > 0" class="mt-2 pt-2 border-t border-slate-700/50">
                                                <button type="button" @click="toggleHistory(impact.id)" class="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-wider text-slate-400 hover:text-blue-400 transition-colors w-full">
                                                    <History class="w-3 h-3" />
                                                    <span>{{ impact.ultimi_movimenti.length }} Moviment{{ impact.ultimi_movimenti.length > 1 ? 'i' : 'o' }} recent{{ impact.ultimi_movimenti.length > 1 ? 'i' : 'e' }}</span>
                                                    <ChevronDown class="w-3 h-3 ml-auto transition-transform" :class="expandedHistory[impact.id] ? 'rotate-180' : ''" />
                                                </button>

                                                <div v-if="expandedHistory[impact.id]" class="mt-2 space-y-1.5">
                                                    <div v-for="(storico, sIdx) in impact.ultimi_movimenti" :key="sIdx"
                                                        class="flex items-center justify-between bg-slate-900/50 p-1.5 rounded text-[10px] border border-slate-800">
                                                        <div class="flex flex-col truncate pr-2">
                                                            <span class="text-slate-300 font-semibold truncate">{{ storico.fornitore }}</span>
                                                            <div class="flex items-center gap-1.5">
                                                                <span class="text-slate-500">{{ storico.data }} · {{ storico.documento }}</span>
                                                                <span v-if="storico.is_pregresso" class="text-[8px] font-black uppercase tracking-wider bg-amber-500/20 text-amber-400 px-1.5 py-0.5 rounded">Pregresso</span>
                                                            </div>
                                                        </div>
                                                        <span class="font-bold text-slate-400 shrink-0">{{ euro(storico.importo) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Previsione cassa -->
                                <div class="p-5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Previsione cassa</p>
                                    <div v-if="bankForecast" class="space-y-3">
                                        <div class="space-y-2">
                                            <div class="flex justify-between text-xs">
                                                <span class="text-slate-400">Saldo attuale</span>
                                                <span class="text-white">{{ euro(bankForecast.attuale_cents) }}</span>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-slate-400">Uscita prevista</span>
                                                <span class="text-rose-400">- {{ euro(totali.netto_cents) }}</span>
                                            </div>
                                        </div>
                                        <div class="pt-3 border-t border-slate-700 space-y-1">
                                            <p class="text-[9px] text-slate-500 uppercase font-bold">Saldo post-pagamento</p>
                                            <p class="font-black text-2xl" :class="bankForecast.isRed ? 'text-rose-500' : 'text-emerald-400'">
                                                {{ euro(bankForecast.post_cents) }}
                                            </p>
                                            <div class="h-1.5 bg-white/10 rounded-full overflow-hidden mt-2">
                                                <div class="h-full rounded-full transition-all"
                                                    :class="bankForecast.isRed ? 'bg-rose-500' : 'bg-emerald-500'"
                                                    :style="{ width: Math.min(Math.max((bankForecast.post_cents / Math.max(bankForecast.attuale_cents, 1)) * 100, 0), 100) + '%' }">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="py-6 text-center text-slate-600 text-xs">
                                        Seleziona un conto nel pannello sinistro
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <!-- Modali -->
        <ModalOverrideBudget
            v-model:show="showOverrideModal"
            :sforo-totale="sforoBudgetTotaleCents"
            :voci-in-sforo="vociInSforo"
            :has-spese-private="hasSpesePrivate"
            :fondi-riserva="fondi_riserva"
            :is-processing="form.processing"
            @confirm="handleOverrideConfirm"
        />

        <ModalSpesaImprevista
            v-model:show="showSpesaImprevistaModal"
            :mode="spesaImprevistaMode"
            :condominio-id="props.condominio.id"
            :fornitore-nome="selectedFornitore?.ragione_sociale || 'Fornitore'"
            :fondi-riserva="fondi_riserva"
            :importo-imprevisto="spesaImprevistaMode === 'corrente'
                ? totali.imponibile_sopravvenienza_cents + totali.iva_sopravvenienza_cents
                : eccedenzaPregressaCents"
            @confirm="handleSpesaImprevistaConfirm"
        />

        <!-- ⚠️ Il file è la fonte autorevole del documento, quindi vince: quello che si
             protegge non è il contenuto ma **il lavoro di chi stava scrivendo**, che
             prima spariva senza un avviso (Fase 1-bis, reperto 6). Non compare quando
             si passa da un documento all'altro dello stesso lotto: là il modulo è pieno
             di roba arrivata da un file, e chiedere sarebbe solo un clic in più. -->
        <!-- ⚠️ `model-value` è l'interruttore, **non** `fileDaConfermare !== null`: la
             chiusura non deve toccare il dato che la conferma sta per leggere. Vedi il
             commento su `fileDaConfermare` e `useConfermaEliminazione.ts`. -->
        <ConfirmDialog
            :model-value="confermaSovrascritturaAperta"
            title="Il modulo non è vuoto"
            confirm-text="Sostituisci con il file"
            cancel-text="Lascia com'è"
            variant="warning"
            @update:model-value="(v: boolean) => { if (!v) confermaSovrascritturaAperta = false; }"
            @confirm="confermaSovrascrittura"
            @cancel="annullaSovrascrittura"
        >
            Leggendo <strong>{{ fileDaConfermare?.file.name }}</strong> devo sostituire quello che hai già scritto
            in questo modulo — righe, importi e dati del documento. Quello che hai compilato a mano andrà perso.
        </ConfirmDialog>

        <ModalImportaXml
            v-model:show="showImportaXmlModal"
            :files="filesPendenti"
            :condominio-senza-codice-fiscale="props.condominio_senza_codice_fiscale"
            :url-anagrafica-condominio="route('condomini.edit', { id: props.condominio.id })"
            @aggiungi="gestisciFileMultipli"
            @rimuovi="rimuoviFilePendente"
            @seleziona="selezionaFilePendente"
        />

        <ModalCreaFornitoreDaXml
            v-model:show="showCreaFornitoreModal"
            :letto-da-xml="esitoFornitoreXml?.letto_da_xml ?? null"
            :documento="{ modalita_pagamento: form.modalita_pagamento, data_documento: form.data_documento, data_scadenza: form.data_scadenza }"
            :ritenuta="ritenutaLettaDaXml"
            :base-imponibile-cents="baseRitenutaGrezzaCents"
            @creato="gestisciFornitoreCreato"
        />

        <!-- Modale di successo — restyle in bianco/nero (03/09/2026), stesso registro
             delle modali di conferma del prodotto: titolo a sinistra, nessuna icona
             di stato ingombrante, si chiude solo dai due pulsanti (nessuna X: qui
             uscire senza scegliere fra «torna all'elenco» e «registra un'altra» non
             ha un significato chiaro). Quando il documento appena registrato veniva
             da un lotto XML con altri file ancora da fare, la modale li elenca
             invece di chiudersi e basta: è la risposta a «carico due documenti, ne
             registro uno, dove trovo l'altro?» — non lo si cerca, riappare qui, una
             riga per documento, cliccabile. -->
        <Teleport to="body">
            <div v-if="showSuccessModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full overflow-hidden border border-slate-200 dark:border-slate-800"
                    :class="filesInCodaPronti.length > 0 ? 'max-w-lg' : 'max-w-md'">

                    <div class="p-8" :class="filesInCodaPronti.length > 0 ? 'pb-5' : ''">
                        <h3 class="font-black text-slate-900 dark:text-slate-100 text-2xl">
                            {{ filesInCodaPronti.length > 0 ? 'Fattura registrata' : 'Operazione completata' }}
                        </h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mt-3">
                            <template v-if="filesInCodaPronti.length > 0">
                                Il documento e le coperture contabili sono a posto. Resta{{ filesInCodaPronti.length > 1 ? 'no' : '' }}
                                {{ filesInCodaPronti.length }} {{ filesInCodaPronti.length > 1 ? 'documenti' : 'documento' }} da rivedere in questo lotto.
                            </template>
                            <template v-else>
                                Il documento e le coperture contabili sono stati registrati e bilanciati correttamente.
                            </template>
                        </p>
                    </div>

                    <!-- Lotto: i documenti ancora da registrare — riga cliccabile per intero,
                         nessun cestino qui: si rimuove tornando alla lista di triage. -->
                    <div v-if="filesInCodaPronti.length > 0" class="px-8 pb-6 space-y-2 max-h-64 overflow-y-auto">
                        <button v-for="voce in filesInCodaPronti" :key="voce.file.name + voce.file.size"
                            type="button"
                            class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors text-left"
                            @click="continuaConProssimo(voce)">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-100 truncate">
                                    {{ voce.esito!.fornitore.letto_da_xml.denominazione }}
                                </p>
                                <p class="text-xs text-slate-400 truncate">
                                    n. {{ voce.esito!.documento.numero_documento }} · {{ euro(totaleLordoStimatoCents(voce.esito!)) }}
                                </p>
                            </div>
                            <ChevronRight class="w-4 h-4 text-slate-300 shrink-0" />
                        </button>
                    </div>

                    <div class="px-8 pb-8 flex justify-end" :class="filesInCodaPronti.length > 0 ? 'pt-1' : ''">
                        <div class="flex flex-col-reverse sm:flex-row sm:gap-3 gap-2">
                            <Button
                                variant="outline"
                                @click="router.visit(route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }))"
                                class="h-11 px-6 rounded-xl font-bold">
                                Torna all'elenco
                            </Button>

                            <!--
                                Solo quando non resta nulla del lotto: se c'è ancora un
                                documento da rivedere la CTA è una riga qui sopra, un secondo
                                bottone "registrane un'altra" accanto sarebbe ambiguo su quale
                                dei due percorsi imbocca.
                            -->
                            <Button
                                v-if="filesInCodaPronti.length === 0"
                                @click="() => { resettaFormPerNuovoDocumento(); showSuccessModal = false; }"
                                class="h-11 px-6 rounded-xl font-bold">
                                Registra un'altra
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <FatturaRegistrazioneGuide v-model:open="showGuideCompleta" />

    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>

<style>
.vs__dropdown-menu {
    z-index: 9999 !important;
    position: absolute !important;
    background: white !important;
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1) !important;
}

.dark .vs__dropdown-menu {
    background: #1e293b !important;
    border: 1px solid #334155;
}

.lg\:col-span-8 {
    position: relative;
    z-index: 1;
}
</style>

<!-- `scoped` e' deliberato: `.style-chooser` e' usata anche da altre pagine, e un
     blocco non incapsulato verrebbe iniettato globalmente non appena questo
     componente viene importato, andando a toccarle. -->
<style scoped>
/* Le etichette dei capitoli qui sono lunghe (fornitore + descrizione dell'intervento)
   e sfondavano il bordo del campo, finendo sotto le icone di cancellazione e apertura.
   `min-width: 0` e' la chiave: senza, un figlio flex non puo' rimpicciolirsi sotto la
   propria larghezza naturale e `text-overflow: ellipsis` non ha alcun effetto.
   Nessuna metrica viene toccata: altezza, raggio e spaziature restano quelle di
   vue-select, identiche ai menu della colonna di sinistra. */
:deep(.style-chooser .vs__selected-options) {
    min-width: 0;
    overflow: hidden;
    flex-wrap: nowrap;
}

:deep(.style-chooser .vs__selected) {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Le icone non devono mai finire coperte dall'etichetta. */
:deep(.style-chooser .vs__actions) {
    flex: 0 0 auto;
}
</style>
