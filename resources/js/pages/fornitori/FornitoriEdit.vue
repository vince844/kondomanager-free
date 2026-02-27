<script setup lang="ts">

import { ref, computed } from 'vue';
import { Link, Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, ArrowLeft, ReceiptEuro, Save, Power, Landmark, ShieldCheck } from 'lucide-vue-next';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import MoneyInput from '@/components/MoneyInput.vue'
import { usePermission } from '@/composables/permissions';
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { trans } from 'laravel-vue-i18n';
import '@vuepic/vue-datepicker/dist/main.css';
import type { BreadcrumbItem } from '@/types';
import type { Categoria } from '@/types/categorie';
import type { Fornitore } from '@/types/fornitori';


// Rimosso anagrafiche dalle props, non servono più qui
const props = defineProps<{
  fornitore: Fornitore;
  categorie: Categoria[];
}>()

const page = usePage(); 
const backUrl = page.props.back_url as string || route('fornitori.index'); 
const { generateRoute } = usePermission();

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

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: trans('fornitori.header.list_fornitori_head'),
      href: route(generateRoute('fornitori.index'))
  },
  {
      title: trans('fornitori.header.edit_fornitore_head'),
      href: '#',
  }
]; 

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
    iban_principale: props.fornitore?.iban_principale ?? ''
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

const submit = () => {
    form.put(route(generateRoute('fornitori.update'), {id: props.fornitore.id}), {
        preserveScroll: true
    });
};
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
                        <Input id="partita_iva" v-model="form.partita_iva" class="mt-1 bg-white uppercase font-mono" placeholder="IT..." />
                        <InputError :message="form.errors.partita_iva" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_fiscale">Codice Fiscale</Label>
                        <Input id="codice_fiscale" v-model="form.codice_fiscale" class="mt-1 bg-white uppercase font-mono" placeholder="Codice fiscale" />
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
                    
                    <div class="sm:col-span-2">
                        <Label>CAP</Label>
                        <Input v-model="form.cap" placeholder="CAP" class="mt-1 bg-white" maxlength="5" />
                        <InputError :message="form.errors.cap" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label>Comune</Label>
                        <Input v-model="form.comune" placeholder="Comune" class="mt-1 bg-white" />
                        <InputError :message="form.errors.comune" />
                    </div>
                    
                    <div class="sm:col-span-1">
                        <Label>Prov.</Label>
                        <Input v-model="form.provincia" placeholder="Prov." class="mt-1 bg-white uppercase" maxlength="2" />
                        <InputError :message="form.errors.provincia" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-2">
                        <Label>Telefono fisso</Label>
                        <Input v-model="form.telefono" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Cellulare</Label>
                        <Input v-model="form.cellulare" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Fax</Label>
                        <Input v-model="form.fax" class="mt-1 bg-white" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label>Email Ordinaria</Label>
                        <Input v-model="form.email" type="email" placeholder="email@esempio.it" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Email PEC</Label>
                        <Input v-model="form.pec" type="email" placeholder="pec@legalmail.it" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>Sito Internet</Label>
                        <Input v-model="form.sito_web" placeholder="https://..." class="mt-1 bg-white" />
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
                            <Input v-model="form.giorni_scadenza" class="pr-8 text-right font-medium bg-white" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground font-bold">gg</span>
                        </div>
                    </div>
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
                                <Input v-model="form.codice_tributo" placeholder="Es. 1040" class="h-10 uppercase font-mono bg-slate-50 dark:bg-slate-900/50" />
                                <InputError :message="form.errors.codice_tributo" class="mt-1" />
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
                        <p class="text-[11px] text-muted-foreground mt-1 italic">Es: 10.000,00</p>
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_ateco">Codice ATECO</Label>
                        <Input id="codice_ateco" v-model="form.codice_ateco" placeholder="Codice ateco" class="mt-1 bg-white font-mono" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-3">
                        <Label for="categoria_id">Categoria fornitore</Label>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="categorie"
                            v-model="form.categoria_id"
                            :reduce="(d: Categoria) => d.id"
                            label="name"
                            placeholder="Seleziona categoria..."
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="numero_iscrizione_ordine">Iscrizione Albo/Ordine (se professionista)</Label>
                        <Input id="numero_iscrizione_ordine" v-model="form.numero_iscrizione_ordine" placeholder="Numero iscrizione albo" class="mt-1 bg-white" />
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
                class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
            >
                Annulla
            </Link>

            <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Plus v-else class="h-4 w-4" />
                Salva Modifiche
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>