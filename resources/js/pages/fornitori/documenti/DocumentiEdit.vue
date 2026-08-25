<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, UploadCloud, Info, FileText, X, FileUp, Eye } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Item } from "@/components/ui/item";
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { trans } from 'laravel-vue-i18n';
import vSelect from "vue-select";
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Documento } from '@/types/documenti';
import type { Fornitore } from '@/types/fornitori';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server: non è un numero nostro. */
  limiteFile: string;
  fornitore: Fornitore;
  documento: Documento;
}>()

const { generatePath, generateRoute } = usePermission();

const file = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const showFileInput = ref(false)

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
  is_approved: !!props.documento?.is_approved,
  file: null as File | null,
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Fornitori', href: route(generateRoute('fornitori.index')) },
  { title: props.fornitore.ragione_sociale, href: generatePath('fornitori/:fornitore', { fornitore: props.fornitore.id }) },
  { title: 'Documenti', href: generatePath('fornitori/:fornitore/documenti', { fornitore: props.fornitore.id }) },
  { title: props.documento.name, href: '#' }
]);

const pageGuides = [
  {
    title: 'Aggiornamento',
    description: 'Carica un nuovo file per sostituire il documento esistente.',
    icon: FileUp,
    colorVariant: 'blue' as const
  },
  {
    title: 'Dettagli',
    description: 'Modifica il nome o la descrizione del documento.',
    icon: FileText,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Visibilità',
    description: 'Cambia lo stato di visibilità in pubblico o privato.',
    icon: Eye,
    colorVariant: 'amber' as const
  }
];

// Validazione file
// ⚠️ Via il controllo sulla dimensione — era un secondo limite scritto dove nessuno lo cerca — e via
// JPG e PNG, che la regola a server (`mimes:pdf`) non ha mai accettato: si offrivano all'utente per
// poi respingerli dopo il caricamento.
const validateFile = (selectedFile: File): boolean => {
  const allowedTypes = ['application/pdf'];

  if (!allowedTypes.includes(selectedFile.type)) {
    form.setError('file', 'Sono ammessi solo file PDF');
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
  form.post(route(...generateRoute('fornitori.documenti.update', 
  { 
    fornitore: props.fornitore.id, 
    documento: props.documento.id
  })) + '?_method=PUT', {
    preserveScroll: true,
    onSuccess: () => {
      // In Inertia after update, it usually redirects, so we don't need to reset
    }
  });
};

</script>

<template>
  <Head title="Modifica documento fornitore" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
          :page-title="`Modifica documento: ${documento.name}`"
          :page-subtitle="`Aggiorna il documento del fornitore ${fornitore.ragione_sociale}`"
          :guides="pageGuides"
          :breadcrumbs="breadcrumbs"
          :video-url="null"
          :back-url="generatePath('fornitori/:fornitore/documenti', { fornitore: props.fornitore.id })"
          back-text="Torna ai documenti"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">Contenuto Documento</CardTitle>
                <CardDescription>Inserisci i dettagli principali e allega il file.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label for="name">Nome documento</Label>
                        <Input 
                            id="name" 
                            class="mt-1 block w-full bg-white dark:bg-slate-950"
                            v-model="form.name" 
                            v-on:focus="form.clearErrors('name')"
                            placeholder="Es. Contratto di appalto" 
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="sm:col-span-6">
                        <Label for="description">Descrizione</Label>
                        <Textarea 
                            id="description" 
                            class="mt-1 block w-full min-h-[160px] bg-white dark:bg-slate-950"
                            v-model="form.description" 
                            v-on:focus="form.clearErrors('description')"
                            placeholder="Descrizione opzionale del documento" 
                        />
                        <InputError :message="form.errors.description" />
                    </div>  
                </div> 

                <div class="grid grid-cols-1 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        
                        <Label class="mb-1 block">File allegato</Label>

                        <!-- File esistente -->
                        <div v-if="showExistingFile" class="mt-1">
                            <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                                        <FileText class="w-5 h-5 text-muted-foreground" />
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium truncate max-w-[250px]">{{ getFileName() }}</span>
                                        <span class="text-xs text-muted-foreground">
                                            {{ props.documento.mime_type || 'Documento' }}
                                            <template v-if="props.documento.file_size">
                                                • {{ formatFileSize(props.documento.file_size) }}
                                            </template>
                                        </span>
                                    </div>
                                </div>
                                <Button 
                                    type="button"
                                    variant="outline" 
                                    size="sm" 
                                    @click="showFileUpload"
                                    class="gap-2"
                                >
                                    <UploadCloud class="w-4 h-4" />
                                    Sostituisci
                                </Button>
                            </Item>
                        </div>

                        <!-- Area upload per sostituzione -->
                        <div v-if="showFileInput || !hasExistingFile" class="mt-1">
                            <label
                                for="file-upload"
                                @dragover.prevent
                                @drop="onDrop"
                                class="block cursor-pointer"
                            >
                                <Empty class="border border-dashed bg-white dark:bg-slate-950 hover:bg-accent/10 transition">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <UploadCloud class="w-8 h-8 text-muted-foreground" />
                                        </EmptyMedia>
                                        <EmptyTitle>Sostituisci il documento</EmptyTitle>
                                        <EmptyDescription>
                                            Trascina qui il file oppure clicca per selezionarlo.
                                            <div class="text-xs text-muted-foreground mt-1">
                                                Solo PDF (max {{ limiteFile }})
                                            </div>
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>

                                <input
                                    id="file-upload"
                                    type="file"
                                    class="hidden"
                                    accept="application/pdf"
                                    @change="handleFileChange"
                                    ref="fileInputRef"
                                />
                            </label>

                            <div v-if="showFileInput && hasExistingFile" class="mt-3 flex justify-end">
                                <Button 
                                    type="button" 
                                    variant="outline" 
                                    size="sm"
                                    @click="cancelFileUpload"
                                    class="gap-2"
                                >
                                    <X class="w-4 h-4" />
                                    Annulla sostituzione
                                </Button>
                            </div>
                        </div>

                        <!-- Nuovo file selezionato -->
                        <div v-if="file" class="mt-1">
                            <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                                        <FileText class="w-5 h-5 text-indigo-500" />
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium truncate max-w-[250px]">
                                            {{ file.name }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">
                                            {{ formatFileSize(file.size) }}
                                        </span>
                                    </div>
                                </div>
                                <Button 
                                    type="button"
                                    variant="ghost" 
                                    size="icon" 
                                    @click.prevent="removeFile"
                                    title="Rimuovi"
                                >
                                    <X class="w-4 h-4 text-red-500 hover:text-red-600" />
                                </Button>
                            </Item>
                            <div v-if="hasExistingFile" class="text-xs text-amber-600 mt-2 font-medium flex items-center gap-1.5">
                                <Info class="w-3.5 h-3.5" />
                                Questo file sostituirà quello esistente.
                            </div>
                        </div>

                        <InputError :message="form.errors.file" class="mt-2" />
                    </div>
                </div>

            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">Impostazioni</CardTitle>
                <CardDescription>Configura la visibilità del documento.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-2 min-h-[24px] mb-1">
                            <Label for="is_published">Visibilità</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                        <Info class="w-4 h-4" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                    <h4 class="text-sm font-bold mb-2">Visibilità</h4>
                                    <p class="text-xs text-slate-500 leading-relaxed">Scegli se rendere il documento visibile al fornitore o mantenerlo a uso interno.</p>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                        <v-select 
                            id="is_published"
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="publishedConstants" 
                            label="label" 
                            v-model="form.is_published"
                            placeholder="Seleziona visibilità"
                            @update:modelValue="form.clearErrors('is_published')" 
                            :reduce="(is_published: PublishedType) => is_published.value"
                        >
                            <template #option="{ label, icon }">
                                <div class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span> 
                                </div>
                            </template>
                            <template #selected-option="{ label, icon }">
                                <div v-if="label" class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                </div>
                            </template>
                        </v-select>
                        <InputError :message="form.errors.is_published" />
                    </div>

                </div>
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
                :href="generatePath('fornitori/:fornitore/documenti', { fornitore: props.fornitore.id })"
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
                Salva modifiche
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
