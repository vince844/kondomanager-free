<script setup lang="ts">

import { computed } from "vue";
import { Head, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { List, Pencil, Building2, Map, FileText, Ruler } from 'lucide-vue-next';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
}>()

const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.view.guides.technical_title'),
    description: trans('gestionale.list_pages.immobili.view.guides.technical_description'),
    icon: Building2,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.view.guides.land_title'),
    description: trans('gestionale.list_pages.immobili.view.guides.land_description'),
    icon: Map,
    colorVariant: 'amber' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.view.guides.notes_title'),
    description: trans('gestionale.list_pages.immobili.view.guides.notes_description'),
    icon: FileText,
    colorVariant: 'emerald' as const
  }
]);
</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.view.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.view.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.view.page_subtitle_named', { name: immobile.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <Link
              :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-4 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <List class="w-3.5 h-3.5" />
              <span>{{ trans('gestionale.form_common.actions.list') }}</span>
            </Link>

            <Link
              :href="generatePath('gestionale/:condominio/immobili/:immobile/edit', { condominio: props.condominio.id, immobile: props.immobile.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <Pencil class="w-3.5 h-3.5" />
              <span>{{ trans('gestionale.form_common.actions.edit') }}</span>
            </Link>
          </div>
        </template>
      </PageHeaderGuide>

      <ImmobileLayout>
        <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          
          <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
              <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.view.sections.general_title') }}</CardTitle>
              <CardDescription>{{ trans('gestionale.list_pages.immobili.view.sections.general_description') }}</CardDescription>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-y-4">
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.type') }}</p>
                <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ immobile.tipologia.nome }}</p>
              </div>
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.unit_floor') }}</p>
                <p class="text-sm font-medium">{{ immobile.interno }} / {{ immobile.piano ?? '-' }}</p>
              </div>
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.building_stair') }}</p>
                <p class="text-sm font-medium">{{ immobile.palazzina?.name ?? '-' }} / {{ immobile.scala?.name ?? '-' }}</p>
              </div>
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1">
                  <Ruler class="w-3 h-3" /> {{ trans('gestionale.list_pages.immobili.view.fields.surface') }}
                </p>
                <p class="text-sm font-medium">{{ immobile.superficie ? immobile.superficie + ' m²' : '-' }}</p>
              </div>
              <div class="sm:col-span-2 pt-2">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.form_common.labels.description') }}</p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1 leading-relaxed">
                  {{ immobile.descrizione || trans('gestionale.list_pages.immobili.view.messages.no_description') }}
                </p>
              </div>
            </CardContent>
          </Card>

          <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
              <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.view.sections.land_title') }}</CardTitle>
              <CardDescription>{{ trans('gestionale.list_pages.immobili.view.sections.land_description') }}</CardDescription>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-y-4">
              <div class="sm:col-span-2 space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.land_city_code') }}</p>
                <p class="text-sm font-medium">{{ immobile.comune_catasto ?? '-' }} <span class="text-slate-400" v-if="immobile.codice_catasto">({{ immobile.codice_catasto }})</span></p>
              </div>
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.section_sheet') }}</p>
                <p class="text-sm font-mono">{{ immobile.sezione_catasto ?? '-' }} / {{ immobile.foglio_catasto ?? '-' }}</p>
              </div>
              <div class="space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ trans('gestionale.list_pages.immobili.view.fields.parcel_sub') }}</p>
                <p class="text-sm font-mono">{{ immobile.particella_catasto ?? '-' }} / {{ immobile.subalterno_catasto ?? '-' }}</p>
              </div>
              <div class="sm:col-span-2 pt-2">
                 <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/50 rounded-lg p-3 flex gap-3">
                    <p class="text-[11px] text-amber-700 dark:text-amber-400 leading-tight">
                      {{ trans('gestionale.list_pages.immobili.view.messages.land_warning') }}
                    </p>
                 </div>
              </div>
            </CardContent>
          </Card>

          <Card class="lg:col-span-2 border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-2">
              <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.view.sections.management_notes_title') }}</CardTitle>
            </CardHeader>
            <CardContent>
              <p class="text-sm text-slate-700 dark:text-slate-300 leading-relaxed italic">
                "{{ immobile.note || trans('gestionale.list_pages.immobili.view.messages.no_notes') }}"
              </p>
            </CardContent>
          </Card>

        </div>
      </ImmobileLayout>
    </div>
  </GestionaleLayout>
</template>
