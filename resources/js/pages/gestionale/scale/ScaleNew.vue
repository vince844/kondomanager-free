<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, ListOrdered, Building2, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings'
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.scale.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.scale.breadcrumbs.structure'), href: generatePath('gestionale/:condominio/scale', { condominio: props.condominio.id }) },
  { title: trans('gestionale.list_pages.scale.create.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.scale.guides.internal_title'),
    description: trans('gestionale.list_pages.scale.guides.internal_description'),
    icon: ListOrdered,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.scale.guides.elevator_title'),
    description: trans('gestionale.list_pages.scale.guides.elevator_description'),
    icon: Building2,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.scale.guides.isolation_title'),
    description: trans('gestionale.list_pages.scale.guides.isolation_description'),
    icon: Info,
    colorVariant: 'amber' as const
  }
]);

const form = useForm({
  name: '',
  description: '',
  note: '',
  palazzina_id: '',
});

const submit = () => {
    form.post(route(...generateRoute('gestionale.scale.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head :title="trans('gestionale.list_pages.scale.create.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">
      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.scale.create.page_title')"
        :page-subtitle="trans('gestionale.list_pages.scale.create.page_subtitle', { condominio: props.condominio.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/scale', { condominio: props.condominio.id })"
        :back-text="trans('gestionale.list_pages.scale.create.back_to_list')"
      />

      <StrutturaLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.scale.create.card_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.scale.create.card_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-6">
                  
                  <div class="sm:col-span-3">
                    <Label for="nome" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.scale.create.labels.name') }}</Label>
                    <Input 
                      id="nome" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.name" 
                      v-on:focus="form.clearErrors('name')"
                      :placeholder="trans('gestionale.list_pages.scale.create.placeholders.name')" 
                    />
                    <InputError :message="form.errors.name" />
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="palazzina" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.scale.create.labels.building') }}</Label>
                    <v-select 
                        :options="props.palazzine" 
                        label="name" 
                        class="mt-1 block w-full bg-white dark:bg-slate-950 text-sm"
                        v-model="form.palazzina_id"
                        :placeholder="trans('gestionale.list_pages.scale.create.placeholders.select_building')"
                        @update:modelValue="form.clearErrors('palazzina_id')" 
                        :reduce="(palazzina: Palazzina) => palazzina.id"
                    />
                    <InputError :message="form.errors.palazzina_id" />
                  </div>

                  <div class="sm:col-span-6">
                    <Label for="descrizione" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.scale.create.labels.description') }}</Label>
                    <Input 
                      id="descrizione" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.description" 
                      v-on:focus="form.clearErrors('description')"
                      :placeholder="trans('gestionale.list_pages.scale.create.placeholders.description')" 
                    />
                    <InputError :message="form.errors.description" />
                  </div>

                  <div class="sm:col-span-6">
                    <Label for="note" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.scale.create.labels.notes') }}</Label>
                    <Textarea 
                      id="note" 
                      class="w-full mt-1 bg-white dark:bg-slate-950 resize-none" 
                      rows="3"
                      :placeholder="trans('gestionale.list_pages.scale.create.placeholders.notes_internal')"
                      v-model="form.note" 
                      v-on:focus="form.clearErrors('note')"
                    />
                    <InputError :message="form.errors.note" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                  :href="generatePath('gestionale/:condominio/scale', { condominio: props.condominio.id })"
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
                  <Plus v-else class="h-4 w-4" />
                  {{ trans('gestionale.list_pages.scale.create.actions.save_scale') }}
              </Button>
            </div>

          </form>

        </div>
      </StrutturaLayout>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
