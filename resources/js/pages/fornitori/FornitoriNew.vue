<script setup lang="ts">

import { ref, computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Info, ShieldCheck, Truck, UserPlus } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import MoneyInput from '@/components/MoneyInput.vue'
import { usePermission } from '@/composables/permissions';
import { trans } from 'laravel-vue-i18n';
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import type { BreadcrumbItem } from '@/types';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';

defineProps<{
  anagrafiche: Anagrafica[];
  categorie: Categoria[];
}>()

const { generateRoute } = usePermission();
/* const page = usePage(); 
const backUrl = route('fornitori.index');  */

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: trans('fornitori.header.list_fornitori_head'),
      href: route(generateRoute('fornitori.index'))
  },
  {
      title: trans('fornitori.header.new_fornitore_head'),
      href: '#',
  }
]; 

const pageGuides = computed(() => [
  {
    title: trans('fornitori.guides.portfolio_title'),
    description: trans('fornitori.guides.portfolio_desc'),
    icon: Truck,
    colorVariant: 'blue' as const
  },
  {
    title: trans('fornitori.guides.compliance_title'),
    description: trans('fornitori.guides.compliance_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  },
  {
    title: trans('fornitori.guides.new_fornitore_guide_title'),
    description: trans('fornitori.guides.new_fornitore_guide_desc'),
    icon: UserPlus,
    colorVariant: 'emerald' as const
  }
]);

const form = useForm({
    ragione_sociale: '',
    codice_fiscale: '',
    partita_iva: '',
    nazione: 'Italia',
    indirizzo: '',
    comune: '',
    provincia: '',
    cap: '',
    iscrizione_cciaa: '',
    data_iscrizione_cciaa: '',
    capitale_sociale: '',
    categoria_id: '',
    codice_ateco: '',
    certificazione_iso: false,
    numero_iscrizione_ordine: '',
    note: '',
    telefono: '',
    cellulare: '',
    fax: '',
    email: '',
    pec: '',
    sito_web: '',
    anagrafica_id: '',
    soggetto_ritenuta: false,
    perc_ritenuta: '',
    perc_imponibile_ritenuta: '100',
    codice_tributo: '',
    giorni_scadenza: 30,
    modalita_pagamento_default: 'bonifico',
    iban_principale: ''
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
    form.post(route(generateRoute('fornitori.store')), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        } 
    });
};

</script>

<template>
  <Head :title="trans('fornitori.header.new_fornitore_title')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('fornitori.header.new_fornitore_title')"
        :page-subtitle="trans('fornitori.header.new_fornitore_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="route(generateRoute('fornitori.index'))"
        :back-text="trans('fornitori.common.back')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('fornitori.forms.main_info_title') }}</CardTitle>
                <CardDescription>{{ trans('fornitori.forms.main_info_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <div class="flex items-center min-h-[24px]">
                            <Label for="ragione_sociale">{{ trans('fornitori.forms.company_name') }}</Label>
                        </div>
                        <Input 
                            id="ragione_sociale" 
                            v-model="form.ragione_sociale" 
                            :placeholder="trans('fornitori.forms.company_name_placeholder')"
                            class="mt-1 bg-white" 
                            required 
                        />
                        <InputError :message="form.errors.ragione_sociale" />
                    </div>

                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-2 min-h-[24px]">
                            <Label for="referente">{{ trans('fornitori.forms.main_contact') }}</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                    <Info class="w-4 h-4" />
                                </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                    <h4 class="text-sm font-bold uppercase mb-2">{{ trans('fornitori.sections.contact_assoc_title') }}</h4>
                                    <p class="text-xs text-slate-500 leading-relaxed">{{ trans('fornitori.sections.contact_assoc_desc') }}</p>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="anagrafiche"
                            v-model="form.anagrafica_id"
                            :reduce="(d: Anagrafica) => d.id"
                            label="nome"
                            :placeholder="trans('fornitori.placeholder.record')"
                        >
                            <template #option="{ nome, indirizzo }">
                                <div class="flex flex-col py-1">
                                    <span class="font-bold text-sm">{{ nome }}</span>
                                    <span class="text-[11px] text-slate-400 italic">{{ indirizzo }}</span>
                                </div>
                            </template>
                        </v-select>
                        <InputError :message="form.errors.anagrafica_id" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="partita_iva">{{ trans('fornitori.label.vat_number') }}</Label>
                        <Input id="partita_iva" v-model="form.partita_iva" class="mt-1 bg-white" :placeholder="trans('fornitori.label.vat_number')" />
                        <InputError :message="form.errors.partita_iva" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_fiscale">{{ trans('fornitori.label.tax_code') }}</Label>
                        <Input id="codice_fiscale" v-model="form.codice_fiscale" class="mt-1 bg-white" :placeholder="trans('fornitori.label.tax_code')" />
                        <InputError :message="form.errors.codice_fiscale" />
                    </div>

                    <div class="sm:col-span-6">
                        <Label for="note">{{ trans('fornitori.forms.internal_notes') }}</Label>
                        <Textarea id="note" class="mt-1 w-full bg-white dark:bg-slate-950" :placeholder="trans('fornitori.forms.internal_notes_placeholder')" v-model="form.note" />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('fornitori.forms.contacts_title') }}</CardTitle>
                <CardDescription>{{ trans('fornitori.forms.contacts_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label>{{ trans('fornitori.forms.address') }}</Label>
                        <Input v-model="form.indirizzo" :placeholder="trans('fornitori.forms.address_placeholder')" class="mt-1 bg-white" />
                        <InputError :message="form.errors.indirizzo" />
                    </div>
                    
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.zip_code') }}</Label>
                        <Input v-model="form.cap" :placeholder="trans('fornitori.forms.zip_code')" class="mt-1 bg-white" maxlength="5" />
                        <InputError :message="form.errors.cap" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label>{{ trans('fornitori.forms.city') }}</Label>
                        <Input v-model="form.comune" :placeholder="trans('fornitori.forms.city')" class="mt-1 bg-white" />
                        <InputError :message="form.errors.comune" />
                    </div>
                    
                    <div class="sm:col-span-1">
                        <Label>{{ trans('fornitori.forms.province') }}</Label>
                        <Input v-model="form.provincia" :placeholder="trans('fornitori.forms.province')" class="mt-1 bg-white" maxlength="2" />
                        <InputError :message="form.errors.provincia" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.phone') }}</Label>
                        <Input v-model="form.telefono" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.mobile') }}</Label>
                        <Input v-model="form.cellulare" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.fax') }}</Label>
                        <Input v-model="form.fax" class="mt-1 bg-white" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.email') }}</Label>
                        <Input v-model="form.email" type="email" :placeholder="trans('fornitori.forms.email_placeholder')" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.pec') }}</Label>
                        <Input v-model="form.pec" type="email" :placeholder="trans('fornitori.forms.pec_placeholder')" class="mt-1 bg-white" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.website') }}</Label>
                        <Input v-model="form.sito_web" :placeholder="trans('fornitori.forms.website_placeholder')" class="mt-1 bg-white" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle class="text-base font-semibold flex items-center gap-2">
                            {{ trans('fornitori.forms.billing_title') }}
                        </CardTitle>
                        <CardDescription>{{ trans('fornitori.forms.billing_desc') }}</CardDescription>
                    </div>
                    <div class="flex items-center space-x-2">
                        <Checkbox 
                            id="soggetto_ritenuta" 
                            v-model="form.soggetto_ritenuta" 
                        />
                        <Label for="soggetto_ritenuta" class="cursor-pointer font-medium text-sm">
                            {{ trans('fornitori.forms.withholding_subject') }}
                        </Label>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label>{{ trans('fornitori.forms.primary_iban') }}</Label>
                        <Input v-model="form.iban_principale" placeholder="IT00 0000 0000 0000 0000 0000 000" class="mt-1 text-lg uppercase tracking-wide bg-white" maxlength="27" />
                        <InputError :message="form.errors.iban_principale" />
                    </div>
                    
                    <div class="sm:col-span-4">
                        <Label>{{ trans('fornitori.forms.payment_method') }}</Label>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="[
                                { label: trans('fornitori.forms.payment_methods.bank_transfer'), value: 'bonifico' },
                                { label: trans('fornitori.forms.payment_methods.mav'), value: 'mav' },
                                { label: trans('fornitori.forms.payment_methods.riba'), value: 'ri.ba' },
                                { label: trans('fornitori.forms.payment_methods.cash'), value: 'contanti' }
                            ]"
                            v-model="form.modalita_pagamento_default"
                            :reduce="(option: any) => option.value"
                            label="label"
                            :placeholder="trans('fornitori.forms.payment_method_placeholder')"
                            :clearable="false"
                        />
                        <InputError :message="form.errors.modalita_pagamento_default" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label>{{ trans('fornitori.forms.deadline_days') }}</Label>
                        <div class="relative mt-1">
                            <Input v-model="form.giorni_scadenza" class="pr-8 text-right font-medium bg-white" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground font-bold">{{ trans('fornitori.view.days_abbr') }}</span>
                        </div>
                    </div>
                </div>

               <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-2 opacity-0">
                    <div v-if="form.soggetto_ritenuta" class="pt-5 border-t border-dashed border-slate-200 dark:border-slate-800">
                        
                        <div class="mb-5">
                            <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">{{ trans('fornitori.forms.tax_automation_title') }}</h4>
                            <div class="flex items-start gap-3 bg-blue-50/50 dark:bg-blue-900/20 p-3.5 rounded-xl border border-blue-100 dark:border-blue-900/30">
                                <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                    <span v-html="trans('fornitori.forms.tax_automation_desc')" />
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6 bg-white dark:bg-slate-950 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                            <div class="sm:col-span-2">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">{{ trans('fornitori.forms.withholding_rate') }}</Label>
                                <div class="relative">
                                    <Input v-model="form.perc_ritenuta" :placeholder="trans('fornitori.forms.example_4')" class="pr-8 h-10 bg-slate-50 dark:bg-slate-900/50" />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold">%</span>
                                </div>
                                <InputError :message="form.errors.perc_ritenuta" class="mt-1" />
                            </div>
                            
                            <div class="sm:col-span-2">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">{{ trans('fornitori.forms.taxable_base') }}</Label>
                                <div class="relative">
                                    <Input v-model="form.perc_imponibile_ritenuta" :placeholder="trans('fornitori.forms.example_100')" class="pr-8 h-10 bg-slate-50 dark:bg-slate-900/50" />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold">%</span>
                                </div>
                                <InputError :message="form.errors.perc_imponibile_ritenuta" class="mt-1" />
                            </div>
                            
                            <div class="sm:col-span-2">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">{{ trans('fornitori.forms.tax_code') }}</Label>
                                <Input v-model="form.codice_tributo" :placeholder="trans('fornitori.forms.example_1040')" class="h-10 uppercase font-mono bg-slate-50 dark:bg-slate-900/50" />
                                <InputError :message="form.errors.codice_tributo" class="mt-1" />
                            </div>
                        </div>

                    </div>
                </Transition>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('fornitori.forms.company_data_title') }}</CardTitle>
                <CardDescription>{{ trans('fornitori.forms.company_data_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                        <Label for="iscrizione_cciaa">{{ trans('fornitori.forms.cciaa_registration') }}</Label>
                        <Input id="iscrizione_cciaa" v-model="form.iscrizione_cciaa" :placeholder="trans('fornitori.forms.cciaa_registration_placeholder')" class="mt-1 bg-white" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="data_iscrizione_cciaa">{{ trans('fornitori.forms.cciaa_registration_date') }}</Label>
                        <VueDatePicker
                            v-model="form.data_iscrizione_cciaa"
                            class="w-full mt-1 h-10"
                            format="dd/MM/yyyy"
                            position="left" 
                            locale="it"
                            :enable-time-picker="false"
                            auto-apply
                            :placeholder="trans('fornitori.forms.select_date')"
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="capitale_sociale">{{ trans('fornitori.forms.share_capital') }}</Label>
                        <MoneyInput
                            id="capitale_sociale"
                            v-model="form.capitale_sociale"
                            :money-options="moneyOptions"
                            :lazy="true" 
                            placeholder="0,00"
                            class="mt-1"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1 italic">{{ trans('fornitori.forms.example_10000') }}</p>
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="codice_ateco">{{ trans('fornitori.view.labels.ateco_code') }}</Label>
                        <Input id="codice_ateco" v-model="form.codice_ateco" :placeholder="trans('fornitori.view.labels.ateco_code')" class="mt-1 bg-white" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-3">
                        <Label for="categoria_id">{{ trans('fornitori.forms.supplier_category') }}</Label>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="categorie"
                            v-model="form.categoria_id"
                            :reduce="(d: Categoria) => d.id"
                            label="name"
                            :placeholder="trans('fornitori.forms.select_category')"
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="numero_iscrizione_ordine">{{ trans('fornitori.forms.professional_register') }}</Label>
                        <Input id="numero_iscrizione_ordine" v-model="form.numero_iscrizione_ordine" :placeholder="trans('fornitori.forms.professional_register_placeholder')" class="mt-1 bg-white" />
                    </div>

                    <div class="sm:col-span-6 mt-2">
                        <div class="flex items-center space-x-2 p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                            <Checkbox 
                                id="certificazione_iso" 
                                v-model="form.certificazione_iso"
                                @update:checked="(val: boolean ) => form.certificazione_iso = val"
                            />
                            <Label for="certificazione_iso" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                {{ trans('fornitori.forms.iso_certification') }}
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
                {{ trans('fornitori.actions.cancel') }}
            </Link>

            <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Plus v-else class="h-4 w-4" />
                {{ trans('fornitori.actions.save_fornitore') }}
            </Button>
        </div>

      </form>
      
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
