<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Info, UserCheck, CalendarDays, Coins } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useDateConverter } from '@/composables/useDateConverter';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Anagrafica } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  anagrafiche: Anagrafica[];
}>();

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: trans('gestionale.list_pages.immobili.anagrafiche.new.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.association_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.association_description'),
    icon: UserCheck,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.quota_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.quota_description'),
    icon: Coins,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.period_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.new.guides.period_description'),
    icon: CalendarDays,
    colorVariant: 'amber' as const
  }
]);

const tipologia = [
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.owner'), id: 'proprietario' },
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.tenant'), id: 'inquilino' },
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.usufructuary'), id: 'usufruttuario' }
];

const form = useForm({
  tipologia: '',
  data_inizio: '',
  data_fine: '',
  quota: '100.00',
  note: '',
  anagrafica_id: '',
});

const submit = () => {
    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);
    
    form.post(route(...generateRoute('gestionale.immobili.anagrafiche.store', { condominio: props.condominio.id, immobile: props.immobile.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.anagrafiche.new.head_title')" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.anagrafiche.new.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.anagrafiche.new.page_subtitle_named', { name: immobile.nome, unit: immobile.interno })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
        :back-text="trans('gestionale.list_pages.immobili.anagrafiche.new.back_to_list')"
      />

      <ImmobileLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.sections.subject_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.sections.subject_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-6">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="anagrafica_id">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.registry') }}</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.available_subjects_title') }}
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.available_subjects_description') }}</p>
                              <Separator class="my-2"/>
                              <div class="text-xs">
                                <span class="font-semibold text-slate-700">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.missing_someone') }}</span><br>
                                {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.go_to_registry_prefix') }}
                                <Link :href="generatePath('anagrafiche')" class="text-indigo-600 hover:underline">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.registry_link') }}</Link>
                                {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.go_to_registry_suffix') }}
                              </div>
                            </div>
                          </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>
                    
                    <v-select
                      id="anagrafica_id"
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="anagrafiche"
                      v-model="form.anagrafica_id"
                      :reduce="(d: Anagrafica) => d.id"
                      label="nome"
                      :placeholder="trans('gestionale.form_common.placeholders.search_or_select')"
                    >
                      <template #option="{ nome, cognome, indirizzo }">
                        <div class="flex flex-col py-0.5">
                          <span class="font-medium text-sm">{{ nome }} {{ cognome }}</span>
                          <span class="text-[11px] text-slate-400 truncate">{{ indirizzo }}</span>
                        </div>
                      </template>
                    </v-select>
                    <InputError :message="form.errors.anagrafica_id" />
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="tipologia" class="mb-1.5 block">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.type') }}</Label>
                    <v-select
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="tipologia"
                      label="label"
                      v-model="form.tipologia"
                      :reduce="(d: DropdownType) => d.id"
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.select_type')"
                    />
                    <InputError :message="form.errors.tipologia" />
                  </div>

                  <div class="sm:col-span-3">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="quota">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.quota_competence') }}</Label>
                      
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.quota_title') }}
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p><strong class="text-red-500 dark:text-red-400">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.warning_label') }}</strong> {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.warning_text') }}</p>
                              <p>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.quota_description') }}</p>
                              <ul class="list-disc pl-4 space-y-1 text-xs">
                                <li><strong>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.quota_example_single') }}</strong></li>
                                <li><strong>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.quota_example_shared') }}</strong></li>
                              </ul>
                            </div>
                          </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <Input
                      id="quota" 
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.quota')" 
                      v-model="form.quota" 
                      class="w-full bg-white dark:bg-slate-950"
                      v-on:focus="form.clearErrors('quota')"
                    />
                    <InputError :message="form.errors.quota" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.sections.validity_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.sections.validity_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                  
                  <div>
                    <Label for="data_inizio" class="mb-1.5 block">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.start_date') }}</Label>
                    <VueDatePicker
                      v-model="form.data_inizio"
                      class="w-full mt-1"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.start_date')"
                    />
                    <InputError :message="form.errors.data_inizio" />
                  </div>

                  <div>
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="data_fine">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.end_date_optional') }}</Label>
                      
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.takeover_title') }}
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.takeover_description_1') }}</p>
                              <p>{{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.takeover_description_2') }}</p>
                              <Separator class="my-2"/>
                              <div class="text-xs text-slate-400 italic">
                                {{ trans('gestionale.list_pages.immobili.anagrafiche.new.help.takeover_hint') }}
                              </div>
                            </div>
                          </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <VueDatePicker
                      v-model="form.data_fine"
                      class="w-full"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.end_date_empty_if_active')"
                    />
                    <InputError :message="form.errors.data_fine" />
                  </div>

                  <div class="sm:col-span-2 mt-2 mb-2 border-t border-dashed"></div>

                  <div class="sm:col-span-2">
                    <Label for="note" class="mb-1.5 block">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.internal_notes') }}</Label>
                    <Textarea 
                        id="note" 
                        class="w-full mt-1 bg-white dark:bg-slate-950 resize-none" 
                        rows="3"
                        :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.notes')" 
                        v-model="form.note" 
                        v-on:focus="form.clearErrors('note')"
                    />
                    <InputError :message="form.errors.note" />
                    <p class="text-[11px] text-muted-foreground mt-1 italic">{{ trans('gestionale.list_pages.immobili.anagrafiche.new.messages.notes_not_printed') }}</p>
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
                  class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                {{ trans('gestionale.form_common.actions.cancel') }}
              </Link>

              <Button 
                  type="submit"
                  :disabled="form.processing" 
                  class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
              >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <Plus v-else class="h-4 w-4" />
                  {{ trans('gestionale.list_pages.immobili.anagrafiche.new.actions.save') }}
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>

   </div>

  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
