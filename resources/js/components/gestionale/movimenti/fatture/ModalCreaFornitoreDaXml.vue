<script setup lang="ts">
/**
 * Crea il fornitore letto da un XML **senza lasciare la pagina** di registrazione
 * della fattura — deciso aprendo la riprogettazione della UI di importazione XML
 * (02/09/2026, docs/lettura_xml_fatture_passive.md): prima, quando l'XML dichiarava
 * un fornitore non ancora in anagrafica, l'amministratore doveva uscire dal form,
 * andare a creare la scheda, e tornare per ricaricare il file da capo.
 *
 * ⚠️ **Non è un secondo endpoint di creazione.** Chiama la STESSA rotta
 * `admin.fornitori.store` che usa la scheda a pagina intera
 * (`FornitoreController::store()`, che negozia sull'Accept e risponde con JSON invece
 * del redirect quando la chiamata lo chiede) — stessa validazione, stessa
 * `Fornitore::create()`, stessi effetti collaterali. Due porte sulla stessa parete con
 * regole diverse è esattamente il difetto che quel controller cita nel proprio
 * docblock per la beta.6: non lo si ripete qui.
 *
 * Tutti i campi arrivano precompilati da ciò che il file dichiara, e restano
 * modificabili — coerente con «tutti i campi comunque modificabili manualmente»
 * (decisione 2 dell'apertura della beta.14).
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Building2, LoaderCircle, X } from 'lucide-vue-next';
import vSelect from 'vue-select';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

interface LettoDaXml {
    denominazione: string;
    partita_iva: string | null;
    partita_iva_paese: string | null;
    codice_fiscale: string | null;
    indirizzo: string | null;
    cap: string | null;
    comune: string | null;
    provincia: string | null;
    nazione: string | null;
    email: string | null;
    regime_forfetario: boolean;
}

import { proponiNaturaDaRitenuta, proponiRegimeDaRitenuta, type RitenutaDaXml } from '@/lib/gestionale/fatture/confrontoRitenuta';

const props = defineProps<{
    show: boolean;
    lettoDaXml: LettoDaXml | null;
    // Per proporre un default sensato di modalità/giorni di scadenza — mai per
    // scriverli a occhi chiusi, l'amministratore li vede e può cambiarli prima di salvare.
    documento: { modalita_pagamento: string | null; data_documento: string; data_scadenza: string | null };
    /**
     * La ritenuta d'acconto che il file dichiara, e la somma delle righe che concorrono
     * alla base — servono a **proporre** il regime, non a deciderlo.
     *
     * ⚠️ Prima della beta.14 questo modale non mandava nulla sulla ritenuta, quindi il
     * fornitore nasceva con `soggetto_ritenuta = false` e la ritenuta dichiarata dal file
     * si perdeva: il condominio pagava tutto al fornitore e non versava niente all'Erario
     * (Fase 1-bis, reperto 12). Chi passava dalla scheda fornitore a pagina intera quella
     * casella la vedeva; questa porta, più veloce, toglieva proprio il passaggio che
     * salvava il dato.
     */
    ritenuta: RitenutaDaXml | null;
    baseImponibileCents: number;
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'creato', fornitore: {
        id: number;
        ragione_sociale: string;
        soggetto_ritenuta: boolean;
        tipo_ritenuta: string | null;
        regime_forfetario: boolean;
    }): void;
}>();

/**
 * I due soli regimi che il file può determinare da sé — vedi `proponiRegimeDaRitenuta`.
 * Non è l'elenco completo di `TipoRitenuta`: provvigioni, non residenti e lavoro dipendente
 * non si ricavano da un'aliquota, e offrirli qui darebbe l'impressione che il file li
 * dichiari. Chi ha bisogno di uno di quelli passa dalla scheda fornitore, che li ha tutti.
 */
/**
 * Le quattro nature del percipiente. Governano **solo** la scelta fra 1019 e 1020:
 * l'aliquota dipende dal regime, non da qui (design F24 §2.2).
 *
 * ⚠️ Sono tutte e quattro, non solo le due proponibili dal file: chi si accorge che il
 * fornitore è una società di persone o un ente non commerciale deve poterlo dire subito,
 * senza uscire dal modale — è il punto in cui ha in mano la fattura e lo sa.
 */
const NATURE_PERCIPIENTE = [
    { label: 'Persona fisica / ditta individuale (IRPEF) — 1019', value: 'persona_fisica_irpef' },
    { label: 'Società di persone (IRPEF) — 1019', value: 'societa_persone_irpef' },
    { label: 'Società di capitali / soggetto IRES — 1020', value: 'soggetto_ires' },
    { label: 'Ente non commerciale — 1019', value: 'ente_non_commerciale' },
];

const REGIMI_PROPONIBILI = [
    { label: 'Appalto di opere/servizi — 4% (art. 25-ter)', value: 'appalto_4' },
    { label: 'Lavoro autonomo — 20% (art. 25)', value: 'lavoro_autonomo_20' },
];

const MODALITA_OPZIONI = [
    { label: 'Bonifico bancario', value: 'bonifico' },
    { label: 'MAV', value: 'mav' },
    { label: 'Ri.Ba.', value: 'ri.ba' },
    { label: 'Contanti', value: 'contanti' },
];

// ⚠️ 'assegno' esiste come modalità di UNA fattura ma non è fra i quattro valori
// ammessi per il default dell'anagrafica (CreateFornitoreRequest::rules()): un
// fornitore pagato una volta per assegno non ha per questo un "assegno" come default
// abituale, e scriverlo sarebbe inventare un valore che la validazione rifiuterebbe.
function modalitaDefaultDa(modalitaFattura: string | null): string {
    return ['bonifico', 'mav', 'ri.ba', 'contanti'].includes(modalitaFattura ?? '') ? modalitaFattura! : 'bonifico';
}

function giorniScadenzaDa(dataDocumento: string, dataScadenza: string | null): number {
    if (!dataScadenza) return 30;
    const giorni = Math.round((new Date(dataScadenza).getTime() - new Date(dataDocumento).getTime()) / 86_400_000);
    return giorni > 0 ? giorni : 30;
}

function statoIniziale() {
    // Stringa vuota, non null: è la convenzione già in uso in questo componente
    // (FornitoriNew.vue) e la sola forma che accetta il componente Input
    // (modelValue?: string | number — niente null). CreateFornitoreRequest valida
    // questi campi `nullable|string`, quindi una stringa vuota resta un valore
    // ammesso; la si manda così com'è, senza reinventare una conversione qui.
    return {
        ragione_sociale: '',
        partita_iva: '',
        codice_fiscale: '',
        indirizzo: '',
        cap: '',
        comune: '',
        provincia: '',
        nazione: '',
        email: '',
        modalita_pagamento_default: 'bonifico',
        giorni_scadenza: 30,
        regime_forfetario: false,
        // Il file dichiara SE il fornitore è forfetario (RegimeFiscale RF19), mai DA
        // QUANDO: sono due fatti diversi, e il secondo non è deducibile dal documento.
        // Restano vuoti anche quando regime_forfetario arriva già spuntato dal file —
        // vedi CreateFornitoreRequest::rules(), stesso obbligo del form standard
        // (FornitoriNew.vue) di cui questo modale non è una seconda porta.
        forfetario_dichiarato_il: '',
        forfetario_riferimento: '',
        // ⚠️ **La spunta e il regime viaggiano insieme, sempre.** `CreateFornitoreRequest`
        // rende `perc_ritenuta` obbligatorio quando `soggetto_ritenuta` è vero e
        // `tipo_ritenuta` è vuoto: una spunta senza regime verrebbe rifiutata dal server, e
        // nell'anteprima della fattura la trattenuta risulterebbe € 0,00 in silenzio.
        soggetto_ritenuta: false,
        tipo_ritenuta: '',
        // ⚠️ **Decide il codice tributo dell'F24: 1019 (IRPEF) o 1020 (IRES), e nient'altro.**
        // Lasciarlo vuoto non è neutro: a valle `GeneraDelegheF24Action` ripiega in silenzio
        // su persona fisica e stampa 1019 anche su una società, mandando il denaro
        // all'Erario sotto un codice che non è il suo. Il file lo dice — `RT01` persona
        // fisica, `RT02` no — e va quindi proposto invece di lasciato indovinare a valle.
        natura_percipiente: '',
    };
}

const data = ref(statoIniziale());
const salvando = ref(false);
const erroreGenerale = ref<string | null>(null);
const erroriCampo = ref<Record<string, string[]>>({});

/**
 * Il fornitore letto da un cedente ESTERO (partita_iva_paese diverso da IT) non è
 * stato agganciato per costruzione (RicercaFornitoreXml, beta.14): lo si segnala qui,
 * perché chi crea la scheda sappia che la partita IVA che sta per scrivere non è nel
 * formato italiano che il resto dell'anagrafica assume.
 */
const fornitoreEstero = ref(false);

/**
 * Che regime proporre, leggendo la ritenuta dichiarata dal file.
 *
 * La logica sta in `lib/gestionale/fatture/confrontoRitenuta.ts` ed è provata lì, sui
 * numeri veri delle cinque fatture del collaudo: l'aliquota non si crede sulla parola, si
 * fa tornare con `ImportoRitenuta ÷ base`.
 */
const proposta = computed(() => proponiRegimeDaRitenuta(props.ritenuta, props.baseImponibileCents));

/** La natura è stata proposta dal file, o l'amministratore la sta scegliendo da sé? */
const naturaProposta = computed(() => proponiNaturaDaRitenuta(props.ritenuta) !== null);

watch(() => props.show, (aperto) => {
    if (!aperto) return;

    erroreGenerale.value = null;
    erroriCampo.value = {};

    const letto = props.lettoDaXml;
    fornitoreEstero.value = !!letto?.partita_iva_paese && letto.partita_iva_paese !== 'IT';

    data.value = {
        ...statoIniziale(),
        ragione_sociale: letto?.denominazione ?? '',
        partita_iva: fornitoreEstero.value ? '' : letto?.partita_iva ?? '', // vedi nota sotto
        codice_fiscale: letto?.codice_fiscale ?? '',
        indirizzo: letto?.indirizzo ?? '',
        cap: letto?.cap ?? '',
        comune: letto?.comune ?? '',
        provincia: letto?.provincia ?? '',
        nazione: letto?.nazione ?? '',
        email: letto?.email ?? '',
        modalita_pagamento_default: modalitaDefaultDa(props.documento.modalita_pagamento),
        giorni_scadenza: giorniScadenzaDa(props.documento.data_documento, props.documento.data_scadenza),
        regime_forfetario: letto?.regime_forfetario ?? false,
        // ⚠️ **Si propone solo ciò che l'aliquota determina, e solo se i numeri del file
        // lo confermano.** Il 4% è l'appalto art. 25-ter, il 20% il lavoro autonomo:
        // tutto il resto lascia la tendina vuota, perché scrivere in anagrafica un regime
        // indovinato vorrebbe dire applicarlo a tutte le fatture future di questo
        // fornitore. La spunta segue il regime e non parte mai da sola.
        soggetto_ritenuta: !!proposta.value?.tipoRitenuta,
        tipo_ritenuta: proposta.value?.tipoRitenuta ?? '',
        natura_percipiente: proponiNaturaDaRitenuta(props.ritenuta) ?? '',
    };
});

function chiudi() {
    emit('update:show', false);
}

async function salva() {
    salvando.value = true;
    erroreGenerale.value = null;
    erroriCampo.value = {};

    try {
        const risposta = await axios.post(route('admin.fornitori.store'), data.value);

        // ⚠️ **I campi fiscali si rimandano indietro da qui, non si rileggono dalla
        // risposta**: `admin.fornitori.store` restituisce solo `id` e `ragione_sociale`
        // (FornitoreController:173), ed è un contratto pubblico con un test che ne
        // asserisce esattamente le due chiavi — allargarlo per comodità nostra sarebbe un
        // intervento globale per un bisogno locale. Un 201 significa che il server ha
        // accettato quello che gli abbiamo mandato, quindi questo è ciò che il fornitore
        // ha davvero. Senza, la pagina lo inseriva nell'elenco con `soggetto_ritenuta:
        // false` scritto a mano e la ritenuta del file si perdeva lo stesso (reperto 12).
        emit('creato', {
            ...risposta.data,
            soggetto_ritenuta: data.value.soggetto_ritenuta,
            tipo_ritenuta: data.value.tipo_ritenuta || null,
            regime_forfetario: data.value.regime_forfetario,
        });
        emit('update:show', false);
    } catch (err: any) {
        if (err?.response?.status === 422) {
            erroriCampo.value = err.response.data?.errors ?? {};
        } else {
            erroreGenerale.value = 'Impossibile creare il fornitore. Riprova, o completa l\'anagrafica dalla pagina Fornitori.';
        }
    } finally {
        salvando.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col max-h-[90vh] overflow-hidden border border-slate-200 dark:border-slate-800">

                <div class="bg-primary/5 dark:bg-primary/10 p-6 border-b border-primary/10 flex items-start gap-4 shrink-0">
                    <div class="bg-primary/10 p-2.5 rounded-xl shrink-0">
                        <Building2 class="w-5 h-5 text-primary" />
                    </div>
                    <div class="flex-1">
                        <h3 class="font-black text-slate-800 dark:text-slate-100 text-base">Crea il fornitore letto dal file</h3>
                        <p class="text-xs text-slate-500 mt-1">
                            I dati arrivano dalla fattura: puoi correggerli prima di salvare.
                        </p>
                    </div>
                    <button type="button" @click="chiudi" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div v-if="erroreGenerale" class="text-xs font-medium text-rose-700 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
                        {{ erroreGenerale }}
                    </div>

                    <div v-if="fornitoreEstero" class="text-xs font-medium text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        Il file dichiara un cedente estero (codice paese «{{ lettoDaXml?.partita_iva_paese }}»): la partita IVA non è nel
                        formato italiano, non è stata precompilata. Il codice fiscale, se presente, è comunque proposto.
                    </div>

                    <div>
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Ragione sociale *</Label>
                        <Input v-model="data.ragione_sociale" class="h-9 text-sm mt-1" />
                        <p v-if="erroriCampo.ragione_sociale" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.ragione_sociale[0] }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Partita IVA</Label>
                            <Input v-model="data.partita_iva" class="h-9 text-sm mt-1" />
                            <p v-if="erroriCampo.partita_iva" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.partita_iva[0] }}</p>
                        </div>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Codice fiscale</Label>
                            <Input v-model="data.codice_fiscale" class="h-9 text-sm mt-1" />
                            <p v-if="erroriCampo.codice_fiscale" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.codice_fiscale[0] }}</p>
                        </div>
                    </div>

                    <div>
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Indirizzo</Label>
                        <Input v-model="data.indirizzo" class="h-9 text-sm mt-1" />
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">CAP</Label>
                            <Input v-model="data.cap" class="h-9 text-sm mt-1" />
                        </div>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Comune</Label>
                            <Input v-model="data.comune" class="h-9 text-sm mt-1" />
                        </div>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Prov.</Label>
                            <Input v-model="data.provincia" class="h-9 text-sm mt-1" maxlength="2" />
                        </div>
                    </div>

                    <div>
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Email</Label>
                        <Input v-model="data.email" type="email" class="h-9 text-sm mt-1" />
                        <p v-if="erroriCampo.email" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.email[0] }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Modalità di pagamento</Label>
                            <v-select
                                class="premium-select mt-1"
                                :options="MODALITA_OPZIONI"
                                v-model="data.modalita_pagamento_default"
                                :reduce="(o: any) => o.value"
                                label="label"
                                :clearable="false"
                                append-to-body />
                        </div>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Giorni di scadenza</Label>
                            <Input v-model.number="data.giorni_scadenza" type="number" min="0" class="h-9 text-sm mt-1" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400 pt-1">
                        <input type="checkbox" v-model="data.regime_forfetario" class="rounded border-slate-300" />
                        Regime forfetario (dichiarato dal file: RF19)
                    </label>

                    <!-- ⚠️ La ritenuta dichiarata dal file. Si PROPONE, non si decide:
                         l'amministratore vede il valore, sa da dove viene, e può togliere
                         la spunta o cambiare regime prima di salvare. Prima della beta.14
                         il fornitore nasceva senza nulla e la ritenuta si perdeva. -->
                    <div v-if="ritenuta && ritenuta.importo > 0"
                         class="space-y-2 bg-slate-50 dark:bg-slate-900/40 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                        <p class="text-xs font-bold text-slate-800 dark:text-slate-200">Il file dichiara una ritenuta d'acconto.</p>
                        <p class="text-[11px] text-slate-600 dark:text-slate-400">
                            Aliquota {{ ritenuta.aliquota }}%<template v-if="ritenuta.tipo">, tipo {{ ritenuta.tipo }}</template><template
                            v-if="ritenuta.causale_pagamento">, causale {{ ritenuta.causale_pagamento }}</template>.
                        </p>

                        <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                            <input type="checkbox" v-model="data.soggetto_ritenuta" class="rounded border-slate-300" />
                            Il fornitore è soggetto a ritenuta d'acconto
                        </label>

                        <div v-if="data.soggetto_ritenuta" class="space-y-1">
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Regime</Label>
                            <!-- ⚠️ `v-select` e non un `<select>` nativo: è il componente che usa
                                 la modalità di pagamento due riquadri più su, ed è **lo stesso con
                                 cui la scheda fornitore a pagina intera rende questo identico
                                 campo** (`FornitoriNew.vue`, «Regime di ritenuta»). Un select
                                 nativo qui sarebbe l'unico di tutto il modale, e per giunta
                                 farebbe apparire lo stesso campo in due modi diversi a seconda da
                                 dove ci si passa. Segnalato da Vincenzo al collaudo. -->
                            <v-select
                                class="premium-select mt-1"
                                :options="REGIMI_PROPONIBILI"
                                v-model="data.tipo_ritenuta"
                                :reduce="(o: any) => o.value"
                                label="label"
                                placeholder="Scegli il regime…"
                                append-to-body />
                            <p v-if="proposta?.tipoRitenuta" class="text-[10px] text-slate-500">
                                Proposto dall'aliquota dichiarata dal file, e i conti tornano. Puoi cambiarlo.
                            </p>
                            <p v-else class="text-[10px] text-amber-700 dark:text-amber-500">
                                L'aliquota del file non basta a stabilire il regime: sceglilo tu. Il file dice che una ritenuta c'è,
                                non quale regime applichiamo noi — e da qui in avanti vale per tutte le fatture di questo fornitore.
                            </p>

                            <!-- ⚠️ La natura del percipiente decide il codice tributo dell'F24,
                                 1019 o 1020, e nient'altro. Lasciarla vuota NON è neutro: a valle
                                 la generazione della delega ripiega in silenzio su persona fisica
                                 e stampa 1019 anche su una società. Il file la dice — RT01/RT02 —
                                 quindi si propone, visibile e modificabile. -->
                            <div class="pt-2">
                                <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Natura del percipiente</Label>
                                <v-select
                                    class="premium-select mt-1"
                                    :options="NATURE_PERCIPIENTE"
                                    v-model="data.natura_percipiente"
                                    :reduce="(o: any) => o.value"
                                    label="label"
                                    placeholder="Chi riceve il pagamento…"
                                    append-to-body />
                                <p v-if="naturaProposta" class="text-[10px] text-slate-500 mt-1">
                                    Proposta dal tipo <strong>{{ ritenuta?.tipo }}</strong> dichiarato dal file. Decide il codice tributo
                                    dell'F24: controllala, soprattutto se il fornitore è un ente non commerciale o una società di persone.
                                </p>
                                <p v-else class="text-[10px] text-amber-700 dark:text-amber-500 mt-1">
                                    Il file non dice chi è il percipiente: sceglilo tu, altrimenti il codice tributo dell'F24 verrà
                                    deciso senza di te.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="data.regime_forfetario" class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-amber-50/50 dark:bg-amber-900/10 p-4 rounded-xl border border-amber-100 dark:border-amber-900/30">
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Data dichiarazione forfetario *</Label>
                            <VueDatePicker
                                v-model="data.forfetario_dichiarato_il"
                                format="dd/MM/yyyy"
                                position="left"
                                locale="it"
                                :enable-time-picker="false"
                                auto-apply
                                placeholder="Seleziona data"
                                class="mt-1" />
                            <p v-if="erroriCampo.forfetario_dichiarato_il" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.forfetario_dichiarato_il[0] }}</p>
                        </div>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Riferimento documento conservato</Label>
                            <Input v-model="data.forfetario_riferimento" placeholder="Es. dichiarazione del 12/01/2026 agli atti" class="h-9 text-sm mt-1" />
                            <p v-if="erroriCampo.forfetario_riferimento" class="text-[11px] text-rose-600 font-medium mt-1">{{ erroriCampo.forfetario_riferimento[0] }}</p>
                        </div>
                    </div>
                </div>

                <div class="p-5 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 shrink-0 flex justify-end gap-2">
                    <Button variant="outline" class="h-10 rounded-xl font-bold px-6" @click="chiudi" :disabled="salvando">Annulla</Button>
                    <Button class="h-10 rounded-xl font-black px-6" :disabled="salvando || !data.ragione_sociale" @click="salva">
                        <LoaderCircle v-if="salvando" class="w-4 h-4 animate-spin mr-1.5" />
                        {{ salvando ? 'Creo il fornitore...' : 'Crea e aggancia' }}
                    </Button>
                </div>

            </div>
        </div>
    </Teleport>
</template>
