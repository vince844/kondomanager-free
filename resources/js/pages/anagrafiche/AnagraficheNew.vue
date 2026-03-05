<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Info, User, MapPin, ShieldCheck, FileText } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { DocumentType } from '@/types/anagrafiche';

const props = defineProps<{
  condomini: Building[];
}>();  

const { generateRoute } = usePermission();

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: trans('anagrafiche.header.list_residents_title'),
    href: route(generateRoute('anagrafiche.index')),
  },
  {
    title: trans('anagrafiche.header.new_resident_head'),
    href: '#',
  }
];

const pageGuides = computed(() => [
  {
    title: trans('anagrafiche.guides.centralized_book_title'),
    description: trans('anagrafiche.guides.centralized_book_desc'),
    icon: User,
    colorVariant: 'blue' as const
  },
  {
    title: trans('anagrafiche.guides.building_link_title'),
    description: trans('anagrafiche.guides.building_link_desc'),
    icon: MapPin,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('anagrafiche.guides.fiscal_data_title'),
    description: trans('anagrafiche.guides.fiscal_data_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  }
]);

const documents = computed(() => [
  {
    label: trans('anagrafiche.label.passport'),
    id: 'passport',
  },
  {
    label: trans('anagrafiche.label.id_card'),
    id: 'id_card',
  }
]);

const form = useForm({
  nome: '',
  indirizzo: '',
  email: '',
  email_secondaria: '',
  pec: '',
  codice_fiscale: '',
  tipologia_documento: '',
  numero_documento: '',
  scadenza_documento: '',
  luogo_nascita: '',
  data_nascita: '',
  telefono: '',
  cellulare: '',
  note: '',
  condomini: [],
});

const submit = () => {
  form.post(route(generateRoute('anagrafiche.store')), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
    }
  });
};

</script>

<template>
  <Head :title="trans('anagrafiche.header.new_resident_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
  
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        :page-title="trans('anagrafiche.header.new_resident_title')"
        :page-subtitle="trans('anagrafiche.header.new_resident_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="route(generateRoute('anagrafiche.index'))"
        back-text="Indietro all'elenco"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{trans('anagrafiche.header.resident_info_heading')}}</CardTitle>
                <CardDescription>{{trans('anagrafiche.header.resident_info_description')}}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <Label for="nome">{{trans('anagrafiche.label.name')}}</Label>
                        <Input 
                            id="nome" 
                            v-model="form.nome" 
                            class="mt-1 bg-white" 
                            :placeholder="trans('anagrafiche.placeholder.name')" 
                            @focus="form.clearErrors('nome')" 
                        />
                        <InputError :message="form.errors.nome" />
                    </div>

                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-2 min-h-[24px]">
                            <Label for="stato">{{trans('anagrafiche.label.buildings')}}</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="cursor-pointer flex items-center outline-none">
                                        <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 z-50">
                                    <div class="space-y-3">
                                        <h4 class="text-sm font-semibold flex items-center gap-2 text-slate-900 dark:text-slate-100">
                                            <Info class="w-4 h-4 text-indigo-500" /> 
                                            {{ trans('anagrafiche.tooltip.buildings_header') }}
                                        </h4>
                                        <div class="text-sm space-y-2 text-slate-500 dark:text-slate-400">
                                            <p>{{ trans('anagrafiche.tooltip.buildings_description') }}</p>
                                            
                                            <Separator class="my-2 border-slate-100 dark:border-slate-800"/>
                                            
                                            <div class="text-xs">
                                                <span class="font-semibold text-slate-700 dark:text-slate-300">Manca un condominio?</span><br>
                                                Vai in <Link :href="route('condomini.index')" class="text-indigo-600 dark:text-indigo-400 hover:underline">gestione condomini</Link> per registrarlo a sistema.
                                            </div>
                                        </div>
                                    </div>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                        <v-select
                            multiple
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="condomini"
                            label="nome"
                            v-model="form.condomini"
                            :reduce="(option: Building) => option.id"
                            :placeholder="trans('anagrafiche.placeholder.buildings')" 
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="luogo_nascita">{{trans('anagrafiche.label.birth_place')}}</Label>
                        <Input 
                            id="luogo_nascita" 
                            v-model="form.luogo_nascita" 
                            class="mt-1 bg-white" 
                            :placeholder="trans('anagrafiche.placeholder.birth_place')" 
                            @focus="form.clearErrors('luogo_nascita')" 
                        />
                        <InputError :message="form.errors.luogo_nascita" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="data_nascita">{{trans('anagrafiche.label.birthday')}}</Label>
                        <VueDatePicker
                            v-model="form.data_nascita"
                            class="w-full mt-1 h-10"
                            format="dd/MM/yyyy"
                            position="left" 
                            locale="it"
                            :enable-time-picker="false"
                            auto-apply
                            :placeholder="trans('anagrafiche.placeholder.birthday')" 
                        />
                        <InputError :message="form.errors.data_nascita" />
                    </div>

                    <div class="sm:col-span-6">
                        <Label for="note">{{trans('anagrafiche.label.notes')}}</Label>
                        <Textarea 
                            id="note" 
                            class="mt-1 w-full bg-white dark:bg-slate-950" 
                            :placeholder="trans('anagrafiche.placeholder.notes')" 
                            v-model="form.note" 
                            @focus="form.clearErrors('note')" 
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
              <CardTitle class="text-base font-semibold">Recapiti e sede</CardTitle>
              <CardDescription>Indirizzo di residenza e contatti principali dell'anagrafica.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
              <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  
                  <div class="sm:col-span-6">
                      <Label for="indirizzo">{{trans('anagrafiche.label.address')}}</Label>
                      <Input 
                          id="indirizzo" 
                          v-model="form.indirizzo" 
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.address')" 
                          @focus="form.clearErrors('indirizzo')" 
                      />
                      <InputError :message="form.errors.indirizzo" />
                  </div>

                  <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                  <div class="sm:col-span-2">
                      <Label for="telefono">{{trans('anagrafiche.label.telephone')}}</Label>
                      <Input 
                          id="telefono" 
                          v-model="form.telefono" 
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.telephone')" 
                          @focus="form.clearErrors('telefono')" 
                      />
                      <InputError :message="form.errors.telefono" />
                  </div>

                  <div class="sm:col-span-2">
                      <Label for="cellulare">{{trans('anagrafiche.label.mobile')}}</Label>
                      <Input 
                          id="cellulare" 
                          v-model="form.cellulare" 
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.mobile')" 
                          @focus="form.clearErrors('cellulare')" 
                      />
                      <InputError :message="form.errors.cellulare" />
                  </div>

                  <div class="hidden sm:block sm:col-span-2"></div>

                  <div class="sm:col-span-2">
                      <Label for="email">{{trans('anagrafiche.label.primary_email')}}</Label>
                      <Input 
                          id="email" 
                          v-model="form.email"
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.primary_email')" 
                          @focus="form.clearErrors('email')" 
                      />
                      <InputError :message="form.errors.email" />
                  </div>

                  <div class="sm:col-span-2">
                      <Label for="email_secondaria">{{trans('anagrafiche.label.secondary_email')}}</Label>
                      <Input 
                          id="email_secondaria" 
                          v-model="form.email_secondaria" 
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.secondary_email')" 
                          @focus="form.clearErrors('email_secondaria')" 
                      />
                      <InputError :message="form.errors.email_secondaria" />
                  </div>

                  <div class="sm:col-span-2">
                      <Label for="pec">{{trans('anagrafiche.label.pec')}}</Label>
                      <Input 
                          id="pec" 
                          v-model="form.pec" 
                          class="mt-1 bg-white" 
                          :placeholder="trans('anagrafiche.placeholder.pec')" 
                          @focus="form.clearErrors('pec')" 
                      />
                      <InputError :message="form.errors.pec" />
                  </div>

              </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{trans('anagrafiche.header.resident_fiscal_heading')}}</CardTitle>
                <CardDescription>{{trans('anagrafiche.header.resident_fiscal_description')}}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-2">
                        <Label for="codice_fiscale">{{trans('anagrafiche.label.fiscal_code')}}</Label>
                        <Input
                            id="codice_fiscale" 
                            v-model="form.codice_fiscale" 
                            class="mt-1 bg-white" 
                            :placeholder="trans('anagrafiche.placeholder.fiscal_code')" 
                            @focus="form.clearErrors('codice_fiscale')" 
                        />
                        <InputError :message="form.errors.codice_fiscale" />
                    </div>

                    <div class="hidden sm:block sm:col-span-4"></div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-2">
                        <Label for="tipologia_documento">{{trans('anagrafiche.label.document_type')}}</Label>
                        <v-select
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="documents"
                            label="label"
                            v-model="form.tipologia_documento"
                            :reduce="(d: DocumentType) => d.id"
                            :placeholder="trans('anagrafiche.placeholder.document_type')" 
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="numero_documento">{{trans('anagrafiche.label.document_number')}}</Label>
                        <Input 
                            id="numero_documento" 
                            v-model="form.numero_documento" 
                            class="mt-1 bg-white" 
                            :placeholder="trans('anagrafiche.placeholder.document_number')" 
                            @focus="form.clearErrors('numero_documento')" 
                        />
                        <InputError :message="form.errors.numero_documento" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="scadenza_documento">{{trans('anagrafiche.label.document_expiry')}}</Label>
                        <VueDatePicker
                            v-model="form.scadenza_documento"
                            class="w-full mt-1 h-10"
                            format="dd/MM/yyyy"
                            position="left" 
                            locale="it"
                            :enable-time-picker="false"
                            auto-apply
                            :placeholder="trans('anagrafiche.placeholder.document_expiry')" 
                        />
                        <InputError :message="form.errors.scadenza_documento" />
                    </div>

                </div>
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3 mt-6">
            <Link
                :href="route(generateRoute('anagrafiche.index'))"
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
                {{trans('anagrafiche.actions.save_resident')}}
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>