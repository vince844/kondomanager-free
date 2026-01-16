<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, UploadCloud, Info, FileText, X } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import type { BreadcrumbItem } from '@/types';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Documento } from '@/types/documenti';
import type { Immobile } from '@/types/gestionale/immobili';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  documento: Documento;
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'modifica documento', href: '#' },
]);

const file = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const showFileInput = ref(false)

// Computed per determinare se mostrare il file esistente o l'input
const hasExistingFile = computed(() => {
  return !!(props.documento.path || props.documento.mime_type);
})

const showExistingFile = computed(() => {
  return hasExistingFile.value && !file.value && !showFileInput.value;
})

const form = useForm({
  name: props.documento?.name ?? '',
  description: props.documento?.description ?? '',
  is_published: !!props.documento?.is_published,
  file: null as File | null,
});

// Validazione file
const validateFile = (selectedFile: File): boolean => {
  const allowedTypes = ['application/pdf'];
  const maxSize = 20 * 1024 * 1024; // 20MB
  
  if (!allowedTypes.includes(selectedFile.type)) {
    form.setError('file', 'Sono ammessi solo file PDF');
    return false;
  }
  
  if (selectedFile.size > maxSize) {
    form.setError('file', 'Il file non può superare i 20MB');
    return false;
  }
  
  return true;
}

// Gestione file
const handleFileChange = (event: Event): void => {
  const target = event.target as HTMLInputElement
  const selectedFile = target.files?.[0] || null
  
  if (selectedFile && validateFile(selectedFile)) {
    file.value = selectedFile
    form.file = selectedFile
    showFileInput.value = false
    form.clearErrors('file')
  }
}

const onDrop = (event: DragEvent): void => {
  event.preventDefault()
  const droppedFile = event.dataTransfer?.files[0] || null
  
  if (droppedFile && validateFile(droppedFile)) {
    file.value = droppedFile
    form.file = droppedFile
    showFileInput.value = false
    
    if (fileInputRef.value) {
      const dataTransfer = new DataTransfer()
      dataTransfer.items.add(droppedFile)
      fileInputRef.value.files = dataTransfer.files
    }
    form.clearErrors('file')
  }
}

const removeFile = (): void => {
  file.value = null
  form.file = null
  
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
  
  if (hasExistingFile.value) {
    showFileInput.value = true
  }
  
  form.clearErrors('file')
}

const showFileUpload = (): void => {
  showFileInput.value = true
}

const cancelFileUpload = (): void => {
  showFileInput.value = false
  file.value = null
  form.file = null
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
  form.clearErrors('file')
}

// Utility functions
const formatFileSize = (bytes?: number): string => {
  if (!bytes || bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

const getFileName = (): string => {
  if (props.documento.path) {
    const pathParts = props.documento.path.split('/');
    return pathParts[pathParts.length - 1] || props.documento.name;
  }
  return props.documento.name;
}

const submit = (): void => {
  form.put(route(...generateRoute('gestionale.immobili.documenti.update', 
    { 
      condominio: props.condominio.id, 
      immobile: props.immobile.id,
      documento: props.documento.id
    })), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
    }
  });
};

</script>


<template>
  <Head title="Modifica documento immobile" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Modifica documento archivio"
        description="Compila il seguente modulo per modificare documento per l'archivo del condominio"
      />

      <form @submit.prevent="submit" class="space-y-2">
        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva modifiche
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

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome" class="font-medium">Nome documento</Label>
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
                  <Label for="nome" class="font-medium">Descrizione documento</Label>
                  <Textarea 
                    id="description" 
                    class="mt-1 block w-full min-h-[200px]"
                    v-model="form.description" 
                    v-on:focus="form.clearErrors('description')"
                    placeholder="Descrizione documento" 
                  />
                  <InputError :message="form.errors.description" />
                </div>     
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="file-upload" class="font-medium">Documento</Label>

                  <!-- File esistente -->
                  <div v-if="showExistingFile" class="mt-4">
                    <div class="bg-muted/30 border rounded-lg p-3">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                          <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                            <FileText class="w-5 h-5 text-muted-foreground" />
                          </div>
                          <div>
                            <div class="text-sm font-medium">{{ getFileName() }}</div>
                            <div class="text-xs text-muted-foreground">
                              {{ props.documento.mime_type_label || 'PDF' }}
                              <template v-if="props.documento.file_size">
                                • {{ formatFileSize(props.documento.file_size) }}
                              </template>
                            </div>
                          </div>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          @click="showFileUpload"
                          title="Sostituisci file"
                        >
                          <UploadCloud class="w-4 h-4" />
                        </Button>
                      </div>
                    </div>
                  </div>

                  <!-- Area upload -->
                  <div v-if="showFileInput || !hasExistingFile" class="mt-4">
                    <label
                      for="file-upload"
                      @dragover.prevent
                      @drop="onDrop"
                      class="block cursor-pointer"
                    >
                      <Empty class="border border-dashed hover:bg-accent/10 transition">
                        <EmptyHeader>
                          <EmptyMedia variant="icon">
                            <UploadCloud class="w-8 h-8 text-muted-foreground" />
                          </EmptyMedia>
                          <EmptyTitle>Trascina qui il tuo documento</EmptyTitle>
                          <EmptyDescription>
                            Oppure <strong>clicca</strong> per selezionarlo.
                            <div class="text-xs text-muted-foreground mt-1">
                              Solo PDF, max 20MB
                            </div>
                          </EmptyDescription>
                        </EmptyHeader>
                      </Empty>

                      <input
                        id="file-upload"
                        type="file"
                        class="hidden"
                        accept=".pdf,application/pdf"
                        @change="handleFileChange"
                        ref="fileInputRef"
                      />
                    </label>

                    <div v-if="showFileInput && hasExistingFile" class="mt-2">
                      <Button 
                        type="button" 
                        variant="outline" 
                        size="sm"
                        @click="cancelFileUpload"
                        class="gap-2"
                      >
                        <X class="w-3 h-3" />
                        Annulla
                      </Button>
                    </div>
                  </div>

                  <!-- Nuovo file selezionato -->
                  <div v-if="file" class="mt-4">
                    <div class="border rounded-lg p-3">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                          <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                            <FileText class="w-5 h-5 text-muted-foreground" />
                          </div>
                          <div>
                            <div class="text-sm font-medium">{{ file.name }}</div>
                            <div class="text-xs text-muted-foreground">
                              {{ formatFileSize(file.size) }}
                            </div>
                          </div>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          @click="removeFile"
                          title="Rimuovi"
                        >
                          <X class="w-4 h-4" />
                        </Button>
                      </div>
                      <div v-if="hasExistingFile" class="text-xs text-muted-foreground mt-2">
                        Questo file sostituirà quello esistente.
                      </div>
                    </div>
                  </div>

                  <InputError :message="form.errors.file" />
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
                    class="mt-1"
                  />
                  <InputError :message="form.errors.is_published" />
                </div>
              </div>

              <div class="pt-3 border-t mt-3">
                <h4 class="text-sm font-medium mb-2">Informazioni</h4>
                <div class="space-y-2 text-sm">
                  <div v-if="props.documento.created_at" class="flex justify-between">
                    <span class="text-muted-foreground">Creato:</span>
                    <span>{{ props.documento.created_at }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-muted-foreground">Stato file:</span>
                    <span :class="hasExistingFile ? 'text-green-600' : 'text-amber-600'">
                      {{ hasExistingFile ? 'Presente' : 'Assente' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>