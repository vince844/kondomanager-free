<script setup lang="ts">
  
import { computed, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, UploadCloud, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import vSelect from "vue-select";
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
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'crea documento', href: '#' },
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
      alert("Solo file PDF sono ammessi.")
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
  <Head title="Crea documento immobile" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <ImmobileLayout>
      <form class="space-y-2" @submit.prevent="submit">
        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>

          <Link
            as="button"
            :href="generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: props.condominio.id, immobile: props.immobile.id })"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>Elenco</span>
          </Link>
        </div>

        <Separator class="my-4" />

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">
          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome">Nome documento</Label>
                  <Input 
                    id="name" 
                    class="mt-1 block w-full"
                    v-model="form.name" 
                    v-on:focus="form.clearErrors('name')"
                    placeholder="Nome documento" 
                  />
                  <InputError :message="form.errors.name" />
                </div>
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="nome">Descrizione documento</Label>
                  <Textarea 
                    id="description" 
                    class="mt-1 block w-full min-h-[200px]"
                    v-model="form.description" 
                    v-on:focus="form.clearErrors('description')"
                    placeholder="Descrizone documento" 
                  />
                  <InputError :message="form.errors.description" />
                </div>     
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="file-upload">Seleziona documento</Label>
                  <label
                    for="file-upload"
                    class="mt-2 flex flex-col items-center justify-center w-full h-48 p-6 border-2 border-dashed rounded-lg cursor-pointer bg-white dark:bg-gray-900 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                  >
                    <UploadCloud class="w-10 h-10 mb-2 text-gray-400" />
                    <span class="text-gray-500 dark:text-gray-400 text-center">
                      <strong>Clicca qui per selezionare il documento</strong>
                    </span>
                    <input
                      id="file-upload"
                      type="file"
                      class="hidden"
                      accept="application/pdf"
                      @change="handleFileChange"
                    />
                  </label>

                  <div v-if="file" class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    File selezionato: <strong>{{ file.name }}</strong>
                    <button 
                      type="button" 
                      @click="removeFile" 
                      class="ml-2 text-red-500 hover:text-red-700"
                    >
                      Rimuovi
                    </button>
                  </div>
                  <InputError :message="form.errors.file" />

                  <!-- Progress bar -->
                  <div v-if="progress !== null" class="mt-4">
                    <div class="w-full h-2 bg-gray-200 rounded overflow-hidden">
                      <div
                        class="h-full bg-blue-600 transition-all duration-300"
                        :style="{ width: `${progress}%` }"
                      ></div>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">{{ progress }}%</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Side Card (1/4 width) -->
          <div class="col-span-1 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">
              <div class="grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                    <Label for="stato">Stato pubblicazione</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-muted-foreground" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-80">
                        <div class="flex justify-between space-x-4">
                          <div class="space-y-1">
                            <h4 class="text-sm font-semibold">
                              Stato pubblicazione
                            </h4>
                            <p class="text-sm">
                              Scegli se rendere visibile il documento o mantenerlo nascosto.
                            </p>
                          </div>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <v-select 
                    :options="publishedConstants" 
                    label="label" 
                    v-model="form.is_published"
                    placeholder="Stato pubblicazione"
                    @update:modelValue="form.clearErrors('is_published')" 
                    :reduce="(is_published: PublishedType) => is_published.value"
                  />
                  <InputError :message="form.errors.is_published" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </ImmobileLayout>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>