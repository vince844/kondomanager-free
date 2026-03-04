<script setup lang="ts">
import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from '@/composables/permissions';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, UploadCloud, Info, FileText, X, Eye, FileSignature } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
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
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Modifica Documento', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Dettagli',
    description: "Modifica il nome o la descrizione per mantenere l'archivio ordinato.",
    icon: FileSignature,
    colorVariant: 'blue' as const
  },
  {
    title: 'Visibilità',
    description: "Aggiorna i permessi per nascondere o mostrare il file sull'app.",
    icon: Eye,
    colorVariant: 'amber' as const
  },
  {
    title: 'Sostituzione',
    description: "Seleziona un nuovo PDF per aggiornare la versione del documento esistente.",
    icon: UploadCloud,
    colorVariant: 'emerald' as const
  }
]);

const file = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const showFileInput = ref(false)

const hasExistingFile = computed(() => {
  return !!(props.documento.path || props.documento.mime_type);
})

const showExistingFile = computed(() => {
  return hasExistingFile.value && !file.value && !showFileInput.value;
})

// Tipizzazione esplicita per evitare l'errore di TS
type EditDocumentForm = {
  name: string;
  description: string;
  is_published: boolean;
  file: File | null;
  _method?: string; // Necessario per le richieste PUT con multipart/form-data
};

const form = useForm<EditDocumentForm>({
  name: props.documento?.name ?? '',
  description: props.documento?.description ?? '',
  is_published: !!props.documento?.is_published,
  file: null,
  _method: 'PUT' // Fake PUT per Laravel quando si inviano file
});

const validateFile = (selectedFile: File): boolean => {
  const allowedTypes = ['application/pdf'];
  const maxSize = 20 * 1024 * 1024; 
  
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
  if (fileInputRef.value) fileInputRef.value.value = ''
  if (hasExistingFile.value) showFileInput.value = true
  form.clearErrors('file')
}

const showFileUpload = (): void => { showFileInput.value = true }

const cancelFileUpload = (): void => {
  showFileInput.value = false
  file.value = null
  form.file = null
  if (fileInputRef.value) fileInputRef.value.value = ''
  form.clearErrors('file')
}

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
  // Nota: usiamo POST invece di PUT perché i form con file multipart/form-data
  // in Laravel/Inertia spesso falliscono se inviati come PUT reale.
  // Usiamo il trucchetto _method: 'PUT' che abbiamo aggiunto nel form.
  form.post(route(...generateRoute('gestionale.immobili.documenti.update', 
    { 
      condominio: props.condominio.id, 
      immobile: props.immobile.id,
      documento: props.documento.id
    })), {
    preserveScroll: true,
  });
};
</script>

<template>
  <Head title="Modifica documento immobile" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Modifica Documento"
        :page-subtitle="`Aggiorna i dettagli del file: ${props.documento.name}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: props.condominio.id, immobile: props.immobile.id })"
        back-text="Annulla e torna all'elenco"
      />

      <ImmobileLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Dettagli e Permessi</CardTitle>
                <CardDescription>Modifica le informazioni e chi può visualizzare il file.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-8">
                    <Label for="name" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Nome documento *</Label>
                    <Input 
                      id="name" 
                      v-model="form.name" 
                      class="w-full bg-white dark:bg-slate-950"
                      placeholder="es. Contratto di Locazione 2026" 
                      v-on:focus="form.clearErrors('name')"
                    />
                    <InputError :message="form.errors.name" />
                  </div>

                  <div class="sm:col-span-4">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="is_published" class="font-bold text-xs uppercase tracking-widest text-slate-500">Visibilità</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                          <p class="text-sm">Scegli se il documento deve essere pubblicato sull'app dei condòmini associati o rimanere ad uso interno.</p>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <v-select 
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="publishedConstants" 
                      label="label" 
                      v-model="form.is_published"
                      :reduce="(is_published: PublishedType) => is_published.value"
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
                    <Label for="description" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Descrizione (Opzionale)</Label>
                    <Textarea 
                      id="description" 
                      v-model="form.description" 
                      class="w-full min-h-[100px] bg-white dark:bg-slate-950 resize-none"
                      placeholder="Eventuali note o dettagli sul contenuto del documento..." 
                      v-on:focus="form.clearErrors('description')"
                    />
                    <InputError :message="form.errors.description" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Sostituzione File</CardTitle>
                <CardDescription>Carica un nuovo PDF se desideri aggiornare la versione attuale.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1">
                  <div class="sm:col-span-1">
                    
                    <div v-if="showExistingFile" class="flex items-center justify-between p-4 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                      <div class="flex items-center gap-4 overflow-hidden">
                        <div class="p-2 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-indigo-500 shrink-0">
                          <FileText class="w-6 h-6" />
                        </div>
                        <div class="flex flex-col min-w-0">
                          <span class="text-sm font-semibold text-slate-700 dark:text-slate-300 truncate">{{ getFileName() }}</span>
                          <span class="text-[10px] text-slate-400 font-mono mt-0.5">
                            {{ props.documento.mime_type || 'PDF' }} • {{ formatFileSize(props.documento.file_size) }}
                          </span>
                        </div>
                      </div>
                      <Button 
                        type="button" 
                        variant="outline" 
                        size="sm" 
                        @click="showFileUpload"
                        class="text-[10px] font-bold uppercase tracking-widest gap-2"
                      >
                        <UploadCloud class="w-3.5 h-3.5" />
                        Sostituisci
                      </Button>
                    </div>

                    <div v-if="showFileInput || !hasExistingFile" class="space-y-4">
                      <label
                        for="file-upload"
                        @dragover.prevent
                        @drop="onDrop"
                        class="flex flex-col items-center justify-center w-full h-48 border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl cursor-pointer bg-white dark:bg-slate-950 hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors group"
                      >
                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                          <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-full mb-3 group-hover:scale-110 transition-transform">
                            <UploadCloud class="w-8 h-8 text-indigo-500" />
                          </div>
                          <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
                            <span class="font-semibold text-indigo-600 dark:text-indigo-400">Clicca per caricare</span> o trascina il nuovo file qui
                          </p>
                          <p class="text-xs text-slate-400 dark:text-slate-500">Solo PDF (Max 20MB)</p>
                        </div>
                        <input
                          id="file-upload"
                          type="file"
                          class="hidden"
                          accept=".pdf,application/pdf"
                          @change="handleFileChange"
                          ref="fileInputRef"
                        />
                      </label>

                      <div v-if="showFileInput && hasExistingFile" class="flex justify-end">
                        <Button 
                          type="button" 
                          variant="ghost" 
                          size="sm"
                          @click="cancelFileUpload"
                          class="text-[10px] font-bold uppercase tracking-widest text-slate-500 hover:text-slate-700"
                        >
                          Annulla Sostituzione
                        </Button>
                      </div>
                    </div>

                    <div v-if="file" class="mt-4 flex items-center justify-between p-4 bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-800/50 rounded-lg shadow-sm">
                      <div class="flex items-center gap-4 overflow-hidden">
                        <div class="p-2 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg text-emerald-600 shrink-0">
                          <FileText class="w-6 h-6" />
                        </div>
                        <div class="flex flex-col min-w-0">
                          <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-400 truncate">{{ file.name }}</span>
                          <span class="text-[10px] text-emerald-500 font-mono mt-0.5">
                            Nuovo file • {{ formatFileSize(file.size) }}
                          </span>
                        </div>
                      </div>
                      <button 
                        type="button" 
                        @click="removeFile" 
                        class="text-[10px] font-bold uppercase tracking-widest text-red-500 hover:text-red-700 transition-colors px-2 py-1 bg-white/50 dark:bg-black/20 rounded-md shrink-0"
                      >
                        Rimuovi
                      </button>
                    </div>

                    <InputError :message="form.errors.file" class="mt-2" />
                  </div>
                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: props.condominio.id, immobile: props.immobile.id })"
                  class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-[10px] font-bold uppercase tracking-widest hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                Annulla
              </Link>

              <Button 
                  type="submit"
                  :disabled="form.processing" 
                  class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
              >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <Save v-else class="h-4 w-4" />
                  Salva Modifiche
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>
   </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>