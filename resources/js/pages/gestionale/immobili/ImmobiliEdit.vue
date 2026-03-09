<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Home, Hash, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';
import type { Immobile } from '@/types/gestionale/immobili';
import type { TipologiaImmobile } from '@/types/gestionale/tipologie-immobili';
import { trans } from 'laravel-vue-i18n';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  palazzine: Palazzina[];
  scale: Scala[];
  tipologie: TipologiaImmobile[]
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: trans('gestionale.list_pages.immobili.edit.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.edit.guides.update_title'),
    description: trans('gestionale.list_pages.immobili.edit.guides.update_description'),
    icon: Home,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.edit.guides.consistency_title'),
    description: trans('gestionale.list_pages.immobili.edit.guides.consistency_description'),
    icon: Info,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.edit.guides.land_title'),
    description: trans('gestionale.list_pages.immobili.edit.guides.land_description'),
    icon: Hash,
    colorVariant: 'amber' as const
  }
]);

const form = useForm({
  nome: props.immobile.nome,
  descrizione: props.immobile.descrizione,
  note: props.immobile.note,
  comune_catasto: props.immobile.comune_catasto,
  codice_catasto: props.immobile.codice_catasto,
  sezione_catasto: props.immobile.sezione_catasto,
  foglio_catasto: props.immobile.foglio_catasto,
  particella_catasto: props.immobile.particella_catasto,
  subalterno_catasto: props.immobile.subalterno_catasto,
  interno: props.immobile.interno,
  piano: props.immobile.piano,
  superficie: props.immobile.superficie,
  numero_vani: props.immobile.numero_vani,
  palazzina_id: props.immobile.palazzina ? props.immobile.palazzina.id : '',
  scala_id: props.immobile.scala ? props.immobile.scala.id : '',
  tipologia_id: props.immobile.tipologia ? props.immobile.tipologia.id : '',
});

const submit = () => {
    form.put(route(...generateRoute('gestionale.immobili.update', { condominio: props.condominio.id, immobile: props.immobile.id })), {
        preserveScroll: true,
    });
};

</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.edit.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.edit.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.edit.page_subtitle_named', { name: props.immobile.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id })"
        :back-text="trans('gestionale.list_pages.immobili.edit.back_to_detail')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.edit.sections.location_title') }}</CardTitle>
            <CardDescription>{{ trans('gestionale.list_pages.immobili.edit.sections.location_description') }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              
              <div class="sm:col-span-3">
                <Label for="nome">{{ trans('gestionale.list_pages.immobili.create.labels.name') }}</Label>
                <Input 
                    id="nome" 
                    v-model="form.nome" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                    v-on:focus="form.clearErrors('nome')"
                />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="sm:col-span-3">
                <Label for="tipologia">{{ trans('gestionale.list_pages.immobili.create.labels.type') }}</Label>
                <v-select 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm"
                  :options="tipologie" label="nome" v-model="form.tipologia_id"
                  :reduce="(t: TipologiaImmobile) => t.id"
                  :placeholder="trans('gestionale.form_common.placeholders.select_one')"
                  @update:modelValue="form.clearErrors('tipologia_id')"
                />
                <InputError :message="form.errors.tipologia_id" />
              </div>

              <div class="sm:col-span-6">
                <Label for="descrizione">{{ trans('gestionale.form_common.labels.description') }}</Label>
                <Input 
                    id="descrizione" 
                    v-model="form.descrizione" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                    v-on:focus="form.clearErrors('descrizione')"
                />
                <InputError :message="form.errors.descrizione" />
              </div>

              <div class="sm:col-span-3">
                <Label for="palazzina">{{ trans('gestionale.list_pages.immobili.create.labels.building') }}</Label>
                <v-select 
                    class="mt-1 bg-white dark:bg-slate-950 text-sm" 
                    :options="palazzine" 
                    label="name" 
                    v-model="form.palazzina_id" 
                    :reduce="(p: Palazzina) => p.id" 
                    :placeholder="trans('gestionale.list_pages.immobili.create.placeholders.select_building')"
                />
              </div>

              <div class="sm:col-span-3">
                <Label for="scala">{{ trans('gestionale.list_pages.immobili.create.labels.stair') }}</Label>
                <v-select 
                    class="mt-1 bg-white dark:bg-slate-950 text-sm" 
                    :options="scale" 
                    label="name" 
                    v-model="form.scala_id" 
                    :reduce="(s: Scala) => s.id" 
                    :placeholder="trans('gestionale.list_pages.immobili.create.placeholders.select_stair')"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.edit.sections.structure_title') }}</CardTitle>
            <CardDescription>{{ trans('gestionale.list_pages.immobili.edit.sections.structure_description') }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-8">
              
              <div class="sm:col-span-2">
                <Label for="interno">{{ trans('gestionale.form_common.labels.unit') }}</Label>
                <Input 
                    id="interno" 
                    v-model="form.interno" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono" 
                    v-on:focus="form.clearErrors('interno')"
                />
                <InputError :message="form.errors.interno" />
              </div>
              
              <div class="sm:col-span-2">
                <Label for="piano">{{ trans('gestionale.form_common.labels.floor') }}</Label>
                <Input 
                    id="piano" 
                    v-model="form.piano" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono" 
                />
              </div>
              
              <div class="sm:col-span-2">
                <Label for="superficie">{{ trans('gestionale.form_common.labels.surface') }} (m²)</Label>
                <Input 
                    id="superficie" 
                    v-model="form.superficie" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>
              
              <div class="sm:col-span-2">
                <Label for="numero_vani">{{ trans('gestionale.form_common.labels.rooms') }}</Label>
                <Input 
                    id="numero_vani" 
                    v-model="form.numero_vani" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>

              <div class="sm:col-span-8">
                <Label for="note">{{ trans('gestionale.form_common.labels.notes') }}</Label>
                <Textarea 
                    id="note" 
                    v-model="form.note" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>

            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
              <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.immobili.edit.sections.land_title') }}</CardTitle>
              <CardDescription>{{ trans('gestionale.list_pages.immobili.edit.sections.land_description') }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-4">
              
              <div class="sm:col-span-3 font-sans">
                <Label for="comune_catasto">{{ trans('gestionale.list_pages.immobili.create.labels.land_city') }}</Label>
                <Input 
                    id="comune_catasto" 
                    v-model="form.comune_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="codice_catasto">{{ trans('gestionale.list_pages.immobili.create.labels.land_code') }}</Label>
                <Input 
                    id="codice_catasto" 
                    v-model="form.codice_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono uppercase" 
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="sezione_catasto">{{ trans('gestionale.struttura.sections.section') }}</Label>
                <Input 
                    id="sezione_catasto" 
                    v-model="form.sezione_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono uppercase" 
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="foglio_catasto">{{ trans('gestionale.struttura.sections.sheet') }}</Label>
                <Input 
                    id="foglio_catasto" 
                    v-model="form.foglio_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono uppercase" 
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="particella_catasto">{{ trans('gestionale.struttura.sections.parcel') }}</Label>
                <Input 
                    id="particella_catasto" 
                    v-model="form.particella_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono uppercase" 
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="subalterno_catasto">{{ trans('gestionale.list_pages.immobili.create.labels.subaltern') }}</Label>
                <Input 
                    id="subalterno_catasto" 
                    v-model="form.subalterno_catasto" 
                    class="mt-1 bg-white dark:bg-slate-950 font-mono uppercase" 
                />
              </div>

            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3 pt-2">
          <Link
            :href="generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
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
            {{ trans('gestionale.list_pages.immobili.edit.actions.save') }}
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
