<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { UploadCloud, LoaderCircle, Info, FileText, Share2, Eye } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Immobile } from '@/types/gestionale/immobili';
import type { BaseDocumentForm } from '@/types/documenti'

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: trans('gestionale.list_pages.immobili.documents.create.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.documents.create.guides.name_title'),
    description: trans('gestionale.list_pages.immobili.documents.create.guides.name_description'),
    icon: FileText,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.documents.create.guides.visibility_title'),
    description: trans('gestionale.list_pages.immobili.documents.create.guides.visibility_description'),
    icon: Eye,
    colorVariant: 'amber' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.documents.create.guides.upload_title'),
    description: trans('gestionale.list_pages.immobili.documents.create.guides.upload_description'),
    icon: UploadCloud,
    colorVariant: 'emerald' as const
  }
]);

const file = ref<File | null>(null)
const progress = ref<number | null>(null)

const form = useForm<BaseDocumentForm>({
  name: '',
  description: '',
  is_published: true,
  file: null,
  anagrafiche: []
});

function handleFileChange(event: Event): void {
  const target = event.target as HTMLInputElement
  if (target?.files?.length) {
    const selectedFile = target.files[0]
    if (selectedFile.type !== 'application/pdf') {
      alert(trans('gestionale.list_pages.immobili.documents.messages.pdf_only'))
      return
    }
    file.value = selectedFile
    form.file = selectedFile
    form.clearErrors('file')
  }
}

function removeFile(): void {
  file.value = null
  form.file = null
  form.clearErrors('file')
}

const submit = (): void => {
  form.post(route(...generateRoute('gestionale.immobili.documenti.store', 
  { 
    condominio: props.condominio.id, 
    immobile: props.immobile.id 
  })), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      file.value = null
      progress.value = null
    }
  });
};
</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.documents.create.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.documents.create.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.documents.create.page_subtitle_named', { name: props.immobile.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: props.condominio.id, immobile: props.immobile.id })"
        :back-text="trans('gestionale.list_pages.immobili.documents.create.back_to_list')"
      />

      <ImmobileLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.documents.create.sections.details_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.documents.create.sections.details_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-8">
                    <Label for="name" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('documenti.label.name') }} *</Label>
                    <Input 
                      id="name" 
                      v-model="form.name" 
                      class="w-full bg-white dark:bg-slate-950"
                      :placeholder="trans('gestionale.list_pages.immobili.documents.create.placeholders.name')"
                      v-on:focus="form.clearErrors('name')"
                    />
                    <InputError :message="form.errors.name" />
                  </div>

                  <div class="sm:col-span-4">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="is_published" class="font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('documenti.label.visibility') }}</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                          <p class="text-sm">{{ trans('gestionale.list_pages.immobili.documents.messages.visibility_help') }}</p>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <v-select 
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="publishedConstants" 
                      label="label" 
                      v-model="form.is_published"
                      :reduce="(is_published: PublishedType) => is_published.value"
                      :placeholder="trans('documenti.placeholder.visibility')"
                    >
                      <template #option="{ label, icon }">
                        <div class="flex items-center gap-2">
                            <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                            <span>{{ trans(label) }}</span> 
                        </div>
                      </template>
                      <template #selected-option="{ label, icon }">
                        <div class="flex items-center gap-2">
                            <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                            <span>{{ trans(label) }}</span>
                        </div>
                      </template>
                    </v-select>
                    <InputError :message="form.errors.is_published" />
                  </div>

                  <div class="sm:col-span-12">
                    <Label for="description" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">{{ trans('gestionale.list_pages.immobili.documents.messages.description_optional') }}</Label>
                    <Textarea 
                      id="description" 
                      v-model="form.description" 
                      class="w-full min-h-[100px] bg-white dark:bg-slate-950 resize-none"
                      :placeholder="trans('gestionale.list_pages.immobili.documents.create.placeholders.description')"
                      v-on:focus="form.clearErrors('description')"
                    />
                    <InputError :message="form.errors.description" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ trans('gestionale.list_pages.immobili.documents.create.sections.file_title') }}</CardTitle>
                <CardDescription>{{ trans('gestionale.list_pages.immobili.documents.create.sections.file_description') }}</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1">
                  
                  <div class="sm:col-span-1">
                    <label
                      for="file-upload"
                      class="flex flex-col items-center justify-center w-full h-48 border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl cursor-pointer bg-white dark:bg-slate-950 hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors group"
                    >
                      <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-full mb-3 group-hover:scale-110 transition-transform">
                          <UploadCloud class="w-8 h-8 text-indigo-500" />
                        </div>
                        <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
                          <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ trans('gestionale.list_pages.immobili.documents.messages.click_to_upload') }}</span> {{ trans('gestionale.list_pages.immobili.documents.messages.or_drag_file') }}
                        </p>
                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ trans('gestionale.list_pages.immobili.documents.messages.pdf_short') }}</p>
                      </div>
                      <input
                        id="file-upload"
                        type="file"
                        class="hidden"
                        accept="application/pdf"
                        @change="handleFileChange"
                      />
                    </label>

                    <div v-if="file" class="mt-4 flex items-center justify-between p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                      <div class="flex items-center gap-3 overflow-hidden">
                        <FileText class="w-5 h-5 text-indigo-500 shrink-0" />
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300 truncate">{{ file.name }}</span>
                      </div>
                      <button 
                        type="button" 
                        @click="removeFile" 
                        class="text-[10px] font-bold uppercase tracking-widest text-red-500 hover:text-red-700 transition-colors px-2 py-1 bg-red-50 dark:bg-red-900/20 rounded-md shrink-0"
                      >
                        {{ trans('documenti.label.remove_document') }}
                      </button>
                    </div>
                    <InputError :message="form.errors.file" class="mt-2" />

                    <div v-if="progress !== null" class="mt-4 space-y-1.5">
                      <div class="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div
                          class="h-full bg-indigo-500 transition-all duration-300"
                          :style="{ width: `${progress}%` }"
                        ></div>
                      </div>
                      <p class="text-[10px] font-bold text-slate-500 text-right">{{ trans('gestionale.list_pages.immobili.documents.messages.upload_completed', { percent: progress }) }}</p>
                      
                    </div>
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: props.condominio.id, immobile: props.immobile.id })"
                  class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-[10px] font-bold uppercase tracking-widest hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                {{ trans('documenti.actions.cancel') }}
              </Link>

              <Button 
                  type="submit"
                  :disabled="form.processing || !file" 
                  class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
              >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <UploadCloud v-else class="h-4 w-4" />
                  {{ trans('gestionale.list_pages.immobili.documents.actions.upload_document') }}
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>
   </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
