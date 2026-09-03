<script setup lang="ts">
/**
 * Il lettore dei file XML ricevuti dallo SdI, in una modale sopra il modulo di
 * registrazione.
 *
 * ## Perché una modale e non una pagina
 *
 * Fino al 03/09/2026 questo era una **fase** della pagina di registrazione
 * (`faseAttiva === 'scelta'`): «Importa XML» apriva la stessa rotta con la dropzone
 * al posto del modulo. Funzionava, ma aveva una porta a senso unico — dal modulo non
 * si tornava indietro. Chi cliccava «Nuova fattura», cominciava a compilare e poi si
 * ricordava di avere l'XML doveva uscire dalla pagina e rientrare dall'altro pulsante,
 * perdendo quello che aveva scritto. Provato sul codice: `faseAttiva` veniva messo a
 * `'revisione'` in due punti e non tornava mai a `'scelta'`.
 *
 * In una modale il lettore è raggiungibile **sempre**, e resta una sola pagina di
 * registrazione fattura: l'XML è un modo per riempirla, non una pagina parallela.
 * Decisione presa con Vincenzo il 03/09/2026 — sua l'intuizione: «avevo pensato alla
 * pagina perché avevo capito che facevamo una cosa in stile importatore Danea, ma la
 * struttura di validazione già l'avevamo e funzionava bene».
 *
 * ## Lo stato dei file NON vive qui
 *
 * `filesPendenti` resta nella pagina e arriva come prop. Non è pigrizia: la **modale
 * di successo**, dopo aver registrato un documento del lotto, elenca quelli che
 * restano — se la coda vivesse qui dentro, si svuoterebbe alla chiusura e quella
 * modale non avrebbe più niente da mostrare. Qui c'è solo la presentazione; chi legge
 * i file, chi li toglie e chi li sceglie è la pagina.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { UploadCloud, LoaderCircle, CheckCircle, AlertOctagon, Trash2, X, Info, TriangleAlert } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import type { EsitoImportazioneXml } from '@/composables/useImportaFatturaXml';

export interface FilePendente {
    file: File;
    stato: 'in_corso' | 'pronto' | 'errore';
    esito: EsitoImportazioneXml | null;
    erroreMessaggio: string | null;
}

const props = defineProps<{
    show: boolean;
    files: FilePendente[];
    /**
     * Il condominio non ha codice fiscale, quindi il controllo «questa fattura è di
     * questo palazzo?» non può girare. Vedi l'avviso nel piede.
     */
    condominioSenzaCodiceFiscale?: boolean;
    /** Dove si rimedia, in un clic. */
    urlAnagraficaCondominio?: string;
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'aggiungi', files: FileList | File[]): void;
    (e: 'rimuovi', voce: FilePendente): void;
    (e: 'seleziona', voce: FilePendente): void;
}>();

const { euro } = useCurrencyFormatter();

const trascinamentoAttivo = ref(false);
const inputFile = ref<HTMLInputElement | null>(null);

function suDrop(e: DragEvent) {
    trascinamentoAttivo.value = false;
    if (e.dataTransfer?.files?.length) emit('aggiungi', e.dataTransfer.files);
}

function suSelezione(e: Event) {
    const files = (e.target as HTMLInputElement).files;
    if (files?.length) emit('aggiungi', files);
    // Permette di ricaricare lo stesso file due volte di fila: senza, il secondo
    // `change` non scatta perché il valore dell'input non è cambiato.
    (e.target as HTMLInputElement).value = '';
}

/**
 * ⚠️ **Esc chiude la modale, ed è arrivato insieme alla rimozione del pulsante
 * «Chiudi»** (03/09/2026, su proposta di Vincenzo: «tanto c'è la x in alto a destra,
 * diamo più importanza alla nota informativa»). Togliere il pulsante è giusto — qui non
 * c'è niente da confermare, si carica e si sceglie — ma lasciava **una sola** via
 * d'uscita, una X piccola in un angolo. Con Esc tornano a essere due, e una è quella
 * che chiunque prova per prima.
 */
function suTasto(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.show) emit('update:show', false);
}

watch(() => props.show, (aperta) => {
    if (aperta) window.addEventListener('keydown', suTasto);
    else window.removeEventListener('keydown', suTasto);
}, { immediate: true });

// Se il componente sparisce mentre la modale è aperta, l'ascoltatore resterebbe
// appeso a `window` per il resto della sessione.
onBeforeUnmount(() => window.removeEventListener('keydown', suTasto));

/**
 * C'è almeno un documento il cui fornitore non è ancora in anagrafica.
 *
 * ⚠️ Serve a una **riga sempre visibile**, non a un tooltip: la regola è già scritta in
 * casa (FatturaShow.vue, card Allegati) — «una riga sola sopra l'elenco, sempre
 * visibile: non un tooltip che si vede solo passandoci sopra col mouse, e mai su un
 * touch screen». «Fornitore da creare» da solo sembra un ostacolo; senza dire dove si
 * crea, l'amministratore esce dal flusso per andarlo a cercare in anagrafica.
 */
const cSonoFornitoriDaCreare = computed(() =>
    props.files.some((v) => v.stato === 'pronto' && v.esito?.fornitore.esito === 'non_trovato'),
);

/**
 * Stima grezza dell'importo, per orientarsi nell'elenco — non è il totale che l'XML
 * dichiara (l'endpoint non lo espone: solo l'imponibile di riga passa il confine
 * centesimi/euro), è imponibile + IVA sommati riga per riga.
 *
 * ⚠️ `euro()` si aspetta CENTESIMI (`fromCents: true` di default) e le righe arrivano
 * già in euro: la conversione va fatta qui. Senza, mostrava «€ 4,88» al posto di
 * «€ 488,00» — trovato dal vivo, non da un test, perché il caso a file singolo salta
 * l'elenco e va dritto al modulo.
 */
function totaleLordoStimatoCents(esito: EsitoImportazioneXml): number {
    const euroTotali = esito.righe.reduce((s, r) => s + r.importo_imponibile * (1 + r.aliquota_iva / 100), 0);
    return Math.round(euroTotali * 100);
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col max-h-[90vh] overflow-hidden border border-slate-200 dark:border-slate-800">

                <div class="p-6 pb-5 shrink-0">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-black text-slate-900 dark:text-slate-100 text-xl">Importa le fatture XML</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                                Leggo numero, data, importi e fornitore dal file: non c'è nulla da ricopiare a mano.
                            </p>
                        </div>
                        <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                            @click="emit('update:show', false)">
                            <X class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <div class="px-6 pb-6 space-y-4 overflow-y-auto flex-1">
                    <div
                        class="border-2 border-dashed rounded-2xl p-8 text-center transition-colors cursor-pointer"
                        :class="trascinamentoAttivo
                            ? 'border-primary bg-primary/5'
                            : 'border-slate-300 dark:border-slate-700 hover:border-primary/50 hover:bg-slate-50 dark:hover:bg-slate-800/50'"
                        @click="inputFile?.click()"
                        @dragover.prevent="trascinamentoAttivo = true"
                        @dragleave.prevent="trascinamentoAttivo = false"
                        @drop.prevent="suDrop"
                    >
                        <UploadCloud class="w-9 h-9 text-primary mx-auto mb-3" />
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-200">Trascina qui i file, o scegli dal computer</p>
                        <p class="text-xs text-slate-400 mt-1.5 max-w-md mx-auto">
                            XML e buste firmate .p7m ricevute dallo SdI. Anche molti insieme: li leggo tutti, poi decidi tu cosa registrare.
                        </p>
                        <input ref="inputFile" type="file" multiple class="hidden" accept=".xml,.p7m" @change="suSelezione" />
                    </div>

                    <div v-if="files.length > 0" class="space-y-2">
                        <div v-for="voce in files" :key="voce.file.name + voce.file.size"
                            class="flex items-center gap-3 px-4 py-3 rounded-xl border"
                            :class="voce.stato === 'errore'
                                ? 'border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20'
                                : 'border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50'">
                            <LoaderCircle v-if="voce.stato === 'in_corso'" class="w-4 h-4 text-primary animate-spin shrink-0" />
                            <AlertOctagon v-else-if="voce.stato === 'errore'" class="w-4 h-4 text-rose-500 shrink-0" />
                            <CheckCircle v-else class="w-4 h-4 text-emerald-500 shrink-0" />

                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-200 truncate">
                                    <template v-if="voce.stato === 'pronto' && voce.esito">
                                        {{ voce.esito.fornitore.letto_da_xml.denominazione }} — n. {{ voce.esito.documento.numero_documento }}
                                    </template>
                                    <template v-else>{{ voce.file.name }}</template>
                                </p>
                                <p class="text-[11px] text-slate-400 truncate">
                                    <template v-if="voce.stato === 'in_corso'">Leggo il file...</template>
                                    <template v-else-if="voce.stato === 'errore'">{{ voce.erroreMessaggio }}</template>
                                    <template v-else-if="voce.esito">
                                        {{ voce.esito.documento.data_documento }} ·
                                        {{ euro(totaleLordoStimatoCents(voce.esito)) }} ·
                                        <span v-if="voce.esito.fornitore.esito === 'trovato'" class="text-emerald-600 dark:text-emerald-400">fornitore riconosciuto</span>
                                        <span v-else-if="voce.esito.fornitore.esito === 'ambiguo'" class="text-amber-600 dark:text-amber-400">più fornitori possibili</span>
                                        <span v-else class="text-slate-500">fornitore da creare</span>
                                    </template>
                                </p>
                            </div>

                            <Button v-if="voce.stato === 'pronto'" size="sm" class="shrink-0 h-8 text-xs" @click="emit('seleziona', voce)">
                                Rivedi e registra
                            </Button>
                            <button type="button" class="shrink-0 text-slate-300 hover:text-slate-500 p-1"
                                :aria-label="`Togli ${voce.file.name} dall'elenco`"
                                @click="emit('rimuovi', voce)">
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ⚠️ Il piede porta **solo informazione**, e c'è solo quando ha
                     qualcosa da dire.
                     Il pulsante «Chiudi» è stato tolto il 03/09/2026 su proposta di
                     Vincenzo — «tanto c'è la x in alto a destra, diamo più importanza
                     alla nota informativa»: questa non è una modale di decisione, non
                     c'è niente da confermare, si carica e si sceglie. Un pulsante che
                     fa la stessa cosa della X competeva con la nota per l'attenzione.
                     In cambio la modale risponde a Esc (vedi `suTasto`), così le vie
                     d'uscita restano due.
                     ⚠️ E il messaggio sta QUI, fuori dall'area che scorre: dentro
                     finiva in fondo all'elenco, cioè leggibile solo scorrendo fino in
                     fondo — proprio quando non serve più. Provato a video con 16 voci
                     su domanda di Vincenzo: «hai fatto la prova con 4 o 5 file per
                     capire se l'informazione rimane visibile?». No, e non rimaneva. -->
                <div v-if="condominioSenzaCodiceFiscale || cSonoFornitoriDaCreare"
                    class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 shrink-0 space-y-2">
                    <p v-if="condominioSenzaCodiceFiscale" class="flex items-start gap-2 text-[11px] text-amber-700 dark:text-amber-400">
                        <TriangleAlert class="w-3.5 h-3.5 shrink-0 mt-px" />
                        <span>
                            <strong class="font-semibold">Non posso controllare che le fatture siano di questo condominio</strong>:
                            manca il suo codice fiscale, che è il dato con cui lo confronto con l'intestatario del file.
                            <a v-if="urlAnagraficaCondominio" :href="urlAnagraficaCondominio" class="underline underline-offset-2 font-semibold hover:text-amber-900 dark:hover:text-amber-300">Compilalo nell'anagrafica</a><span v-else>Compilalo nell'anagrafica del condominio</span>
                            e il controllo riparte da solo.
                        </span>
                    </p>

                    <p v-if="cSonoFornitoriDaCreare" class="flex items-start gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                        <Info class="w-3.5 h-3.5 shrink-0 mt-px text-slate-400" />
                        <span>
                            <strong class="font-semibold text-slate-600 dark:text-slate-300">«Fornitore da creare» non ferma niente</strong>:
                            lo crei dal modulo di registrazione con i dati già letti dal file, senza ricaricarlo.
                            Se preferisci averlo pronto prima, puoi crearlo da Fornitori e poi tornare qui.
                        </span>
                    </p>
                </div>

            </div>
        </div>
    </Teleport>
</template>
