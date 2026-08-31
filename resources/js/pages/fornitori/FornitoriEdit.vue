<script setup lang="ts">

import { ref, computed, nextTick, onMounted, watch } from 'vue';
import { Link, Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Power, Landmark, ShieldCheck, Info } from 'lucide-vue-next';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import CercaComune from '@/components/comuni/CercaComune.vue';
import CercaAteco from '@/components/ateco/CercaAteco.vue';
import Alert from '@/components/Alert.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import MoneyInput from '@/components/MoneyInput.vue'
import { usePermission } from '@/composables/permissions';
import { usePuliziaErrori } from '@/composables/usePuliziaErrori';
import vSelect from "vue-select";
import NuovaCategoriaFornitore from '@/components/fornitori/NuovaCategoriaFornitore.vue';
import VueDatePicker from '@vuepic/vue-datepicker';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { trans } from 'laravel-vue-i18n';
import '@vuepic/vue-datepicker/dist/main.css';
import type { BreadcrumbItem } from '@/types';
import type { Categoria } from '@/types/categorie';
import type { Fornitore } from '@/types/fornitori';
import type { Flash } from '@/types/flash';


type RegimeOption = { value: string; label: string };

const props = defineProps<{
  fornitore: Fornitore;
  categorie: Categoria[];
  tipiRitenuta: RegimeOption[];
  natureRecipiente: RegimeOption[];
}>()

const regimiProvvigioni = ['provvigioni_base_50', 'provvigioni_base_20'];

const page = usePage(); 
const { generateRoute } = usePermission();
// ⚠️ Il ripiego chiamava `route('fornitori.index')`, un nome che non esiste: le rotte dei
// fornitori vivono sotto il prefisso di ruolo (`admin.fornitori.index`). Finché il controller
// manda `back_url` non si vede; il giorno che non lo mandasse, Ziggy solleverebbe **durante il
// setup** e la pagina di modifica resterebbe bianca — un ripiego che rompe più di ciò da cui
// ripara. Trovato dalla guardia `NomiDiRottaCheNonEsistonoTest` nella beta.62.
const backUrl = page.props.back_url as string || route(generateRoute('fornitori.index'));

// `RedirectHelper::backOr()` può riportare qui, sulla scheda stessa — e questa pagina non
// disegnava il flash, a differenza di `FornitoriList.vue`. Il salvataggio riusciva e nessun
// messaggio compariva: metà esatta della segnalazione «non compare il messaggio di successo».
const flashMessage = computed(() => (page.props.flash as { message?: Flash } | undefined)?.message);

// Il salvataggio riesce con il pulsante in fondo alla pagina, e `preserveScroll: true` lascia lo
// scorrimento dov'è: il messaggio verde nasceva in testa al modulo, cioè fuori schermo, e chi
// aveva appena premuto «Salva modifiche» non vedeva nessuna conferma. Stessa cura già in uso su
// `FornitoriList.vue`.
const inCima = () => window.scrollTo({ top: 0, behavior: 'smooth' });
onMounted(() => { if (flashMessage.value) inCima(); });
watch(flashMessage, (nuovo) => { if (nuovo) inCima(); });

const pageGuides = computed(() => [
  {
    title: trans('fornitori.guides.edit_status_title'),
    description: trans('fornitori.guides.edit_status_desc'),
    icon: Power,
    colorVariant: 'blue' as const
  },
  {
    title: trans('fornitori.guides.edit_treasury_title'),
    description: trans('fornitori.guides.edit_treasury_desc'),
    icon: Landmark,
    colorVariant: 'amber' as const
  },
  {
    title: trans('fornitori.guides.edit_compliance_title'),
    description: trans('fornitori.guides.edit_compliance_desc'),
    icon: ShieldCheck,
    colorVariant: 'emerald' as const
  }
]);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('fornitori.header.list_fornitori_head'),
      href: route(generateRoute('fornitori.index'))
  },
  {
      title: trans('fornitori.header.edit_fornitore_head'),
      href: '#',
  }
]); 

// Popolamento form con i dati del fornitore, incluso lo stato
const form = useForm({
    ragione_sociale: props.fornitore?.ragione_sociale ?? '',
    codice_fiscale: props.fornitore?.codice_fiscale ?? '',
    partita_iva: props.fornitore?.partita_iva ?? '',
    nazione: props.fornitore?.nazione ?? 'Italia',
    indirizzo: props.fornitore?.indirizzo ?? '',
    comune: props.fornitore?.comune ?? '',
    provincia: props.fornitore?.provincia ?? '',
    cap: props.fornitore?.cap ?? '',
    iscrizione_cciaa: props.fornitore?.iscrizione_cciaa ?? '',
    data_iscrizione_cciaa: props.fornitore?.data_iscrizione_cciaa ?? '',
    capitale_sociale: props.fornitore?.capitale_sociale ?? '',
    categoria_id: props.fornitore?.categoria_id ?? '',
    codice_ateco: props.fornitore?.codice_ateco ?? '',
    certificazione_iso: props.fornitore?.certificazione_iso ?? false,
    numero_iscrizione_ordine: props.fornitore?.numero_iscrizione_ordine ?? '',
    note: props.fornitore?.note ?? '',
    telefono: props.fornitore?.telefono ?? '',
    cellulare: props.fornitore?.cellulare ?? '',
    fax: props.fornitore?.fax ?? '',
    email: props.fornitore?.email ?? '',
    pec: props.fornitore?.pec ?? '',
    sito_web: props.fornitore?.sito_web ?? '',
    stato: props.fornitore?.stato ?? 'attivo',
    soggetto_ritenuta: props.fornitore?.soggetto_ritenuta ?? false,
    perc_ritenuta: props.fornitore?.perc_ritenuta ?? '',
    perc_imponibile_ritenuta: props.fornitore?.perc_imponibile_ritenuta ?? '100',
    codice_tributo: props.fornitore?.codice_tributo ?? '',
    giorni_scadenza: props.fornitore?.giorni_scadenza ?? 30,
    modalita_pagamento_default: props.fornitore?.modalita_pagamento_default ?? 'bonifico',
    iban_principale: props.fornitore?.iban_principale ?? '',

    // --- Regime fiscale ritenuta (v1.10, Fase 1) ---
    tipo_ritenuta: props.fornitore?.tipo_ritenuta ?? '',
    natura_percipiente: props.fornitore?.natura_percipiente ?? '',
    residente_fiscale: props.fornitore?.residente_fiscale ?? true,
    regime_forfetario: props.fornitore?.regime_forfetario ?? false,
    forfetario_dichiarato_il: props.fornitore?.forfetario_dichiarato_il ?? '',
    forfetario_riferimento: props.fornitore?.forfetario_riferimento ?? '',
    provvigioni_base_ridotta: props.fornitore?.provvigioni_base_ridotta ?? false,
    provvigioni_dichiarazione_il: props.fornitore?.provvigioni_dichiarazione_il ?? '',
});

const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2, 
  disableNegative: false,       
  allowBlank: false,
  masked: true 
})

// Nomi leggibili per il riepilogo dei rifiuti: nell'elenco in testa al modulo deve comparire
// «Giorni di scadenza», non `giorni_scadenza`.
const etichetteCampi: Record<string, string> = {
    ragione_sociale: 'Ragione sociale',
    partita_iva: 'Partita IVA',
    codice_fiscale: 'Codice fiscale',
    note: 'Note aggiuntive interne',
    indirizzo: 'Indirizzo e civico',
    cap: 'CAP',
    comune: 'Comune',
    provincia: 'Provincia',
    nazione: 'Nazione',
    telefono: 'Telefono fisso',
    cellulare: 'Cellulare',
    fax: 'Fax',
    email: 'Email ordinaria',
    pec: 'Email PEC',
    sito_web: 'Sito internet',
    categoria_id: 'Categoria',
    codice_ateco: 'Codice ATECO',
    iscrizione_cciaa: 'Iscrizione CCIAA',
    data_iscrizione_cciaa: 'Data iscrizione CCIAA',
    numero_iscrizione_ordine: "Numero d'iscrizione all'ordine",
    capitale_sociale: 'Capitale sociale',
    stato: 'Stato operativo',
    iban_principale: 'IBAN principale',
    modalita_pagamento_default: 'Modalità di pagamento',
    giorni_scadenza: 'Giorni di scadenza',
    tipo_ritenuta: 'Regime di ritenuta',
    natura_percipiente: 'Natura del percipiente',
    perc_ritenuta: '% da trattenere',
    perc_imponibile_ritenuta: '% base imponibile',
    codice_tributo: 'Codice tributo',
    forfetario_dichiarato_il: 'Data della dichiarazione forfetaria',
    forfetario_riferimento: 'Riferimento della dichiarazione forfetaria',
    provvigioni_dichiarazione_il: 'Data della dichiarazione sulle provvigioni',
};

/**
 * Il Comune scelto dall'elenco ISTAT riempie **due** campi: il nome e la sigla della provincia.
 * Il CAP no, e non per dimenticanza: la tabella `comuni` non ha quella colonna — un comune può
 * avere decine di CAP, quindi non è un dato che si possa dedurre dal nome. Resta a mano.
 *
 * Il campo non diventa una tendina: chi sa cosa scrivere continua a scriverlo. È la stessa scelta
 * fatta sul Comune catastale del condominio, e la ragione è che i comuni si fondono e cambiano nome.
 */
/**
 * Il codice scelto dall'elenco ISTAT riempie il campo con il **solo codice**, non con il titolo.
 * È il codice che va in anagrafica: il titolo lo si è appena letto scegliendolo, e ripeterlo dentro
 * la casella riprodurrebbe esattamente l'errore da cui è nata la beta.7 — «43.22.01 impianti
 * idraulici» incollato dentro un campo che accetta solo il codice.
 */
const atecoScelto = (c: { codice: string }) => {
  form.codice_ateco = c.codice;
};

const comuneScelto = (c: { nome: string; sigla: string }) => {
  form.comune = c.nome;
  form.provincia = c.sigla;
};

// La riga rossa sparisce appena il campo viene corretto, invece di restare finché non si salva
// di nuovo: senza, il programma continua a segnalare un errore che l'utente ha già sistemato.
usePuliziaErrori(form);

const riepilogoErrori = ref<HTMLElement | null>(null);

const submit = () => {
    form.put(route(generateRoute('fornitori.update'), {id: props.fornitore.id}), {
        preserveScroll: true,
        // Senza questo, un rifiuto su un campo lontano dal pulsante non si vede: la pagina si
        // rimonta identica e «Salva Modifiche» sembra non fare niente. È la segnalazione da cui
        // nasce questa beta.
        onError: () => {
            nextTick(() => {
                const box = riepilogoErrori.value as HTMLElement | null;
                box?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                box?.focus?.();
            });
        },
    });
};

/**
 * Il «+» accanto alla tendina ha appena creato una categoria: qui la si seleziona.
 *
 * ⚠️ **Si cerca per nome, non per id, e non è un ripiego.** La richiesta del componente è un
 * ricaricamento parziale (`only: ['categorie', ...]`), quindi quando arriva questo evento la prop
 * `categorie` è già quella nuova; l'id però non torna indietro da nessuna parte, mentre il nome sì —
 * ed è **unico a database**, garantito dalla regola `unique` della richiesta di creazione.
 */
function categoriaCreata(nome: string) {
    const creata = props.categorie.find((c) => c.name === nome)

    if (creata) {
        form.categoria_id = creata.id
    }
}

</script>

<template>
  <Head :title="trans('fornitori.header.edit_fornitore_title')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        :page-title="trans('fornitori.header.edit_fornitore_title')"
        :page-subtitle="trans('fornitori.header.edit_fornitore_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="backUrl"
        back-text="Indietro"
      />
      
      <form class="space-y-6" @submit.prevent="submit">

        <div v-if="flashMessage" class="py-1">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div ref="riepilogoErrori" tabindex="-1" class="outline-none">
          <FormErrorSummary :errors="form.errors" :labels="etichetteCampi" />
        </div>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <CardTitle class="text-base font-semibold">Informazioni principali</CardTitle>
                        <CardDescription>Dati identificativi e stato operativo del fornitore.</CardDescription>
                    </div>
                    
                    <div class="flex items-center p-1 bg-slate-100 dark:bg-slate-900/50 rounded-lg border border-slate-200 dark:border-slate-800 shrink-0">
                        <button 
                            type="button" 
                            @click="form.stato = 'attivo'"
                            class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-md transition-all duration-200"
                            :class="form.stato === 'attivo' 
                                ? 'bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 shadow-sm ring-1 ring-slate-200/50 dark:ring-slate-700' 
                                : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'"
                        >
                            Attivo
                        </button>
                        
                        <button 
                            type="button" 
                            @click="form.stato = 'sospeso'"
                            class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-md transition-all duration-200"
                            :class="form.stato === 'sospeso' 
                                ? 'bg-white dark:bg-slate-800 text-amber-600 dark:text-amber-400 shadow-sm ring-1 ring-slate-200/50 dark:ring-slate-700' 
                                : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'"
                        >
                            Sospeso
                        </button>

                        <button 
                            type="button" 
                            @click="form.stato = 'cessato'"
                            class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-md transition-all duration-200"
                            :class="form.stato === 'cessato' 
                                ? 'bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 shadow-sm ring-1 ring-slate-200/50 dark:ring-slate-700' 
                                : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'"
                        >
                            Cessato
                        </button>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label for="ragione_sociale">Ragione sociale</Label>
                        <Input 
                          id="ragione_sociale" 
                          v-model="form.ragione_sociale" 
                          placeholder="Es: Rossi Impianti S.r.l." 
                          class="mt-1 bg-white" 
                          required 
                        />
                        <InputError :message="form.errors.ragione_sociale" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="partita_iva">Partita IVA</Label>
                        <Input id="partita_iva" v-model="form.partita_iva" class="mt-1 bg-white uppercase placeholder:normal-case placeholder:font-sans" placeholder="Inserisci partita IVA" />
                        <InputError :message="form.errors.partita_iva" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_fiscale">Codice Fiscale</Label>
                        <Input id="codice_fiscale" v-model="form.codice_fiscale" class="mt-1 bg-white uppercase placeholder:normal-case placeholder:font-sans" placeholder="Inserisci codice fiscale" />
                        <InputError :message="form.errors.codice_fiscale" />
                    </div>

                    <div class="sm:col-span-6">
                        <Label for="note">Note aggiuntive interne</Label>
                        <Textarea id="note" class="mt-1 w-full bg-white dark:bg-slate-950" placeholder="Inserisci una nota visibile solo agli amministratori..." v-model="form.note" />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">Recapiti e sede</CardTitle>
                <CardDescription>Indirizzo operativo e canali di comunicazione ufficiali.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label>Indirizzo e civico</Label>
                        <Input v-model="form.indirizzo" placeholder="Via, Piazza, Corso..." class="mt-1 bg-white" />
                        <InputError :message="form.errors.indirizzo" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="comune">Comune</Label>
                        <div class="mt-1 flex items-center gap-2">
                          <Input id="comune" v-model="form.comune" placeholder="Comune" class="bg-white" />
                          <CercaComune @scelto="comuneScelto" />
                        </div>
                        <InputError :message="form.errors.comune" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label>CAP</Label>
                        <Input v-model="form.cap" placeholder="CAP" class="mt-1 bg-white" maxlength="5" />
                        <InputError :message="form.errors.cap" />
                    </div>

                    <div class="sm:col-span-1">
                        <Label>Prov.</Label>
                        <Input v-model="form.provincia" placeholder="Prov." class="mt-1 bg-white uppercase" maxlength="2" />
                        <InputError :message="form.errors.provincia" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-2">
                        <Label>Telefono fisso</Label>
                        <Input v-model="form.telefono" placeholder="Es: 06 1234567" class="mt-1 bg-white" />
                        <InputError :message="form.errors.telefono" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Cellulare</Label>
                        <Input v-model="form.cellulare" placeholder="Es: 333 1234567" class="mt-1 bg-white" />
                        <InputError :message="form.errors.cellulare" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Fax</Label>
                        <Input v-model="form.fax" placeholder="Es: 06 1234568" class="mt-1 bg-white" />
                        <InputError :message="form.errors.fax" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label>Email Ordinaria</Label>
                        <Input v-model="form.email" type="email" placeholder="email@esempio.it" class="mt-1 bg-white" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Email PEC</Label>
                        <Input v-model="form.pec" type="email" placeholder="pec@legalmail.it" class="mt-1 bg-white" />
                        <InputError :message="form.errors.pec" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Sito Internet</Label>
                        <Input v-model="form.sito_web" placeholder="https://..." class="mt-1 bg-white" />
                        <InputError :message="form.errors.sito_web" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle class="text-base font-semibold flex items-center gap-2">
                            Fatturazione e pagamenti
                        </CardTitle>
                        <CardDescription>Regole per la registrazione e il pagamento dei compensi.</CardDescription>
                    </div>
                    <div class="flex items-center space-x-2">
                        <Checkbox 
                            id="soggetto_ritenuta" 
                            v-model="form.soggetto_ritenuta" 
                        />
                        <Label for="soggetto_ritenuta" class="cursor-pointer font-medium text-sm">
                            Soggetto a ritenuta d'acconto
                        </Label>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label>IBAN Principale (Coordinate di default)</Label>
                        <Input v-model="form.iban_principale" placeholder="IT00 0000 0000 0000 0000 0000 000" class="mt-1 text-lg uppercase tracking-wide bg-white" maxlength="27" />
                        <InputError :message="form.errors.iban_principale" />
                    </div>
                    
                    <div class="sm:col-span-4">
                        <Label>Modalità di pagamento</Label>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="[
                                { label: 'Bonifico bancario', value: 'bonifico' },
                                { label: 'MAV', value: 'mav' },
                                { label: 'Ri.Ba.', value: 'ri.ba' },
                                { label: 'Contanti', value: 'contanti' }
                            ]"
                            v-model="form.modalita_pagamento_default"
                            :reduce="(option: any) => option.value"
                            label="label"
                            placeholder="Seleziona modalità..."
                            :clearable="false"
                        />
                        <InputError :message="form.errors.modalita_pagamento_default" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Scadenza (Giorni)</Label>
                        <div class="relative mt-1">
                            <Input v-model="form.giorni_scadenza" type="number" min="0" step="1" class="pr-8 text-right font-medium bg-white" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground font-bold">gg</span>
                        </div>
                        <InputError :message="form.errors.giorni_scadenza" />
                    </div>

                    <div class="sm:col-span-6 flex flex-wrap items-center gap-x-6 gap-y-2 pt-1">
                        <div class="flex items-center space-x-2">
                            <Checkbox id="residente_fiscale" v-model="form.residente_fiscale" />
                            <Label for="residente_fiscale" class="cursor-pointer text-sm text-slate-600 dark:text-slate-400">
                                Fornitore fiscalmente residente in Italia
                            </Label>
                        </div>
                        <div class="flex items-center space-x-2">
                            <Checkbox id="regime_forfetario" v-model="form.regime_forfetario" />
                            <Label for="regime_forfetario" class="cursor-pointer text-sm text-slate-600 dark:text-slate-400">
                                Fornitore in regime forfetario
                            </Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                        <Info class="w-4 h-4" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                    <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                        Il regime forfetario esclude per legge la ritenuta d'acconto: se attivo, il sistema non la applicherà mai su questo fornitore, indipendentemente dalle altre impostazioni sottostanti.
                                    </p>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                    </div>

                    <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-2 opacity-0">
                        <div v-if="form.regime_forfetario" class="sm:col-span-6 grid grid-cols-1 sm:grid-cols-2 gap-4 bg-amber-50/50 dark:bg-amber-900/10 p-4 rounded-xl border border-amber-100 dark:border-amber-900/30">
                            <div>
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Data dichiarazione forfetario</Label>
                                <VueDatePicker
                                    v-model="form.forfetario_dichiarato_il"
                                    format="dd/MM/yyyy"
                                    position="left"
                                    locale="it"
                                    :enable-time-picker="false"
                                    auto-apply
                                    placeholder="Seleziona data"
                                />
                                <InputError :message="form.errors.forfetario_dichiarato_il" class="mt-1" />
                            </div>
                            <div>
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Riferimento documento conservato</Label>
                                <Input v-model="form.forfetario_riferimento" placeholder="Es. dichiarazione del 12/01/2026 agli atti" class="h-10 bg-white dark:bg-slate-950" />
                                <InputError :message="form.errors.forfetario_riferimento" class="mt-1" />
                            </div>
                        </div>
                    </Transition>
                </div>

               <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-2 opacity-0">
                    <div v-if="form.soggetto_ritenuta" class="pt-5 border-t border-dashed border-slate-200 dark:border-slate-800">
                        
                        <div class="mb-5">
                            <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">Automazioni Fiscali (F24 e CU)</h4>
                            <div class="flex items-start gap-3 bg-blue-50/50 dark:bg-blue-900/20 p-3.5 rounded-xl border border-blue-100 dark:border-blue-900/30">
                                <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                    Questi parametri permettono al sistema di <strong>calcolare automaticamente la trattenuta</strong> in fase di registrazione fattura. Il software scorporerà in automatico il <em>netto da pagare</em> per il fornitore e alimenterà lo scadenziario fiscale per la <strong>generazione automatica del Modello F24 e della Certificazione Unica</strong>.
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6 bg-white dark:bg-slate-950 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                            <div class="sm:col-span-3">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Regime di ritenuta</Label>
                                <v-select
                                    class="premium-select bg-slate-50 dark:bg-slate-900/50"
                                    :options="tipiRitenuta"
                                    v-model="form.tipo_ritenuta"
                                    :reduce="(o: RegimeOption) => o.value"
                                    label="label"
                                    placeholder="Seleziona il regime applicabile..."
                                />
                                <InputError :message="form.errors.tipo_ritenuta" class="mt-1" />
                            </div>

                            <div class="sm:col-span-3">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Natura del percipiente</Label>
                                <v-select
                                    class="premium-select bg-slate-50 dark:bg-slate-900/50"
                                    :options="natureRecipiente"
                                    v-model="form.natura_percipiente"
                                    :reduce="(o: RegimeOption) => o.value"
                                    label="label"
                                    placeholder="IRPEF o IRES: decide 1019 vs 1020..."
                                />
                                <InputError :message="form.errors.natura_percipiente" class="mt-1" />
                            </div>

                            <div v-if="regimiProvvigioni.includes(form.tipo_ritenuta)" class="sm:col-span-6 flex flex-wrap items-center gap-4 bg-slate-50 dark:bg-slate-900/40 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                                <div class="flex items-center space-x-2">
                                    <Checkbox id="provvigioni_base_ridotta" v-model="form.provvigioni_base_ridotta" />
                                    <Label for="provvigioni_base_ridotta" class="cursor-pointer text-xs text-slate-600 dark:text-slate-400 max-w-md">
                                        Il percipiente ha dichiarato di avvalersi in via continuativa di dipendenti/collaboratori (base ridotta al 50%)
                                    </Label>
                                </div>
                                <div class="flex-1 min-w-[180px]">
                                    <VueDatePicker
                                        v-model="form.provvigioni_dichiarazione_il"
                                        format="dd/MM/yyyy"
                                        position="left"
                                        locale="it"
                                        :enable-time-picker="false"
                                        auto-apply
                                        placeholder="Data dichiarazione"
                                    />
                                    <InputError :message="form.errors.provvigioni_dichiarazione_il" class="mt-1" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="flex items-center gap-2 mb-2">
                                <h5 class="text-xs font-bold uppercase tracking-wider text-slate-500">Override manuale (facoltativo)</h5>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                            Se hai selezionato un regime sopra, aliquota e codice tributo si calcolano automaticamente in fase di registrazione fattura. Compila questi campi solo per un caso non coperto dal regime selezionato.
                                        </p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6 bg-white dark:bg-slate-950 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                                <div class="sm:col-span-2">
                                    <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">% Da Trattenere</Label>
                                    <div class="relative">
                                        <Input v-model="form.perc_ritenuta" placeholder="Es. 4" class="pr-8 h-10 bg-slate-50 dark:bg-slate-900/50" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold">%</span>
                                    </div>
                                    <InputError :message="form.errors.perc_ritenuta" class="mt-1" />
                                </div>

                                <div class="sm:col-span-2">
                                    <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">% Base Imponibile</Label>
                                    <div class="relative">
                                        <Input v-model="form.perc_imponibile_ritenuta" placeholder="Es. 100" class="pr-8 h-10 bg-slate-50 dark:bg-slate-900/50" />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold">%</span>
                                    </div>
                                    <InputError :message="form.errors.perc_imponibile_ritenuta" class="mt-1" />
                                </div>

                                <div class="sm:col-span-2">
                                    <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Codice Tributo</Label>
                                    <Input v-model="form.codice_tributo" placeholder="Es: 1040" class="h-10 uppercase placeholder:normal-case bg-slate-50 dark:bg-slate-900/50" />
                                    <InputError :message="form.errors.codice_tributo" class="mt-1" />
                                </div>
                            </div>
                        </div>

                    </div>
                </Transition>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">Dati societari</CardTitle>
                <CardDescription>Iscrizioni a camere di commercio, ordini e certificazioni.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                        <Label for="iscrizione_cciaa">Iscrizione CCIAA</Label>
                        <Input id="iscrizione_cciaa" v-model="form.iscrizione_cciaa" placeholder="Numero iscrizione CCIA" class="mt-1 bg-white" />
                        <InputError :message="form.errors.iscrizione_cciaa" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="data_iscrizione_cciaa">Data iscrizione CCIAA</Label>
                        <VueDatePicker
                            v-model="form.data_iscrizione_cciaa"
                            class="w-full mt-1 h-10"
                            format="dd/MM/yyyy"
                            position="left" 
                            locale="it"
                            :enable-time-picker="false"
                            auto-apply
                            placeholder="Seleziona data"
                        />
                        <InputError :message="form.errors.data_iscrizione_cciaa" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="capitale_sociale">Capitale sociale</Label>
                        <MoneyInput
                            id="capitale_sociale"
                            v-model="form.capitale_sociale"
                            :money-options="moneyOptions"
                            :lazy="true" 
                            placeholder="0,00"
                            class="mt-1"
                        />
                        <InputError :message="form.errors.capitale_sociale" />
                        <p class="text-[11px] text-muted-foreground mt-1 italic">Es: 10.000,00</p>
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_ateco">Codice ATECO</Label>
                        <div class="mt-1 flex items-center gap-2">
                          <Input id="codice_ateco" v-model="form.codice_ateco" placeholder="Inserisci codice ATECO" class="bg-white" />
                          <CercaAteco @scelto="atecoScelto" />
                        </div>
                        <InputError :message="form.errors.codice_ateco" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-3">
                        <Label for="categoria_id">Categoria fornitore</Label>
                        <!--
                          Il «+» accanto alla tendina: la categoria che manca ci si accorge che manca
                          **qui**, con mezza scheda già compilata, non nella pagina delle categorie.
                          Senza, la scelta sarebbe fra perdere quello che si è scritto e mettere
                          «Altro» — e «Altro» è quello che poi resta lì per sempre.
                        -->
                        <div class="mt-1 flex items-center gap-2">
                            <v-select
                                class="w-full premium-select bg-white dark:bg-slate-950 min-w-0 flex-1"
                                :options="categorie"
                                v-model="form.categoria_id"
                                :reduce="(d: Categoria) => d.id"
                                label="name"
                                placeholder="Seleziona categoria..."
                            />
                            <NuovaCategoriaFornitore @creata="categoriaCreata" />
                        </div>
                        <InputError :message="form.errors.categoria_id" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="numero_iscrizione_ordine">Iscrizione Albo/Ordine (se professionista)</Label>
                        <Input id="numero_iscrizione_ordine" v-model="form.numero_iscrizione_ordine" placeholder="Numero iscrizione albo" class="mt-1 bg-white" />
                        <InputError :message="form.errors.numero_iscrizione_ordine" />
                    </div>

                    <div class="sm:col-span-6 mt-2">
                        <div class="flex items-center space-x-2 p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                            <Checkbox 
                                id="certificazione_iso" 
                                v-model="form.certificazione_iso"
                                @update:checked="(val: boolean ) => form.certificazione_iso = val"
                            />
                            <Label for="certificazione_iso" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                L'azienda possiede la certificazione ISO conforme alle normative europee
                            </Label>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
                :href="route(generateRoute('fornitori.index'))"
                class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm select-none"
            >
                Annulla
            </Link>

            <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-sm font-semibold shadow-md gap-2 select-none"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Save v-else class="h-4 w-4" />
                Salva modifiche
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>