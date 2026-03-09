<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Info, UserCheck, CalendarDays, Coins, List } from 'lucide-vue-next';
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
import type { Anagrafica, AnagraficaWithPivot } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  anagrafiche: Anagrafica[];
  anagrafica: AnagraficaWithPivot;
}>();

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: trans('gestionale.list_pages.immobili.anagrafiche.edit.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.role_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.role_description'),
    icon: UserCheck,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.quota_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.quota_description'),
    icon: Coins,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.period_title'),
    description: trans('gestionale.list_pages.immobili.anagrafiche.edit.guides.period_description'),
    icon: CalendarDays,
    colorVariant: 'amber' as const
  }
]);

const tipologiaList = [
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.owner'), id: 'proprietario' },
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.tenant'), id: 'inquilino' },
  { label: trans('gestionale.list_pages.immobili.anagrafiche.types.usufructuary'), id: 'usufruttuario' }
];

const form = useForm({
  tipologia: props.anagrafica.pivot?.tipologia ?? '',
  data_inizio: props.anagrafica.pivot?.data_inizio ?? '',
  data_fine: props.anagrafica.pivot?.data_fine ?? '',
  quota: props.anagrafica.pivot?.quota ?? '',
  note: props.anagrafica.pivot?.note ?? '',
  anagrafica_id: props.anagrafica.id ?? '',
});

const submit = () => {
    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);
    
    form.put(route(...generateRoute('gestionale.immobili.anagrafiche.update', { 
        condominio: props.condominio.id, 
        immobile: props.immobile.id,
        anagrafica: props.anagrafica.id
    })), {
        preserveScroll: true,
    });
};
</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.anagrafiche.edit.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.anagrafiche.edit.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.anagrafiche.edit.page_subtitle_named', { name: immobile.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
        :back-text="trans('gestionale.list_pages.immobili.anagrafiche.edit.back_to_list')"
      />

      <ImmobileLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.sections.subject_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.sections.subject_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-6">
                    <Label for="anagrafica_id" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.registry_associated') }}</Label>
                    <v-select
                      id="anagrafica_id"
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="anagrafiche"
                      v-model="form.anagrafica_id"
                      :reduce="(d: Anagrafica) => d.id"
                      label="nome"
                      :placeholder="trans('gestionale.form_common.placeholders.select_one')"
                      disabled
                    >
                      <template #selected-option="{ nome, cognome }">
                         <span class="font-bold">{{ nome }} {{ cognome }}</span>
                      </template>
                    </v-select>
                    <p class="text-[10px] text-slate-400 mt-1 italic">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.messages.registry_not_editable') }}</p>
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="tipologia" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.type') }}</Label>
                    <v-select
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="tipologiaList"
                      label="label"
                      v-model="form.tipologia"
                      :reduce="(d: DropdownType) => d.id"
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.select_type')"
                    />
                    <InputError :message="form.errors.tipologia" />
                  </div>

                  <div class="sm:col-span-3">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="quota" class="font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.quota_competence') }}</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                           <div class="space-y-2">
                             <p class="text-sm">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.help.quota_description') }}</p>
                             <Separator class="my-2" />
                             <p class="text-[11px] text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.help.quota_example') }}</p>
                           </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <Input
                      id="quota" 
                      :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.quota')" 
                      v-model="form.quota" 
                      class="w-full bg-white dark:bg-slate-950 font-mono"
                    />
                    <InputError :message="form.errors.quota" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.sections.validity_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.sections.validity_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                  
                  <div>
                    <Label for="data_inizio" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.start_date') }}</Label>
                    <VueDatePicker
                      v-model="form.data_inizio"
                      class="w-full"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                    />
                    <InputError :message="form.errors.data_inizio" />
                  </div>

                  <div>
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="data_fine" class="font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.end_date') }}</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-amber-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                          <p class="text-sm">{{ trans('gestionale.list_pages.immobili.anagrafiche.edit.help.takeover_description') }}</p>
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
                    />
                    <InputError :message="form.errors.data_fine" />
                  </div>

                  <div class="sm:col-span-2 mt-2 mb-2 border-t border-dashed"></div>

                  <div class="sm:col-span-2">
                    <Label for="note" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.anagrafiche.labels.internal_notes') }}</Label>
                    <Textarea 
                        id="note" 
                        class="w-full mt-1 bg-white dark:bg-slate-950 resize-none" 
                        rows="3"
                        :placeholder="trans('gestionale.list_pages.immobili.anagrafiche.placeholders.notes')" 
                        v-model="form.note" 
                    />
                    <InputError :message="form.errors.note" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
                  class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-[10px] font-bold uppercase tracking-widest hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                {{ trans('gestionale.form_common.actions.cancel') }}
              </Link>

              <Button 
                  type="submit"
                  :disabled="form.processing" 
                  class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
              >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <Save v-else class="h-4 w-4" />
                  {{ trans('gestionale.list_pages.immobili.anagrafiche.edit.actions.save_changes') }}
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>
   </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
